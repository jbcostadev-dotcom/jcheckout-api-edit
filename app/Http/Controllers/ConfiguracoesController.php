<?php

namespace App\Http\Controllers;

use App\Models\Sanctum\NewAccessToken;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ConfiguracoesController extends Controller
{
    public function getUrlFront(){
        $query = DB::select(DB::raw("SELECT url FROM rota_frontend"));
        return $query[0]->url;
    }
    public function adicionarDominio(Request $request){
        try {
            if(!isset($request->token) || !isset($request->dominio) || !isset($request->tipo_dominio)){
                return response()->json([
                    'status' => 401,
                    'mensagem' => 'Houve um erro interno.'
                ]);
            }
            // Cadastro direto: remove qualquer verificação de conteúdo/DNS do domínio

            $queryVerificaUsuario = "SELECT qtd_dominio, tipo_usuario, id_usuario FROM users WHERE token_checkout = '" . $request->token . "'";
            $queryVerifica = DB::select(DB::raw($queryVerificaUsuario));

            // if(empty($queryVerifica)){
            //     return response()->json([
            //         'status' => 401
            //     ]);
            // }

            $queryVerificaDominio = "SELECT count(*) as cnt FROM dominio WHERE id_usuario = " . $queryVerifica[0]->id_usuario;
            $queryVerificaDominio = DB::select(DB::raw($queryVerificaDominio));

            if($queryVerificaDominio[0]->cnt >= $queryVerifica[0]->qtd_dominio){
                return response()->json([
                    'status' => 409,
                    'mensagem' => 'Você excedeu o seu limite de domínios'
                ]);
            }


            $sql = "SELECT sum(qry1.cnt) as loja,
                        sum(qry2.cnt) as checkout
                FROM
                (
                    SELECT count(*) as cnt
                    FROM dominio
                    WHERE dominio = '" . $request->dominio . "'
                    AND tipo_dominio = 'loja'
                )qry1,
                (
                    SELECT count(*) as cnt
                    FROM dominio
                    WHERE dominio = '" . $request->dominio . "'
                    AND tipo_dominio = 'checkout'
                )qry2";

            $verificaDominio2 = DB::select(DB::raw($sql));

            if($request->tipo_dominio == 'checkout' && $verificaDominio2[0]->checkout > 0){
                return response()->json([
                    'status' => 420,
                    'mensagem' => 'Você já tem um checkout associado à este domínio.'
                ]);
            }


            $requisicaoFront = Http::get(
                $this->getUrlFront()
                . 'dominio/'
                . $request->tipo_dominio
                . '/' . $request->dominio
                . '/' . $request->id_usuario
                . '?token=' . $request->token
                . '&url_api=' . request()->getHttpHost() . '/api/'
                . '&idloja=' . '33333'
            );

            // Não bloquear cadastro se a chamada ao frontend falhar;
            // mantém tentativa, mas segue com o cadastro no banco.

            DB::table('dominio')->insert([
                'dominio' => $request->dominio,
                'id_usuario' => $queryVerifica[0]->id_usuario,
                'tipo_usuario' => $queryVerifica[0]->tipo_usuario,
                'tipo_dominio' => $request->tipo_dominio,
                'id_loja' => ($request->tipo_dominio == 'checkout' ? null : $request->id_loja),
                'sn_ssl' => 'n',
                'adicionado_em' => date('Y-m-d H:i:s')
            ]);

            $lastId = DB::select(DB::raw('SELECT max(id_dominio) as id FROM dominio WHERE id_usuario = :id_usuario'),['id_usuario' => $queryVerifica[0]->id_usuario]);

            DB::table('log_dominio')->insert([
                'id_dominio' => $lastId[0]->id,
                'status' => 'Ativo',
                'atualizacao' => 'Domínio adicionado ao servidor',
                'dt_atualizacao' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'status' => 200
            ]);
        } catch (\Exception $e) {
            return $e;
            return response()->json([
                'status' => 401,
                'mensagem' => 'Houve um erro interno [500]'
            ]);
        }
    }

    /**
     * Verifica se o domínio está servindo a página index do checkout.
     * Tenta HTTP e HTTPS e procura por uma assinatura estável no HTML.
     */
    private function verificaIndexCheckout(string $dominio): bool
    {
        try {
            // Assinaturas com regex para aceitar variações de aspas e espaçamentos
            $assinaturasRegex = [
                '/<meta[^>]*a_hash\s*=\s*["\']h_checkout["\'][^>]*>/i',
                '/<meta[^>]*id\s*=\s*["\']token_check["\'][^>]*>/i',
                '/<meta[^>]*id\s*=\s*["\']url_api["\'][^>]*>/i',
                '/<meta[^>]*id\s*=\s*["\']lojaid["\'][^>]*>/i',
            ];

            $paths = [
                '',              // raiz
                '/',             // raiz explícita
                '/index.html',   // raiz explícita (html)
                '/index.php',    // raiz explícita (php)
            ];

            $schemes = ['http://', 'https://'];
            $urls = [];
            foreach ($schemes as $scheme) {
                foreach ($paths as $path) {
                    $urls[] = $scheme . $dominio . $path;
                }
            }

            foreach ($urls as $url) {
                try {
                    $resp = Http::timeout(10)
                        ->retry(2, 500)
                        ->withoutVerifying()
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; jcheckout-verifier/1.0)'
                        ])
                        ->withOptions([
                            'allow_redirects' => true,
                        ])
                        ->get($url);
                    if ($resp->successful()) {
                        $body = $resp->body();
                        foreach ($assinaturasRegex as $pattern) {
                            if (@preg_match($pattern, $body)) {
                                return true;
                            }
                        }
                    }
                } catch (\Throwable $t) {
                    // Ignora erros por URL e tenta a próxima (ex: TLS, redirecionamento inválido)
                    continue;
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }


    public function getLojas(Request $request)
    {
        try {
            $coluna = 'id_usuario_pai';

            $query = DB::select(DB::raw(
                'SELECT *
                 FROM loja
                 WHERE ' . $coluna . ' = :id_usuario'
            ),
                [
                    'id_usuario' => $request->id_usuario,
                ]
            );
            if(empty($query)){
                return response()->json(['status' => 401]);
            }
            return response()->json($query);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function getDominios(Request $request){
        try {
            if(empty($request->id_usuario) || !isset($request->id_usuario)){
                return response()->json([
                    'status' => 500
                ]);
            }

            $query = "
                SELECT d.*,
                       u.username as usuario,
                       DATE_FORMAT(adicionado_em, '%d/%m/%Y às %H:%i:%s') as dataformatada,
                       l.nm_loja,
                       d.tipo_dominio
                FROM dominio d
                LEFT JOIN users u ON d.id_usuario = u.id_usuario
                LEFT JOIN loja l ON d.id_loja = l.id_loja
                WHERE d.id_usuario = " . $request->id_usuario;

            $query = DB::select(DB::raw($query));
            if(empty($query)){
                return response()->json([
                    'status' => 409
                ]);
            }

            $resultado = [];

            foreach($query as $i => $valor){
                $resultado[] = $valor;
            }

            return response()->json($resultado);


        } catch (\Exception $e) {

        }
    }

    public function getLogDominio(Request $request){
        try{
            if(!isset($request->id_usuario) || empty($request->id_usuario)) return response()->json(['status' => 401]);

            $query = "SELECT lg.atualizacao,
                             DATE_FORMAT(lg.dt_atualizacao, '%d/%m/%Y %H:%i:%s') as dtatualizacao,
                             d.dominio
                      FROM log_dominio lg
                      JOIN dominio d on d.id_dominio = lg.id_dominio
                      WHERE d.id_usuario = " . $request->id_usuario . "
                        ORDER BY dtatualizacao asc
                      ";
            $query = DB::select(DB::raw($query));

            if(empty($query)){
                return response()->json([]);
            }
            $listaretorno = [];

            foreach($query as $key => $row){
                $listaretorno[$row->dominio][] = [
                    'data' => $row->dtatualizacao,
                    'atualizacao' => $row->atualizacao
                ];
            }

            return response()->json($listaretorno);

        } catch(\Exception $e){
            return $e;
            return response()->json([
                'status' => 401
            ]);
        }
    }

    public function updateLogDominio(Request $request, $tipo_log){
        if(!isset($request->id_dominio) || !isset($tipo_log)) return response()->json(['status' => 401]);
        try{
            DB::table('log_dominio')->insert([
                'id_dominio' => $request->id_dominio,
                'status' => 'Ativo',
                'atualizacao' => 'SSL Ativado ao domínio',
                'dt_atualizacao' => date('Y-m-d H:i:s')
            ]);

            DB::select(DB::raw("UPDATE dominio SET sn_ssl = 's' WHERE id_dominio=:id_dominio"),['id_dominio' => $request->id_dominio]);

        } catch (\Exception $e){
            return response()->json(['status' => 500]);
        }


    }

    public function apagarDominio(Request $request){
        if(!isset($request->id_dominio) || empty($request->id_dominio) || $request->id_dominio == 'undefined') return response()->json(['status' => 500]);

        try {
            $dadosDominio = DB::select(DB::raw("
                SELECT *
                FROM dominio
                WHERE id_dominio = :id_dominio

            "),['id_dominio' => $request->id_dominio]);


            if(empty($dadosDominio[0])) return response()->json(['status' => 501]);

            $query = "DELETE FROM dominio WHERE id_dominio = " . $request->id_dominio;
            DB::select(DB::raw($query));
            $query2 = "UPDATE loja SET dominio_padrao = null WHERE dominio_padrao = '" . $dadosDominio[0]->dominio . "'";
            DB::select(DB::raw($query2));
            return response()->json([
                'status' => 200,
                'dados' => $dadosDominio[0]
            ]);
        } catch(\Exception $e){
            return response()->json(['status' => 500]);
        }
    }
}
