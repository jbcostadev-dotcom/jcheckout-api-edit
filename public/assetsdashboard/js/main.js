$(document).ready(function(e){
    const _global = {
        registrarUsuario: ()=>{
            let validado;
    
            $("#btnsalva").click(function(e){
                const campos = ['reg_usuario', 'reg_senha', 'dias_add', 'reg_dtiniciotoken', 'reg_qtd_lojas'];
                let camposerro = [];
    
                $(campos).each((index,data)=>{
                    if($('#'+data).val() == ''){
                        validado = false;
                        camposerro.push($('#'+data).prev().text());
                    }
                })
                console.log(camposerro)
    
                if(validado != false || camposerro.length < 1){
                    $.ajax({
                        type : 'POST',
                        url : '/master/cadastraUsuario',
                        data : {
                            usuario : $("#reg_usuario").val(),
                            senha : $("#reg_senha").val(),
                            dt_inicio_token : $("#reg_dtiniciotoken").val(),
                            qtd_subusuarios : $("#reg_qtd_subusuarios").val(),
                            qtd_lojas : $("#reg_qtd_lojas").val(),
                            dias: $("#dias_add").val()
                        },             
                        success: (retorno)=>{
                            if(retorno.status == 200){
                                _global.toast(retorno.mensagem, 'sucesso')
                            }else{
                                _global.toast(retorno.mensagem, 'erro');
                            }
                        }
                    })
                }else{
                    let string = "Verifique os campos: ";
                    $(camposerro).each((index,data)=>{
                        string += data+', '
                    });

                    _global.toast(string, 'erro');
                }
            })            
        },
        toast(mensagem, tipo){
            const l = {
                sucesso: {
                    icone: 'icon-check-2',
                    tipo: 'success'
                },
                erro: {
                    icone: 'icon-simple-remove',
                    tipo: 'danger'
                }
            }

            $.notify({
                icon: "tim-icons " + l[tipo]['icone'],
                message: mensagem
          
              }, {
                type: l[tipo]['tipo'],
                timer: 8000,
                placement: {
                  from: 'top',
                  align: 'right'
                }
              });
        },
    }

    const init = () =>{
        _global.registrarUsuario();
    }

    init();
})