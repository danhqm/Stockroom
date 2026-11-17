<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
class DatabaseSeeder extends Seeder {
 public function run(): void {
  if(!app()->environment('local','testing')) throw new \RuntimeException('Demo seeding is local-only.');
  foreach(['manager','staff'] as $role){
   $email=$role.'@stockroom.test';
   if(User::where('email',$email)->exists()) continue;
   $password=config('stockroom.'.$role.'_password');
   if(!is_string($password)||strlen($password)<12) throw new \RuntimeException('Set a demo password of at least 12 characters for '.$role.'.');
   $user=new User; $user->name=ucfirst($role); $user->email=$email; $user->password=Hash::make($password); $user->role=$role; $user->save();
  }
 }
}
