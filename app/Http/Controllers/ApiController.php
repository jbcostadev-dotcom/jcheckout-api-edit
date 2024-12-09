<?php

namespace App\Http\Controllers;

use App\Models\Sanctum\NewAccessToken;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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

            if (date('Y-m-d H:i:s') > $fimToken) {
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

    public function inscreve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'usuario' => 'bail|required|string|max:50|unique:users,username',
            'senha' => 'bail|required|string|min:8|max:50',
        ], [
            'usuario.required' => 'Nome de usuário é obrigatório!',
            'usuario.max' => 'O nome de usuário não pode ter mais de 50 caracteres',
            'usuario.unique' => 'O nome de usuário deve ser único',
            'senha.required' => 'Senha é necessária!',
            'senha.min' => 'A senha deve ter pelo menos 8 caracteres!',
            'senha.max' => 'A senha não pode ter mais de 50 caracteres!',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $parentUserId = DB::table('usuario_pai')->insertGetId([
            'usuario' => $request->usuario,
            'senha' => Hash::make($request->senha),
            'dt_inicio_token' => now()->format('Y-m-d'),
            'dt_fim_token' => now()->addMonth()->format('Y-m-d'),
            'qtd_sub_usuarios' => 1,
            'qtd_lojas' => 1,
            'token' => Hash::make($request->senha),
        ]);

        DB::table('users')->insert([
            'name' => $request->usuario,
            'username' => $request->usuario,
            'password' => Hash::make($request->senha),
            'tipo_usuario' => 'pai',
            'id_usuario' => $parentUserId,
        ]);

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'status' => 201
        ], 201);
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

