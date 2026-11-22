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
use Illuminate\Support\Facades\Hash;
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

        $pending = User::where('email', $data['email'])->first();
        if ($pending && $pending->isPending() && Hash::check($data['password'], $pending->password)) {
            throw ValidationException::withMessages(['email' => 'Your account is waiting for approval. A manager or admin will let you in shortly.']);
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
        abort_if(config('tm.registration') === 'closed' && User::count() > 0, 404);

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $mode = config('tm.registration', 'approval');
        $first = User::count() === 0;
        abort_if($mode === 'closed' && ! $first, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        // The very first account on a fresh install becomes THE admin. Everyone after is a member, and (default)
        // must be approved by a manager/admin before they can sign in. Nobody can sign up as manager or admin.
        $role = $first ? UserRole::Admin : UserRole::Member;
        $active = $first || $mode === 'open';
        $colors = ['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14'];

        $user = User::create($data + ['role' => $role, 'is_active' => $active, 'approved_at' => $active ? now() : null, 'avatar_color' => $colors[array_rand($colors)]]);
        event(new Registered($user));
        $n = app(\App\Services\Notifier::class);
        $n->send($n->staff([$user->id], true), 'user_joined', $active ? "{$user->name} joined" : "{$user->name} is waiting for approval", "{$user->email} signed up as {$role->label()}", route('users.index', $active ? [] : ['status' => 'pending']), $user);
        ActivityLogger::record($user, null, 'registered', "{$user->name} created an account ({$role->label()})".($active ? '' : ', waiting for approval'));

        if (! $active) {
            return redirect()->route('login')->with('success', 'Account created. A manager or admin must approve it before you can sign in.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user);
        $request->session()->regenerate();

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
