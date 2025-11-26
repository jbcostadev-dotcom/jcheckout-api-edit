<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\Helper;
use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Http;

class WhatsappController extends Controller
{
    public function enviaMensagem($hash, $tipoMensagem, $codigoPix = "", $rastreio = ""){
        $helper = new Helper();
        $pagamento = new CheckoutController;

        $queryCarrinho = $helper->query("
            SELECT c.*,
                   l.nm_loja,
                   p.titulo
            FROM carrinho c
            LEFT JOIN produto p ON c.id_produto = p.id_produto 
            LEFT JOIN loja l ON c.id_loja = l.id_loja
            WHERE c.hash = '" . $hash . "'
        ");

        $queryCredenciais = $helper->query("
            SELECT instance_id, instance_token, rastreio_padrao, token_seguranca
            FROM whatsapp_loja
            WHERE id_loja = " . $queryCarrinho[0]->id_loja . "
        ");

        if(empty($queryCredenciais)){
            return false;
        }

        $instanceId = $queryCredenciais[0]->instance_id;
        $instanceToken = $queryCredenciais[0]->instance_token;
        $tokenSeguranca = $queryCredenciais[0]->token_seguranca;

        if($tipoMensagem == 'pedido'){

            $msg1 = Http::withHeaders(['Client-Token' => $tokenSeguranca])->post('https://api.z-api.io/instances/' . $instanceId . '/token/' . $instanceToken . '/send-text',[
                'phone' => $queryCarrinho[0]->telefone,
                'message' => "
Olá " . $queryCarrinho[0]->nome_completo . ", parabéns você fez uma ótima compra! 
                
O produto " . $queryCarrinho[0]->titulo . " é excelente! 😊
                
🚚 O seu pedido já foi recebido e está sendo preparado para ser enviado. 
                
⚠ Atenção: Se você já fez o pagamento do Pix, basta aguardar a nossa confirmação.
                
🕗 Realize o pagamento com Pix dentro de 30 minutos para que o pedido não seja cancelado.
                
👉 Pague com o código copia e cola da mensagem abaixo.
                
📱 Escolha a opção PIX COPIA E COLA, depois basta colar o código do Pix no campo do seu aplicativo.
                
Att " . $queryCarrinho[0]->nm_loja . "   
                "
            ]);
            $msg2 = Http::withHeaders(['Client-Token' => $tokenSeguranca])->post('https://api.z-api.io/instances/' . $instanceId . '/token/' . $instanceToken . '/send-text',[
                'phone' => $queryCarrinho[0]->telefone,
                'message' => $codigoPix
            ]);

            
            if($msg1->status() == 200 && $msg2->status() == 200){
                return true;
            }else{
                false;
            }
        }else if($tipoMensagem == 'aprovado'){
            $msg1 = Http::withHeaders(['Client-Token' => $tokenSeguranca])->post('https://api.z-api.io/instances/' . $instanceId . '/token/' . $instanceToken . '/send-text',[
                'phone' => $queryCarrinho[0]->telefone,
                'message' => "
Olá " . $queryCarrinho[0]->nome_completo . ", temos boas notícias! 
                
O pagamento do produto " . $queryCarrinho[0]->titulo . " foi confirmado! 😊
                
✔️ Recebemos a confirmação do seu pagamento.

🚚 Estamos preparando a sua encomenda, em breve você receberá um código de rastreio para rastrear a sua encomenda! 
                
Att " . $queryCarrinho[0]->nm_loja . "   
                "
            ]);

            $helper->query("UPDATE carrinho SET whatsapp_pgtoaprovado = 's' WHERE hash = '" . $hash . "'");

            return response()->json(['status' => 200]);
        }else if($tipoMensagem == 'rastreio'){
            if($rastreio == 'false' || is_null($rastreio)){
                $rastreio = $queryCredenciais[0]->rastreio_padrao;
                if(is_null($rastreio)){
                    $rastreio = 'THAGI' . rand(90000,99999) . 'PSGBR';
                }
            }else{
                $rastreio = $rastreio;
            }
            $msg1 = Http::withHeaders(['Client-Token' => $tokenSeguranca])->post('https://api.z-api.io/instances/' . $instanceId . '/token/' . $instanceToken . '/send-text',[
                'phone' => $queryCarrinho[0]->telefone,
                'message' => "
Olá " . $queryCarrinho[0]->nome_completo . ", temos boas notícias! 
                
A sua encomenda do produto " . $queryCarrinho[0]->titulo . " foi enviada! 😊
                
🚚 Você escolheu o frete: " . $queryCarrinho[0]->frete_selecionado . "

🚚 Segue abaixo o seu código de rastreio ⬇️

✔️ " . $rastreio . "

Att " . $queryCarrinho[0]->nm_loja . "   
                "
            ]);

            $helper->query("UPDATE carrinho SET whatsapp_rastreio = 's' WHERE hash = '" . $hash . "'");
            
            return response()->json(['status' => 200]);
        }
        
        return response()->json(['status' => 404]);

    }

    public function atualizaStatus($hash, $coluna){
        try {
            $helper = new Helper();

            $helper->query("
                UPDATE carrinho SET " . $coluna . " = 's'
                WHERE hash = '" . $hash . "'
            ");
            return true;
        } catch(\Exception $e){
            return false;
        }
    }
}
