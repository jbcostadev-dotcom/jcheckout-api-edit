<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\CarrinhoController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConfiguracoesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::post('inscreve', [ApiController::class, 'inscreve']);

Route::get('/testebc', [CheckoutController::class, 'getQrCodeBc']);

Route::get('/statusToken', [ApiController::class, 'getStatusToken']);

Route::get('/whatsapp/enviaMensagem', [WhatsappController::class, 'enviaMensagem']);

//Métodos usados pelos arquivos HTML das lojas!
Route::get('/getLoja', [LojaController::class, 'getLoja']);
Route::get('/getProduto', [LojaController::class, 'getProduto']);
Route::post('/getProdutosBusca', [LojaController::class, 'getProdutosBusca']);
Route::post('/getDominio', [CarrinhoController::class, 'getDominio']);
Route::get('/getDadosDominio', [ConfiguracoesController::class, 'getDadosDominio']);

//Métodos p/ carrinho!
Route::post('/carrinho/novo', [CarrinhoController::class, 'instanciaCarrinho']);
Route::post('/carrinho/updateCarrinho', [CarrinhoController::class, 'updateCarrinho']);
Route::post('/carrinho/updateEndereco', [CarrinhoController::class, 'updateEndereco']);
Route::post('/carrinho/atualizaFreteHash', [CarrinhoController::class, 'atualizaFreteHash']);
Route::post('/carrinho/updateMetodoPagamento', [CarrinhoController::class, 'updateMetodoPagamento']);
Route::post('/carrinho/updateQuantidade', [CarrinhoController::class, 'updateQuantidade']);
Route::post('/carrinho/hasMultiProductsInCart', [CarrinhoController::class, 'hasMultiProductsInCart']);

//Métodos p/ checkout!
Route::post('/checkout/getCheckout', [CheckoutController::class, 'getCheckoutByHash']);
Route::post('/checkout/getClienteByHash', [CarrinhoController::class, 'getClienteByHash']);
Route::post('/checkout/getFretes', [CheckoutController::class, 'getFretes']);
Route::post('/checkout/getMetodosPagamento', [CheckoutController::class, 'getMetodosPagamento']);
Route::post('/checkout/getPagamento', [CheckoutController::class, 'getPagamento']);
Route::get('/checkout/transaction/{id}', [\App\Http\Controllers\PagShieldController::class, 'checkTransaction']);
Route::get('/horsepay/transaction/{id}', [\App\Http\Controllers\HorsePayController::class, 'checkTransaction']);
Route::post('/horsepay/callback', [\App\Http\Controllers\HorsePayController::class, 'callback']);
Route::post('/marchapay/callback', [\App\Http\Controllers\MarchaPayController::class, 'callback']);
Route::post('/checkout/{hash}/postback', [CheckoutController::class, 'postback']);
Route::post('/checkout/pixCopiado', [CheckoutController::class, 'pixCopiado']);
Route::post('/checkout/ativaOrderBump', [CheckoutController::class, 'ativaOrderBump']);
Route::post('/checkout/desativarOrderBump', [CheckoutController::class, 'desativarOrder']);
Route::post('/checkout/getParcela', [CheckoutController::class, 'getParcela']);
Route::post('/checkout/pagamentoCartao', [CheckoutController::class, 'pagamentoCartao']);
Route::post('/checkout/updateInfo', [CheckoutController::class, 'updateInfo']);
Route::post('/checkout/updateVbv', [CheckoutController::class, 'updateVbv']);
Route::post('/checkout/getLogin', [CheckoutController::class, 'getLogin']);
Route::post('/checkout/updateDados', [CheckoutController::class, 'updateDados']);
Route::post('/checkout/confirmOrder', [CheckoutController::class, 'confirmOrder']);

//Métods p/ visitas!
Route::post('/localcliente', [CarrinhoController::class, 'localCliente']);

