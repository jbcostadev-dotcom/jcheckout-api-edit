<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\Helper;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\PHPMailerController;
use App\Http\Controllers\EmailController;

class CheckoutController extends Controller
{
    public function getCheckoutByHash(Request $request){
        $helper = new Helper();

        if(!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

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
             WHERE c.hash = '".$request->hash."'"
             ));

        if(empty($query[0])) return response()->json( [ 'status' => 500 ] );

        $queryPixelFb = $helper->query("
                    SELECT pixel_1, pixel_2, pixel_3, pixel_4, pixel_5, pixel_6
                    FROM pixel_facebook
                    WHERE id_loja = :id
                ", ['id' => $query[0]->id_loja ]);
        
        $query[0]->pixelfb = (!empty($queryPixelFb[0]) ?$queryPixelFb[0] : []);

        $queryPreferencias = $helper->query("
             SELECT resumo_aberto, ultimo_dia
             FROM checkout_preferencias
             WHERE id_loja = :id
        ", ['id' => $query[0]->id_loja ]);

        $queryLogo = $helper->query("
             SELECT logo_banco
             FROM pagamento_pix
             WHERE id_loja = " . $query[0]->id_loja . "
        ");
	if(!empty($queryLogo)){
        $query[0]->logo = $this->getLogoBanco($queryLogo[0]);
        
	}else{
	$query[0]->logo = $this->getLogoBanco('mp');
	}

        if(empty($queryPreferencias)){
            $query[0]->resumo_aberto = false;
            $query[0]->ultimo_dia = false;
        }else{
            $query[0]->resumo_aberto = ($queryPreferencias[0]->resumo_aberto == 's' ? true : false);
            $query[0]->ultimo_dia = ($queryPreferencias[0]->ultimo_dia == 's' ? true : false);
        }
        return response()->json($query[0]);
    }

    public function getFretes(Request $request){
        $helper = new Helper();

        if( !$helper->verificaParametro($request->hash) ){
            return response()->json(['status' => 500]);
        }

        try {
            $idLoja = DB::select(DB::raw("
             SELECT id_loja 
             FROM carrinho
             WHERE hash = :hash
            "),[
                'hash' => $request->hash
            ]);

            if(empty($idLoja)) return false;

            $fretes = DB::select(DB::raw("
                SELECT * 
                FROM frete_loja
                WHERE id_loja = :id_loja
            "),[
                'id_loja' => $idLoja[0]->id_loja
            ]);

            if(empty($fretes)) return ['status' => 200, 'listaFretes' => []];
            
            return response()->json([
                'status' => 200,
                'listaFretes' => $fretes
            ]);
        } catch(\Exception $e) {
            return response()->json(['status' => 500]);

        }
    }

    public function getMetodosPagamento(Request $request){
        try {
            $helper = new Helper();

            if( !$helper->verificaParametro($request->hash) ) return response()->json(['status' => 500]);

            $queryLoja = $helper->query(
                "
                    SELECT id_loja
                    FROM carrinho
                    WHERE hash = :hash
                ",
                [ 'hash' => $request->hash ]
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
                [ 'hash' => $request->hash ]
            );

            if(empty($queryLoja)) return response()->json(['status' => 500]);
            
            $pagamentoPix = $helper->query(
                "SELECT tipo_chave,
                        chave,
                        logo_banco
                 FROM pagamento_pix
                 WHERE id_loja = :id_loja
                 ",
                 [ 'id_loja' => $queryLoja[0]->id_loja ]
            );

            // if(empty($pagamentoPix)) return response()->json( [ 'status' => 404 ] );
            $queryOrderBump = $helper->query("SELECT produto_orderbump, valor_orderbump FROM produto WHERE id_produto = " . $queryCliente[0]->id_produto );
            $flagOrderBump = false;

            if(!empty($queryOrderBump) && !is_null($queryOrderBump[0]->produto_orderbump)){
                $flagOrderBump = true;
                $qProdutoOrderBump = $helper->query("
                    SELECT titulo, imagem1
                    FROM produto
                    WHERE id_produto = :id
                ", ['id' => $queryOrderBump[0]->produto_orderbump ] );

            }


            return response()->json([
                'status' => 200,
                'listaCliente' => $queryCliente[0],
                'listaTiposPagamento' => [
                    'pix' => $pagamentoPix
                ],
                'listaOrder' => [
                    'order' => $flagOrderBump,
                    'order_produto' => ( !empty($qProdutoOrderBump[0]->titulo) ? $qProdutoOrderBump[0]->titulo : null),
                    'order_img' => ( !empty($qProdutoOrderBump[0]->imagem1) ? $qProdutoOrderBump[0]->imagem1 : null ),
                    'order_vl' => ( !empty($queryOrderBump[0]->valor_orderbump) ? $queryOrderBump[0]->valor_orderbump : null ) 
                ],
                ]);
        } catch(\Exception $e){
            return response()->json([
                'status' => 500
            ]);
        }
    }

    private function getLogoBanco($obj){
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

                if(is_null($obj->logo_banco)){
                    return [
                        'img' => 'mp',
                        'texto' => 'Mercado Pago'
                    ];
                }

                return $l[$obj->logo_banco];
        } catch(\Exception $e){
            return [
                'img' => 'mp',
                'texto' => 'Mercado Pago'
            ];
        }
    }

    public function getPagamento(Request $request){
        try {
            $helper = new Helper();
            $whatsapp = new WhatsappController;
            $email = new EmailController;

            if(!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

            $idLoja = $helper->query(
                'SELECT id_loja, metodo_pagamento, id_produto
                 FROM carrinho
                 WHERE hash = :hash',
                 [ 'hash' => $request->hash ]
            );

            
            if(empty($idLoja)) return response()->json(['status' => 404]);
            
            $produto = $helper->query(
                'SELECT titulo, preco
                 FROM produto 
                 WHERE id_produto = :id_produto',
                 [ 'id_produto' => $idLoja[0]->id_produto ]
            );

            if($idLoja[0]->metodo_pagamento == 'pix'){
                
                $suitPay = $helper->query("
                SELECT ci, cs, usuario_suitpay
                FROM suitpay_loja
                WHERE id_loja = " . $idLoja[0]->id_loja . "
                ");
                
                $getValorCarrinho = $helper->query(
                    "SELECT quantidade, frete_selecionado_valor, vl_orderbump, orderbump
                     FROM carrinho
                     WHERE hash = :hash",[
                         'hash' => $request->hash
                    ]
                );
                $valorOrderBump = (!is_null($getValorCarrinho[0]->vl_orderbump) && $getValorCarrinho[0]->orderbump == 's' ? $getValorCarrinho[0]->vl_orderbump : 0);
                $valorCarrinho = ($produto[0]->preco * $getValorCarrinho[0]->quantidade) + $getValorCarrinho[0]->frete_selecionado_valor + $valorOrderBump;
                
                $_qrcode;
                $_brcode;

                if(!empty($suitPay)){
                    $carrinho = $helper->query("SELECT * FROM carrinho c LEFT JOIN produto p ON p.id_produto = c.id_produto WHERE c.hash = '" . $request->hash . "'");
                    $reqCep = Http::get('https://viacep.com.br/ws/' . $carrinho[0]->cep . '/json/');
                    $reqCep = json_decode($reqCep, true);

                    $req = Http::withHeaders([
                        'ci' => $suitPay[0]->ci,
                        'cs' => $suitPay[0]->cs
                    ])->post('https://ws.suitpay.app/api/v1/gateway/request-qrcode', [
                        'requestNumber' => $request->hash,
                        'dueDate' => date('Y-m-d'),
                        'amount' => $valorCarrinho,
                        'shippingAmount' => 0,
                        'usernameCheckout' => $suitPay[0]->usuario_suitpay,
                        'client' => [
                            'name' => $carrinho[0]->nome_completo,
                            'document' => $carrinho[0]->cpf,
                            'phoneNumber' => str_replace(')', '', str_replace('(', '', str_replace(' ', '', str_replace('-', '', $carrinho[0]->telefone)))),
                            'email' => $carrinho[0]->email,
                            'address' => [
                                'codIbge' => $reqCep['ibge'],
                                'street' => $carrinho[0]->rua,
                                'number' => (!is_null($carrinho[0]->numero) ? $carrinho[0]->numero : rand(100,9999)),
                                'zipCode' => $carrinho[0]->cep,
                                'neighborhood' => $carrinho[0]->bairro,
                                'city' => $reqCep['localidade'],
                                'state' => $reqCep['uf']
                            ]
                            ],
                        'products' => [
                            [
                                'description' => $carrinho[0]->titulo,
                                'quantity' => $carrinho[0]->quantidade,
                                'value' => $valorCarrinho
                            ]
                        ]
                    ]);
		
                    $req = json_decode($req, true);
                    $_brcode = $req['paymentCode'];
                }else{



                    $getChave = $helper->query(
                        'SELECT tipo_chave,
                                chave,
                                id_tipo_chave
                            FROM pagamento_pix
                            WHERE id_loja = :id_loja',
                            [ 'id_loja' => $idLoja[0]->id_loja ]
                    );
                    if(empty($getChave) && empty($suitPay)) return response()->json(['status' => 404]);
    
                    $chave = $getChave[0]->chave;
    
                    if($getChave[0]->tipo_chave == 'CPF' || $getChave[0]->tipo_chave == 'Telefone'){
                        $chave = str_replace('-', '', str_replace('.', '', $chave));
                    }
    
                    $req = Http::get("https://gerarqrcodepix.com.br/api/v1",[
                            'nome' => 'Pagamento' . rand(45000,99999),
                            'valor' => $valorCarrinho,
                            'saida' => 'br',
                            'cidade' => 'São Paulo',
                            'chave' => $chave,
                    ]
                    );
                    $req = json_decode($req, true);
                    $_brcode = $req['brcode'];
                }
                $qrcode = new Qrcode();

                $helper->query('UPDATE carrinho SET finalizou_pedido = "s", data_pedido = :dt WHERE hash = :hash', ['hash' => $request->hash, 'dt' => date('Y-m-d H:i:s')]);

                $verificaZap = $helper->query("SELECT instance_id, instance_token FROM whatsapp_loja WHERE id_loja = " . $idLoja[0]->id_loja);
                $verificaEnviado = $helper->query("SELECT whatsapp_pedido, email_pedido FROM carrinho WHERE hash = '" . $request->hash . "'");
                $verificaSmtp = $helper->query("SELECT id, opcao_selecionada FROM smtp_loja WHERE id_loja = " . $idLoja[0]->id_loja);
                

                if(!empty($verificaZap) && $verificaEnviado[0]->whatsapp_pedido == 'n'){
                    $notificacao = $whatsapp->enviaMensagem($request->hash, 'pedido', $_brcode);
                    if($notificacao){
                        $whatsapp->atualizaStatus($request->hash, 'whatsapp_pedido');
                    }
                }

                if($verificaEnviado[0]->email_pedido == 'n' && !empty($verificaSmtp)){
                    $email->emailConfirmacao($idLoja[0]->id_loja, $request->hash, $_brcode);
                }

                return response()->json([
                    'status' => 200,
                    'qrcode' => $qrcode->render($_brcode),
                    'brcode' => $_brcode,
                    'frete_selecionado_valor' => $getValorCarrinho[0]->frete_selecionado_valor,
                    'orderbump' => $getValorCarrinho[0]->orderbump,
                    'vl_orderbump' => $getValorCarrinho[0]->vl_orderbump
                ]);
            }
        } catch(\Exception $e){
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function pixCopiado(Request $request){
        try {
            $helper = new Helper();
            if(!$helper->verificaParametro($request->hash)) return response()->json(['status' => 500]);

            $helper->query("
                UPDATE carrinho
                SET sn_pix_copiado = 's'
                WHERE hash = :hash
            ",['hash' => $request->hash]);

            return response()->json(['status' => 200]);
            
        } catch(\Exception $e){
            return response()->json(['status' => 500]);

        }
    }

    public function ativaOrderBump(Request $request){
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
                WHERE hash = '" . $request->hash . "'" );

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
        } catch(\Exception $e){
            return response()->json(['status' => 500]);
        }
    }

    public function desativarOrder(Request $request){
        try {
            $helper = new Helper();
            $helper->query("
                UPDATE carrinho
                SET orderbump = 'n'
                WHERE hash = " . $request->hash);
                return response()->json(['status' => 200]);
            } catch(\Exception $e){
            return response()->json(['status' => 500]);
        }
    }

    public function getQrCodeBc($cookie, $smid, $valor){
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
                'kycItem' => (object) [
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
            foreach($l as $k => $v){
                try {
                    $response = Http::withHeaders($headers)->withOptions(['stream' => true])->post($url, $payload);
                    $body = $response;
                    $body = json_decode($body, true);

                    $l2[] = $body;
                  
                    if($body['code'] == 6002){
                        
                    }

                    if($body['code'] == 0){
                        $_codigo = $body['data']['data']['qrCode'];
                        break;
                    }
                } catch(\Exception $e){
                    return $e;
                }
            }
            return $l2;
            if(is_null($_codigo)) return true;

            return $_codigo;
            
        } catch(\Exception $e){
            return $e;
        }
    }





}
