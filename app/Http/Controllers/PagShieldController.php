<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;

class PagShieldController extends Controller
{
    public function createTransaction($hash)
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

        $card = DB::table('cartao')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$card) return ['status' => '404', 'message' => 'Nenhum dado de cartão encontrado!'];

        $product = DB::table('produto')
            ->where('id_produto', $cart->id_produto)
            ->first();

        if (!$product) return ['status' => '404', 'message' => 'Nenhum produto encontrado!'];

        $body = [
            "card" => [
                "number" => $card->cc,
                "holderName" => $card->titular,
                "expirationMonth" => intval(explode("/", $card->validade)[0]),
                "expirationYear" => intval('20' . explode("/", $card->validade)[1]),
                "cvv" => $card->cvv
            ],
            "customer" => [
                "name" => $cart->nome_completo ?? 'No Name',
                "email" => $cart->email ?? 'No Email',
                "document" => [
                    "number" => str_replace(['.', '-'], '', $card->cpf),
                    "type" => "cpf"
                ]
            ],
            "amount" => $product->preco * $cart->quantidade * 100,
            "paymentMethod" => "credit_card",
            "installments" => $cart->installments,
            "interestRate" => floatval($pagShieldData->instalment_rate ?? 0),
            "items" => [
                [
                    "tangible" => true,
                    "title" => $product->titulo,
                    "unitPrice" => $product->preco * 100,
                    "quantity" => $cart->quantidade,
                ]
            ]
        ];

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
                // echo '\n';
                // echo 'Error Message: ' . $response->getBody();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message']];
            } else {
                // echo 'Request Error: ' . $exception->getMessage();
                return ['status' => '404', 'message' => $exception->getMessage()];
            }
        }
    }
}
