<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/tasks')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_can_log_in_and_out(): void
    {
        $u = User::factory()->create(['email' => 'a@b.test']);
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_shows_friendly_error(): void
    {
        User::factory()->create(['email' => 'a@b.test']);
        $this->from('/login')->post('/login', ['email' => 'a@b.test', 'password' => 'nope'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'a@b.test']);
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_first_registered_user_is_admin_then_members(): void
    {
        $this->post('/register', ['name' => 'First', 'email' => 'f@t.test', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $this->assertSame(UserRole::Admin, User::first()->role);
        $this->post('/logout');
        $this->post('/register', ['name' => 'Second', 'email' => 's@t.test', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $second = User::where('email', 's@t.test')->first();
        $this->assertSame(UserRole::Member, $second->role);
        $this->assertFalse($second->is_active); // waits for approval
        $this->assertGuest();
        $this->post('/login', ['email' => 's@t.test', 'password' => 'secret123'])->assertSessionHasErrors('email');
    }

    public function test_registration_validates_input(): void
    {
        $this->post('/register', ['name' => '', 'email' => 'bad', 'password' => 'short', 'password_confirmation' => 'x'])
            ->assertSessionHasErrors(['name', 'email', 'password']);
    }
}
