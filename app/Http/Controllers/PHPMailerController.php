<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PHPMailer\PHPMailer\PHPMailer;  
use PHPMailer\PHPMailer\Exception;
use DB;


class PHPMailerController extends Controller
{

   public function enviarEmailConfirmacao($host, $username, $password, $porta, $email, $id_loja, $hash, $codigopix, $pago = false) {
      require base_path("vendor/autoload.php");
   
      $mail = new PHPMailer(true);
   
      try {

            $objLoja = $this->getLoja($id_loja);
            $objCarrinho = $this->getCarrinho($hash);

            $data = [
               'produto_titulo' => $objCarrinho->titulo,
               'produto_valor' => floatval($objCarrinho->preco),
               'produto_imagem' => $objCarrinho->imagem1,
               'totalcarrinho' => (floatval($objCarrinho->preco) * floatval($objCarrinho->quantidade)) + floatval($objCarrinho->valor_orderbump),
               'nm_loja' => $objLoja->nm_loja,
               'img_loja' => $objLoja->img_loja,
               'email_loja' => $objLoja->email_loja,
               'codigoPix' => $codigopix,
               'nomeCliente' => $objCarrinho->nome_completo,
               'quantidade' => $objCarrinho->quantidade
            ];

            if(!is_null($objCarrinho->produto_orderbump) && $objCarrinho->valor_orderbump != 0){
               $objOrderBump = $this->getProdutoOrderBump($objCarrinho->produto_orderbump);
               $data['orderbump'] = true;
               $data['orderbump_produto'] = $objOrderBump->titulo; 
               $data['orderbump_preco'] = $objCarrinho->valor_orderbump;
               $data['orderbump_imagem'] = $objOrderBump->imagem1;
            }else{
               $data['orderbump'] = false;
            }

            $data['pago'] = $pago;


            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Port = $porta;
            $mail->setFrom($email, $objLoja->nm_loja); // Substitua 'Seu Nome' pelo nome do remetente
            $mail->addAddress($objCarrinho->email);
            $mail->isHTML(true);
            $mail->Subject = 'Confirmação de Pedido - ' . $objLoja->nm_loja;
            $mail->Body = view('email.confirmacaopedido')->with('data', $data)->render();
            $mail->CharSet = 'UTF-8';
   
            return $mail->send();
            
      } catch (Exception $e) {
            return false;
      }
   }

   public function atualizaStatus($hash){
      try {
         DB::select(DB::raw("UPDATE carrinho SET email_pedido = 's' WHERE hash = :hash "), ['hash' => $hash]);
         return true;
      } catch(\Exception $e){
         return false;
      }
   }

   public function atualizaStatusLembrete($hash){
      try {
         DB::select(DB::raw("UPDATE carrinho SET email_lembrete = 's' WHERE hash = :hash "), ['hash' => $hash]);
         return true;
      } catch(\Exception $e){
         return false;
      }
   }

   public function getLoja($id_loja){
      try {
         $q = DB::select(DB::raw("
            SELECT * 
            FROM loja
            WHERE id_loja = " . $id_loja . "
         "));

         return $q[0];
      } catch(\Exception $e){
         return false;
      }
   }

   public function getCarrinho($hash){
      try {
         $q = DB::select(DB::raw("
            SELECT *
            FROM carrinho c
            JOIN produto p ON c.id_produto = p.id_produto
            WHERE c.hash = " . $hash . "
         "));

         return $q[0];
      } catch(\Exception $e){

      }
   }

   public function getProdutoOrderBump($id_produto){
      try {
         $q = DB::select(DB::raw("
            SELECT *
            FROM produto
            WHERE id_produto = " . $id_produto . "
         "));

         return $q[0];

      } catch(\Exception $e){

      }
   }

   public function enviaLembretePagamento($host, $username, $password, $porta, $email, $id_loja, $hash, $codigopix) {
      require base_path("vendor/autoload.php");
   
      $mail = new PHPMailer(true);
   
      try {

            $objLoja = $this->getLoja($id_loja);
            $objCarrinho = $this->getCarrinho($hash);

            $data = [
               'produto_titulo' => $objCarrinho->titulo,
               'produto_valor' => floatval($objCarrinho->preco),
               'produto_imagem' => $objCarrinho->imagem1,
               'totalcarrinho' => (floatval($objCarrinho->preco) * floatval($objCarrinho->quantidade)) + floatval($objCarrinho->valor_orderbump),
               'nm_loja' => $objLoja->nm_loja,
               'img_loja' => $objLoja->img_loja,
               'email_loja' => $objLoja->email_loja,
               'codigoPix' => $codigopix,
               'nomeCliente' => $objCarrinho->nome_completo,
               'quantidade' => $objCarrinho->quantidade
            ];

            if(!is_null($objCarrinho->produto_orderbump)){
               $objOrderBump = $this->getProdutoOrderBump($objCarrinho->produto_orderbump);
               $data['orderbump'] = true;
               $data['orderbump_produto'] = $objOrderBump->titulo; 
               $data['orderbump_preco'] = $objCarrinho->valor_orderbump;
               $data['orderbump_imagem'] = $objOrderBump->imagem1;
            }else{
               $data['orderbump'] = false;
            }


            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Port = $porta;
            $mail->setFrom($email, $objLoja->nm_loja); // Substitua 'Seu Nome' pelo nome do remetente
            $mail->addAddress($objCarrinho->email);
            $mail->isHTML(true);
            $mail->Subject = 'Lembrete de Pagamento - ' . $objLoja->nm_loja;
            $mail->Body = view('email.lembretepagamento')->with('data', $data)->render();
            $mail->CharSet = 'UTF-8';
   
            return $mail->send();
            
      } catch (Exception $e) {
            return false;
      }
   }


}
