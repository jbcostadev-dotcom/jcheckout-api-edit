<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\Helper;

class UsuarioController extends Controller
{
    private $helper;

    public function __construct()
    {
        $this->helper = new Helper();
    }

    public function updateTema(Request $request){
        if(
            !$this->helper->verificaParametro($request->id_usuario)
           || !$this->helper->verificaParametro($request->cor)
           ){
            return response()->json([
                'status' => 500
            ]);
        }
        try {
            $verifica = DB::select(DB::raw('
                SELECT * FROM usuario_preferencias
                WHERE id_usuario = :id_usuario 
                '),[
                    'id_usuario' => $request->id_usuario
                ]);

            if(count($verifica) < 1){
                DB::table('usuario_preferencias')->insert([
                    'id_usuario' => $request->id_usuario,
                    'cor_tema' => $request->cor 
                ]);

                return response()->json(['status' => 200]);
            }

            DB::select(DB::raw("
                UPDATE usuario_preferencias
                SET cor_tema = :cor_tema
                WHERE id_usuario = :id_usuario
            "),[
                'id_usuario' => $request->id_usuario,
                'cor_tema' => $request->cor 
            ]);

            return response()->json(['status' => 200]);

        } catch(\Exception $e){
            return response()->json(['status' => 500]);

        }
    }
}
