<?php
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
Route::view('/', 'stockroom');
Route::view('/workspace', 'stockroom');
Route::prefix('api')->group(function(){
 Route::get('/health',function(){ DB::select('SELECT 1'); return response()->json(['status'=>'healthy']); });
 Route::get('/csrf',fn()=>response()->json(['token'=>csrf_token()]));
 Route::post('/login',[AuthController::class,'login'])->middleware('throttle:20,1');
 Route::middleware('auth')->group(function(){
  Route::get('/me',[AuthController::class,'me']);
  Route::post('/logout',[AuthController::class,'logout']);
 });
});

require __DIR__.'/stockroom.php';

require __DIR__.'/extraction.php';
