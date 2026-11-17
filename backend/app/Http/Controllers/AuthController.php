<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
 public function login(Request $request): JsonResponse {
  $data=$request->validate(['email'=>'required|email|max:254','password'=>'required|string|max:128']);
  $data['email']=Str::lower(trim($data['email']));
  $key='login:'.hash('sha256',$data['email'].'|'.$request->ip());
  if(RateLimiter::tooManyAttempts($key,5)) return response()->json(['message'=>'Too many attempts. Try again later.'],429)->header('Retry-After',(string)RateLimiter::availableIn($key));
  if(!Auth::attempt($data)) { RateLimiter::hit($key,900); throw ValidationException::withMessages(['email'=>['The email or password is incorrect.']]); }
  RateLimiter::clear($key); $request->session()->regenerate();
  return $this->me($request);
 }
 public function me(Request $request): JsonResponse { return response()->json(['user'=>$request->user()->only(['id','name','email','role'])]); }
 public function logout(Request $request): Response { Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); return response()->noContent(); }
}
