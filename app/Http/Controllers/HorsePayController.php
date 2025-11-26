<?php

namespace App\Http\Controllers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HorsePayController extends Controller
{
    private function logAttempt(array $payload)
    {
        try {
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $path = storage_path('logs/horsepay_attempts.log');
            \Illuminate\Support\Facades\File::append($path, $line . PHP_EOL);
        } catch (\Throwable $e) {
            // Silently ignore logging errors
        }
    }
    private function getShopDataByHash($hash)
    {
        return DB::table('carrinho')
            ->where('hash', $hash)
            ->whereNull('data_delete')
            ->orderBy('id_carrinho', 'DESC')
            ->first();
    }

    public function createToken($idLoja, $useReserve = false)
    {
        try {
            $client = new \GuzzleHttp\Client();

            $pix = DB::table($useReserve ? 'pagamento_reserva' : 'pagamento_pix')
                ->where('id_loja', $idLoja)
                ->where('logo_banco', 'horsePay')
                ->first();

            if (!$pix || !$pix->chave || !$pix->public_key) {
                return false;
            }

            $endpoint = 'https://api.horsepay.io/auth/token';
            $headers = [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
            ];
            $body = [
                'client_key' => $pix->public_key,
                'client_secret' => $pix->chave,
            ];

            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'create_token_request',
                'id_loja' => $idLoja,
                'endpoint' => $endpoint,
                'headers' => [
                    'content-type' => 'application/json',
                ],
                'body' => $body,
            ]);

            $response = $client->request('POST', $endpoint, [
                'headers' => $headers['headers'],
                'body' => json_encode($body),
            ]);

            $data = json_decode($response->getBody(), true);
            $token = $data['token'] ?? ($data['access_token'] ?? null);

            if ($token) {
                DB::table($useReserve ? 'pagamento_reserva' : 'pagamento_pix')
                    ->where('id_loja', $idLoja)
                    ->where('logo_banco', 'horsePay')
                    ->update(['token_horsepay' => $token]);

                $this->logAttempt([
                    'ts' => now()->toIso8601String(),
                    'event' => 'create_token_success',
                    'id_loja' => $idLoja,
                    'token_preview' => Str::limit($token, 20, '...'),
                ]);
                return $token;
            }

            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'create_token_empty',
                'id_loja' => $idLoja,
                'response' => $data,
            ]);
            return false;
        } catch (RequestException $e) {
            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'create_token_error',
                'id_loja' => $idLoja,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);
            return false;
        }
    }

    public function createTransaction($hash, $postbackUrl, $paymentMethod, $useReserve = false)
    {
        $client = new \GuzzleHttp\Client();

        $cart = $this->getShopDataByHash($hash);
        if (!$cart) return ['status' => '404', 'message' => 'Nenhum dado de carrinho encontrado!'];

        $pix = DB::table($useReserve ? 'pagamento_reserva' : 'pagamento_pix')
            ->where('id_loja', $cart->id_loja)
            ->where('logo_banco', 'horsePay')
            ->first();

        if (!$pix) return ['status' => '404', 'message' => 'Nenhuma credencial HorsePay encontrada!'];

        $token = $pix->token_horsepay;
        if (!$token) {
            $token = $this->createToken($cart->id_loja, $useReserve);
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
            $endpoint = 'https://api.horsepay.io/transaction/neworder';
            $reqHeaders = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ];
            $reqBody = [
                'payer_name' => $cart->nome_completo ?? 'Cliente',
                'amount' => (float) number_format($amount, 2, '.', ''),
                'callback_url' => $postbackUrl,
                'client_reference_id' => $hash,
            ];

            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'neworder_request',
                'hash' => $hash,
                'id_loja' => $cart->id_loja,
                'endpoint' => $endpoint,
                'headers' => [
                    'Content-Type' => 'application/json',
                    // Mask token to avoid leaking secrets in logs
                    'Authorization' => 'Bearer ' . Str::limit($token, 12, '...'),
                ],
                'body' => $reqBody,
            ]);

            $response = $client->request('POST', $endpoint, [
                'headers' => $reqHeaders,
                'body' => json_encode($reqBody),
            ]);

            $data = json_decode($response->getBody(), true);

            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'neworder_success',
                'hash' => $hash,
                'id_loja' => $cart->id_loja,
                'status_code' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
                'response_keys' => array_keys($data ?? []),
            ]);

            // Normaliza para interface comum (status de negócio "Aguardando Pagamento" ao criar PIX)
            return [
                'id' => $data['external_id'] ?? ($data['id'] ?? ($data['order_id'] ?? null)),
                'status' => 'Aguardando Pagamento',
                'paymentMethod' => 'pix',
                'pix' => [
                    // BRCode copiado pelo cliente
                    'qrcode' => $data['copy_past'] ?? null,
                ],
                // Caso o frontend queira usar imagem de QR pronta
                'qrcodeImage' => $data['payment'] ?? null,
            ];
        } catch (RequestException $exception) {
            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'neworder_error',
                'hash' => $hash,
                'id_loja' => $cart->id_loja,
                'error' => $exception->getMessage(),
                'response' => $exception->hasResponse() ? (string) $exception->getResponse()->getBody() : null,
            ]);
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

        // Log callback recebido
        $this->logAttempt([
            'ts' => now()->toIso8601String(),
            'event' => 'callback_received',
            'payload' => $payload,
        ]);

        // Preferir hash enviado como client_reference_id
        $hash = $payload['client_reference_id'] ?? null;

        // Fallback: localizar por external_id / id / order_id presentes no JSON da transação
        if (!$hash) {
            $transactionId = $payload['external_id'] ?? ($payload['id'] ?? ($payload['order_id'] ?? null));
            if ($transactionId) {
                $hash = DB::table('transactions')
                    ->where('data', 'LIKE', '%"id":' . $transactionId . '%')
                    ->orWhere('data', 'LIKE', '%"external_id":' . $transactionId . '%')
                    ->value('hash');
            }
        }

        if (!$hash) {
            $this->logAttempt([
                'ts' => now()->toIso8601String(),
                'event' => 'callback_hash_not_found',
                'lookup_keys' => [
                    'client_reference_id' => $payload['client_reference_id'] ?? null,
                    'external_id' => $payload['external_id'] ?? null,
                    'id' => $payload['id'] ?? null,
                    'order_id' => $payload['order_id'] ?? null,
                ],
            ]);
            return response()->json(['status' => 404]);
        }

        // Mapear status: booleano true => paid, false => unpaid
        $statusFlag = $payload['status'] ?? null;
        if (is_string($statusFlag)) {
            // Converter strings "true"/"false" em booleans
            $lower = strtolower($statusFlag);
            if (in_array($lower, ['true', '1', 'yes', 'paid'])) $statusFlag = true;
            elseif (in_array($lower, ['false', '0', 'no', 'unpaid'])) $statusFlag = false;
        }
        $status = is_bool($statusFlag) ? ($statusFlag ? 'paid' : 'unpaid') : ($statusFlag ?? ($payload['paid'] ? 'paid' : null));

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

        // Atualizações externas após pagamento
        if (($status ?? '') === 'paid') {
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

    public function checkTransaction($transactionId)
    {
        // Sem documentação de endpoint de consulta, retornamos 200 e mantemos fluxo por callback
        return response()->json(['status' => '200', 'message' => 'processing']);
    }
}