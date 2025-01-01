<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;

class PagShieldController extends Controller
{
    public function createTransaction($hash, $postbackUrl, $paymentMethod)
    {
        $client = new \GuzzleHttp\Client();

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $pagShieldData = DB::table('pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'pagShield')
            ->first();

        if (!$pagShieldData) return ['status' => '404', 'message' => 'Nenhuma chave secreta encontrada para o PagShield!'];

        $products = DB::table('order_products AS op')
            ->leftJoin('produto AS p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $cart->id_carrinho)
            ->select('p.titulo', 'p.preco', 'o.quantity', 'o.unit_price')
            ->get();

        if ($products->isEmpty()) return ['status' => '404', 'message' => 'Nenhum produto encontrado!'];

        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'tangible' => true,
                'title' => $product->titulo,
                'unitPrice' => intval(($product->unit_price ?? $product->preco) * 100),
                'quantity' => intval($product->quantity),
            ];
        }

        $body = [
            'postbackUrl' => $postbackUrl,
            'customer' => [
                'name' => $cart->nome_completo ?? 'No Name',
                'email' => $cart->email ?? 'No Email',
                'document' => [
                    'number' => str_replace(['.', '-'], '', $cart->cpf),
                    'type' => 'cpf'
                ]
            ],
            'amount' => intval(collect($items)->sum(function ($item) {
                return $item['unitPrice'] * $item['quantity'];
            })),
            'installments' => intval($cart->installments),
            'interestRate' => floatval($pagShieldData->instalment_rate ?? 0),
            'items' => $items,
            'setTestMode' => true,
        ];

        if ($paymentMethod === 'cartao') {
            $card = DB::table('cartao')
                ->where('hash', $hash)
                ->whereNull('data_delete')
                ->orderBy('id', 'DESC')
                ->first();

            if (!$card) return ['status' => '404', 'message' => 'Nenhum dado de cartão encontrado!'];

            $body['paymentMethod'] = 'credit_card';

            $body['card'] = [
                'number' => $card->cc,
                'holderName' => $card->titular,
                'expirationMonth' => intval(explode('/', $card->validade)[0]),
                'expirationYear' => intval('20' . explode('/', $card->validade)[1]),
                'cvv' => $card->cvv
            ];
        } elseif ($paymentMethod === 'pix') {
            $body['paymentMethod'] = 'pix';

            $body['pix'] = [
                'expiresInDays' => 1
            ];
        } else {
            return ['status' => '404', 'message' => 'Método de pagamento errado!'];
        }

        try {
            $response = $client->request('POST', 'https://api.pagshield.io/v1/transactions', [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Basic ' . base64_encode("{$pagShieldData->chave}:x"),
                    'content-type' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();

                // echo 'HTTP Status Code: ' . $response->getStatusCode();
                // echo ' || ';
                // echo 'Error Message: ' . $response->getBody();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message'] ?? 'Unknown error!'];
            } else {
                // echo 'Request Error: ' . $exception->getMessage();
                return ['status' => '404', 'message' => $exception->getMessage()];
            }
        }
    }

    public function checkTransaction($transactionId)
    {
        $client = new \GuzzleHttp\Client();

        $hash = DB::table('transactions')
            ->where('data', 'LIKE', '{"id":' . $transactionId . ',%')
            ->value('hash');

        if (!$hash) return ['status' => '404', 'message' => 'Não foi encontrado!'];

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $pagShieldData = DB::table('pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'pagShield')
            ->first();

        if (!$pagShieldData) return ['status' => '404', 'message' => 'Nenhuma chave secreta encontrada para o PagShield!'];

        try {
            $response = $client->request('GET', "https://api.pagshield.io/v1/transactions/{$transactionId}", [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Basic ' . base64_encode("{$pagShieldData->chave}:x"),
                    'content-type' => 'application/json',
                ],
            ]);

            $response = json_decode($response->getBody(), true);

            DB::table('transactions')
                ->where('hash', $hash)
                ->update([
                    'data' => json_encode($response),
                    'status' => ucfirst($response['status']),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return ['status' => '200', 'message' => $response['status']];
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();

                // echo 'HTTP Status Code: ' . $response->getStatusCode();
                // echo ' || ';
                // echo 'Error Message: ' . $response->getBody();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message'] ?? 'Unknown error!'];
            } else {
                // echo 'Request Error: ' . $exception->getMessage();
                return ['status' => '404', 'message' => $exception->getMessage()];
            }
        }
    }
}
