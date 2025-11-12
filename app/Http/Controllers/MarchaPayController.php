<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class MarchaPayController extends Controller
{
    private function logAttempt(array $payload)
    {
        try {
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $path = storage_path('logs/marchapay_attempts.log');
            \Illuminate\Support\Facades\File::append($path, $line . PHP_EOL);
        } catch (\Throwable $e) {
            // Silently ignore logging errors
        }
    }

    public function createTransaction($hash, $postbackUrl, $paymentMethod, $useReserve = false)
    {
        $client = new \GuzzleHttp\Client();

        $cart = DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();

        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        // Suporta apenas PIX inicialmente
        if ($paymentMethod === 'cartao') {
            return [
                'status' => '404',
                'message' => 'MarchaPay não suporta pagamento por cartão neste fluxo.'
            ];
        }

        $marchaData = DB::table($useReserve ? 'pagamento_reserva' : 'pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'marchaPay')
            ->first();

        if (!$marchaData) return ['status' => '404', 'message' => 'Nenhuma chave secreta encontrada para o MarchaPay!'];

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
                'unitPrice' => intval((($product->unit_price ?? $product->preco) * 100)),
                'quantity' => intval($product->quantity),
            ];
        }

        $amountCents = array_sum(array_map(function ($item) {
            return $item['unitPrice'] * $item['quantity'];
        }, $items)) + (intval(($cart->frete_selecionado_valor ?? 0) * 100));

        $documentNumber = preg_replace('/\D/', '', ($cart->cpf ?? '00000000000'));

        $body = [
            'amount' => $amountCents,
            'paymentMethod' => 'pix',
            'items' => $items,
            'customer' => [
                'document' => [
                    'type' => 'cpf',
                    'number' => $documentNumber,
                ],
                'name' => $cart->nome_completo ?? 'Cliente',
                'email' => $cart->email ?? 'cliente@example.com',
            ],
            'postbackUrl' => $postbackUrl ?: url('/api/marchapay/callback'),
            'externalRef' => $hash,
            'metadata' => json_encode(['hash' => $hash], JSON_UNESCAPED_UNICODE),
        ];

        try {
            $endpoint = 'https://api.marchabb.com/v1/transactions';
            $reqHeaders = [
                'accept' => 'application/json',
                'authorization' => 'Basic ' . base64_encode("{$marchaData->chave}:x"),
                'content-type' => 'application/json',
            ];

            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'create_transaction_request',
                'hash' => $hash,
                'id_loja' => $cart->id_loja,
                'endpoint' => $endpoint,
                'headers' => ['accept' => 'application/json', 'content-type' => 'application/json'],
                'body' => $body,
                'useReserve' => $useReserve,
            ]);

            $response = $client->request('POST', $endpoint, [
                'headers' => $reqHeaders,
                'json' => $body,
            ]);

            $response = json_decode($response->getBody(), true);

            $pixCode = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);

            return [
                'status' => 200,
                'paymentMethod' => 'pix',
                'id' => $response['id'] ?? null,
                'pix' => [
                    'qrcode' => $pixCode,
                ],
                'secureUrl' => $response['secureUrl'] ?? null,
            ];
        } catch (RequestException $exception) {
            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'create_transaction_error',
                'hash' => $hash,
                'id_loja' => $cart->id_loja,
                'error' => $exception->getMessage(),
                'response' => $exception->hasResponse() ? (string) $exception->getResponse()->getBody() : null,
            ]);

            return [
                'status' => '404',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function callback(Request $request)
    {
        $payload = $request->all();

        $this->logAttempt([
            'ts' => now()->toIso8601String(),
            'event' => 'callback_received',
            'payload' => $payload,
        ]);

        // O postback envia o objeto em data; preferimos externalRef para localizar o hash
        $data = $payload['data'] ?? $payload;
        $hash = $data['externalRef'] ?? null;

        if (!$hash && !empty($data['metadata'])) {
            // Tentar extrair hash do metadata string
            if (is_string($data['metadata'])) {
                if (preg_match('/hash\s*:\s*([a-zA-Z0-9_-]+)/', $data['metadata'], $m)) {
                    $hash = $m[1];
                }
            } elseif (is_array($data['metadata'])) {
                $hash = $data['metadata']['hash'] ?? null;
            }
        }

        if (!$hash) {
            // Fallback: procurar id da transação no registro
            $transactionId = $data['id'] ?? null;
            if ($transactionId) {
                $hash = DB::table('transactions')
                    ->where('data', 'LIKE', '%"id":' . $transactionId . '%')
                    ->value('hash');
            }
        }

        if (!$hash) {
            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'callback_hash_not_found',
                'lookup_keys' => [
                    'externalRef' => $data['externalRef'] ?? null,
                    'id' => $data['id'] ?? null,
                ],
            ]);
            return response()->json(['status' => 404]);
        }

        $status = strtolower($data['status'] ?? 'pending');

        DB::table('transactions')
            ->where('hash', $hash)
            ->update([
                'data' => json_encode($data),
                'status' => ucfirst($status),
                'updated_at' => now(),
            ]);

        DB::table('carrinho')
            ->where('hash', $hash)
            ->update([
                'gateway_status' => ucfirst($status),
                'finalizou_pedido' => in_array($status, ['paid', 'approved']) ? 's' : DB::raw('finalizou_pedido'),
                'data_pedido' => in_array($status, ['paid', 'approved']) ? now() : DB::raw('data_pedido'),
            ]);

        if (in_array($status, ['paid', 'approved'])) {
            (new \App\Http\Controllers\CheckoutController())->shopifyOrderUpdate($hash);
        }

        $this->logAttempt([
            'ts' => now()->toIso8601String(),
            'event' => 'callback_processed',
            'hash' => $hash,
            'status' => $status,
        ]);

        return response()->json(['status' => 200]);
    }
}