<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AuthTest extends TestCase {
 use RefreshDatabase;
 public function test_guest_cannot_read_session(): void { $this->getJson('/api/me')->assertUnauthorized(); }
 public function test_csrf_endpoint_returns_token(): void { $this->getJson('/api/csrf')->assertOk()->assertJsonStructure(['token']); }
 public function test_login_and_logout(): void {
  $user=User::factory()->create(['password'=>'Valid-demo-pass1!']);
  $this->postJson('/api/login',['email'=>$user->email,'password'=>'Valid-demo-pass1!'])->assertOk()->assertJsonPath('user.role','staff');
  $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id',$user->id);
  $this->postJson('/api/logout')->assertNoContent();
  $this->getJson('/api/me')->assertUnauthorized();
 }
 public function test_invalid_password_is_rejected(): void {
  $user=User::factory()->create();
  $this->postJson('/api/login',['email'=>$user->email,'password'=>'wrong'])->assertUnprocessable();
  $this->assertGuest();
 }
 public function test_login_attempts_are_limited(): void {
  for($i=0;$i<5;$i++) $this->postJson('/api/login',['email'=>'missing@example.test','password'=>'wrong'])->assertUnprocessable();
  $this->postJson('/api/login',['email'=>'missing@example.test','password'=>'wrong'])->assertStatus(429);
 }
 public function test_health_checks_database(): void { $this->getJson('/api/health')->assertOk()->assertJsonPath('status','healthy'); }
 public function test_roles_limit_management_but_allow_receiving(): void {
  $staff=User::factory()->create();
  $manager=User::factory()->create(); $manager->role='manager'; $manager->save();
  $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($staff)->allows('manage-stockroom'));
  $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($manager)->allows('manage-stockroom'));
  $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($staff)->allows('receive-stock'));
  $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($manager)->allows('receive-stock'));
 }
 public function test_public_signup_is_unavailable(): void { $this->postJson('/api/register',[])->assertNotFound(); }
}
