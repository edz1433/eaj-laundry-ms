<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\Branch;
use App\Models\DailyTask;
use App\Models\DailyTaskCompletion;
use App\Models\EmployeeAttendanceRecord;
use App\Models\JobOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function kiosk()
    {
        $employee = AttendanceEmployee::query()
            ->with('branch')
            ->whereKey(session('attendance_employee_id'))
            ->where('status', 'active')
            ->first();

        if (! $employee) {
            return redirect()->route('attendance.login');
        }

        $workDate = today()->toDateString();
        $dailyTasks = DailyTask::query()
            ->with(['completions' => fn ($query) => $query
                ->with(['completer', 'employeeCompleter'])
                ->where('branch_id', $employee->branch_id)
                ->whereDate('work_date', $workDate)])
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id))
            ->orderBy('name')
            ->get();

        return view('attendance.kiosk', compact('employee', 'dailyTasks', 'workDate'));
    }

    public function connectivity()
    {
        return response()
            ->json(['online' => true, 'checked_at' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
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
        $canChooseBranch = $user->canManageAllBranches();
        $selectedBranchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: null)
            : $user->branch_id;
        $selectedEmployeeId = $request->integer('employee_id') ?: null;
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $workDate = $dateFrom === $dateTo ? $dateFrom : $dateFrom.' to '.$dateTo;

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $employeesQuery = AttendanceEmployee::query()
            ->with('branch')
            ->where('status', 'active')
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($selectedEmployeeId, fn ($query) => $query->whereKey($selectedEmployeeId))
            ->orderBy('first_name')
            ->orderBy('last_name');

        $employees = (clone $employeesQuery)
            ->get();

        $records = $employeesQuery
            ->paginate(20)
            ->withQueryString();

        $attendanceByEmployee = EmployeeAttendanceRecord::query()
            ->with(['employee', 'branch'])
            ->whereIn('attendance_employee_id', $records->getCollection()->pluck('id'))
            ->whereDate('work_date', '>=', $dateFrom)
            ->whereDate('work_date', '<=', $dateTo)
            ->latest('work_date')
            ->latest()
            ->get()
            ->groupBy('attendance_employee_id');

        $records->through(fn (AttendanceEmployee $employee) => $this->attendanceRowForEmployee(
            $employee,
            $attendanceByEmployee->get($employee->id)?->first(),
            $dateFrom
        ));

        return view('admin.attendance.index', compact('branches', 'employees', 'records', 'selectedBranchId', 'selectedEmployeeId', 'workDate', 'dateFrom', 'dateTo', 'canChooseBranch'));
    }

    public function timeIn(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->attendanceEmployeeForAdmin($request, (int) $validated['employee_id']);
        $this->assertWithinAttendanceEmployeeBranch($employee, (float) $validated['latitude'], (float) $validated['longitude']);
        $record = $this->attendanceRecordForToday($employee);

        $record->update([
            'clock_in' => [...($record->clock_in ?? []), now()->format('H:i:s')],
            'clock_in_photos' => [...($record->clock_in_photos ?? []), $this->storeAttendanceImage($validated['face_image'], 'attendance-proofs')],
            'clock_in_locations' => [...($record->clock_in_locations ?? []), $this->locationPayload($validated)],
        ]);

        return back()->with('success', "{$employee->name} timed in successfully.");
    }

    public function timeOut(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->attendanceEmployeeForAdmin($request, (int) $validated['employee_id']);
        $this->assertWithinAttendanceEmployeeBranch($employee, (float) $validated['latitude'], (float) $validated['longitude']);
        $record = $this->attendanceRecordForToday($employee);

        $record->update([
            'clock_out' => [...($record->clock_out ?? []), now()->format('H:i:s')],
            'clock_out_photos' => [...($record->clock_out_photos ?? []), $this->storeAttendanceImage($validated['face_image'], 'attendance-proofs')],
            'clock_out_locations' => [...($record->clock_out_locations ?? []), $this->locationPayload($validated)],
        ]);

        return back()->with('success', "{$employee->name} timed out successfully.");
    }

    public function publicTimeIn(Request $request)
    {
        $validated = $this->validatePublicAttendanceRequest($request);
        $employee = $this->employeeFromAttendanceSession();
        $branch = $employee->branch;
        $this->assertWithinAttendanceEmployeeBranch($employee, (float) $validated['latitude'], (float) $validated['longitude']);
        $record = $this->attendanceRecordForToday($employee);

        $record->update([
            'clock_in' => [...($record->clock_in ?? []), now()->format('H:i:s')],
            'clock_in_photos' => [...($record->clock_in_photos ?? []), $this->storeAttendanceImage($validated['face_image'], 'attendance-proofs')],
            'clock_in_locations' => [...($record->clock_in_locations ?? []), $this->locationPayload($validated)],
        ]);

        return response()->json([
            'message' => "{$employee->name} timed in successfully.",
            'employee' => $employee->name,
            'branch' => $branch->name,
            'time' => now()->format('h:i A'),
        ]);
    }

    public function publicTimeOut(Request $request)
    {
        $validated = $this->validatePublicAttendanceRequest($request);
        $employee = $this->employeeFromAttendanceSession();
        $branch = $employee->branch;
        $this->assertWithinAttendanceEmployeeBranch($employee, (float) $validated['latitude'], (float) $validated['longitude']);
        $record = $this->attendanceRecordForToday($employee);

        $record->update([
            'clock_out' => [...($record->clock_out ?? []), now()->format('H:i:s')],
            'clock_out_photos' => [...($record->clock_out_photos ?? []), $this->storeAttendanceImage($validated['face_image'], 'attendance-proofs')],
            'clock_out_locations' => [...($record->clock_out_locations ?? []), $this->locationPayload($validated)],
        ]);

        return response()->json([
            'message' => "{$employee->name} timed out successfully.",
            'employee' => $employee->name,
            'branch' => $branch->name,
            'time' => now()->format('h:i A'),
        ]);
    }

    public function preparePublicAttendance(Request $request)
    {
        $validated = $this->validatePublicCredentialsRequest($request);
        $employee = $this->employeeFromAttendanceSession();
        $branch = $employee->branch;
        $this->assertWithinAttendanceEmployeeBranch($employee, (float) $validated['latitude'], (float) $validated['longitude']);

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
            ],
            'captured_at' => now()->format('M d, Y h:i A'),
        ]);
    }

    public function publicCompleteDailyTask(Request $request, DailyTask $task)
    {
        $employee = $this->employeeFromAttendanceSession();

        abort_if($task->branch_id !== null && (int) $task->branch_id !== (int) $employee->branch_id, 403);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $workDate = today()->toDateString();
        $path = $request->file('photo')->store('daily-tasks', 'public');
        Storage::disk('public')->setVisibility($path, 'public');
        $existing = DailyTaskCompletion::query()
            ->where('daily_task_id', $task->id)
            ->where('branch_id', $employee->branch_id)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($existing?->photo_path) {
            Storage::disk('public')->delete($existing->photo_path);
        }

        DailyTaskCompletion::updateOrCreate(
            ['daily_task_id' => $task->id, 'branch_id' => $employee->branch_id, 'work_date' => $workDate],
            [
                'completed_by' => null,
                'completed_by_employee_id' => $employee->id,
                'photo_path' => $path,
                'remarks' => $validated['remarks'] ?? null,
                'completed_at' => now(),
            ]
        );

        return back()->with('success', 'End-of-day proof uploaded successfully.');
    }

    public function publicScanJobOrder(Request $request)
    {
        $employee = $this->employeeFromAttendanceSession();
        $validated = $request->validate([
            'qr_text' => ['required', 'string', 'max:1000'],
        ]);

        $jobOrder = $this->jobOrderFromQrText($validated['qr_text']);
        $productionBranch = app(JobOrderController::class)
            ->acceptProductionByBranch($request, $jobOrder, (int) $employee->branch_id);

        $jobOrder->refresh();

        return response()->json([
            'message' => "Accepted {$jobOrder->job_order_number} for production at {$productionBranch->name}.",
            'job_order_number' => $jobOrder->job_order_number,
            'dropoff_branch' => $jobOrder->branch?->name,
            'processing_branch' => $productionBranch->name,
            'status' => $jobOrder->status,
        ]);
    }

    private function validateAttendanceRequest(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'exists:attendance_employees,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'face_image' => ['required', 'string'],
        ]);
    }

    private function validatePublicAttendanceRequest(Request $request): array
    {
        return $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'face_image' => ['required', 'string'],
        ]);
    }

    private function validatePublicCredentialsRequest(Request $request): array
    {
        return $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
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

    private function assertWithinAttendanceEmployeeBranch(AttendanceEmployee $employee, float $latitude, float $longitude): void
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

    private function employeeFromAttendanceSession(): AttendanceEmployee
    {
        $employee = AttendanceEmployee::query()
            ->with('branch')
            ->whereKey(session('attendance_employee_id'))
            ->where('status', 'active')
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages(['employee' => 'Please login as an employee first.']);
        }

        return $employee;
    }

    private function attendanceEmployeeForAdmin(Request $request, int $employeeId): AttendanceEmployee
    {
        $employee = AttendanceEmployee::query()
            ->whereKey($employeeId)
            ->where('status', 'active')
            ->with('branch')
            ->firstOrFail();

        if (! $request->user()->isAdmin()) {
            abort_unless((int) $request->user()->branch_id === (int) $employee->branch_id, 403);
        }

        return $employee;
    }

    private function attendanceRecordForToday(AttendanceEmployee $employee): EmployeeAttendanceRecord
    {
        $record = EmployeeAttendanceRecord::query()
            ->where('attendance_employee_id', $employee->id)
            ->whereDate('work_date', today())
            ->first();

        if ($record) {
            return $record;
        }

        return EmployeeAttendanceRecord::create([
                'branch_id' => $employee->branch_id,
                'attendance_employee_id' => $employee->id,
                'work_date' => today()->toDateString(),
                'clock_in' => [],
                'clock_out' => [],
                'clock_in_photos' => [],
                'clock_out_photos' => [],
                'clock_in_locations' => [],
                'clock_out_locations' => [],
        ]);
    }

    private function attendanceRowForEmployee(AttendanceEmployee $employee, ?EmployeeAttendanceRecord $record, string $dateFrom): EmployeeAttendanceRecord
    {
        if ($record) {
            $record->setRelation('employee', $employee);
            $record->setRelation('branch', $employee->branch);

            return $record;
        }

        $emptyRecord = new EmployeeAttendanceRecord([
            'attendance_employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'work_date' => $dateFrom,
            'clock_in' => [],
            'clock_out' => [],
            'clock_in_photos' => [],
            'clock_out_photos' => [],
            'clock_in_locations' => [],
            'clock_out_locations' => [],
        ]);

        $emptyRecord->exists = false;
        $emptyRecord->setRelation('employee', $employee);
        $emptyRecord->setRelation('branch', $employee->branch);

        return $emptyRecord;
    }

    private function jobOrderFromQrText(string $qrText): JobOrder
    {
        $qrText = trim($qrText);
        $path = parse_url($qrText, PHP_URL_PATH) ?: $qrText;

        if (preg_match('#/job-orders/(\d+)/(?:scan|receipt)#', $path, $matches)) {
            return JobOrder::query()->findOrFail((int) $matches[1]);
        }

        if (preg_match('/\bJO[-A-Z0-9]+\b/i', $qrText, $matches)) {
            return JobOrder::query()
                ->where('job_order_number', strtoupper($matches[0]))
                ->firstOrFail();
        }

        return JobOrder::query()
            ->where('job_order_number', $qrText)
            ->firstOrFail();
    }

    private function locationPayload(array $validated): array
    {
        return [
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'captured_at' => now()->toDateTimeString(),
        ];
    }

    private function storeAttendanceImage(string $image, string $directory): string
    {
        abort_unless(str_starts_with($image, 'data:image/'), 422, 'Invalid attendance image.');
        abort_unless(str_contains($image, ','), 422, 'Invalid attendance image.');

        [$meta, $contents] = explode(',', $image, 2);
        $extension = str_contains($meta, 'image/png') ? 'png' : 'jpg';
        $decoded = base64_decode($contents, true);
        abort_if($decoded === false, 422, 'Invalid attendance image.');
        $path = $directory.'/'.uniqid('attendance_', true).'.'.$extension;

        Storage::disk('public')->put($path, $decoded);
        Storage::disk('public')->setVisibility($path, 'public');

        return $path;
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);

            return [
                $this->parseDate($parts[0] ?? null, today()->toDateString()),
                $this->parseDate($parts[1] ?? $parts[0] ?? null, today()->toDateString()),
            ];
        }

        $date = $this->parseDate($request->date, today()->toDateString());

        return [$date, $date];
    }

    private function parseDate(?string $date, string $fallback): string
    {
        if (! $date) {
            return $fallback;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
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
