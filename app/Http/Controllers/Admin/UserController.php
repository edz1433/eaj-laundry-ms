<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('branch')
            ->visibleTo(auth()->user())
            ->when(auth()->user()->role === 'branch_manager', fn ($query) => $query->where('branch_id', auth()->user()->branch_id))
            ->latest()
            ->paginate(10);
        $branches = Branch::where('is_active', true)
            ->when(auth()->user()->role === 'branch_manager', fn ($query) => $query->whereKey(auth()->user()->branch_id))
            ->orderBy('name')
            ->get();
        $menuItems = Menu::items();
        $roles = $this->availableRoles();

        return view('admin.users.index', compact('users', 'branches', 'menuItems', 'roles'));
    }

    public function create()
    {
        $branches = Branch::where('is_active', true)->get();

        return view('admin.users.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', Rule::in([
                'super_admin',
                'admin',
                'branch_manager',
                'cashier',
                'staff',
            ])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'access' => ['nullable', 'array'],
            'access.*' => ['string', Rule::in(Menu::keys())],
        ]);

        if (! auth()->user()->isSuperAdmin() && $validated['role'] === 'super_admin') {
            abort(403);
        }

        if (auth()->user()->role === 'branch_manager') {
            $validated['branch_id'] = auth()->user()->branch_id;
        }

        $validated['access'] = $this->normalizedAccess($validated['role'], $validated['access'] ?? []);

        User::create($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User account created successfully.');
    }

    public function show(User $user)
    {
        $this->authorizeUserAccess($user);

        $user->load('branch');

        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $this->authorizeUserAccess($user);

        $branches = Branch::where('is_active', true)->get();

        return view('admin.users.edit', compact('user', 'branches'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeUserAccess($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'role' => ['required', Rule::in([
                'super_admin',
                'admin',
                'branch_manager',
                'cashier',
                'staff',
            ])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'access' => ['nullable', 'array'],
            'access.*' => ['string', Rule::in(Menu::keys())],
        ]);

        if (! auth()->user()->isSuperAdmin() && ($validated['role'] === 'super_admin' || $user->isSuperAdmin())) {
            abort(403);
        }

        if (auth()->user()->role === 'branch_manager') {
            abort_unless((int) $user->branch_id === (int) auth()->user()->branch_id, 403);
            $validated['branch_id'] = auth()->user()->branch_id;
        }

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $validated['access'] = $this->normalizedAccess($validated['role'], $validated['access'] ?? []);

        $user->update($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User account updated successfully.');
    }

    public function destroy(User $user)
    {
        $this->authorizeUserAccess($user);

        if (auth()->id() === $user->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Super admin accounts cannot be deleted here.');
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User account deleted successfully.');
    }

    private function availableRoles(): array
    {
        $roles = ['admin', 'branch_manager', 'cashier', 'staff'];

        if (auth()->user()->isSuperAdmin()) {
            array_unshift($roles, 'super_admin');
        }

        return $roles;
    }

    private function normalizedAccess(string $role, array $access): array
    {
        if ($role === 'super_admin') {
            return Menu::keys();
        }

        return array_values(array_intersect($access, Menu::keys()));
    }

    private function authorizeUserAccess(User $user): void
    {
        if (! auth()->user()->isSuperAdmin() && $user->isSuperAdmin()) {
            abort(403);
        }

        if (auth()->user()->role === 'branch_manager' && (int) $user->branch_id !== (int) auth()->user()->branch_id) {
            abort(403);
        }
    }
}
