<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('manage-users');
        $users = User::query()
            ->withCount(['tasks', 'assignedTasks'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->role))
            ->orderBy('name')->paginate(10)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->authorize('manage-users');

        return view('users.form', ['user' => new User(['role' => UserRole::Member, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'role' => ['required', Rule::in(UserRole::values())],
        ]);
        $user = User::create($data + ['is_active' => $request->boolean('is_active', true)]);
        ActivityLogger::record($request->user(), null, 'user_created', "Created user {$user->name} ({$user->role->label()})");

        return redirect()->route('users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(User $user)
    {
        $this->authorize('manage-users');

        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'role' => ['required', Rule::in(UserRole::values())],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        // Never lock the system out: you cannot demote or deactivate yourself.
        if ($user->id === $request->user()->id) {
            $data['role'] = $user->role->value;
            $data['is_active'] = true;
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        ActivityLogger::record($request->user(), null, 'user_updated', "Updated user {$user->name} ({$user->role->label()}, ".($user->is_active ? 'active' : 'deactivated').')');

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        $name = $user->name;
        $user->delete(); // their tasks are removed by cascade; assigned tasks become unassigned
        ActivityLogger::record($request->user(), null, 'user_deleted', "Deleted user {$name}");

        return redirect()->route('users.index')->with('success', "User {$name} deleted.");
    }
}
