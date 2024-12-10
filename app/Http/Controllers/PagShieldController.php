<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagShieldController extends Controller
{
    public function createTransaction($hash)
    {
        $client = new \GuzzleHttp\Client();

        $card = DB::table('cartao')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$card) dd('No card data found!');

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) dd('No cart data found!');

        $product = DB::table('produto')
            ->where('id_produto', $cart->id_produto)
            ->first();

        if (!$product) dd('No product found!');

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
            "amount" => $product->preco * 100,
            "paymentMethod" => "credit_card",
            "installments" => $cart->installments,
            "interestRate" => 5,
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
                    'authorization' => 'Basic ' . base64_encode("sk_live_6fY8rDJ41u4PbaDV4N89j2bFvkje7g2P4qvi0eK6ZE:x"),
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
