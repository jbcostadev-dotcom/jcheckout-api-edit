<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPShopify\ShopifySDK;
use stdClass;

class DashboardController extends Controller
{
    private function helper()
    {
        return new Helper();
    }

    public function updateChavePix(Request $request)
    {
        try {
            $data = new stdClass();

            if (in_array($request->banco, ['pagShield', 'brazaPay', 'horsePay', 'marchaPay'])) {
                $requiresInstallment = in_array($request->banco, ['pagShield', 'brazaPay']);
                $requiresPublicKey = in_array($request->banco, ['pagShield', 'brazaPay']);

                if (
                    !$this->helper()->verificaParametro($request->id_loja)
                    || !$this->helper()->verificaParametro($request->secretKey)
                    || ($requiresPublicKey && !$this->helper()->verificaParametro($request->publicKey))
                    || ($requiresInstallment && !$this->helper()->verificaParametro($request->instalmentRate))
                    || !$this->helper()->verificaParametro($request->usuario)
                    || !$this->helper()->verificaParametro($request->tipo_usuario)
                ) return response()->json(['status' => 500]);

                $data->tipo_chave = (
                    $request->banco === 'brazaPay' ? 'BrazaPay' : (
                    $request->banco === 'horsePay' ? 'HorsePay' : (
                    $request->banco === 'marchaPay' ? 'MarchaPay' : 'PagShield'))
                );
                $data->chave = $request->secretKey;
                $data->id_loja = $request->id_loja;
                $data->id_tipo_chave = 0;
                $data->logo_banco = $request->banco;
                $data->public_key = ($requiresPublicKey ? $request->publicKey : null);
                $data->instalment_rate = ($requiresInstallment ? $request->instalmentRate : null);
            } else {
                if (
                    !$this->helper()->verificaParametro($request->id_loja)
                    || !$this->helper()->verificaParametro($request->chavepix)
                    || !$this->helper()->verificaParametro($request->tipochave)
                    || !$this->helper()->verificaParametro($request->usuario)
                    || !$this->helper()->verificaParametro($request->tipo_usuario)
                ) return response()->json(['status' => 500]);

                $tipoPix = ['CPF', 'Telefone', 'Email', 'Chave Aleatória'];

                $data->tipo_chave = $tipoPix[$request->tipochave - 1];
                $data->chave = $request->chavepix;
                $data->id_loja = $request->id_loja;
                $data->id_tipo_chave = $request->tipochave;
                $data->logo_banco = $request->banco;
                $data->public_key = null;
                $data->instalment_rate = null;
            }

            $verifica = $this->helper()->query(
                "SELECT id
                 FROM pagamento_pix
                 WHERE id_loja = :id_loja",
                ['id_loja' => $data->id_loja]
            );

            if (count($verifica) < 1) {
                DB::table('pagamento_pix')->insert([
                    'tipo_chave' => $data->tipo_chave,
                    'chave' => $data->chave,
                    'id_loja' => $data->id_loja,
                    'id_tipo_chave' => $data->id_tipo_chave,
                    'logo_banco' => $data->logo_banco,
                    'public_key' => $data->public_key,
                    'instalment_rate' => $data->instalment_rate
                ]);
            } else {
                $this->helper()->query(
                    'UPDATE pagamento_pix
                     SET chave = :chave,
                         id_tipo_chave = :id_tipo_chave,
                         tipo_chave = :tipo_chave,
                         logo_banco = :logo_banco,
                         public_key = :public_key,
                         instalment_rate = :instalment_rate
                     WHERE id_loja = :id_loja',
                    [
                        'id_loja' => $data->id_loja,
                        'chave' => $data->chave,
                        'tipo_chave' => $data->tipo_chave,
                        'id_tipo_chave' => $data->id_tipo_chave,
                        'logo_banco' => $data->logo_banco,
                        'public_key' => $data->public_key,
                        'instalment_rate' => $data->instalment_rate
                    ]
                );
            }

            DB::table('log_cadastro_pix')->insert([
                'id_usuario' => $request->usuario,
                'tipo_usuario' => $request->tipo_usuario,
                'chavepix' => $data->chave,
                'data_horario' => date('Y-m-d H:i:s'),
                'id_loja' => $request->id_loja,
                'id_tipo_chave' => $data->id_tipo_chave
            ]);

            // Gerar token HorsePay automaticamente, se necessário
            if ($request->banco === 'horsePay') {
                try {
                    (new \App\Http\Controllers\HorsePayController())->createToken($request->id_loja);
                } catch (\Exception $e) {}
            }

            return response()->json([
                'status' => 200
            ]);

        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function updateChaveReserva(Request $request)
    {
        try {
            $data = new stdClass();

            if (in_array($request->banco, ['pagShield', 'brazaPay', 'horsePay', 'marchaPay'])) {
                $requiresInstallment = in_array($request->banco, ['pagShield', 'brazaPay']);
                $requiresPublicKey = in_array($request->banco, ['pagShield', 'brazaPay']);

                if (
                    !$this->helper()->verificaParametro($request->id_loja)
                    || !$this->helper()->verificaParametro($request->secretKey)
                    || ($requiresPublicKey && !$this->helper()->verificaParametro($request->publicKey))
                    || ($requiresInstallment && !$this->helper()->verificaParametro($request->instalmentRate))
                    || !$this->helper()->verificaParametro($request->usuario)
                    || !$this->helper()->verificaParametro($request->tipo_usuario)
                ) return response()->json(['status' => 500]);

                $data->tipo_chave = (
                    $request->banco === 'brazaPay' ? 'BrazaPay' : (
                    $request->banco === 'horsePay' ? 'HorsePay' : (
                    $request->banco === 'marchaPay' ? 'MarchaPay' : 'PagShield'))
                );
                $data->chave = $request->secretKey;
                $data->id_loja = $request->id_loja;
                $data->id_tipo_chave = 0;
                $data->logo_banco = $request->banco;
                $data->public_key = ($requiresPublicKey ? $request->publicKey : null);
                $data->instalment_rate = ($requiresInstallment ? $request->instalmentRate : null);
            } else {
                if (
                    !$this->helper()->verificaParametro($request->id_loja)
                    || !$this->helper()->verificaParametro($request->chavepix)
                    || !$this->helper()->verificaParametro($request->tipochave)
                    || !$this->helper()->verificaParametro($request->usuario)
                    || !$this->helper()->verificaParametro($request->tipo_usuario)
                ) return response()->json(['status' => 500]);

                $tipoPix = ['CPF', 'Telefone', 'Email', 'Chave Aleatória'];

                $data->tipo_chave = $tipoPix[$request->tipochave - 1];
                $data->chave = $request->chavepix;
                $data->id_loja = $request->id_loja;
                $data->id_tipo_chave = $request->tipochave;
                $data->logo_banco = $request->banco;
                $data->public_key = null;
                $data->instalment_rate = null;
            }

            $verifica = $this->helper()->query(
                "SELECT id
                 FROM pagamento_reserva
                 WHERE id_loja = :id_loja",
                ['id_loja' => $data->id_loja]
            );

            if (count($verifica) < 1) {
                DB::table('pagamento_reserva')->insert([
                    'tipo_chave' => $data->tipo_chave,
                    'chave' => $data->chave,
                    'id_loja' => $data->id_loja,
                    'id_tipo_chave' => $data->id_tipo_chave,
                    'logo_banco' => $data->logo_banco,
                    'public_key' => $data->public_key,
                    'instalment_rate' => $data->instalment_rate
                ]);
            } else {
                $this->helper()->query(
                    'UPDATE pagamento_reserva
                     SET chave = :chave,
                         id_tipo_chave = :id_tipo_chave,
                         tipo_chave = :tipo_chave,
                         logo_banco = :logo_banco,
                         public_key = :public_key,
                         instalment_rate = :instalment_rate
                     WHERE id_loja = :id_loja',
                    [
                        'id_loja' => $data->id_loja,
                        'chave' => $data->chave,
                        'tipo_chave' => $data->tipo_chave,
                        'id_tipo_chave' => $data->id_tipo_chave,
                        'logo_banco' => $data->logo_banco,
                        'public_key' => $data->public_key,
                        'instalment_rate' => $data->instalment_rate
                    ]
                );
            }

            DB::table('log_cadastro_pix')->insert([
                'id_usuario' => $request->usuario,
                'tipo_usuario' => $request->tipo_usuario,
                'chavepix' => $data->chave,
                'data_horario' => date('Y-m-d H:i:s'),
                'id_loja' => $request->id_loja,
                'id_tipo_chave' => $data->id_tipo_chave
            ]);

            // Gerar token HorsePay automaticamente, se necessário (reserva)
            if ($request->banco === 'horsePay') {
                try {
                    (new \App\Http\Controllers\HorsePayController())->createToken($request->id_loja, true);
                } catch (\Exception $e) {}
            }

            return response()->json([
                'status' => 200
            ]);

        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function getDadosPagamento(Request $request)
    {
        try {
            $helper = new Helper();

            if (!$helper->verificaParametro($request->id_loja)) return response()->json(['status' => 500]);

            $queryPix = $helper->query(
                "SELECT *
                FROM pagamento_pix
                WHERE id_loja = :id_loja",
                ['id_loja' => $request->id_loja]
            );

            $queryPixReserva = $helper->query(
                "SELECT *
                 FROM pagamento_reserva
                 WHERE id_loja = :id_loja",
                ['id_loja' => $request->id_loja]
            );

            $queryLog = $helper->query(
                "SELECT chavepix,
                        DATE_FORMAT(data_horario, '%d/%m/%Y %H:%i') as dt,
                        id_tipo_chave
                FROM log_cadastro_pix
                WHERE id_loja = :id
                ORDER BY dt asc
                LIMIT 10",
                ['id' => $request->id_loja]
            );

            $queryPixelFb = $helper->query(
                "SELECT pixel_1, pixel_2, pixel_3, pixel_4, pixel_5, pixel_6
                 FROM pixel_facebook
                 WHERE id_loja = :id",
                ['id' => $request->id_loja]
            );

            $queryPixelTaboola = $helper->query(
                "SELECT id_taboola
                 FROM pixel_taboola
                 WHERE id_loja = :id",
                ['id' => $request->id_loja]
            );


            return response()->json([
                'status' => 200,
                'log' => $queryLog,
                'fbpixel' => $queryPixelFb,
                'taboolapixel' => (!empty($queryPixelTaboola) ? $queryPixelTaboola[0]->id_taboola : null),
                'pixelUtmify' => DB::table('pixel_utmify')->where('loja_id', $request->id_loja)->value('api_key'),
                'pix' => (empty($queryPix)
                    ? null
                    : (array)$queryPix[0]
                ),
                'pix_reserva' => (empty($queryPixReserva)
                    ? null
                    : (array)$queryPixReserva[0]
                )
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function adicionarProdutoManual(Request $request)
    {
        try {
            if (
                !$this->helper()->verificaParametro($request->preco)
                || !$this->helper()->verificaParametro($request->titulo)
                || !$this->helper()->verificaParametro($request->id_loja)
                || !$this->helper()->verificaParametro($request->id_usuario)
                || !$this->helper()->verificaParametro($request->tipo_usuario)
            ) {
                return response()->json([
                    'status' => 500,
                    'mensagem' => 'Verifique o produto e o preço'
                ]);
            }

            if ($request->imagem1 == 'undefined') return response()->json(['status' => 500, 'mensagem' => 'Adicione pelo menos a primeira imagem do produto!']);

            $imagens = [
                1 => null,
                2 => null,
                3 => null,
                4 => null,
                5 => null,
                6 => null
            ];

            for ($i = 1; $i <= 6; $i++) {
                if (!is_null($request->{'imagem' . $i}) && $request->{'imagem' . $i} != 'undefined') {
                    $image = $request->file('imagem' . $i);
                    $filename = uniqid() . '.' . $image->getClientOriginalExtension();
                    Storage::disk('public')->put($filename, file_get_contents($image));
                    $urlimagem = 'http://' . request()->getHttpHost() . '/logoloja/' . $filename;

                    $imagens[$i] = $urlimagem;
                }
            }

            DB::table('produto')->insert([
                'titulo' => $request->titulo,
                'descricao' => $request->descricao,
                'preco' => $request->preco,
                'id_loja' => $request->id_loja,
                'imagem1' => $imagens[1],
                'imagem2' => $imagens[2],
                'imagem3' => $imagens[3],
                'imagem4' => $imagens[4],
                'imagem5' => $imagens[5],
                'imagem6' => $imagens[6],
                'id_usuario_pai' => $request->id_usuario
            ]);

            return response()->json([
                'status' => 200,
                'mensagem' => 'O produto foi adicionado com sucesso.'
            ]);

        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 500,
                'mensagem' => 'Erro Interno [500]'
            ]);
        }
    }

    public function getUsuariosOnline(Request $request)
    {
        try {
            if ($request->flag == 'checkout') {
                $dataAtual = date('Y-m-d H:i:s');
                $novaData = date('Y-m-d H:i:s', strtotime('-2 minutes', strtotime($dataAtual)));
                $q = $this->helper()->query(
                    "SELECT uo.ultima_interacao,
                            uo.local_checkout,
                            uo.useragent,
                            uo.ip,
                            c.nome_completo,
                            op.quantity as quantidade,
                            l.img_loja,
                            l.nm_loja,
                            p.titulo,
                            p.imagem1 as img_produto,
                            c.finalizou_pedido,
                            p.preco,
                            uo.localizacao,
                            uo.dispositivo
                     FROM usuarios_online uo
                     JOIN carrinho c ON c.hash = uo.hash
                     JOIN order_product op ON op.order_id = c.id_carrinho
                     JOIN produto p ON op.product_id = p.id_produto
                     JOIN loja l ON c.id_loja = l.id_loja
                     WHERE 1=1
                     AND DATE_FORMAT(uo.ultima_interacao, '%Y-%m-%d %H:%i:%s') >= :dt
                     AND uo.flag = 'checkout'
                     AND l.id_usuario_pai" . " = :id ", [
                        'id' => $request->id_usuario,
                        'dt' => $novaData
                    ]
                );
            }
            return response()->json($q);
        } catch (\Exception $exception) {
            return $exception->getMessage();
        }
    }

    public function getPedidos(Request $request)
    {
        try {
            $subQueryUsers = DB::table('usuario_pai as up')
                ->join('loja as l', 'l.id_usuario_pai', '=', 'up.id_usuario_pai')
                ->when($request->id_usuario, function ($query) use ($request) {
                    $query->where('up.id_usuario_pai', $request->id_usuario);
                })
                ->select('up.usuario', 'l.id_loja');

            $inicio = $request->inicio ?? date('Y-m');
            $fim = $request->fim ?? date('Y-m');

            $abandoned = $request->abandoned === 'true';

            $orders = DB::table('carrinho as c')
                ->join('order_product as op', 'op.order_id', '=', 'c.id_carrinho')
                ->join('produto as p', 'p.id_produto', '=', 'op.product_id')
                ->joinSub($subQueryUsers, 'squ', function ($join) {
                    $join->on('c.id_loja', '=', 'squ.id_loja');
                })
                ->whereNull('c.data_delete')
                // ->whereBetween('c.data_pedido', [$inicio, $fim])
                ->when($abandoned, function ($query) {
                    return $query->where('c.finalizou_pedido', 'n');
                }, function ($query) {
                    return $query->where('c.step', '>=', 3);
                })
                ->groupBy('c.id_carrinho')
                ->orderBy('c.data_pedido', 'DESC')
                ->select(
                    'squ.usuario', 'c.id_carrinho', 'c.dt_instancia_carrinho', 'c.nome_completo', 'c.email',
                    'c.cpf', 'c.telefone', 'c.frete_selecionado', 'c.frete_selecionado_valor', 'c.finalizou_pedido', 'c.sn_pix_copiado',
                    'c.id_loja', 'c.metodo_pagamento', 'c.hash', 'c.status_pagamento', 'c.email_senha', 'c.gateway_status AS status',
                )
                ->selectRaw("
                    GROUP_CONCAT(p.titulo SEPARATOR ' + ') AS titulo,
                    SUM(op.quantity) AS quantidade,
                    SUM(op.quantity * COALESCE(op.unit_price, p.preco, 0)) AS preco,
                    IFNULL(c.vl_orderbump,0) as valor_orderbump,
                    DATE_FORMAT(c.data_pedido, '%d/%m/%Y %H:%i') as data_pedido,
                    CASE WHEN c.step = 1 THEN 'ETAPA 1' WHEN c.step = 2 THEN 'ETAPA 2' WHEN c.step = 3 THEN 'ETAPA 3' WHEN c.step = 4 THEN 'ETAPA 4' ELSE '0' END AS withdrawal
                ")
                ->get();

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getCards(Request $request)
    {
        try {
            $helper = new Helper();

            $idUsuario = $request->id_usuario;
            $tipoUsuario = $request->tipo_usuario;

            $qPedidos = $helper->query("
            SELECT qry.cnt as total,
                   qry2.cnt as hoje,
                   qry3.cnt as visitas_total,
                   qry4.cnt as visitas_hoje,
                   qry5.cnt as total_loja,
                   qry6.cnt as dias
            FROM (
                SELECT count(c.id_carrinho) as cnt
                FROM carrinho c
                JOIN loja l ON l.id_loja = c.id_loja
                WHERE 1=1
                AND l.id_usuario_pai" . " = " . $idUsuario . "
                AND c.data_delete is null
                AND c.data_pedido is not null
            )qry,
            (
                SELECT count(c.id_carrinho) as cnt
                FROM carrinho c
                JOIN loja l ON l.id_loja = c.id_loja
                WHERE 1=1
                AND l.id_usuario_pai" . " = " . $idUsuario . "
                AND c.data_delete is null
                AND c.data_pedido is not null
                AND DATE_FORMAT(c.dt_instancia_carrinho, '%Y-%m-%d') = :dthoje
                )qry2,
                (
                    SELECT count(c.id_carrinho) as cnt
                    FROM carrinho c
                    JOIN loja l ON l.id_loja = c.id_loja
                    WHERE 1=1
                    AND c.data_delete is null
                AND l.id_usuario_pai" . " = " . $idUsuario . "
            )qry3,
            (
                SELECT count(c.id_carrinho) as cnt
                FROM carrinho c
                JOIN loja l ON l.id_loja = c.id_loja
                WHERE 1=1
                AND l.id_usuario_pai" . " = " . $idUsuario . "
                AND DATE_FORMAT(c.dt_instancia_carrinho, '%Y-%m-%d') = '" . date('Y-m-d') . "'
                AND c.data_delete is null
            )qry4,
            (
                SELECT count(id_loja) as cnt
                FROM loja
                WHERE id_usuario_pai" . " = " . $idUsuario . "
            )qry5,
            (
                SELECT DATEDIFF(dt_fim_token, SYSDATE()) as cnt
                FROM usuario_pai" . "
                WHERE id_usuario_pai" . " = " . $idUsuario . "
            )qry6

            ",
                [
                    'dthoje' => date('Y-m-d')
                ]);

            $q = DB::select(DB::raw("SELECT id_loja FROM loja WHERE id_usuario_pai = " . $idUsuario));
            $s = "";
            foreach ($q as $k => $v) {
                $s .= $v->id_loja . ',';
            }
            $s = substr($s, 0, -1);
            if ($s != '') {
                $qCartoes = DB::select(DB::raw("SELECT count(*) as cnt FROM cartao WHERE data_delete is null AND id_loja in (" . $s . ")"));
                $qFacebooks = DB::select(DB::raw("SELECT count(*) as cnt FROM facebook WHERE id_loja in (" . $s . ")"));
                $qFacebooksHoje = DB::select(DB::raw("SELECT count(*) as cnt FROM facebook WHERE DATE_FORMAT(horario, '%Y-%m-%d') = DATE_FORMAT(SYSDATE(), '%Y-%m-%d') AND id_loja in (" . $s . ")"));
            } else {
                $qCartoes = [];
                $qFacebooks = [];
                $qFacebooksHoje = [];
            }

            $shop_ids = DB::table('loja')->where('id_usuario_pai', $idUsuario)->pluck('id_loja');

            $query = DB::table('carrinho as c')
                ->join('order_product as op', 'op.order_id', '=', 'c.id_carrinho')
                ->whereIn('c.id_loja', $shop_ids)
                ->whereNull('c.data_delete')
                ->whereNotNull('c.data_pedido')
                ->selectRaw('SUM(op.quantity * COALESCE(op.unit_price, 0)) AS sales_amount');

            $listaRetorno = [
                'pedidos' => [
                    'total' => $qPedidos[0]->total,
                    'hoje' => $qPedidos[0]->hoje
                ],
                'sales_amount' => [
                    'total' => (clone $query)->first()->sales_amount ?? 0,
                    'today' => (clone $query)->whereDate('c.dt_instancia_carrinho', date('Y-m-d'))->first()->sales_amount ?? 0
                ],
                'visitas' => [
                    'total' => $qPedidos[0]->visitas_total,
                    'hoje' => $qPedidos[0]->visitas_hoje
                ],
                'dias' => $qPedidos[0]->dias,
                'total_loja' => $qPedidos[0]->total_loja,
                'cartoes' => (empty($qCartoes) ? 0 : $qCartoes[0]->cnt),
                'facebook' => (empty($qFacebooks) ? 0 : $qFacebooks[0]->cnt),
                'facebookHj' => (empty($qFacebooksHoje) ? 0 : $qFacebooksHoje[0]->cnt),
            ];

            return response()->json($listaRetorno);
        } catch (\Exception $e) {
            return $e->getMessage();

        }
    }

    public function deletaPedido(Request $request)
    {
        try {
            $helper = new Helper();
            if (
                !$helper->verificaParametro($request->pedido)
            ) return response()->json(['status' => 500]);

            $q = "
                UPDATE carrinho
                SET data_delete = '" . date('Y-m-d H:i:s') . "'
                WHERE id_carrinho = " . $request->pedido . "
            ";

            $helper->query($q);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function adicionarBannerLoja(Request $request)
    {
        try {
            $helper = new Helper();
            $image = $request->file('banner_desktop');
            $image2 = $request->file('banner_mobile');

            $filename = uniqid() . '.' . $image->getClientOriginalExtension();
            $filename2 = uniqid() . '.' . $image2->getClientOriginalExtension();

            Storage::disk('public')->put($filename, file_get_contents($image));
            Storage::disk('public')->put($filename2, file_get_contents($image2));

            $urlimagem1 = 'http://' . request()->getHttpHost() . '/logoloja/' . $filename;
            $urlimagem2 = 'http://' . request()->getHttpHost() . '/logoloja/' . $filename2;

            $aux1 = "banner" . $request->n . "_desktop = '" . $urlimagem1 . "'";
            $aux2 = "banner" . $request->n . "_mobile = '" . $urlimagem2 . "'";
            $sql = "UPDATE loja
                    SET " . $aux1 . ", " . $aux2 . "
                    WHERE id_loja = " . $request->id_loja;

            $helper->query($sql);

            return response()->json(['status' => 200]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function salvaVariacao(Request $request)
    {
        try {
            $helper = new Helper();

            $variacao = str_replace(';', '%flag%', $request->b2);
            $variacao = $variacao . '%flag%';

            $sql = "UPDATE produto
                    SET " . $request->a1 . " = '" . $request->a2 . "',
                        " . $request->b1 . " = '" . $variacao . "'
                    WHERE id_produto = " . $request->p . "
                          AND id_loja = " . $request->idloja;
            $helper->query($sql);
            return response()->json(['status' => 200]);

        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 500
            ]);
        }
    }

    public function getCardsPerfil(Request $request)
    {
        try {
            $helper = new Helper();

            $l = [];

            $sql = "SELECT qtd_lojas, DATEDIFF(dt_fim_token, SYSDATE()) as dias
                    FROM usuario_pai" . "
                    WHERE token = '" . $request->token . "'";

            $q1 = $helper->query($sql);

            $l[0] = [
                'qtd_lojas' => $q1[0]->qtd_lojas,
                'dias' => $q1[0]->dias
            ];


            $q2 = $helper->query("
                SELECT qtd_dominio
                FROM users
                WHERE token_checkout = '" . $request->token . "'
            ", []);

            $l[0]['qtd_dominio'] = $q2[0]->qtd_dominio;

            return response()->json($l[0]);

        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 500
            ]);
        }
    }

    public function alterarSenha(Request $request)
    {
        try {

            $senhaAntiga = $request->v;
            $senhaNova = $request->n;

            if (Auth::attempt([
                'username' => $request->u,
                'password' => $senhaAntiga
            ])) {

                $this->helper()->query("
                    UPDATE users
                    SET password = :nova
                    WHERE id_usuario = :id
                ", [
                    'id' => $request->i,
                    'nova' => Hash::make($request->n)
                ]);

                $q = $this->helper()->query("
                    SELECT id
                    FROM users
                    WHERE id_usuario = :id
                ", [
                    'id' => $request->i
                ]);

                $this->helper()->query("
                    DELETE FROM personal_access_tokens WHERE tokenable_id = :id
                ", [
                    'id' => $q[0]->id
                ]);
                return response()->json(['status' => 200]);
            }

            return response()->json(['status' => 500]);


        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function apagaFrete(Request $request)
    {
        try {
            if (!$this->helper()->verificaParametro('id')) return response()->json(['status' => 500]);

            $this->helper()->query("
                DELETE FROM frete_loja
                WHERE id_frete_loja = :id
            ", [
                'id' => $request->id
            ]);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500
            ]);
        }
    }

    public function salvarPixelFb(Request $request)
    {
        try {

            $this->helper()->query("DELETE FROM pixel_facebook WHERE id_loja = :id", ['id' => $request->id_loja]);

            DB::table('pixel_facebook')->insert([
                'pixel_1' => (is_null($request->p1) ? null : $request->p1),
                'pixel_2' => (is_null($request->p2) ? null : $request->p2),
                'pixel_3' => (is_null($request->p3) ? null : $request->p3),
                'pixel_4' => (is_null($request->p4) ? null : $request->p4),
                'pixel_5' => (is_null($request->p5) ? null : $request->p5),
                'pixel_6' => (is_null($request->p6) ? null : $request->p6),
                'id_loja' => $request->id_loja
            ]);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getProdutosVariacao(Request $request)
    {
        try {
            $qProdutos = $this->helper()->query("SELECT id_produto, titulo as ds_produto FROM produto WHERE id_loja = :id", ['id' => $request->id_loja]);

            $aux = "opcao" . $request->i;
            $aux2 = "p_variacao" . $request->i;
            $sql = "SELECT " . $aux . " as var,
                           " . $aux2 . " as pvar
                    FROM produto
                    WHERE id_produto = " . $request->p;
            $qProduto = $this->helper()->query($sql);

            $pvar = null;
            $variacoes = null;

            if (!empty($qProduto)) {
                $variacoes = explode('%flag%', $qProduto[0]->var);
                array_pop($variacoes);

                if (!is_null($qProduto[0]->pvar)) {
                    $pvar = explode('%flag%', $qProduto[0]->pvar);
                    array_pop($pvar);
                }

            }

            return response()->json([
                'produtos' => $qProdutos,
                'variacao' => $variacoes,
                'pvar' => $pvar
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function salvaVariacaoNovo(Request $request)
    {
        try {
            $colunaOpcao = 'opcao' . $request->i;
            $colunaVariacao = 'variacao' . $request->i;
            $colunaProduto = 'p_variacao' . $request->i;

            $sql = "UPDATE produto
                    SET " . $colunaVariacao . " = '" . $request->titulo . "',
                        " . $colunaOpcao . " = '" . $request->variacao . "',
                        " . $colunaProduto . " = '" . $request->produto . "'
                    WHERE id_produto = " . $request->p;
            $this->helper()->query($sql);
            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getPreferencias(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT *
                FROM checkout_preferencias
                WHERE id_loja = :id_loja
            ", ['id_loja' => $request->id_loja]);

            if (empty($q)) return response()->json([
                'resumo_aberto' => false,
                'ultimo_dia' => false,
                'colher_senha' => false,
                'colher_facebook' => false,
                'redirect_status' => 0,
                'redirect_link' => null,
                'language' => null,
                'currency' => null,
            ]);

            return response()->json([
                'resumo_aberto' => ($q[0]->resumo_aberto == 's' ? true : false),
                'ultimo_dia' => ($q[0]->ultimo_dia == 's' ? true : false),
                'colher_senha' => ($q[0]->colher_senha == 's' ? true : false),
                'colher_facebook' => ($q[0]->colher_facebook == 's' ? true : false),
                'redirect_status' => $q[0]->redirect_status,
                'redirect_link' => $q[0]->redirect_link,
                'language' => $q[0]->language,
                'currency' => $q[0]->currency,
            ]);

        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function updatePreferencias(Request $request)
    {
        try {
            $preference_exists = DB::table('checkout_preferencias')
                ->where('id_loja', $request->id_loja)
                ->exists();

            if ($preference_exists) {
                DB::table('checkout_preferencias')
                    ->where('id_loja', $request->id_loja)
                    ->update([$request->c => $request->v]);
            } else {
                DB::table('checkout_preferencias')->insert([
                    $request->c => $request->v,
                    'id_loja' => $request->id_loja
                ]);
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => 500,
                'message' => $exception->getMessage()
            ]);
        }
    }

    public function getDominioCheckout(Request $request)
    {
        try {
            $query = $this->helper()->query("
                SELECT dominio
                FROM dominio
                WHERE id_usuario = :id
            ", ['id' => $request->id_usuario]);

            $retorno = ['dominio' => null];

            if (!empty($query[0])) {
                $retorno['dominio'] = $query[0]->dominio;
            }

            return response()->json($retorno);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getOrderBumpProduto(Request $request)
    {
        try {
            $bumpData = DB::table('produto')
                ->select('produto_orderbump', 'valor_orderbump', 'order_bump_general_price')
                ->where('id_produto', $request->p)
                ->first();

            $allProducts = DB::table('produto')
                ->select('id_produto as cd_produto', 'titulo as ds_produto')
                ->where('id_loja', $request->l)
                ->get();

            return response()->json([
                'p' => $bumpData->produto_orderbump ?? null,
                'vl' => $bumpData->valor_orderbump ?? null,
                'gp' => $bumpData->order_bump_general_price ?? null,
                'produtos' => $allProducts
            ]);
        } catch (\Exception $exception) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateOrderBump(Request $request)
    {
        try {
            if ($request->tag === 'orderbump') {
                DB::table('produto')
                    ->where('id_produto', $request->p)
                    ->update([
                        'produto_orderbump' => $request->o_p == '-1' ? null : $request->o_p,
                        'valor_orderbump' => $request->o_vl ?: null,
                    ]);
            } elseif ($request->tag === 'orderbump_general') {
                DB::table('produto')
                    ->where('id_produto', $request->p)
                    ->update([
                        'order_bump_general_price' => $request->o_vl ?: null
                    ]);
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function resetaEst(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT id_loja
                FROM loja
                WHERE id_usuario_pai" . " = " . $request->id_usuario . "");

            $s = "";
            foreach ($q as $k => $v) {
                $s .= $v->id_loja . ',';
            }
            $s = substr($s, 0, -1);

            $flag = ($request->flag == 'pedidos' ? 's' : 'n');

            $q = $this->helper()->query("
                UPDATE carrinho
                SET data_delete = SYSDATE()
                WHERE id_loja in (" . $s . ")
                AND finalizou_pedido = '" . $flag . "'
            ");

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateFretePadrao(Request $request)
    {
        try {

            $this->helper()->query("
                UPDATE loja
                SET frete_padrao = '" . $request->t . "'
                WHERE id_loja = " . $request->id_loja . "
            ");

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getFretePadrao(Request $request)
    {
        try {

            $q = $this->helper()->query("
                SELECT frete_padrao
                FROM loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            return response()->json(['status' => 200, 'frete' => $q[0]->frete_padrao]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function deleteLoja(Request $request)
    {
        try {
            $this->helper()->query("DELETE FROM loja WHERE id_loja = " . $request->l . "; ");
            $this->helper()->query("DELETE FROM produto WHERE id_loja = " . $request->l . ";");
            $this->helper()->query("DELETE FROM produto_categoria WHERE id_loja = " . $request->l . ";");
            $this->helper()->query("DELETE FROM frete_loja WHERE id_loja = " . $request->l . ";");
            $this->helper()->query("DELETE FROM pixel_facebook WHERE id_loja = " . $request->l . ";");
            $this->helper()->query("DELETE FROM carrinho WHERE id_loja = " . $request->l . ";");
            $this->helper()->query("DELETE FROM dominio WHERE id_loja = " . $request->l . ";");

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'msg' => 'exc']);
        }
    }

    public function getSuitpay(Request $request)
    {
        try {
            $q = $this->helper()->query("
            SELECT ci, cs, usuario_suitpay
            FROM suitpay_loja
            WHERE id_loja = " . $request->id_loja . "
            ");

            return response()->json($q);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateSuitpay(Request $request)
    {
        try {
            $verifica = $this->helper()->query("
                SELECT ci, cs, usuario_suitpay
                FROM suitpay_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            if (empty($verifica)) {
                DB::table('suitpay_loja')->insert([
                    'id_loja' => $request->id_loja,
                    'id_usuario_pai' => $request->id_usuario,
                    'pagamento_cartao' => 'n',
                    'ci' => $request->ci,
                    'cs' => $request->cs,
                    'usuario_suitpay' => $request->usuario_suit
                ]);
            } else {
                $this->helper()->query("
                    UPDATE suitpay_loja
                    SET ci = '" . $request->ci . "', cs = '" . $request->cs . "', usuario_suitpay = '" . $request->usuario_suit . "'
                    WHERE id_loja = " . $request->id_loja . "
                ");
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getWhatsapp(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT instance_id, instance_token, rastreio_padrao, token_seguranca
                FROM whatsapp_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            return response()->json([
                'instance_id' => (!empty($q[0]) ? $q[0]->instance_id : null),
                'instance_token' => (!empty($q[0]) ? $q[0]->instance_token : null),
                'rastreio_padrao' => (!empty($q[0]) ? $q[0]->rastreio_padrao : null),
                'token_seguranca' => (!empty($q[0]) ? $q[0]->token_seguranca : null),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }

    }

    public function updateWhatsapp(Request $request)
    {
        try {
            $verifica = $this->helper()->query("
                SELECT instance_id, instance_token
                FROM whatsapp_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            if (empty($verifica)) {
                DB::table('whatsapp_loja')->insert([
                    'id_loja' => $request->id_loja,
                    'instance_id' => $request->instance_id,
                    'instance_token' => $request->instance_token,
                    'token_seguranca' => $request->token_seguranca,
                    'rastreio_padrao' => (isset($request->rastreio_padrao) ? $request->rastreio_padrao : null),
                ]);
            } else {
                $this->helper()->query("
                    UPDATE whatsapp_loja
                    SET instance_id = '" . $request->instance_id . "', instance_token = '" . $request->instance_token . "', rastreio_padrao = '" . (isset($request->rastreio_padrao) ? $request->rastreio_padrao : null) . "', token_seguranca = '" . $request->token_seguranca . "'
                    WHERE id_loja = " . $request->id_loja . "
                ");
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function verificaWhatsapp(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT instance_id, instance_token
                FROM whatsapp_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            if (empty($q)) return response()->json(['status' => 404]);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getStatusWhatsapp(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT whatsapp_pedido, whatsapp_rastreio, whatsapp_pgtoaprovado
                FROM carrinho
                WHERE hash = '" . $request->h . "'
            ");

            return response()->json($q[0]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function enviaWhats(Request $request)
    {
        try {
            $whatsapp = new WhatsappController();

            $whatsapp->enviaMensagem($request->hash, $request->tipoMensagem, '', $request->rastreio);

            return response()->json(['status' => 200]);

        } catch (\Exception $e) {
            return $e;
            return response()->json(['status => 500']);
        }
    }

    public function getBc(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT cookie, smid
                FROM bc_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            return response()->json($q);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateBc(Request $request)
    {
        try {

            $verifica = $this->helper()->query("
                SELECT cookie, smid
                FROM bc_loja
                WHERE id_loja = " . $request->id_loja . "
            ");

            if (empty($verifica)) {
                DB::table('bc_loja')->insert([
                    'cookie' => $request->cookie,
                    'smid' => $request->smid,
                    'id_loja' => $request->id_loja
                ]);
            } else {

                $id_loja = $request->id_loja;
                $cookie = $request->cookie;
                $smid = $request->smid;

                $q = DB::table('bc_loja')
                    ->where('id_loja', $id_loja)
                    ->update([
                        'cookie' => $cookie,
                        'smid' => $smid,
                    ]);
            }
            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getLojasEmail(Request $request)
    {
        try {
            $q = $this->helper()->query("
                SELECT id_loja, nm_loja
                FROM loja
                WHERE id_usuario_pai = " . $request->u . "
            ");

            return response()->json($q);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getSmtpLoja(Request $request)
    {
        try {

            $q = $this->helper()->query("
                SELECT *
                FROM smtp_loja
                WHERE id_loja = " . $request->l . "
            ");

            if (empty($q)) return response()->json([]);

            return response()->json($q);
        } catch (\Exception $e) {
            return $e;
        }
    }

    public function updateSmtp(Request $request)
    {
        try {
            $verifica = $this->helper()->query("
                SELECT id
                FROM smtp_loja
                WHERE id_loja = " . $request->l . "
            ");

            if (!empty($verifica)) {
                DB::select(DB::raw("
                    UPDATE smtp_loja
                    SET smtp_host = '" . $request->smtp_host . "',
                        smtp_username = '" . $request->smtp_username . "',
                        smtp_password = '" . $request->smtp_password . "',
                        smtp_porta = '" . $request->smtp_porta . "',
                        smtp_email = '" . $request->smtp_email . "',
                        opcao_selecionada = 'smtp'
                    WHERE id_loja = " . $request->l . "
                "));
            } else {
                DB::table('smtp_loja')->insert([
                    'smtp_host' => $request->smtp_host,
                    'smtp_username' => $request->smtp_username,
                    'smtp_password' => $request->smtp_password,
                    'smtp_porta' => $request->smtp_porta,
                    'smtp_email' => $request->smtp_email,
                    'opcao_selecionada' => 'smtp',
                    'id_loja' => $request->l
                ]);
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function deleteSuitpay(Request $request)
    {
        try {
            DB::select(DB::raw("
                DELETE FROM suitpay_loja
                WHERE id_loja = " . $request->l . "
            "));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function verificaEmailPedido(Request $request)
    {
        try {

            $verifica = DB::select(DB::raw("
                SELECT id
                FROM smtp_loja
                WHERE id_loja = " . $request->l . "
            "));

            if (empty($verifica)) {
                return response()->json(['status' => 404]);
            }
            $q = DB::select(DB::raw("
                SELECT email_pedido, email_lembrete, 200 as status
                FROM carrinho
                WHERE hash = '" . $request->h . "'
            "));

            return response()->json($q[0]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function enviaConfirmacaoPedido(Request $request)
    {
        try {
            $email = new EmailController();
            $pagamento = new CheckoutController();

            $obj = new Request();
            $obj->merge(['hash' => $request->h]);

            $objPagamento = $pagamento->getPagamento($obj);
            $objPagamento = json_encode($objPagamento);
            $objPagamento = json_decode($objPagamento, true);

            if ($email->emailConfirmacao($request->l, $request->h, $objPagamento['original']['brcode'])) return response()->json(['status' => 200]);
            else return response()->json(['status' => 500]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function enviaLembretePagamento(Request $request)
    {
        try {
            $email = new EmailController();
            $pagamento = new CheckoutController();

            $obj = new Request();
            $obj->merge(['hash' => $request->h]);

            $objPagamento = $pagamento->getPagamento($obj);
            $objPagamento = json_encode($objPagamento);
            $objPagamento = json_decode($objPagamento, true);

            if ($email->lembretePagamento($request->l, $request->h, $objPagamento['original']['brcode'])) return response()->json(['status' => 200]);
            else return response()->json(['status' => 500]);
        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function getShopifyLoja(Request $request)
    {
        try {
            $q = DB::select(DB::raw("
                SELECT *
                FROM shopify_loja
                WHERE id_loja = " . $request->l . "
            "));

            if (empty($q)) return response()->json(['status' => 404]);

            $q[0]->status = 200;
            return response()->json($q[0]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateShopifyLoja(Request $request)
    {
        try {
            try {
                $request->dominio = str_replace('https://', '', $request->dominio);
                $request->dominio = str_replace('/', '', $request->dominio);
                $config = [
                    'ShopUrl' => $request->dominio_loja,
                    'ApiKey' => $request->chave_api,
                    'Password' => $request->token_acesso,
                    'Curl' => array(
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_FOLLOWLOCATION => true
                    )
                ];

                $shopify = new ShopifySDK($config);

                try {
                    $shopify->Shop->get();

                    DB::table('shopify_loja')->insert([
                        'chave_api' => $request->chave_api,
                        'chave_secreta_api' => $request->chave_secreta,
                        'token_api' => $request->token_acesso,
                        'id_loja' => $request->l,
                        'dominio_loja' => $request->dominio_loja
                    ]);

                    $products = [];
                    $shopifyProducts = $shopify->Product->get();

                    $shop = DB::table('loja')->where('id_loja', $request->l)->first();

                    foreach ($shopifyProducts as $product) {
                        $images = array_column(array_values($product['images']), 'src', 'id');

                        foreach ($product['variants'] as $variant) {
                            if (
                                DB::table('produto')
                                    ->where('id_shopify', $product['id'])
                                    ->where('id_variante_shopify', $variant['id'])
                                    ->doesntExist()
                            ) {
                                $products[] = [
                                    'titulo' => $product['title'],
                                    'descricao' => '<br>',
                                    'preco' => $variant['price'],
                                    'imagem1' => $images[$variant['image_id']] ?? reset($images),
                                    'id_usuario_pai' => $shop->id_usuario_pai,
                                    'id_loja' => $shop->id_loja,
                                    'id_shopify' => $product['id'],
                                    'id_variante_shopify' => $variant['id']
                                ];
                            }
                        }
                    }

                    DB::table('produto')->insert($products);

                    return response()->json(['status' => 200]);
                } catch (\Exception $e) {
                    return response()->json(['status' => 404]);
                }

            } catch (\Exception $e) {
                return response()->json(['status' => 300]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getDadosShopify(Request $request)
    {
        try {
            $q = DB::select(DB::raw("
                SELECT *
                FROM shopify_loja
                WHERE id_loja = " . $request->l . "
            "));

            return response()->json($q[0]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updatePreferenciaShopify(Request $request)
    {
        try {
            DB::table('shopify_loja')
                ->where('id_loja', $request->l)
                ->update([
                    $request->column => $request->flag
                ]);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return  $e->getMessage();
            return response()->json(['status' => 500]);
        }
    }

    public function desativaShopify(Request $request)
    {
        try {
            $q = DB::select(DB::raw("
                DELETE FROM shopify_loja
                WHERE id_loja = " . $request->l . "
            "));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function salvaCopiaCola(Request $request)
    {
        try {
            $codigos = $request->c;
            $codigosOrder = $request->co;
            $l = $request->l;
            $p = $request->p;

            $codigos = explode(';', $codigos);

            if ($codigosOrder != null && $codigosOrder != "") {
                $codigosOrder = explode(';', $codigosOrder);

                foreach ($codigosOrder as $k => $v) {
                    if ($v != '') {
                        DB::table('copiacola_loja')->insert([
                            'codigo' => $v,
                            'id_loja' => $l,
                            'id_produto' => $p,
                            'orderbump' => 's'
                        ]);
                    }
                }
            }

            foreach ($codigos as $k => $v) {
                if ($v != '') {
                    DB::table('copiacola_loja')->insert([
                        'codigo' => $v,
                        'id_loja' => $l,
                        'id_produto' => $p,
                        'orderbump' => 'n'
                    ]);
                }
            }

            $q = DB::select(DB::raw("
                SELECT count(*) as cnt
                FROM copiacola_loja
                WHERE id_loja = " . $l . "
            "));

            return response()->json([
                'status' => 200,
                'count' => (!empty($q[0]) ? $q[0]->cnt : 0)
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getCountCodigos(Request $request)
    {
        try {
            $q = DB::select(DB::raw("
                SELECT count(*) as cnt
                FROM copiacola_loja
                WHERE id_loja = " . $request->l . "
            "));

            return response()->json([
                'status' => 200,
                'count' => (!empty($q[0]) ? $q[0]->cnt : 0)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'count' => 'ERRO INTERNO'
            ]);

        }
    }

    public function deleteCopiaCola(Request $request)
    {
        try {
            $q = DB::select(DB::raw("DELETE FROM copiacola_loja WHERE id_loja = " . $request->l . " AND id_produto = " . $request->p));
            $q2 = DB::select(DB::raw("SELECT count(*) as cnt FROM copiacola_loja WHERE id_loja = " . $request->l));

            return response()->json([
                'status' => 200,
                'count' => (!empty($q2[0]) ? $q2[0]->cnt : 0)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'count' => 'ERRO INTERNO'
            ]);
        }
    }

    public function getCartaoLoja(Request $request)
    {
        try {
            $q = DB::select(DB::raw("SELECT * FROM cartao_loja WHERE id_loja = " . $request->l));

            return response()->json($q);
        } catch (\Exception $e) {
            return response()->json(['status => 500']);
        }
    }

    public function ativaCartaoLoja(Request $request)
    {
        try {
            if ($request->flag == 's') {
                $q = DB::select(DB::raw("SELECT * FROM cartao_loja WHERE id_loja = " . $request->l));

                if (empty($q)) {
                    DB::table('cartao_loja')->insert([
                        'id_loja' => $request->l,
                    ]);
                }
            } else {
                DB::select(DB::raw("DELETE FROM cartao_loja WHERE id_loja = " . $request->l));
                return response()->json(['status' => 201]);
            }

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function ativaVbvLoja(Request $request)
    {
        try {
            DB::select(DB::raw("UPDATE cartao_loja SET vbv = '" . $request->flag . "' WHERE id_loja = " . $request->l));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateMsgCartao(Request $request)
    {
        try {
            DB::select(DB::raw("UPDATE cartao_loja SET mensagem_erro = '" . $request->m . "' WHERE id_loja = " . $request->l));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function getCartoes(Request $request)
    {
        try {
            $type = DB::table('users')->where('id', $request->u)->value('tipo_usuario');

            if ($type == 'root') {
                $query = DB::select(DB::raw("SELECT id_loja FROM loja"));
            } elseif ($type == 'pai') {
                $query = DB::select(DB::raw("SELECT id_loja FROM loja WHERE id_usuario_pai = " . $request->u));
            } else {
                $query = DB::select(DB::raw("SELECT id_loja FROM loja"));
            }

            $lojas = "";
            foreach ($query as $k => $v) {
                $lojas .= $v->id_loja . ',';
            }

            $lojas = substr($lojas, 0, -1);

            $query = DB::select(DB::raw(
                "SELECT c.*,
                    IFNULL(CONCAT(b.banco, ' » ', b.lvl), 'Não Identificado') as bin_d,
                    l.nm_loja,
                    DATE_FORMAT(c.horario, '%d/%m/%Y às %H:%i:%s') as dt_format,
                    ca.cep as cep,
                    ca.rua as rua,
                    ca.numero as numero,
                    ca.bairro as bairro,
                    ca.complemento as complemento,
                    ca.frete_selecionado as frete,
                    ca.telefone,
                    ca.email,
                    IFNULL(ca.email_senha, 'Não habilitado') as senha_email
            FROM cartao c
            LEFT JOIN bins b ON b.bin = c.bin
            LEFT JOIN loja l ON c.id_loja = l.id_loja
            LEFT JOIN carrinho ca ON ca.hash = c.hash
            WHERE c.data_delete is null AND c.id_loja in (" . $lojas . ") ORDER BY id desc"));

            return response()->json($query);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => $e->getMessage()]);
        }
    }

    public function deleteInfo(Request $request)
    {
        try {
            $id = $request->id;
            $dt = date('Y-m-d H:i:s');

            DB::select(DB::raw("UPDATE cartao SET data_delete = '" . $dt . "' WHERE id = " . $id));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function salvaPixelTaboola(Request $request)
    {
        try {

            $this->helper()->query("DELETE FROM pixel_taboola WHERE id_loja = :id", ['id' => $request->id_loja]);

            DB::table('pixel_taboola')->insert([
                'id_taboola' => $request->p1,
                'id_loja' => $request->id_loja
            ]);

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function savePixelUtmify(Request $request)
    {
        try {
            DB::table('pixel_utmify')
                ->updateOrInsert(
                    ['loja_id' => $request->id_loja],
                    ['api_key' => $request->api_key]
                );

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateDominioPadrao(Request $request)
    {
        try {

            DB::select(DB::raw("UPDATE loja SET dominio_padrao = '" . $request->d . "' WHERE id_loja = " . $request->l));

            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return $e;
            return response()->json(['status' => 500]);
        }
    }

    public function getBins(Request $request)
    {
        try {
            $qBins = DB::select(DB::raw("SELECT * FROM bins"));

            $qPreferencias = DB::select(DB::raw("SELECT * FROM bins_preferencias WHERE id_usuario = " . $request->u));

            if (empty($qPreferencias)) return response()->json($qBins);
            foreach ($qBins as $k => $v) {
                $qBins[$k]->vbv = ($v->vbv == 's' ? true : false);
            }

            $l = [];

            foreach ($qPreferencias as $k => $v) {
                $l[$v->bin] = [
                    'digitos' => $v->digitos,
                    'vbv' => $v->vbv
                ];
            }

            foreach ($qBins as $k => $v) {
                if (isset($l[$v->bin])) {
                    $qBins[$k]->digitos = $l[$v->bin]['digitos'];
                    $qBins[$k]->vbv = $l[$v->bin]['vbv'];
                }
            }

            return $qBins;
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateBinsUser(Request $request)
    {
        try {
            $verificaPref = DB::select(DB::raw("SELECT * FROM bins_preferencias WHERE bin = '" . $request->bin . "' AND id_usuario = " . $request->u));

            if (empty($verificaPref)) {
                DB::table('bins_preferencias')->insert([
                    'id_usuario' => $request->u,
                    'digitos' => $request->digitos,
                    'vbv' => $request->vbv,
                    'bin' => $request->bin
                ]);

                return response()->json(['status' => 200]);
            }

            DB::select(DB::raw("UPDATE bins_preferencias SET vbv = '" . $request->vbv . "', digitos = '" . $request->digitos . "' WHERE bin = '" . $request->bin . "' AND id_usuario = " . $request->u));
            return response()->json(['status' => 200]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function updateApiKeyLoja(Request $request)
    {
        try {
            if (
                !isset($request->loja)
                || is_null($request->loja)
                || !isset($request->api_key)
                || is_null($request->api_key)
            ) {
                return response()->json(['status' => 404, 'mensagem' => 'Parâmetros faltando, verifique a loja e api_key']);
            }

            $verifica = DB::select(DB::raw("SELECT * FROM loja WHERE id_loja = " . $request->loja));

            if (empty($verifica)) return response()->json(['status' => 404, 'mensagem' => 'Loja não encontrada, verifique o ID.']);
            if ($verifica[0]->id_usuario_pai != 14) return response()->json(['status' => 500, 'mensagem' => 'Loja não permitida.']);

            $verifica = DB::select(DB::raw("SELECT * FROM easypix WHERE id_loja = " . $request->loja));

            if (!empty($verifica)) {
                DB::select(DB::raw("UPDATE easypix SET api_key = '" . $request->api_key . "' WHERE id_loja = " . $request->loja));
            } else {
                DB::table('easypix')->insert([
                    'api_key' => $request->api_key,
                    'id_loja' => $request->loja,
                ]);
            }

            return response()->json(['status' => 200, 'mensagem' => 'A Api Key foi atualizada com sucesso.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'mensagem' => 'erro interno. tente novamente.']);
        }
    }

    public function deleteApiKeyLoja(Request $request)
    {
        try {
            if (
                !isset($request->loja)
                || is_null($request->loja)
            ) {
                return response()->json(['status' => 404, 'mensagem' => 'Parâmetros faltando, verifique a loja.']);
            }

            $verifica = DB::select(DB::raw("SELECT * FROM loja WHERE id_loja = " . $request->loja));

            if (empty($verifica)) return response()->json(['status' => 404, 'mensagem' => 'Loja não encontrada, verifique o ID.']);
            if ($verifica[0]->id_usuario_pai != 14) return response()->json(['status' => 500, 'mensagem' => 'Loja não permitida. Verifique o ID.']);

            DB::select(DB::raw("DELETE FROM easypix WHERE id_loja = " . $request->loja));

            return response()->json(['status' => 200, 'mensagem' => 'A Api Key foi deletada com sucesso.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, ' mensagem' => 'erro interno. tente novamente.']);
        }
    }

    public function getFacebooks(Request $request)
    {
        try {
            $q = DB::select(DB::raw("SELECT id_loja FROM loja WHERE id_usuario_pai = " . $request->u));
            $s = "";
            foreach ($q as $k => $v) {
                $s .= $v->id_loja . ',';
            }
            $s = substr($s, 0, -1);

            if ($request->periodo == 'delete') {
                $q = DB::select(DB::raw("DELETE FROM facebook WHERE id_loja in (" . $s . ")"));
                return response()->json(['status' => 200]);;
            }

            if ($request->periodo == 'total') {
                $q = DB::select(DB::raw("SELECT *, DATE_FORMAT(horario,'%d/%m/%Y às %h:%i') as hr FROM facebook WHERE id_loja in (" . $s . ")"));
                return response()->json($q);
            }

            if ($request->periodo == 'hoje') {
                $q = DB::select(DB::raw("SELECT *, DATE_FORMAT(horario,'%d/%m/%Y às %h:%i') as hr FROM facebook WHERE DATE_FORMAT(horario, '%Y-%m-%d') = DATE_FORMAT(SYSDATE(), '%Y-%m-%d') AND id_loja in (" . $s . ")"));
                return response()->json($q);
            }

            return response()->json([]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }


}
