<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\adminmaster\AdminMasterController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PHPMailerController;
use App\Http\Controllers\EmailController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/api/autenticaUsuario', [ApiController::class, 'autenticaUsuario'])->name('api-autenticaUsuario');
Route::post('/api/dashboard/alterarSenha', [DashboardController::class, 'alterarSenha']);

Route::get('/api/easypix/updateApiKeyLoja', [DashboardController::class, 'updateApiKeyLoja']);
Route::get('/api/easypix/deleteApiKeyLoja', [DashboardController::class, 'deleteApiKeyLoja']);

Route::get('/testeemail', function(){
  return view('email.confirmacaopedido');
});
Route::get('/enviaemail', [EmailController::class, 'enviarEmailHtml']);
Route::get('/', function () {
    return response()->json([
      'status' => 401,
      ':D' => 'Busco un curro de verdad me dijo esa puta. Cuando vio lo que gabana dijo: SOY TU PUTA!'
    ]);
  });


  Route::get('/testecsv', [LojaController::class, 'testecsv'])->name('testecsv');


Route::get('/master/login', function(){
    return view('adminmaster.login');
})->name('masterlogin');

Route::post('/master/autenticausuario', [AdminMasterController::class, 'autenticaUsuario']);


Route::group(['middleware' => 'auth'], function () {
  Route::get('/master/dashboard', [AdminMasterController::class, 'dashboard'])->name('viewdashboard');
  Route::post('/master/cadastraUsuario', [AdminMasterController::class, 'cadastraUsuario'])->name('cadastraUsuario');
  Route::get('/master/getUsuarios', [AdminMasterController::class, 'getUsuarios'])->name('getUsuarios');

  Route::get('/master/registrar',function () {
    return view('adminmaster.registrarusuario');
  })->name('registrarusuario');
});
