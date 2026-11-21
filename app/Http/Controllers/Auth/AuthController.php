<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttle = Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $secs = RateLimiter::availableIn($throttle);
            throw ValidationException::withMessages(['email' => "Too many attempts. Try again in {$secs} seconds."]);
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttle, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records, or the account is deactivated.']);
        }

        RateLimiter::clear($throttle);
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLogger::record($user, null, 'login', "{$user->name} signed in");

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        // The very first account on a fresh install becomes the administrator; everyone after is a member.
        $role = User::count() === 0 ? UserRole::Admin : UserRole::Member;
        $colors = ['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14'];

        $user = User::create($data + ['role' => $role, 'avatar_color' => $colors[array_rand($colors)], 'last_login_at' => now()]);
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        ActivityLogger::record($user, null, 'registered', "{$user->name} created an account ({$role->label()})");

        return redirect()->route('dashboard')->with('success', "Welcome, {$user->name}! You are signed in as {$role->label()}.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}
