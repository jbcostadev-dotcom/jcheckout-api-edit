<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class HorsePayController extends Controller
{
    private function getShopDataByHash($hash)
    {
        return DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();
    }

    public function createToken($idLoja)
    {
        try {
            $client = new \GuzzleHttp\Client();

            $pix = DB::table('pagamento_pix')
                ->where('id_loja', $idLoja)
                ->where('logo_banco', 'horsePay')
                ->first();

            if (!$pix || !$pix->chave || !$pix->public_key) {
                return false;
            }

            $response = $client->request('POST', 'https://api.horsepay.io/auth/token', [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'body' => json_encode([
                    'client_key' => $pix->public_key,
                    'client_secret' => $pix->chave,
                ]),
            ]);

            $data = json_decode($response->getBody(), true);
            $token = $data['token'] ?? ($data['access_token'] ?? null);

            if ($token) {
                DB::table('pagamento_pix')
                    ->where('id_loja', $idLoja)
                    ->where('logo_banco', 'horsePay')
                    ->update(['token_horsepay' => $token]);

                return $token;
            }

            return false;
        } catch (RequestException $e) {
            return false;
        }
    }

    public function createTransaction($hash, $postbackUrl, $paymentMethod)
    {
        $client = new \GuzzleHttp\Client();

        $cart = $this->getShopDataByHash($hash);
        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $pix = DB::table('pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'horsePay')
            ->first();

        if (!$pix) return ['status' => '404', 'message' => 'Nenhuma credencial HorsePay encontrada!'];

        $token = $pix->token_horsepay;
        if (!$token) {
            $token = $this->createToken($cart->id_loja);
            if (!$token) return ['status' => '404', 'message' => 'Falha ao obter token HorsePay!'];
        }

        $products = DB::table('order_product AS op')
            ->leftJoin('produto AS p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $cart->id_carrinho)
            ->select('p.titulo', 'p.preco', 'op.quantity', 'op.unit_price')
            ->get();

        if ($products->isEmpty()) return ['status' => '404', 'message' => 'Nenhum produto encontrado!'];

        $amount = collect($products)->sum(function ($product) {
            return ($product->unit_price ?? $product->preco) * $product->quantity;
        }) + ($cart->frete_selecionado_valor ?? 0);

        try {
            $response = $client->request('POST', 'https://api.horsepay.io/transaction/neworder', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body' => json_encode([
                    'payer_name' => $cart->nome_completo ?? 'Cliente',
                    'amount' => (float) number_format($amount, 2, '.', ''),
                    'callback_url' => $postbackUrl,
                ]),
            ]);

            $data = json_decode($response->getBody(), true);

            // Normaliza para interface comum (status 200 esperado pelo CheckoutController)
            return [
                'id' => $data['external_id'] ?? ($data['id'] ?? ($data['order_id'] ?? null)),
                'status' => 200,
                'paymentMethod' => 'pix',
                'pix' => [
                    // BRCode copiado pelo cliente
                    'qrcode' => $data['copy_past'] ?? null,
                ],
                // Caso o frontend queira usar imagem de QR pronta
                'qrcodeImage' => $data['payment'] ?? null,
            ];
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                return ['status' => '404', 'message' => json_decode($response->getBody()->getContents(), true)['message'] ?? 'Unknown error!'];
            } else {
                return ['status' => '404', 'message' => $exception->getMessage()];
            }
        }
    }

    public function callback(Request $request)
    {
        $payload = $request->all();
        $transactionId = $payload['id'] ?? ($payload['order_id'] ?? null);
        $status = $payload['status'] ?? ($payload['paid'] ? 'paid' : null);

        if (!$transactionId) return response()->json(['status' => 500]);

        $hash = DB::table('transactions')
            ->where('data', 'LIKE', '%"id":' . $transactionId . '%')
            ->value('hash');

        if (!$hash) return response()->json(['status' => 404]);

        DB::table('transactions')
            ->where('hash', $hash)
            ->update([
                'data' => json_encode($payload),
                'status' => ucfirst($status ?? 'Paid'),
                'updated_at' => now(),
            ]);

        DB::table('carrinho')
            ->where('hash', $hash)
            ->update([
                'gateway_status' => ucfirst($status ?? 'Paid'),
                'finalizou_pedido' => 's',
                'data_pedido' => now(),
            ]);

        (new \App\Http\Controllers\CheckoutController())->shopifyOrderUpdate($hash);

        return response()->json(['status' => 200]);
    }

    public function checkTransaction($transactionId)
    {
        // Sem documentação de endpoint de consulta, retornamos 200 e mantemos fluxo por callback
        return response()->json(['status' => '200', 'message' => 'processing']);
    }
}