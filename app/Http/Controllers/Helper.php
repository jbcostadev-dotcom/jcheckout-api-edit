<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
class Helper extends Controller
{
    public function verificaParametro($parametro){
        if(
            is_null($parametro)
            || !isset($parametro)
            || $parametro == 'undefined' 
        ) return false;
        
        return true;
    }

    public function query($query = "", $lista = []){
        try {  
            $q = DB::select(DB::raw(
                $query
            ), $lista );

            return $q;
        } catch (\Exception $e) {
            return $e;
        }
    }


    // public function query($query = ""){
    //     if($query == "" || empty($query) || is_null($query)){
    //         return false;
    //     }
        
    //     try{
    //         $conexao = DB::select( DB::raw ( $query ) );
    //         return response()->json($conexao);
    //     } catch(\Exception $e){
    //         return $e;
    //     }
    // }
}
