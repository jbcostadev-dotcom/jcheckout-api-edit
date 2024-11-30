<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use DB;
use App\Models\Sanctum\PersonalAccessToken;
use App\Models\Sanctum\NewAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\HasApiTokens;

class ApiController extends Controller
{
    public function autenticaUsuario(Request $request)
    {
        if (Auth::attempt([
            'username' => $request->usuario,
            'password' => $request->senha
        ])) {
            $token = auth()->user()->createToken('Autenticado via login');
            $idusuario = auth()->user();

            $coresTema = DB::select(DB::raw("
                SELECT cor_tema as cor FROM usuario_preferencias WHERE id_usuario = :id_usuario
            "), ['id_usuario' => $idusuario->id_usuario]);
            

            $sqlVerificaToken = "
                SELECT dt_fim_token
                FROM usuario_" . $idusuario->tipo_usuario . "
                WHERE id_usuario_" . $idusuario->tipo_usuario . " = " . $idusuario->id_usuario . " 
            ";

            $verificaToken = DB::select(DB::raw($sqlVerificaToken));
            $fimToken = $verificaToken[0]->dt_fim_token;

            if(date('Y-m-d H:i:s') > $fimToken){
                return response()->json([
                    'status' => 400,
                    'mensagem' => 'Token vencido.'
                ]);
            }

            return response()->json([
                'api_token' => $token->plainTextToken,
                'token_checkout' => $idusuario->token_checkout,
                'tipo_usuario' => $idusuario->tipo_usuario,
                'id_usuario' => $idusuario->id_usuario,
                'usuario' => $idusuario->username,
                'suitpay' => ($idusuario->suitpay == 's' ? true : false),
                'cor_tema' => (empty($coresTema[0]->cor) ? null : $coresTema[0]->cor),
                'status' => 200,
                'mensagem' => 'Autenticado com sucesso.'
            ]);
        } else {
            return response()
                ->json([
                    'status' => 401,
                    'mensagem' => 'Não autorizado'
                ]);
        }
    }

    public function getStatusToken(Request $request)
    {
        $query = DB::select(
            DB::raw(
                "SELECT dt_inicio_token as inicio, dt_fim_token as fim 
            FROM usuario_pai
            WHERE token = :token"
            ),
            ['token' => $request->token]
        );

        if (empty($query)) {
            return response()->json([
                'status' => 200,
                'mensagem' => 'Não!!!'
            ]);
        }

        $inicio = strtotime($query[0]->inicio);
        $fim = strtotime($query[0]->fim);
        $dataatual = strtotime(date('Y-m-d H:i:s'));
        if ($fim > $dataatual) {
            return response()->json([
                'status' => 200,
                'status_token' => 'Ativo'
            ]);
        } else {
            session()->put('usuario_checkout', '');
            return response()->json([
                'status' => 401,
                'status_token' => 'Inativo'
            ]);
        }
    }

    public function verificaTokenApi(Request $request)
    {
        return response()->json([
            'status' => 200,
        ]);
    }

}