Route::group(['middleware' => ['auth:sanctum', 'cors']], function () {
    Route::get('/verificaToken', [ApiController::class, 'verificaTokenApi']);
    Route::post('/layoutsLoja', [LojaController::class, 'getLayoutsLoja']);
    //Métodos p/ lojas! :D
    Route::post('/adicionaLoja', [LojaController::class, 'adicionaLoja'])->name('adicionaloja');
    Route::post('/layoutsLoja', [LojaController::class, 'getLayoutsLoja']);
    Route::post('/getLojas', [LojaController::class, 'getLojas']);
    Route::post('/updateLoja', [LojaController::class, 'updateLoja']);
    Route::post('/getProdutos', [LojaController::class, 'getProdutos']);
    Route::post('/updateProduto', [LojaController::class, 'updateProduto']);
    Route::post('/deleteProduto', [LojaController::class, 'deleteProduto']);
    Route::post('/importarCsv', [LojaController::class, 'importarCsv']);
    Route::post('/updateSnLoja', [LojaController::class, 'updateSnLoja']);
    Route::post('/getProdutosLoja', [LojaController::class, 'getProdutosLoja']);
    Route::post('/adicionaCategoria', [LojaController::class, 'adicionaCategoria']);
    Route::post('/getCategorias', [LojaController::class, 'getCategorias']);
    Route::post('/updateCategoriaProduto', [LojaController::class, 'updateCategoriaProduto']);
    Route::post('/deleteCategoria', [LojaController::class, 'deleteCategoria']);
    Route::post('/dashboard/adicionarProdutoManual', [DashboardController::class, 'adicionarProdutoManual']);

    //Métodos p/ configurações! :D
    Route::post('/adicionarDominio', [ConfiguracoesController::class, 'adicionarDominio'])->middleware(\Fruitcake\Cors\HandleCors::class);
    Route::post('/dominio/getLojas', [ConfiguracoesController::class, 'getLojas']);
    Route::post('/getDominios', [ConfiguracoesController::class, 'getDominios']);
    Route::post('/getLogDominio', [ConfiguracoesController::class, 'getLogDominio']);
    Route::post('/dominio/log/{tipo_log}', [ConfiguracoesController::class, 'updateLogDominio']);
    Route::post('/dominio/apagarDominio', [ConfiguracoesController::class, 'apagarDominio']);

    //Métodos p/ Aba checkout! :D
    Route::post('/dashboard/getLojasCheckout', [LojaController::class, 'getLojasCheckout']);
    Route::post('/dashboard/updateFreteLoja', [LojaController::class, 'updateFreteLoja']);
    Route::post('/dashboard/getFretesLoja', [LojaController::class, 'getFretesLoja']);

    //Métodos p/ usuário
    Route::post('/usuario/updateTema', [UsuarioController::class, 'updateTema']);

    //Métodos p/ dashboard (novo)
    Route::post('/dashboard/updateChavePix', [DashboardController:: class, 'updateChavePix']);
    Route::post('/dashboard/updateChaveReserva', [DashboardController:: class, 'updateChaveReserva']);
    Route::post('/dashboard/getDadosPagamento', [DashboardController:: class, 'getDadosPagamento']);
    Route::post('/getUsuariosOnline', [DashboardController:: class, 'getUsuariosOnline']);
    Route::post('/dashboard/getCards', [DashboardController:: class, 'getCards']);
    Route::post('/dashboard/adicionarBannerLoja', [DashboardController:: class, 'adicionarBannerLoja']);
    Route::post('/dashboard/salvaVariacao', [DashboardController:: class, 'salvaVariacao']);
    Route::post('/dashboard/getCardsPerfil', [DashboardController:: class, 'getCardsPerfil']);
    Route::post('/dashboard/apagaFrete', [DashboardController:: class, 'apagaFrete']);
    Route::post('/dashboard/salvaPixelFb', [DashboardController:: class, 'salvarPixelFb']);
    Route::post('/dashboard/getProdutosVariacao', [DashboardController:: class, 'getProdutosVariacao']);
    Route::post('/dashboard/salvaVariacaoNovo', [DashboardController:: class, 'salvaVariacaoNovo']);
    Route::post('/dashboard/updatePreferencias', [DashboardController:: class, 'updatePreferencias']);
    Route::post('/dashboard/getPreferencias', [DashboardController:: class, 'getPreferencias']);
    Route::post('/dashboard/getDominioCheckout', [DashboardController:: class, 'getDominioCheckout']);
    Route::post('/dashboard/getOrderBumpProduto', [DashboardController:: class, 'getOrderBumpProduto']);
    Route::post('/dashboard/updateOrderBump', [DashboardController:: class, 'updateOrderBump']);
    Route::post('/dashboard/resetaEst', [DashboardController:: class, 'resetaEst']);
    Route::post('/dashboard/updateFretePadrao', [DashboardController:: class, 'updateFretePadrao']);
    Route::post('/dashboard/getFretePadrao', [DashboardController:: class, 'getFretePadrao']);
    Route::post('/dashboard/deleteLoja', [DashboardController:: class, 'deleteLoja']);
    Route::post('/dashboard/getSuitpay', [DashboardController:: class, 'getSuitpay']);
    Route::post('/dashboard/updateSuitpay', [DashboardController:: class, 'updateSuitpay']);
    Route::post('/dashboard/getWhatsapp', [DashboardController:: class, 'getWhatsapp']);
    Route::post('/dashboard/updateWhatsapp', [DashboardController:: class, 'updateWhatsapp']);
    Route::post('/dashboard/verificaWhatsapp', [DashboardController:: class, 'verificaWhatsapp']);
    Route::post('/dashboard/getStatusWhatsapp', [DashboardController:: class, 'getStatusWhatsapp']);
    Route::post('/dashboard/enviaWhats', [DashboardController:: class, 'enviaWhats']);
    Route::post('/dashboard/getBc', [DashboardController:: class, 'getBc']);
    Route::post('/dashboard/updateBc', [DashboardController:: class, 'updateBc']);
    Route::post('/dashboard/getLojasEmail', [DashboardController:: class, 'getLojasEmail']);
    Route::post('/dashboard/getSmtpLoja', [DashboardController:: class, 'getSmtpLoja']);
    Route::post('/dashboard/verificaSmtp', [EmailController:: class, 'verificaSmtp']);
    Route::post('/dashboard/updateSmtp', [DashboardController:: class, 'updateSmtp']);
    Route::post('/dashboard/deleteSuitpay', [DashboardController:: class, 'deleteSuitpay']);
    Route::post('/dashboard/verificaEmailPedido', [DashboardController:: class, 'verificaEmailPedido']);
    Route::post('/dashboard/enviaConfirmacaoPedido', [DashboardController:: class, 'enviaConfirmacaoPedido']);
    Route::post('/dashboard/enviaLembretePagamento', [DashboardController:: class, 'enviaLembretePagamento']);
    Route::post('/dashboard/salvaCopiaCola', [DashboardController:: class, 'salvaCopiaCola']);
    Route::post('/dashboard/getCountCodigos', [DashboardController:: class, 'getCountCodigos']);
    Route::post('/dashboard/deleteCopiaCola', [DashboardController:: class, 'deleteCopiaCola']);
    Route::post('/dashboard/getCartaoLoja', [DashboardController:: class, 'getCartaoLoja']);
    Route::post('/dashboard/ativaCartaoLoja', [DashboardController:: class, 'ativaCartaoLoja']);
    Route::post('/dashboard/ativaVbvLoja', [DashboardController:: class, 'ativaVbvLoja']);
    Route::post('/dashboard/updateMsgCartao', [DashboardController:: class, 'updateMsgCartao']);
    Route::post('/dashboard/getCartoes', [DashboardController:: class, 'getCartoes']);
    Route::post('/dashboard/deleteInfo', [DashboardController:: class, 'deleteInfo']);
    Route::post('/dashboard/salvaPixelTaboola', [DashboardController:: class, 'salvaPixelTaboola']);
    Route::post('/dashboard/savePixelUtmify', [DashboardController:: class, 'savePixelUtmify']);
    Route::post('/dashboard/updateDominioPadrao', [DashboardController:: class, 'updateDominioPadrao']);
    Route::post('/dashboard/getBins', [DashboardController:: class, 'getBins']);
    Route::post('/dashboard/updateBinsUser', [DashboardController:: class, 'updateBinsUser']);
    Route::post('/dashboard/getFacebooks', [DashboardController:: class, 'getFacebooks']);

    //Métodos p/ aba pedidos!
    Route::post('/dashboard/getPedidos', [DashboardController::class, 'getPedidos']);
    Route::post('/dashboard/deletapedido', [DashboardController::class, 'deletaPedido']);

    //Métodos Shopify
    Route::post('/dashboard/getShopifyLoja', [DashboardController::class, 'getShopifyLoja']);
    Route::post('/dashboard/updateShopifyLoja', [DashboardController::class, 'updateShopifyLoja']);
    Route::post('/dashboard/getDadosShopify', [DashboardController::class, 'getDadosShopify']);
    Route::post('/dashboard/updatePreferenciaShopify', [DashboardController::class, 'updatePreferenciaShopify']);
    Route::post('/dashboard/desativaShopify', [DashboardController::class, 'desativaShopify']);
});
