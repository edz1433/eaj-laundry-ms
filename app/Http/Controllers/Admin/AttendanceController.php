<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function kiosk()
    {
        return view('attendance.kiosk');
    }

    public function challenge()
    {
        $sequence = collect(['blink', 'left', 'right'])
            ->shuffle()
            ->values()
            ->all();
        $nonce = (string) Str::uuid();

        Cache::put($this->challengeCacheKey($nonce), $sequence, now()->addMinutes(2));

        return response()->json([
            'nonce' => $nonce,
            'sequence' => $sequence,
            'expires_in' => 120,
        ]);
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
        $this->assertWithinBranchGeofence($employee, (float) $validated['latitude'], (float) $validated['longitude']);

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
        $this->assertWithinBranchGeofence($employee, (float) $validated['latitude'], (float) $validated['longitude']);

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
        $this->verifyChallenge($validated);
        $branch = $this->branchForLocation((float) $validated['latitude'], (float) $validated['longitude']);
        $employee = $this->matchEmployeeByFace($validated['descriptor'], $branch);

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
        $this->verifyChallenge($validated);
        $branch = $this->branchForLocation((float) $validated['latitude'], (float) $validated['longitude']);
        $employee = $this->matchEmployeeByFace($validated['descriptor'], $branch);

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
            'challenge_nonce' => ['required', 'string'],
            'challenge_result' => ['required', 'array', 'size:3'],
            'challenge_result.*' => ['required', 'string', 'in:blink,left,right'],
        ]);
    }

    private function verifyChallenge(array $validated): void
    {
        $expected = Cache::pull($this->challengeCacheKey($validated['challenge_nonce']));

        if (! $expected || array_values($validated['challenge_result']) !== array_values($expected)) {
            throw ValidationException::withMessages([
                'face' => 'Live face challenge expired or was not completed correctly. Please try again.',
            ]);
        }
    }

    private function branchForLocation(float $latitude, float $longitude): Branch
    {
        $branch = Branch::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(function (Branch $branch) use ($latitude, $longitude) {
                $radius = (int) ($branch->attendance_radius_meters ?: 150);

                return [
                    'branch' => $branch,
                    'distance' => $this->distanceInMeters((float) $branch->latitude, (float) $branch->longitude, $latitude, $longitude),
                    'radius' => $radius,
                ];
            })
            ->filter(fn (array $result) => $result['distance'] <= $result['radius'])
            ->sortBy('distance')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'location' => 'This device is not inside any configured branch attendance area.',
            ]);
        }

        return $branch['branch'];
    }

    private function matchEmployeeByFace(array $descriptor, ?Branch $branch = null): User
    {
        $match = User::query()
            ->with('branch')
            ->where('status', 'active')
            ->whereNotNull('branch_id')
            ->whereNotNull('face_descriptors')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
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
            $message = $branch
                ? "No enrolled employee matched this live face for {$branch->name}."
                : 'No enrolled employee matched this live face.';

            throw ValidationException::withMessages(['face' => $message]);
        }

        return $match['employee'];
    }

    private function assertWithinBranchGeofence(User $employee, float $latitude, float $longitude): void
    {
        $employee->loadMissing('branch');
        $branch = $employee->branch;

        if (! $branch || $branch->latitude === null || $branch->longitude === null) {
            return;
        }

        $radius = (int) ($branch->attendance_radius_meters ?: 150);
        $distance = $this->distanceInMeters((float) $branch->latitude, (float) $branch->longitude, $latitude, $longitude);

        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => "Attendance location is outside {$branch->name}'s allowed {$radius}m radius.",
            ]);
        }
    }

    private function employeeForAttendance(Request $request, int $userId): User
    {
        $employee = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->with('branch')
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

    private function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function challengeCacheKey(string $nonce): string
    {
        return 'attendance_challenge:'.$nonce;
    }
}
