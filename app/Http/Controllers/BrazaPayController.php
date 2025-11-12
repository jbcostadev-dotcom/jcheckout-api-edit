<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;

class BrazaPayController extends Controller
{
    public function createTransaction($hash, $postbackUrl, $paymentMethod, $useReserve = false)
    {
        $client = new \GuzzleHttp\Client();

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $brazaData = DB::table($useReserve ? 'pagamento_reserva' : 'pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'brazaPay')
            ->first();

        if (!$brazaData) return ['status' => '404', 'message' => 'Nenhuma chave secreta encontrada para o BrazaPay!'];

        $products = DB::table('order_product AS op')
            ->leftJoin('produto AS p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $cart->id_carrinho)
            ->select('p.titulo', 'p.preco', 'op.quantity', 'op.unit_price')
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
            }) + ($cart->frete_selecionado_valor * 100)),
            'installments' => intval($cart->installments),
            'interestRate' => floatval($brazaData->instalment_rate ?? 0),
            'items' => $items,
            'shipping' => [
                'address' => [
                    'street' => $cart->rua,
                    'streetNumber' => $cart->numero,
                    'neighborhood' => $cart->bairro,
                    'city' => 'Cidade',
                    'zipCode' => preg_replace("/\D/", "", $cart->cep),
                    'state' => 'SP',
                    'country' => 'br'
                ],
                'fee' => intval($cart->frete_selecionado_valor * 100)
            ],
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
            $response = $client->request('POST', 'https://api.brazapay.co/v1/transactions', [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Basic ' . base64_encode("{$brazaData->chave}:x"),
                    'content-type' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message'] ?? 'Unknown error!'];
            } else {
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

        $brazaData = DB::table('pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'brazaPay')
            ->first();

        if (!$brazaData) return ['status' => '404', 'message' => 'Nenhuma chave secreta encontrada para o BrazaPay!'];

        try {
            $response = $client->request('GET', "https://api.brazapay.co/v1/transactions/{$transactionId}", [
                'headers' => [
                    'accept' => 'application/json',
                    'authorization' => 'Basic ' . base64_encode("{$brazaData->chave}:x"),
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

            DB::table('carrinho')
                ->where('hash', $hash)
                ->update([
                    'gateway_status' => ucfirst($response['status']),
                    'finalizou_pedido' => 's',
                    'data_pedido' => now(),
                ]);

            (new \App\Http\Controllers\CheckoutController())->shopifyOrderUpdate($hash);

            return ['status' => '200', 'message' => $response['status']];
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message'] ?? 'Unknown error!'];
            } else {
                return ['status' => '404', 'message' => $exception->getMessage()];
            }
        }
    }
}