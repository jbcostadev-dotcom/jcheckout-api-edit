<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UtmifyController extends Controller
{
    public function createOrder($hash, $paymentDate)
    {
        $client = new \GuzzleHttp\Client();

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $utmifyApiKey = DB::table('pixel_utmify')
            ->where('loja_id', $cart->id_loja)
            ->value('api_key');

        if (!$utmifyApiKey) return ['status' => '404', 'message' => 'Chave API não encontrada!'];

        $utm = DB::table('utms')
            ->where('cart_id', $cart->id_carrinho)
            ->orderBy('id', 'DESC')
            ->first();

        $product = DB::table('produto')
            ->where('id_produto', $cart->id_produto)
            ->first();

        if (!$product) return ['status' => '404', 'message' => 'Nenhum produto encontrado!'];

        $body = [
            'orderId' => strval($hash),
            'platform' => config('app.name'),
            'paymentMethod' => 'credit_card',
            'status' => 'paid',
            'createdAt' => $cart->dt_instancia_carrinho,
            'approvedDate' => $paymentDate,
            'refundedAt' => null,
            'customer' => [
                'name' => $cart->nome_completo ?? 'No Name',
                'email' => $cart->email ?? 'No Email',
                'phone' => $cart->telefone,
                'document' => str_replace(['.', '-'], '', $cart->cpf),
                'country' => 'BR',
            ],
            'products' => [
                [
                    'id' => $product->id_produto,
                    'name' => $product->titulo,
                    'planId' => null,
                    'planName' => null,
                    'quantity' => $cart->quantidade,
                    'priceInCents' => $product->preco * 100
                ]
            ],
            'trackingParameters' => [
                'src' => null,
                'sck' => null,
                'utm_source' => $utm->source ?? null,
                'utm_campaign' => $utm->campaign ?? null,
                'utm_medium' => $utm->medium ?? null,
                'utm_content' => $utm->content ?? null,
                'utm_term' => $utm->term ?? null,
            ],
            'commission' => [
                'totalPriceInCents' => $product->preco * $cart->quantidade * 100,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => 0,
                'currency' => 'BRL'
            ],
            'isTest' => true
        ];

        try {
            $response = $client->request('POST', 'https://api.utmify.com.br/api-credentials/orders', [
                'headers' => [
                    'accept' => 'application/json',
                    'x-api-token' => $utmifyApiKey,
                    'Content-Type' => 'application/json'
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
                return ['status' => '400', 'message' => json_decode($response->getBody()->getContents(), true)];
            } else {
                // echo 'Request Error: ' . $exception->getMessage();
                return ['status' => '400', 'message' => $exception->getMessage()];
            }
        }
    }
}
