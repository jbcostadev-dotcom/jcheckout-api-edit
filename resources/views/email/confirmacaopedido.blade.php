<div>
    <table width="100%" cellpadding="0" cellspacing="0"
       style="font-family:Roboto,'Helvetica Neue',Helvetica,sans-serif">
       <tbody>
          <tr>
             <td align="center">
                <table width="600" align="center" cellpadding="0"
                   cellspacing="0">
                   <tbody>
                      <tr>
                         <td style="text-align:center">
                            <img
                               src="//{{$data['img_loja']}}"
                               width="130" class="CToWUd"
                               data-bit="iit">
                         </td>
                      </tr>
                      <tr>
                         <td width="600" cellpadding="0" cellspacing="0">
                            <table align="center" width="570"
                               cellpadding="0" cellspacing="0">
                               <tbody>
                                  <tr>
                                     <td>
                                        <h1>Oi, {{$data['nomeCliente']}}!</h1>
                                        @if(!$data['pago'])
                                        <p>
                                           O seu pedido foi
                                           reservado com sucesso.
                                           Para garantir o seu
                                           produto, <strong>efetue
                                           o pagamento do Pix</strong>
                                           o mais rápido possível,
                                           tá?
                                        </p>
                                        <p>
                                           1. Copie o código abaixo
                                        </p>
                                        <p
                                           style="padding:15px;background:#f5f5f5;border:2px
                                           dashed
                                           #666;font-size:11px">
                                           <a
                                              href="#m_8888339108945219824_m_1936670017613310613_"
                                              style="color:#333;text-decoration:none"><strong>{{$data['codigoPix']}}</strong></a>
                                        </p>
                                        <br>
                                        <p>
                                           2. Abra seu aplicativo
                                           de pagamento onde você
                                           utiliza o Pix, escolha a
                                           opção <strong>Pix Copia
                                           e Cola</strong> e
                                           insira o código copiado.
                                        </p>
                                        <p style="font-size:11px">
                                           <br>
                                           Seu Pix expira dia
                                           <strong><?php // Obtendo a data atual + 1 dia em formato UNIX timestamp
                                            $dataUnix = strtotime('+1 day');
                                            
                                            // Formatando a data e hora conforme o desejado
                                            $dataFormatada = date('d/m/Y \à\s\ H:i', $dataUnix); 
                                            echo $dataFormatada; ?></strong> A
                                           confirmação de pagamento
                                           é realizada em poucos
                                           minutos. Se você já
                                           realizou o pagamento,
                                           desconsidere esta
                                           mensagem.
                                           <br><br>
                                        </p>
                                        @elseif($data['pago'])
                                        <p>
                                          O seu pedido foi
                                          reservado com sucesso e já confirmamos o seu pagamento.
                                          <strong>Em breve você receberá o código de rastreio para acompanhar sua encomenda.</strong>
                                       </p>
                                        @endif
                                        <table width="100%"
                                           style="margin-top:20px">
                                           <thead>
                                              <tr>
                                                 <th colspan="2">
                                                    <h3>Resumo
                                                       da
                                                       compra
                                                    </h3>
                                                 </th>
                                              </tr>
                                           </thead>
                                           <tbody>
                                              <tr>
                                                 <td valign="top"
                                                    width="80">
                                                    <img
                                                       src="{{$data['produto_imagem']}}"
                                                       width="70"
                                                       class="CToWUd"
                                                       data-bit="iit">
                                                 </td>
                                                 <td valign="top"
                                                    style="padding:15px
                                                    0 0 10px">
                                                    <p>
                                                       {{$data['produto_titulo']}}
                                                    </p>
                                                    <p
                                                       style="line-height:0.5em">
                                                       <strong>
                                                        <span>R$ {{ str_replace('.', ',' , number_format($data['produto_valor'], 2) ) }} x {{$data['quantidade']}}</span>
                                                       </strong>
                                                    </p>
                                                 </td>
                                              </tr>
                                              @if($data['orderbump'])
                                              <tr>
                                                 <td valign="top"
                                                    width="80">
                                                    <img
                                                       src="{{$data['orderbump_imagem']}}"
                                                       width="70"
                                                       class="CToWUd"
                                                       data-bit="iit">
                                                 </td>
                                                 <td valign="top"
                                                    style="padding:15px
                                                    0 0 10px">
                                                    <p>
                                                       {{$data['orderbump_produto']}}
                                                    </p>
                                                    <p
                                                       style="line-height:0.5em">
                                                       <strong>
                                                        <span>R$ {{ str_replace('.', ',' , number_format($data['orderbump_preco'], 2) ) }} x 1</span>
                                                       </strong>
                                                    </p>
                                                 </td>
                                              </tr>
                                              @endif
                                           </tbody>
                                        </table>
                                        <table width="100%"
                                           style="margin-top:20px;background:#f8f8f8;padding:30px
                                           20px">
                                           <tbody>
                                              <tr>
                                                 <td
                                                    style="text-align:right;font-size:20px"
                                                    valign="top">Total</td>
                                                 <td
                                                    style="padding-left:10px"
                                                    valign="top">
                                                    <strong
                                                       style="font-size:20px">
                                                    1x de R$
                                                    {{ str_replace('.', ',' , number_format($data['totalcarrinho'], 2) ) }}
                                                    </strong>
                                                 </td>
                                              </tr>
                                           </tbody>
                                        </table>
                                     </td>
                                  </tr>
                               </tbody>
                            </table>
                         </td>
                      </tr>
                      <tr>
                         <td>
                            <table align="center" width="570"
                               cellpadding="0" cellspacing="0">
                               <tbody>
                                  <tr>
                                     <td align="center">
                                     </td>
                                  </tr>
                                  <tr>
                                     <td colspan="2">
                                        Contato:
                                     </td>
                                  </tr>
                                  <tr>
                                     <td colspan="2">
                                        <img
                                           src="https://ci4.googleusercontent.com/proxy/T_30Dcj4P74QHVr-xV1Rjd8312L5xcENvmtk7how8DQ2bafH0zLaAQfB6VIQTBZZet475xD9BimBDA=s0-d-e1-ft#https://icons.yampi.me/emails/email.png"
                                           alt="e-mail" width="14"
                                           height="12"
                                           class="CToWUd"
                                           data-bit="iit">
                                        <a
                                           href="mailto:{{$data['email_loja']}}"
                                           target="_blank">{{$data['email_loja']}}</a>
                                     </td>
                                  </tr>
                                  <tr height="15">
                                     <td colspan="2"></td>
                                  </tr>
                                  <tr>

                                  </tr>
                                  <tr>
                                     <td colspan="2">
                                        <hr>
                                     </td>
                                  </tr>
                                  <tr>
                                     <td colspan="2">
                                        {{$data['nm_loja']}}
                                     </td>
                                  </tr>
                               </tbody>
                            </table>
                         </td>
                      </tr>
                   </tbody>
                </table>
             </td>
          </tr>
       </tbody>
    </table>
    <div class="yj6qo"></div>
    <div class="adL">
    </div>
 </div>