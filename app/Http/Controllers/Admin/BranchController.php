<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Support\DefaultInventoryItems;
use App\Support\DefaultLaundryServices;
use App\Support\DefaultServiceInventoryUsages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $branches = Branch::query()
            ->when($user->role === 'branch_manager', fn ($query) => $query->whereKey($user->branch_id))
            ->withCount('users')
            ->latest()
            ->paginate(10);

        return view('admin.branches.index', [
            'branches' => $branches,
            'canCreateBranch' => $user->isSuperAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate($this->rules());

        DB::transaction(function () use ($request, $validated) {
            $branch = Branch::create($validated + ['is_active' => $request->boolean('is_active')]);

            DefaultLaundryServices::seedForBranch($branch);
            DefaultInventoryItems::seedForBranch($branch);
            DefaultServiceInventoryUsages::seedForBranch($branch);
            BranchSetting::firstOrCreate(
                ['branch_id' => $branch->id],
                [
                    'job_order_prefix' => $branch->code,
                    'invoice_prefix' => 'INV-'.$branch->code,
                ]
            );
        });

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch created successfully.');
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorizeBranch($branch);

        $validated = $request->validate($this->rules($branch));
        $validated['is_active'] = $request->boolean('is_active');

        $branch->update($validated);

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        if ($branch->users()->exists()) {
            return back()->with('error', 'Branch has users and cannot be deleted.');
        }

        $branch->delete();

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch deleted successfully.');
    }

    private function rules(?Branch $branch = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')->ignore($branch?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function authorizeBranch(Branch $branch): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() || $user->role === 'admin') {
            return;
        }

        abort_unless($user->role === 'branch_manager' && (int) $user->branch_id === (int) $branch->id, 403);
    }
}
