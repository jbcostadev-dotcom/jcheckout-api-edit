<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Swift_SmtpTransport;
use Swift_Mailer;
use DB;
use Illuminate\Mail\Mailable;
use App\Http\Controllers\PHPMailerController;


class EmailController extends Controller
{
    public function verificaSmtp(Request $request)
    {

        $smtpHost = $request->smtp_host;
        $smtpPort = $request->smtp_porta;
        $smtpUsername = $request->smtp_email;
        $smtpPassword = $request->smtp_password;
        
        $transport = (new Swift_SmtpTransport($smtpHost, $smtpPort))
            ->setUsername($smtpUsername)
            ->setPassword($smtpPassword);

        $mailer = new Swift_Mailer($transport);

        try {
            $mailer->getTransport()->start();
            return response()->json(['status' => 200]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500]);
        }
    }

    public function emailConfirmacao($id_loja, $hash, $codigoPix, $pago = false)
    {
        try {

            $obj = $this->getCredenciais($id_loja);
            
            $smtpHost = $obj->smtp_host;
            $smtpPort = $obj->smtp_porta;
            $smtpUsername = $obj->smtp_email;
            $smtpPassword = $obj->smtp_password;
    
            $email = new PHPMailerController();
    
            if($email->enviarEmailConfirmacao($smtpHost, $smtpUsername, $smtpPassword, $smtpPort, $smtpUsername, $id_loja, $hash, $codigoPix, $pago)){
                $email->atualizaStatus($hash);
                return true;
            }else{
                return false;
            }
        
        } catch(\Exception $e){
            return false;
        }
    }

    public function getCredenciais($id_loja){
        try {
            $q = DB::select(DB::raw("
                SELECT *
                FROM smtp_loja
                WHERE id_loja = " . $id_loja . "
            "));

            return $q[0];
        } catch(\Exception $e){
            return $e;
        }

    }


    public function lembretePagamento($id_loja, $hash, $codigoPix)
    {
        try {

            $obj = $this->getCredenciais($id_loja);
            
            $smtpHost = $obj->smtp_host;
            $smtpPort = $obj->smtp_porta;
            $smtpUsername = $obj->smtp_email;
            $smtpPassword = $obj->smtp_password;
    
            $email = new PHPMailerController();
    
            if($email->enviaLembretePagamento($smtpHost, $smtpUsername, $smtpPassword, $smtpPort, $smtpUsername, $id_loja, $hash, $codigoPix)){
                $email->atualizaStatusLembrete($hash);
                return true;
            }else{
                return false;
            }
        
        } catch(\Exception $e){
            return false;
        }
    }

    
}
