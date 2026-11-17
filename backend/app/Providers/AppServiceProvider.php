<?php
namespace App\Providers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider {
 public function register(): void {}
 public function boot(): void {
  Gate::define('manage-stockroom',fn(User $user)=>$user->role==='manager');
  Gate::define('receive-stock',fn(User $user)=>in_array($user->role,['manager','staff'],true));
 }
}
