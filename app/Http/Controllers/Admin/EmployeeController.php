<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $employees = User::query()
            ->with('branch')
            ->visibleTo($user)
            ->whereIn('role', ['admin', 'branch_manager', 'cashier', 'staff'])
            ->when($user->role === 'branch_manager', fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && $user->isAdmin(), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;
                $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $user->isAdmin(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        return view('admin.employees.index', compact('branches', 'employees'));
    }

    public function update(Request $request, User $employee)
    {
        $this->authorizeEmployee($request, $employee);

        $validated = $request->validate([
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'face_image' => ['nullable', 'string'],
            'face_descriptors' => ['nullable', 'json'],
        ]);

        $updates = [
            'monthly_salary' => $validated['monthly_salary'] ?? 0,
        ];

        if (! empty($validated['face_image']) && ! empty($validated['face_descriptors'])) {
            $descriptors = json_decode($validated['face_descriptors'], true);

            abort_unless(is_array($descriptors) && count($descriptors) >= 4, 422, 'Register at least 4 face samples.');

            foreach ($descriptors as $descriptor) {
                abort_unless(is_array($descriptor) && count($descriptor) === 128, 422, 'Invalid face descriptor.');
            }

            $updates['face_image_path'] = $this->storeFaceImage($validated['face_image'], 'employee-faces');
            $updates['face_descriptors'] = $descriptors;
            $updates['face_enrolled_at'] = now();
        }

        $employee->update($updates);

        return back()->with('success', 'Employee profile updated successfully.');
    }

    private function authorizeEmployee(Request $request, User $employee): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless((int) $request->user()->branch_id === (int) $employee->branch_id, 403);
    }

    private function storeFaceImage(string $image, string $directory): string
    {
        abort_unless(str_starts_with($image, 'data:image/'), 422, 'Invalid face image.');

        [$meta, $contents] = explode(',', $image, 2);
        $extension = str_contains($meta, 'image/png') ? 'png' : 'jpg';
        $path = $directory.'/'.uniqid('face_', true).'.'.$extension;

        Storage::disk('public')->put($path, base64_decode($contents));

        return $path;
    }
}
