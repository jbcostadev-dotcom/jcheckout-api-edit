<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;

class UtmifyController extends Controller
{
    public function createOrder($hash, $paymentStatus, $paymentMethod = null, $paymentDate = null)
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

        $products = DB::table('order_product AS op')
            ->leftJoin('produto AS p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $cart->id_carrinho)
            ->select('p.id_produto', 'p.titulo', 'p.preco', 'op.quantity', 'op.unit_price')
            ->get();

        if ($products->isEmpty()) return ['status' => '404', 'message' => 'Nenhum produto encontrado!'];

        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'id' => $product->id_produto,
                'name' => $product->titulo,
                'planId' => null,
                'planName' => null,
                'quantity' => $product->quantity,
                'priceInCents' => ($product->unit_price ?? $product->preco) * 100
            ];
        }

        $statuses = ['waiting_payment', 'paid', 'refused', 'refunded', 'chargedback'];

        $body = [
            'orderId' => strval($hash),
            'platform' => config('app.name'),
            'paymentMethod' => $paymentMethod ?? 'free_price',
            'status' => in_array($paymentStatus, $statuses) ? $paymentStatus : 'refused',
            'createdAt' => Carbon::parse($cart->dt_instancia_carrinho, 'America/Sao_Paulo')->setTimezone('UTC')->format('Y-m-d H:i:s'),
            'approvedDate' => $paymentDate,
            'refundedAt' => null,
            'customer' => [
                'name' => $cart->nome_completo ?? 'No Name',
                'email' => $cart->email ?? 'No Email',
                'phone' => $cart->telefone,
                'document' => str_replace(['.', '-'], '', $cart->cpf),
                'country' => 'BR',
            ],
            'products' => $items,
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
                'totalPriceInCents' => intval(collect($items)->sum(function ($item) {
                    return $item['priceInCents'] * $item['quantity'];
                }) + ($cart->frete_selecionado_valor * 100)),
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => 0,
                'currency' => 'BRL'
            ],
            'isTest' => false
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
