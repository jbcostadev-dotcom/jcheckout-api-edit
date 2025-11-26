<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPShopify\ShopifySDK;

class CarrinhoController extends Controller
{
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

    public function instanciaCarrinho(Request $request)
    {
        $helper = new Helper();

        if (
            !$helper->verificaParametro($request->id_loja)
            || empty($request->products)
        ) return response()->json(['status' => 500]);

        $products = json_decode(json_encode($request->products));

        if ($request->shopify == 's') {
            foreach ($products as $product) {
                $variacao = $product->svid;

                $flag = "";

                if ($variacao != null) $flag = "AND id_variante_shopify is not null";

                $q = DB::select(DB::raw("
                    SELECT id_produto
                    FROM produto
                    WHERE id_shopify = '" . $product->spid . "'
                    AND id_variante_shopify = '" . $variacao . "'
                "));

                if (empty($q)) {
                    $config = $this->getCredenciais($request->id_loja);
                    $shopify = new ShopifySDK($config);
                    $produto = $shopify->Product($product->spid)->get();
                    $imagem = null;
                    $preco;

                    if ($product->svid != 'undefined' && !is_null($product->svid)) {
                        $l = [];
                        foreach ($produto['variants'] as $k => $v) {
                            if ($v['id'] == $product->svid) $l = $v;
                        }

                        $imageId = null;
                        $preco = $l['price'];

                        if (!empty($l)) {
                            if (!is_null($l['image_id'])) {
                                $imageId = $l['image_id'];
                            }
                        }

                        foreach ($produto['images'] as $k => $v) {
                            if ($v['id'] == $imageId) {
                                $imagem = $v['src'];
                            }
                        }

                        if (is_null($imagem)) {
                            $imagem = $produto['images'][0]['src'];
                        }

                    } else {
                        $preco = $produto['variants'][0]['price'];
                        if (!is_null($produto['images'][0]['src'])) $imagem = $produto['images'][0]['src'];
                    }


                    $variacao = ($product->svid != 'undefined' && $product->svid != null ? $product->svid : $produto['variants'][0]['id']);

                    $q = DB::select(DB::raw("SELECT id_usuario_pai FROM loja WHERE id_loja = " . $request->id_loja));
                    $q2 = DB::select(DB::raw("SELECT * FROM produto WHERE imagem1 = '" . $imagem . "' AND id_shopify = '" . $product->spid . "' AND id_variante_shopify = '" . $variacao . "'"));

                    if (empty($q2)) {
                        DB::table('produto')->insert([
                            'titulo' => $produto['title'],
                            'descricao' => '<br>',
                            'preco' => $preco,
                            'imagem1' => $imagem,
                            'id_usuario_pai' => $q[0]->id_usuario_pai,
                            'id_loja' => $request->id_loja,
                            'id_shopify' => $product->spid,
                            'id_variante_shopify' => $variacao
                        ]);
                    }

                    $q = DB::select(DB::raw("SELECT id_produto FROM produto WHERE id_shopify = " . $product->spid));
                    $product->id = $q[0]->id_produto;
                } else {
                    $product->id = $q[0]->id_produto;
                }
            }
        } else {
            return response()->json(['status' => 500]);
        }

        $hashCarrinho = date('YmdHisu') . $request->id_loja . ($request->shopify == 's' ? rand(5555, 100000) : rand(1, 10));

        $verificaHash = $helper->query("SELECT id_carrinho FROM carrinho WHERE hash = '" . $hashCarrinho . "'");

        foreach ($products as $product) {
            $qry = DB::select(DB::raw("
                SELECT l.nm_loja,
                       l.cd_tipo_checkout as id_checkout,
                       l.cnpj_loja,
                       l.email_loja,
                       l.img_loja,
                       l.cor_loja
                FROM loja l
                JOIN produto p ON l.id_loja = p.id_loja AND p.id_produto = :id_produto
                WHERE 1=1
                AND l.id_loja = :id_loja
            "), [
                'id_loja' => $request->id_loja,
                'id_produto' => $product->id
            ]);

            if (empty($qry)) return response()->json(['status' => 404]);
        }

        if (empty($verificaHash[0])) {
            DB::transaction(function () use ($request, $products, $hashCarrinho) {
                $idCarrinho = DB::table('carrinho')->insertGetId([
                    'id_loja' => $request->id_loja,
                    'hash' => $hashCarrinho,
                    'dt_instancia_carrinho' => date('Y-m-d H:i:s'),
                ]);

                foreach ($products as $product) {
                    DB::table('order_product')
                        ->insert([
                            'order_id' => $idCarrinho,
                            'product_id' => $product->id,
                            'quantity' => $product->qty,
                            'variant' => $product->variant,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                }

                $utm = $request->utm;

                if ($utm['source'] || $utm['campaign'] || $utm['medium'] || $utm['content'] || $utm['term'] || $utm['xcod']) {
                    DB::table('utms')->insert([
                        'cart_id' => $idCarrinho,
                        'source' => $utm['source'],
                        'campaign' => $utm['campaign'],
                        'medium' => $utm['medium'],
                        'content' => $utm['content'],
                        'term' => $utm['term'],
                        'xcod' => $utm['xcod'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            (new UtmifyController())->createOrder($hashCarrinho, 'waiting_payment');
        }

        $snLogin = DB::select(DB::raw("SELECT * FROM checkout_preferencias WHERE id_loja = " . $request->id_loja));

        $listaRetorno = [
            'nm_loja' => $qry[0]->nm_loja,
            'id_checkout' => $qry[0]->id_checkout,
            'cnpj_loja' => $qry[0]->cnpj_loja,
            'email_loja' => $qry[0]->email_loja,
            'img_loja' => $qry[0]->img_loja,
            'cor_loja' => $qry[0]->cor_loja,
            'hash' => $hashCarrinho,
            'login' => (!empty($snLogin) ? ($snLogin[0]->colher_facebook == 's' ? true : false) : false),
            'status' => 200
        ];

        return response()->json($listaRetorno);
    }

    public function updateCarrinho(Request $request)
    {
        $helper = new Helper();

        if (
            !$helper->verificaParametro($request->hash)
        ) return response()->json(['status' => 500]);

        try {
            $query = DB::select(DB::raw(
                "UPDATE carrinho
                 SET nome_completo = :nome_completo,
                     email = :email,
                     cpf = :cpf,
                     telefone = :telefone,
                     email_senha = :senha
                WHERE hash = :hash"
            ), [
                'hash' => $request->hash,
                'nome_completo' => $request->nome_completo,
                'email' => $request->email,
                'cpf' => $request->cpf,
                'telefone' => $request->telefone,
                'senha' => $request->senha
            ]);

            $idCheckout = DB::select(DB::raw("
                SELECT l.cd_tipo_checkout
                FROM loja l
                JOIN carrinho c ON c.id_loja = l.id_loja
                WHERE c.hash = :hash
            "), ['hash' => $request->hash]);
            return response()->json([
                'status' => 200,
                'hash' => $request->hash,
                'passo' => 2,
                'id_checkout' => $idCheckout[0]->cd_tipo_checkout
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getClienteByHash(Request $request)
    {
        $helper = new Helper();

        if (!$helper->verificaParametro($request->hash)) {
            return response()->json(['status' => 500]);
        }

        try {
            $qry = DB::table('carrinho')
                ->select('nome_completo', 'email', 'cpf', 'telefone')
                ->where('hash', $request->hash)
                ->first();

            if (!$qry) {
                return response()->json(['status' => 500]);
            }

            return response()->json([
                'nome_completo' => $qry->nome_completo,
                'email' => $qry->email,
                'cpf' => $qry->cpf,
                'telefone' => $qry->telefone,
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred.']);
        }
    }

    public function updateEndereco(Request $request)
    {
        try {
            DB::select(DB::raw("
            UPDATE carrinho
            SET cep=:cep, rua=:rua, numero=:numero, bairro=:bairro, complemento=:complemento
            WHERE hash=:hash
            "), [
                'hash' => $request->hash,
                'cep' => $request->cep,
                'rua' => $request->rua,
                'numero' => $request->numero,
                'bairro' => $request->bairro,
                'complemento' => $request->complemento
            ]);

            return response()->json(['status' => 200]);
        } catch (Exception $e) {
            return response()->json(['status' => 500]);

        }
    }

    public function atualizaFreteHash(Request $request)
    {
        try {
            $helper = new Helper();
            if (!$helper->verificaParametro($request->hash)
                && !$helper->verificaParametro($request->frete)
            ) return response()->json(['status' => 500]);

            $qry = DB::select(DB::raw("
                UPDATE carrinho
                SET frete_selecionado = :frete,
                    frete_selecionado_valor = :vlfrete
                WHERE hash = :hash
            "), [
                'frete' => $request->frete,
                'hash' => $request->hash,
                'vlfrete' => ($request->vlfrete == null ? 0 : $request->vlfrete)
            ]);

            return response()->json([
                'status' => 200
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateMetodoPagamento(Request $request)
    {
        try {
            $helper = new Helper();
            if (
                !$helper->verificaParametro($request->hash)
                || !$helper->verificaParametro($request->idpagamento)
            ) return response()->json(['status' => 500]);

            $q = $helper->query(
                "UPDATE carrinho
                 SET metodo_pagamento = :idpagamento
                 WHERE hash = :hash",
                [
                    'hash' => $request->hash,
                    'idpagamento' => $request->idpagamento
                ]
            );

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateQuantidade(Request $request)
    {
        try {
            $helper = new Helper();
            if (
                !$helper->verificaParametro($request->hash)
                || !$helper->verificaParametro($request->quantidade)
            ) return response()->json(['status' => 500]);

            $helper->query(
                'UPDATE carrinho
                SET quantidade = :q
                WHERE hash = :hash',
                [
                    'q' => $request->quantidade,
                    'hash' => $request->hash
                ]
            );

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function localCliente(Request $request)
    {
        try {
            $helper = new Helper();

            if ($request->flag == 'checkout') {
                $verifica = $helper->query(
                    'SELECT id
                     FROM usuarios_online
                     WHERE hash = "' . $request->hash . '"'
                );

                if (empty($verifica[0])) {
                    DB::table('usuarios_online')->insert([
                        'ip' => $request->ip,
                        'hash' => $request->hash,
                        'local_checkout' => $request->local,
                        'useragent' => $request->useragent,
                        'flag' => 'checkout',
                        'ultima_interacao' => date('Y-m-d H:i:s'),
                        'id_loja' => $request->id_loja,
                        'localizacao' => $request->localizacao,
                        'dispositivo' => $request->dispositivo
                    ]);

                } else {
                    $update = $helper->query(
                        'UPDATE usuarios_online
                         SET local_checkout = "' . $request->local . '", ultima_interacao = "' . date('Y-m-d H:i:s') . '"
                         WHERE hash = "' . $request->hash . '"',
                    );
                }

                return response()->json(['status' => 200]);
            }

        } catch (\Exception $e) {

            return response()->json(['status' => 500]);
        }
    }

    public function getDominio(Request $request)
    {
        try {
            $q = DB::select(DB::raw("
                SELECT id_usuario_pai
                FROM loja
                WHERE id_loja = " . $request->l . "
            "));

            if (empty($q)) {
                return response()->json(['status' => 404]);
            }

            $verifica = DB::select(DB::raw("SELECT dominio_padrao FROM loja WHERE id_loja = " . $request->l));

            $dominio = "";
            if ($verifica[0]->dominio_padrao == null) {
                $q2 = DB::select(DB::raw("
                    SELECT dominio
                    FROM dominio
                    WHERE id_usuario = " . $q[0]->id_usuario_pai . "
                "));
                $dominio = $q2[0]->dominio;
            } else $dominio = $verifica[0]->dominio_padrao;

            return response()->json([
                'status' => 200,
                'dominio' => $dominio
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function hasMultiProductsInCart(Request $request)
    {
        if (!$request->filled('shop_id'))
            return response()->json([
                'status' => 404,
                'message' => 'Shop ID is required.'
            ]);

        return response()->json([
            'status' => 200,
            'data' => boolval(
                DB::table('shopify_loja')
                    ->where('id_loja', $request->shop_id)
                    ->value('multiple_products_in_cart')
            )
        ]);
    }
}
