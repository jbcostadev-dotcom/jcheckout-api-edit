<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LojaController extends Controller
{
    public function adicionaLoja(Request $request)
    {
        try {
            if (in_array($request->tipo_usuario, ['pai', 'user'])) {
                $verifica_qtd_lojas = DB::select(DB::raw('
                    SELECT count(*) as count
                    FROM loja
                    WHERE id_usuario_pai = :id_usuario_pai
                '), [
                    'id_usuario_pai' => $request->id_usuario,
                ]);

                $qry = DB::select(DB::raw(
                    'SELECT qtd_lojas
                     FROM usuario_pai
                     WHERE id_usuario_pai = :id_usuario_pai
                     '
                ), [
                    'id_usuario_pai' => $request->id_usuario,
                ]);

                $qtd_atual_lojas = (empty($verifica_qtd_lojas[0]->count) ? 0 : $verifica_qtd_lojas[0]->count);

                if ($qtd_atual_lojas < $qry[0]->qtd_lojas) {
                    DB::table('loja')->insert([
                        'nm_loja' => $request->nome_loja,
                        'id_usuario_pai' => $request->id_usuario,
                        'cd_tipo_checkout' => $request->tipo_checkout,
                        'shop_type' => $request->shop_type,
                    ]);

                    return response()->json([
                        'status' => 200,
                        'mensagem' => 'Sucesso! A loja foi adicionada.',
                    ]);

                } else {
                    return response()->json([
                        'status' => 401,
                        'mensagem' => 'Você atingiu o limite de lojas.',
                    ]);
                }
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível salvar a loja',
            ]);
        }
    }

    public function getLayoutsLoja(Request $request)
    {
        $query = DB::select(DB::raw(
            'SELECT * FROM tipo_checkout'
        ));

        $retorno = [];

        foreach ($query as $key => $row) {
            $retorno[] = [
                'codigo' => $row->cd_tipo_checkout,
                'descricao' => $row->ds_tipo_checkout,
            ];
        }

        return response()->json($retorno);
    }

    public function getLojas(Request $request)
    {
        try {
            $coluna = 'id_usuario_pai';
            $helper = new Helper();
            $query = DB::select(DB::raw(
                'SELECT *
                 FROM loja
                 WHERE ' . $coluna . ' = :id_usuario'
            ),
                [
                    'id_usuario' => $request->id_usuario,
                ]
            );
            if (empty($query)) {
                return response()->json(['status' => 401]);
            }

            foreach ($query as $k => $v) {
                $q = $helper->query("
                SELECT qry.cnt as visitas_checkout,
                       qry2.cnt pedidos
                FROM(
                    SELECT count(id_carrinho) as cnt
                    FROM carrinho
                    WHERE id_loja = " . $v->id_loja . "
                    AND data_delete is null
                )qry,
                (
                    SELECT count(id_carrinho) as cnt
                    FROM carrinho
                    WHERE id_loja = " . $v->id_loja . "
                    AND data_pedido is not null
                    AND data_delete is null
                )qry2
                ");

                $l[$k] = $v;
                $l[$k]->visitas_checkout = $q[0]->visitas_checkout;
                $l[$k]->pedidos = $q[0]->pedidos;

            }

            return response()->json($l);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function updateLoja(Request $request)
    {
        if (empty($request->id_loja) || is_null($request->id_loja)) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível realizar as alterações na loja.'
            ]);
        }

        try {
            $urlimagem = null;

            if (!is_null($request->imagem) && $request->imagem != 'undefined') {
                $image = $request->file('imagem');
                $filename = uniqid() . '.' . $image->getClientOriginalExtension();
                Storage::disk('public')->put($filename, file_get_contents($image));
                $urlimagem = request()->getHttpHost() . '/logoloja/' . $filename;
            }

            if ($urlimagem == null) {
                $qryVerificaImagem = DB::select(DB::raw(
                    'SELECT img_loja FROM loja WHERE id_loja = :id_loja'
                ), ['id_loja' => $request->id_loja]);

                if ($qryVerificaImagem[0]->img_loja != null && $qryVerificaImagem[0]->img_loja != '') {
                    $urlimagem = $qryVerificaImagem[0]->img_loja;
                }
            }

            DB::table('loja')
                ->where('id_loja', $request->id_loja)
                ->update([
                    'nm_loja' => $request->nm_loja,
                    'cd_tipo_checkout' => $request->cd_tipo_checkout,
                    'img_loja' => $urlimagem,
                    'cor_loja' => $request->cor_loja,
                    'email_loja' => $request->email_loja,
                    'cnpj_loja' => $request->cnpj_loja,
                    'alert_text' => $request->alert_text
                ]);

            return response()->json([
                'status' => 200,
                'mensagem' => 'Sucesso! As alterações foram realizadas.',
                'id_loja' => $request->id_loja,
                'img' => $urlimagem
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível salvar as alterações da loja.'
            ]);
        }
    }

    public function importarCsv(Request $request)
    {
        try {
            if (!isset($request->id_usuario) || !isset($request->tipo_usuario) || !isset($request->id_loja)) {
                return response()->json([
                    'status' => 401,
                    'mensagem' => 'Não foi possível importar - Erro interno'
                ]);
            }
            $csv = $request->file('csv');
            $arquivo_csv = uniqid() . '.' . $csv->getClientOriginalExtension();
            Storage::disk('public')->put($arquivo_csv, file_get_contents($csv));

            $csv = array_map('str_getcsv', file('./logoloja/' . $arquivo_csv));
            $listaRetorno = [];

            // return response()->json($csv);
            // return $csv;
            foreach ($csv as $key => $row) {
                if ($row[0] != "Handle") {
                    if (count($row) > 1) {
                        if (isset($row[2]) && isset($row[3]) && strlen($row[1]) < 150) {
                            $listaRetorno[$row[0]][] = [
                                'titulo' => $row[1],
                                'descricao' => $row[2],
                                'preco' => (isset($row[20]) ? $row[20] : null),
                                'imagem' => (isset($row[25]) ? $row[25] : null),
                                'variacao1' => (isset($row[8]) ? $row[8] : null),
                                'opcao1' => (isset($row[9]) ? $row[9] : null),
                                'variacao2' => (isset($row[10]) ? $row[10] : null),
                                'opcao2' => (isset($row[11]) ? $row[11] : null),
                                'variacao3' => (isset($row[12]) ? $row[12] : null),
                                'opcao3' => (isset($row[13]) ? $row[13] : null),
                                ($request->tipo_usuario == 'pai' ? 'id_usuario_pai' : 'id_usuario_filho') => $request->id_usuario,
                                'id_loja' => $request->id_loja
                            ];

                        }
                    }
                }
            }

            $lista = [];
            $aux = 0;
            $auximagem = 0;
            // return response()->json($listaRetorno);
            foreach ($listaRetorno as $key => $row) {

                $first = true;
                foreach ($row as $key2 => $row2) {
                    if ($first == true) {
                        $lista[$aux] = $row2;
                        $first = false;
                        $lista[$aux]['imagem'] = [];
                    } else {

                        $lista[$aux]['titulo'] = ($row2['titulo'] != "" ? $row2['titulo'] : $lista[$aux]['titulo']);
                        $lista[$aux]['descricao'] = ($row2['descricao'] != "" ? $row2['descricao'] : $lista[$aux]['descricao']);
                        $lista[$aux]['preco'] = ($row2['preco'] != "" ? $row2['preco'] : $lista[$aux]['preco']);
                        $lista[$aux]['imagem'][] = [$auximagem++ => $row2['imagem']];

                        $lista[$aux]['variacao1'] = ($row2['variacao1'] != "" ? $row2['variacao1'] : $lista[$aux]['variacao1']);
                        $lista[$aux]['variacao2'] = ($row2['variacao2'] != "" ? $row2['variacao2'] : $lista[$aux]['variacao2']);
                        $lista[$aux]['variacao3'] = ($row2['variacao3'] != "" ? $row2['variacao3'] : $lista[$aux]['variacao3']);


                        $lista[$aux]['opcao1'] = ($row2 != "" && !empty($row2['opcao1']) ? $lista[$aux]['opcao1'] .= '%flag%' . $row2['opcao1'] . '%flag%' : $lista[$aux]['opcao1']);
                        $lista[$aux]['opcao2'] = ($row2 != "" && !empty($row2['opcao2']) ? $lista[$aux]['opcao2'] .= '%flag%' . $row2['opcao2'] . '%flag%' : $lista[$aux]['opcao2']);
                        $lista[$aux]['opcao3'] = ($row2 != "" && !empty($row2['opcao3']) ? $lista[$aux]['opcao3'] .= '%flag%' . $row2['opcao3'] . '%flag%' : $lista[$aux]['opcao3']);
                    }
                }
                $auximagem = 0;
                $aux++;
            }

            $auxCountImg = 0;
            $i = 0;

            foreach ($lista as $key => $row) {
                $lista[$key] = $row;
                $auxCountImg = count($lista[$key]['imagem']);
                while ($auxCountImg - 1 >= $i) {
                    $lista[$key]['imagem' . ($i + 1)] = $lista[$key]['imagem'][$i][$i];

                    $i++;
                }

                unset($lista[$key]['imagem']);
                $i = 0;
                $auxCountImg = 0;
            }

            foreach ($lista as $key => $row) {
                if ($row['variacao1'] == 'Title' && $row['opcao1'] == 'Default Title') {
                    $lista[$key]['variacao1'] = null;
                    $lista[$key]['opcao1'] = null;
                }
                if ($row['variacao2'] == '' && $row['opcao2'] == '') {
                    $lista[$key]['variacao2'] = null;
                    $lista[$key]['opcao2'] = null;
                }
                if ($row['variacao3'] == '' && $row['opcao3'] == '') {
                    $lista[$key]['variacao3'] = null;
                    $lista[$key]['opcao3'] = null;
                }

                if ($row['preco'] == 0 || $row['titulo'] == "" || !isset($row['imagem1'])) {
                    unset($lista[$key]);

                }
            }
            foreach ($lista as $key => $row) {
                if (is_numeric($row['preco'])) {
                    DB::table('produto')->insert(
                        $row
                    );
                }
            }
            return response()->json([
                'status' => 200,
                'mensagem' => 'Os produtos foram importados com sucesso'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível importar os produtos'
            ]);
        }
    }

    public function getProdutos(Request $request)
    {
        try {
            $qry = DB::select(DB::raw(
                "SELECT id_produto,
                         titulo,
                         preco,
                         sn_loja,
                         opcao1,
                         variacao1,
                         opcao2,
                         variacao2,
                         opcao3,
                         variacao3,
                         preco_anterior
                 FROM produto WHERE id_loja = :id_loja"
            ), ['id_loja' => $request->id_loja]);

            foreach ($qry as $key => $row) {
                if ($row->sn_loja == 's') {
                    $qry[$key]->sn_loja = true;
                } else {
                    $qry[$key]->sn_loja = false;
                }

                if (is_null($row->preco_anterior)) {
                    $qry[$key]->preco_anterior = number_format($row->preco + 30, 2);
                }
            }

            return response()->json($qry);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível buscar os produtos.'
            ]);

        }
    }

    public function updateProduto(Request $request)
    {
        try {
            if ($request->campo == 'undefined') {
                return response()->json([
                    'status' => 401
                ]);
            }
            $query = "UPDATE produto SET $request->campo = '$request->valor' WHERE id_produto = $request->id_produto";
            $qry = DB::select(DB::raw($query));

            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function deleteProduto(Request $request)
    {
        try {
            if ($request->id_loja == 'undefined' || !isset($request->id_loja) || !isset($request->id_produto)) {
                return response()->json([
                    'status' => 401
                ]);
            }

            $query = "DELETE FROM produto WHERE id_produto = $request->id_produto";
            DB::select(DB::raw($query));

            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function updateSnLoja(Request $request)
    {
        try {
            if (!isset($request->loja) || !isset($request->valor) || !isset($request->id_produto)) {
                return response()->json([
                    'status' => 401
                ]);
            }

            $valor = ($request->valor == 'true' ? 's' : 'n');
            $qry = "UPDATE produto
                    SET sn_loja = '" . $valor . "'
                    WHERE 1=1
                    AND id_produto = " . $request->id_produto . "
                    AND id_loja = " . $request->loja;
            DB::select(DB::raw($qry));

            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function getLoja(Request $request)
    {
        if (!isset($request->token) || !isset($request->loja) || !isset($request->dominio)) {
            return response()->json([
                'mensagem' => 'Não autorizado.'
            ]);
        }

        $verificaToken = DB::select(DB::raw(
            "SELECT * FROM users WHERE token_checkout = :token"
        ), [
            'token' => $request->token
        ]);


        if (empty($verificaToken[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado [001]'
            ]);
        }

        $tipoUsuario = $verificaToken[0]->tipo_usuario;
        $verificaLojasUsuario = DB::select(DB::raw(
            "SELECT nm_loja, id_loja, cnpj_loja, email_loja, img_loja, cor_loja
             FROM loja
             WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario .
            " AND id_loja = " . $request->loja
        ));

        if (empty($verificaLojasUsuario[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado - EMP'
            ]);
        }

        $dadosUsuario = DB::select(DB::raw(
            "SELECT * FROM usuario_pai" . " WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario
        ));
        if (empty($dadosUsuario[0])) {
            return response()->json(['mensagem' => "Não autorizado"]);
        }
        $dataFimToken = $dadosUsuario[0]->dt_fim_token;

        if (strtotime(date('Y-m-d H:i:s')) > strtotime($dataFimToken)) {
            return response()->json([
                'mensagem' => 'Não autorizado [002]'
            ]);
        }

        $queryProduto = DB::select(DB::raw(
            "SELECT
                    p.*,
                    pc.ds_categoria
            FROM loja l
            JOIN produto p ON l.id_usuario_pai" . " = p.id_usuario_pai" . " AND p.id_loja = " . $request->loja . "
            LEFT JOIN produto_categoria pc ON p.id_produto_categoria = pc.id_produto_categoria
            WHERE 1=1
            AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
            AND l.id_loja = " . $request->loja . "
            AND p.sn_loja = 's'
            "
        ));

        $queryLoja = DB::select(DB::raw(
            "SELECT l.nm_loja,
                    l.id_loja,
                    l.cnpj_loja,
                    l.email_loja,
                    l.img_loja,
                    l.cor_loja,
                    l.banner1_desktop,
                    l.banner1_mobile,
                    l.banner2_desktop,
                    l.banner2_mobile,
                    l.banner3_desktop,
                    l.banner3_mobile
            FROM loja l
            WHERE 1=1
            AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
            AND l.id_loja = " . $request->loja
        ));

        $listaRetorno = [
            'loja' => $queryLoja[0],
            'produtos' => [],
            'categoria' => []
        ];

        foreach ($queryProduto as $key => $row) {
            $listaRetorno['produtos'][($row->ds_categoria == null ? 'outros' : $row->ds_categoria)][] = $row;
            $flag = false;
            if (!is_null($row->ds_categoria)) {
                foreach ($listaRetorno['categoria'] as $i => $v) {
                    if ($v == $row->ds_categoria) $flag = true;
                }
            }
            if (!$flag) $listaRetorno['categoria'][] = $row->ds_categoria;

        }
        return response()->json($listaRetorno);

    }

    public function getProduto(Request $request)
    {

        if (!isset($request->token) || !isset($request->loja) || !isset($request->dominio) || !isset($request->p)) {
            return response()->json([
                'mensagem' => 'Não autorizado.'
            ]);
        }


        $verificaToken = DB::select(DB::raw(
            "SELECT * FROM users WHERE token_checkout = :token"
        ), [
            'token' => $request->token
        ]);


        if (empty($verificaToken[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado [001]'
            ]);
        }

        $tipoUsuario = $verificaToken[0]->tipo_usuario;
        $verificaLojasUsuario = DB::select(DB::raw(
            "SELECT nm_loja, id_loja, cnpj_loja, email_loja, img_loja, cor_loja
             FROM loja
             WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario .
            " AND id_loja = " . $request->loja
        ));
        if (empty($verificaLojasUsuario[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado'
            ]);
        }

        $dadosUsuario = DB::select(DB::raw(
            "SELECT * FROM usuario_pai" . " WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario
        ));
        if (empty($dadosUsuario[0])) {
            return response()->json(['mensagem' => "Não autorizado"]);
        }
        $dataFimToken = $dadosUsuario[0]->dt_fim_token;

        if (strtotime(date('Y-m-d H:i:s')) > strtotime($dataFimToken)) {
            return response()->json([
                'mensagem' => 'Não autorizado [002]'
            ]);
        }

        $queryProduto = DB::select(DB::raw(
            "SELECT
                    p.*,
                    IFNULL(pc.ds_categoria, 'Promoção') as categ
            FROM loja l
            JOIN produto p ON l.id_usuario_pai" . " = p.id_usuario_pai" . "
            LEFT JOIN produto_categoria pc ON p.id_produto_categoria = pc.id_produto_categoria
            WHERE 1=1
            AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
            AND l.id_loja = " . $request->loja . "
            AND p.id_produto = " . $request->p
        ));

        $queryLoja = DB::select(DB::raw(
            "SELECT l.nm_loja,
                    l.id_loja,
                    l.cnpj_loja,
                    l.email_loja,
                    l.img_loja,
                    l.cor_loja
            FROM loja l
            WHERE 1=1
            AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
            AND l.id_loja = " . $request->loja
        ));


        if (empty($queryProduto[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado [004]'
            ]);
        }
        $produtoImg = [];

        for ($i = 1; $i <= 10; $i++) {
            if ($queryProduto[0]->{'imagem' . $i} != null) {
                $produtoImg[] = str_replace('https://', '//', str_replace('http://', '//', $queryProduto[0]->{'imagem' . $i}));
            }
        }

        $listaProduto = [
            'id_produto' => $queryProduto[0]->id_produto,
            'titulo' => $queryProduto[0]->titulo,
            'descricao' => $queryProduto[0]->descricao,
            'preco' => $queryProduto[0]->preco,
            'preco_anterior' => number_format($queryProduto[0]->preco_anterior, 2),
            'categoria' => $queryProduto[0]->categ
        ];

        $listaVariacoes = [];

        for ($i = 1; $i <= 3; $i++) {
            if ($queryProduto[0]->{'variacao' . $i} != null) {
                $opcoes = explode('%flag%', $queryProduto[0]->{'opcao' . $i});
                $_opcoes = [];

                $_p = explode('%flag%', $queryProduto[0]->{'p_variacao' . $i});
                $_p2 = [];

                foreach ($_p as $_i => $v) {
                    $_p2[] = $v;
                }

                foreach ($opcoes as $_i => $v) {
                    $_opcoes[] = $v;
                }

                array_pop($_opcoes);
                array_pop($_p2);

                $l_p = [];
                foreach ($_p2 as $k => $v) {
                    if ($v != -1 && $v != '-1') {
                        $q = DB::select(DB::raw("SELECT imagem1,
                                                        imagem2,
                                                        imagem3,
                                                        imagem4,
                                                        imagem5,
                                                        titulo,
                                                        descricao,
                                                        id_produto
                                                FROM produto
                                                WHERE id_produto = :id"
                        ), ['id' => $v]);
                        $l_p[] = $q[0];
                    }
                }

                $listaVariacoes[] = [
                    'titulo' => $queryProduto[0]->{'variacao' . $i},
                    'opcoes' => $_opcoes,
                    'p' => $l_p
                ];
            }
        }

        $listaProduto['variacoes'] = (empty($listaVariacoes) ? null : $listaVariacoes);

        $qCategorias = DB::select(DB::raw("
            SELECT ds_categoria
            FROM produto_categoria
            WHERE id_loja = :id
        "), ['id' => $request->loja]);

        $l = [];
        foreach ($qCategorias as $k => $v) {
            $l[] = $v->ds_categoria;
        }

        $qryDominio = DB::select(DB::raw("SELECT dominio FROM dominio WHERE id_usuario = " . $verificaToken[0]->id_usuario . ""));
        $listaRetorno = [
            'loja' => $queryLoja[0],
            'produto' => $listaProduto,
            'produtoimg' => $produtoImg,
            'categoria' => $l,
            'check' => (!empty($qryDominio) ? $qryDominio[0]->dominio : null)
        ];

        return response()->json($listaRetorno);

    }

    public function getProdutosLoja(Request $request)
    {
        try {
            $qry = DB::select(DB::raw(
                "SELECT p.id_produto,
                         p.titulo,
                         p.preco,
                         p.sn_loja,
                         p.opcao1,
                         p.variacao1,
                         p.opcao2,
                         p.variacao2,
                         p.opcao3,
                         p.variacao3,
                         IFNULL(pc.ds_categoria, 'Nenhuma') as ds_categoria
                 FROM produto p
                 LEFT JOIN produto_categoria pc ON pc.id_produto_categoria = p.id_produto_categoria
                 WHERE p.id_loja = :id_loja
                    AND p.sn_loja = 's'"
            ), ['id_loja' => $request->id_loja]);

            foreach ($qry as $key => $row) {
                if ($row->sn_loja == 's') {
                    $qry[$key]->sn_loja = true;
                } else {
                    $qry[$key]->sn_loja = false;
                }
            }

            return response()->json($qry);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Não foi possível buscar os produtos.'
            ]);

        }

    }

    public function adicionaCategoria(Request $request)
    {
        if (empty($request->id_loja) || $request->id_loja == 'undefined') {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Erro interno'
            ]);
        }

        try {
            DB::table('produto_categoria')->insert([
                'ds_categoria' => $request->categoria,
                'id_loja' => $request->id_loja
            ]);

            $lastId = DB::select(DB::raw("SELECT qry.id as id,
                                                (SELECT ds_categoria
                                                FROM produto_categoria
                                                WHERE id_produto_categoria = qry.id) as ds
                                        FROM
                                        (
                                        SELECT max(id_produto_categoria) as id FROM produto_categoria LIMIT 1
                                        )qry"));
            $qryAllCategs = "SELECT * FROM produto_categoria WHERE id_loja = " . $request->id_loja;
            $listaCategoriasAtualizada = DB::select(DB::raw($qryAllCategs));

            return response()->json([
                'status' => 200,
                'mensagem' => 'sucesso',
                'id' => $lastId[0]->id,
                'ds' => $lastId[0]->ds,
                'novaLista' => $listaCategoriasAtualizada
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401,
                'mensagem' => 'erro interno'
            ]);
        }
    }

    public function getCategorias(Request $request)
    {
        try {
            if (is_null($request->id_loja) || empty($request->id_loja)) {
                return response()->json([
                    'status' => 401,
                    'mensagem' => 'Erro interno'
                ]);
            }

            $query = "SELECT * FROM produto_categoria WHERE id_loja = " . $request->id_loja;
            $qry = DB::select(DB::raw($query));
            return response()->json([
                'status' => 200,
                'categoria' => $qry
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Erro interno'
            ]);
        }
    }

    public function updateCategoriaProduto(Request $request)
    {
        if (is_null($request->id_loja) || empty($request->id_loja || empty($request->id_produto || empty($request->categoria)))) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Erro interno'
            ]);
        }

        try {
            $qryupdate = "UPDATE produto
                          SET id_produto_categoria = " . $request->categoria . "
                          WHERE id_produto = " . $request->id_produto;
            DB::select(DB::raw($qryupdate));
            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401
            ]);

        }
    }

    public function deleteCategoria(Request $request)
    {
        if (is_null($request->id_loja) || empty($request->id_loja || empty($request->id_categoria))) {
            return response()->json([
                'status' => 401,
                'mensagem' => 'Erro interno'
            ]);
        }

        try {
            $qryupdate = "DELETE FROM produto_categoria WHERE id_produto_categoria = " . $request->id_categoria;
            DB::select(DB::raw($qryupdate));
            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401
            ]);

        }
    }

    public function getLojasCheckout(Request $request)
    {
        $helper = new Helper();

        if (
            !$helper->verificaParametro($request->tipo_usuario)
            && !$helper->verificaParametro($request->id_usuario)
        ) return response()->json(['status' => 500]);
        try {
            $campo = 'id_usuario_pai';
            $query = "SELECT id_loja, nm_loja
                    FROM loja
                    WHERE " . $campo . " = " . $request->id_usuario;

            $qry = DB::select(DB::raw($query));

            return response()->json($qry);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'mensagem' => 'erro interno'
            ]);
        }
    }

    public function updateFreteLoja(Request $request)
    {
        $helper = new Helper();

        try {
            if (
                !$helper->verificaParametro($request->id_loja)
                || !$helper->verificaParametro($request->texto)
                || !$helper->verificaParametro($request->preco)
            ) {
                return response()->json([
                    'status' => 500
                ]);
            }
            $queryVerifica = DB::select(DB::raw("

                    SELECT id_frete_loja
                    FROM frete_loja
                    WHERE id_loja = :id_loja

                "), [
                'id_loja' => $request->id_loja
            ]);

            if (count($queryVerifica) >= 2) {
                return response()->json([
                    'status' => 401,
                    'mensagem' => 'esgotado o limite de fretes'
                ]);
            }

            DB::table('frete_loja')->insert([
                'id_loja' => $request->id_loja,
                'preco' => $request->preco,
                'ds_frete' => $request->texto
            ]);

            return response()->json([
                'status' => 200,
                'preco' => $request->preco,
                'ds_frete' => $request->texto
            ]);

        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 500
            ]);
        }
    }

    public function getFretesLoja(Request $request)
    {
        try {
            if (!isset($request->id_loja) || is_null($request->id_loja)) {
                return response()->json([
                    'status' => 500
                ]);
            }

            $query = DB::select(DB::raw("

                    SELECT id_frete_loja,
                           preco,
                           ds_frete
                    FROM frete_loja
                    WHERE id_loja = :id_loja

                "), [
                'id_loja' => $request->id_loja
            ]);

            return response()->json($query);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 500
            ]);

        }
    }

    public function getProdutosBusca(Request $request)
    {
        try {
            $helper = new Helper();
            if (
                !$helper->verificaParametro($request->loja)
                || !$helper->verificaParametro($request->txt)
            ) return response()->json(['status' => 500]);

            $q = $helper->query(
                "SELECT titulo, preco, id_produto as i, imagem1 as img
                     FROM produto
                     WHERE id_loja = :id_loja
                           AND titulo LIKE CONCAT('%', :txt, '%')
                     LIMIT 5
                     ",
                [
                    'id_loja' => $request->loja,
                    'txt' => $request->txt
                ]
            );

            if (empty($q)) return response()->json(['status' => 404]);
            return response()->json(['status' => 200, 'produtos' => $q]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500
            ]);
        }
    }

    public function getLojaCategoria(Request $request)
    {
        if (!isset($request->token) || !isset($request->loja) || !isset($request->dominio) || !isset($request->categoria)) {
            return response()->json([
                'mensagem' => 'Não autorizado.'
            ]);
        }

        $verificaToken = DB::select(DB::raw(
            "SELECT * FROM users WHERE token_checkout = :token"
        ), [
            'token' => $request->token
        ]);


        if (empty($verificaToken[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado [001]'
            ]);
        }

        $tipoUsuario = $verificaToken[0]->tipo_usuario;
        $verificaLojasUsuario = DB::select(DB::raw(
            "SELECT nm_loja, id_loja, cnpj_loja, email_loja, img_loja, cor_loja
                    FROM loja
                    WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario .
            " AND id_loja = " . $request->loja
        ));
        if (empty($verificaLojasUsuario[0])) {
            return response()->json([
                'mensagem' => 'Não autorizado'
            ]);
        }

        $dadosUsuario = DB::select(DB::raw(
            "SELECT * FROM usuario_pai" . " WHERE id_usuario_pai" . " = " . $verificaToken[0]->id_usuario
        ));
        if (empty($dadosUsuario[0])) {
            return response()->json(['mensagem' => "Não autorizado"]);
        }
        $dataFimToken = $dadosUsuario[0]->dt_fim_token;

        if (strtotime(date('Y-m-d H:i:s')) > strtotime($dataFimToken)) {
            return response()->json([
                'mensagem' => 'Não autorizado [002]'
            ]);
        }

        $getCategoria = DB::select(DB::raw("
                SELECT cd_categoria
                FROM produto_categoria
                WHERE
            "));

        $queryProduto = DB::select(DB::raw(
            "SELECT
                        p.*,
                        pc.ds_categoria
                FROM loja l
                JOIN produto p ON l.id_usuario_pai" . " = p.id_usuario_pai" . " AND p.id_loja = " . $request->loja . "
                LEFT JOIN produto_categoria pc ON p.id_produto_categoria = pc.id_produto_categoria
                WHERE 1=1
                AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
                AND l.id_loja = " . $request->loja . "
                AND p.sn_loja = 's'
                "
        ));

        $queryLoja = DB::select(DB::raw(
            "SELECT l.nm_loja,
                        l.id_loja,
                        l.cnpj_loja,
                        l.email_loja,
                        l.img_loja,
                        l.cor_loja,
                        l.banner1_desktop,
                        l.banner1_mobile,
                        l.banner2_desktop,
                        l.banner2_mobile,
                        l.banner3_desktop,
                        l.banner3_mobile
                FROM loja l
                WHERE 1=1
                AND l.id_usuario_pai" . " = " . $verificaToken[0]->id_usuario . "
                AND l.id_loja = " . $request->loja
        ));

        $listaRetorno = [
            'loja' => $queryLoja[0],
            'produtos' => [],
            'categoria' => []
        ];

        foreach ($queryProduto as $key => $row) {
            $listaRetorno['produtos'][($row->ds_categoria == null ? 'outros' : $row->ds_categoria)][] = $row;
            $flag = false;
            if (!is_null($row->ds_categoria)) {
                foreach ($listaRetorno['categoria'] as $i => $v) {
                    if ($v == $row->ds_categoria) $flag = true;
                }
            }
            if (!$flag) $listaRetorno['categoria'][] = $row->ds_categoria;

        }
        return response()->json($listaRetorno);


    }


}

