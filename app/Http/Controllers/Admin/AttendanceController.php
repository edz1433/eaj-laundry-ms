<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function kiosk()
    {
        return view('attendance.kiosk');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $selectedBranchId = $user->isAdmin()
            ? ($request->integer('branch_id') ?: null)
            : $user->branch_id;

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $user->isAdmin(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $employees = User::query()
            ->with('branch')
            ->whereIn('role', ['admin', 'branch_manager', 'cashier', 'staff'])
            ->where('status', 'active')
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->when(! $user->isAdmin(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get();

        $todayAttendance = Attendance::query()
            ->whereDate('work_date', today())
            ->whereIn('user_id', $employees->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $records = Attendance::query()
            ->with(['user', 'branch'])
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->when(! $user->isAdmin(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->latest('work_date')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.attendance.index', compact('branches', 'employees', 'records', 'selectedBranchId', 'todayAttendance'));
    }

    public function timeIn(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->employeeForAttendance($request, (int) $validated['user_id']);

        $attendance = Attendance::firstOrCreate(
            [
                'user_id' => $employee->id,
                'work_date' => today()->toDateString(),
            ],
            [
                'branch_id' => $employee->branch_id,
                'status' => 'present',
            ]
        );

        if ($attendance->time_in) {
            throw ValidationException::withMessages(['user_id' => 'This employee already timed in today.']);
        }

        $attendance->update([
            'branch_id' => $employee->branch_id,
            'time_in' => now(),
            'time_in_latitude' => $validated['latitude'],
            'time_in_longitude' => $validated['longitude'],
            'time_in_photo_path' => $this->storeFaceImage($validated['face_image'], 'attendance-faces'),
            'status' => 'present',
        ]);

        return back()->with('success', "{$employee->name} timed in successfully.");
    }

    public function timeOut(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->employeeForAttendance($request, (int) $validated['user_id']);

        $attendance = Attendance::query()
            ->where('user_id', $employee->id)
            ->whereDate('work_date', today())
            ->first();

        if (! $attendance || ! $attendance->time_in) {
            throw ValidationException::withMessages(['user_id' => 'This employee has not timed in yet.']);
        }

        if ($attendance->time_out) {
            throw ValidationException::withMessages(['user_id' => 'This employee already timed out today.']);
        }

        $attendance->update([
            'time_out' => now(),
            'time_out_latitude' => $validated['latitude'],
            'time_out_longitude' => $validated['longitude'],
            'time_out_photo_path' => $this->storeFaceImage($validated['face_image'], 'attendance-faces'),
        ]);

        return back()->with('success', "{$employee->name} timed out successfully.");
    }

    public function publicTimeIn(Request $request)
    {
        $validated = $this->validatePublicAttendanceRequest($request);
        $employee = $this->matchEmployeeByFace($validated['descriptor']);

        $attendance = Attendance::firstOrCreate(
            [
                'user_id' => $employee->id,
                'work_date' => today()->toDateString(),
            ],
            [
                'branch_id' => $employee->branch_id,
                'status' => 'present',
            ]
        );

        if ($attendance->time_in) {
            throw ValidationException::withMessages(['face' => "{$employee->name} already timed in today."]);
        }

        $attendance->update([
            'branch_id' => $employee->branch_id,
            'time_in' => now(),
            'time_in_latitude' => $validated['latitude'],
            'time_in_longitude' => $validated['longitude'],
            'time_in_photo_path' => $this->storeFaceImage($validated['face_image'], 'attendance-faces'),
            'status' => 'present',
        ]);

        return response()->json([
            'message' => "{$employee->name} timed in successfully.",
            'employee' => $employee->name,
            'time' => $attendance->time_in->format('h:i A'),
        ]);
    }

    public function publicTimeOut(Request $request)
    {
        $validated = $this->validatePublicAttendanceRequest($request);
        $employee = $this->matchEmployeeByFace($validated['descriptor']);

        $attendance = Attendance::query()
            ->where('user_id', $employee->id)
            ->whereDate('work_date', today())
            ->first();

        if (! $attendance || ! $attendance->time_in) {
            throw ValidationException::withMessages(['face' => "{$employee->name} has not timed in yet."]);
        }

        if ($attendance->time_out) {
            throw ValidationException::withMessages(['face' => "{$employee->name} already timed out today."]);
        }

        $attendance->update([
            'time_out' => now(),
            'time_out_latitude' => $validated['latitude'],
            'time_out_longitude' => $validated['longitude'],
            'time_out_photo_path' => $this->storeFaceImage($validated['face_image'], 'attendance-faces'),
        ]);

        return response()->json([
            'message' => "{$employee->name} timed out successfully.",
            'employee' => $employee->name,
            'time' => $attendance->time_out->format('h:i A'),
        ]);
    }

    private function validateAttendanceRequest(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'face_image' => ['required', 'string'],
        ]);
    }

    private function validatePublicAttendanceRequest(Request $request): array
    {
        return $request->validate([
            'descriptor' => ['required', 'array', 'size:128'],
            'descriptor.*' => ['numeric'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'face_image' => ['required', 'string'],
            'liveness_token' => ['required', 'string', 'in:passed'],
        ]);
    }

    private function matchEmployeeByFace(array $descriptor): User
    {
        $match = User::query()
            ->where('status', 'active')
            ->whereNotNull('branch_id')
            ->whereNotNull('face_descriptors')
            ->get()
            ->map(function (User $employee) use ($descriptor) {
                $distance = collect($employee->face_descriptors ?? [])
                    ->map(fn (array $known) => $this->euclideanDistance($known, $descriptor))
                    ->min();

                return [
                    'employee' => $employee,
                    'distance' => $distance,
                ];
            })
            ->filter(fn (array $result) => $result['distance'] !== null)
            ->sortBy('distance')
            ->first();

        if (! $match || $match['distance'] > 0.48) {
            throw ValidationException::withMessages(['face' => 'No enrolled employee matched this live face.']);
        }

        return $match['employee'];
    }

    private function employeeForAttendance(Request $request, int $userId): User
    {
        $employee = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->firstOrFail();

        if (! $request->user()->isAdmin()) {
            abort_unless((int) $request->user()->branch_id === (int) $employee->branch_id, 403);
        }

        return $employee;
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

    private function euclideanDistance(array $known, array $candidate): float
    {
        $sum = 0;

        foreach ($known as $index => $value) {
            $difference = (float) $value - (float) ($candidate[$index] ?? 0);
            $sum += $difference * $difference;
        }

        return sqrt($sum);
    }
}
