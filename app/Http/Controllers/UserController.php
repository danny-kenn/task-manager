<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * People management.
 *   admin   : the single owner. Creates/edits/deactivates/deletes managers and members. Nobody can create another admin.
 *   manager : approves sign-ups and manages MEMBERS only (cannot touch managers or the admin, cannot change roles upward).
 */
class UserController extends Controller
{
    private function roleValues(Request $request): array
    {
        return array_map(fn (UserRole $r) => $r->value, $request->user()->assignableRoles());
    }

    public function index(Request $request)
    {
        $this->authorize('manage-users');
        $users = User::query()
            ->withCount(['tasks' => fn ($q) => $q->notPersonal(), 'assignedTasks' => fn ($q) => $q->notPersonal()])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->role))
            ->when($request->input('status') === 'pending', fn ($q) => $q->where('is_active', false)->whereNull('approved_at'))
            ->orderByRaw('(is_active = 0 and approved_at is null) desc')->orderBy('name')->paginate(10)->withQueryString();
        $pending = User::where('is_active', false)->whereNull('approved_at')->count();

        return view('users.index', compact('users', 'pending'));
    }

    public function create(Request $request)
    {
        $this->authorize('manage-users');

        return view('users.form', ['user' => new User(['role' => UserRole::Member, 'is_active' => true]), 'roles' => $request->user()->assignableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'role' => ['required', Rule::in($this->roleValues($request))],
        ]);
        $user = User::create($data + ['is_active' => $request->boolean('is_active', true), 'approved_at' => now()]);
        ActivityLogger::record($request->user(), null, 'user_created', "Created user {$user->name} ({$user->role->label()})");

        return redirect()->route('users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(Request $request, User $user)
    {
        $this->authorize('manage-user', $user);

        return view('users.form', ['user' => $user, 'roles' => $request->user()->assignableRoles()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-user', $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'role' => ['required', Rule::in($this->roleValues($request))],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        if ($data['is_active'] && ! $user->approved_at) {
            $data['approved_at'] = now();
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $oldRole = $user->role;
        $user->update($data);
        if ($oldRole !== $user->role) {
            app(Notifier::class)->send($user, 'account', 'Your role changed', "You are now a {$user->role->label()}.", route('dashboard'), $request->user());
        }
        ActivityLogger::record($request->user(), null, 'user_updated', "Updated user {$user->name} ({$user->role->label()}, ".($user->is_active ? 'active' : 'deactivated').')');

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    /** Let a self-registered person in. */
    public function approve(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-user', $user);
        $user->forceFill(['is_active' => true, 'approved_at' => now()])->save();
        app(Notifier::class)->send($user, 'account', 'Your account is approved', 'You can now use the task manager.', route('dashboard'), $request->user());
        ActivityLogger::record($request->user(), null, 'user_approved', "Approved {$user->name}");

        return back()->with('success', "{$user->name} can now sign in.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete-user', $user);
        $name = $user->name;
        // Work they created (anything assigned to someone else, or a manager's tasks) is handed to the admin.
        // Only a member's personal tasks disappear with them. Tasks assigned TO them become unassigned.
        $handover = \App\Models\Task::withTrashed()->where('user_id', $user->id)
            ->when($user->role === UserRole::Member, fn ($q) => $q->whereNotNull('assignee_id')->whereColumn('assignee_id', '!=', 'user_id'))
            ->update(['user_id' => $request->user()->id]);
        $user->delete();
        ActivityLogger::record($request->user(), null, 'user_deleted', "Deleted user {$name}".($handover ? " ({$handover} work tasks handed to you)" : ''));

        return redirect()->route('users.index')->with('success', "User {$name} deleted.");
    }
}
