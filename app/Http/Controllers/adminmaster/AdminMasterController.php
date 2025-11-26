<?php

namespace App\Http\Controllers\adminmaster;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;


class AdminMasterController extends Controller
{
    public function autenticaUsuario(Request $request)
    {
        if (Auth::attempt([
            'username' => 'root',
            'password' => $request->token
        ])) {
            return response()->json([
                'usuario' => 'root',
                'status' => 200
            ]);
        } else {
            return response()->json(['status' => 500]);
        }
    }

    public function dashboard()
    {
        $listaretorno = [
            'usuario' => Auth::user()->username
        ];
        return view('adminmaster.dashboard')->with('data', $listaretorno);
    }

    public function cadastraUsuario(Request $request)
    {
        $data = strtotime($request->dt_inicio_token);
        $datafim = date("Y-m-d", strtotime("+" . $request->dias . " days", $data));
        $token_checkout = Hash::make($request->usuario);

        $verifica = DB::select(DB::raw("SELECT * FROM usuario_pai WHERE usuario = '" . $request->usuario . "'"));

        if (!empty($verifica)) return response()->json(['status' => 500, 'mensagem' => 'Usuário já existente no checkout.']);

        try {
            DB::table('usuario_pai')->insert([
                'usuario' => $request->usuario,
                'senha' => Hash::make($request->senha),
                'dt_inicio_token' => $request->dt_inicio_token,
                'dt_fim_token' => $datafim,
                'qtd_sub_usuarios' => 1,
                'qtd_lojas' => $request->qtd_lojas,
                'token' => $token_checkout
            ]);
            $getLastId = DB::select(DB::raw("SELECT MAX(id_usuario_pai) as id FROM usuario_pai"));

            DB::table('users')->insert([
                'name' => $request->usuario,
                'email' => null,
                'username' => $request->usuario,
                'password' => Hash::make($request->senha),
                'tipo_usuario' => 'pai',
                'id_usuario' => $getLastId[0]->id,
                'token_checkout' => $token_checkout
            ]);

            return response()
                ->json([
                    'status' => 200,
                    'mensagem' => 'Sucesso! O usuário foi cadastrado.'
                ]);
        } catch (\Exception $e) {
            return response()
                ->json([
                    'status' => 500,
                    'mensagem' => 'Erro Interno.'
                ]);
        }
    }

    public function getUsuarios(Request $request)
    {
        try {
            $data = DB::select(DB::raw("
                SELECT *, DATE_FORMAT(dt_fim_token, '%d/%m/%Y') as dtfimtoken
                FROM usuario_pai
                WHERE sn_ativo = 's'
            "));
            return response()->json($data);
        } catch (\Exception $e) {
            return $e;
        }
    }
}
