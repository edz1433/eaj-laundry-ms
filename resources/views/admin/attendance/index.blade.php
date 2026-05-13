@extends('layouts.app')

@section('page_title', 'Attendance')

@section('content')
<div
    x-data="attendanceCapture()"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="attendance" class="h-3.5 w-3.5"></span>
                Face and location attendance
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Attendance</h1>
            <p class="text-sm text-muted">Time in and time out with camera capture and GPS coordinates.</p>
        </div>

        <form method="GET" class="flex gap-2">
            @if(auth()->user()->isAdmin())
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4"></span>
            </button>
        </form>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-border px-4 py-3 dark:border-gray-800">
                <h2 class="text-base font-semibold">Today's Employees</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-3">Employee</th>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3">Time In</th>
                            <th class="px-4 py-3">Time Out</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border dark:divide-gray-800">
                        @forelse($employees as $employee)
                            @php($attendance = $todayAttendance->get($employee->id))
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $employee->name }}</p>
                                    <p class="text-xs text-muted">{{ str_replace('_', ' ', $employee->role) }}</p>
                                </td>
                                <td class="px-4 py-3">{{ $employee->branch?->name ?? 'All branches' }}</td>
                                <td class="px-4 py-3">{{ $attendance?->time_in?->format('h:i A') ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $attendance?->time_out?->format('h:i A') ?? '-' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click="selectEmployee(@js($employee->id), @js($employee->name))" class="inline-flex h-8 items-center rounded-md border border-border px-2.5 text-xs font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                        Select
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No active employees found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-base font-semibold">Time Clock</h2>
            <p class="mt-1 text-sm text-muted" x-text="employeeName || 'Select an employee from the list.'"></p>

            <div class="mt-3 overflow-hidden rounded-md border border-border bg-smoke dark:border-gray-800 dark:bg-gray-950">
                <video x-ref="video" autoplay muted playsinline class="aspect-video w-full object-cover"></video>
                <canvas x-ref="canvas" class="hidden"></canvas>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="startCamera()" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                    <span data-lucide="eye" class="h-4 w-4"></span>
                    Camera
                </button>
                <button type="button" @click="capture()" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                    <span data-lucide="user" class="h-4 w-4"></span>
                    Capture
                </button>
            </div>

            <p class="mt-2 text-xs text-muted" x-text="message"></p>
            <p class="mt-1 text-xs text-muted" x-show="latitude && longitude">GPS: <span x-text="latitude"></span>, <span x-text="longitude"></span></p>

            <div class="mt-4 grid grid-cols-2 gap-2">
                <form method="POST" action="{{ route('admin.attendance.time-in') }}" @submit="prepareSubmit">
                    @csrf
                    <input type="hidden" name="user_id" x-model="employeeId">
                    <input type="hidden" name="latitude" x-model="latitude">
                    <input type="hidden" name="longitude" x-model="longitude">
                    <input type="hidden" name="face_image" x-model="faceImage">
                    <button :disabled="!canSubmit" class="h-9 w-full rounded-md bg-primary text-sm font-medium text-white disabled:opacity-50">Time In</button>
                </form>

                <form method="POST" action="{{ route('admin.attendance.time-out') }}" @submit="prepareSubmit">
                    @csrf
                    <input type="hidden" name="user_id" x-model="employeeId">
                    <input type="hidden" name="latitude" x-model="latitude">
                    <input type="hidden" name="longitude" x-model="longitude">
                    <input type="hidden" name="face_image" x-model="faceImage">
                    <button :disabled="!canSubmit" class="h-9 w-full rounded-md border border-border text-sm font-medium hover:bg-smoke disabled:opacity-50 dark:border-gray-800 dark:hover:bg-gray-950">Time Out</button>
                </form>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-border px-4 py-3 dark:border-gray-800">
            <h2 class="text-base font-semibold">Attendance Logs</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Employee</th><th class="px-4 py-3">Time In</th><th class="px-4 py-3">Time Out</th><th class="px-4 py-3">GPS</th></tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($records as $record)
                        <tr>
                            <td class="px-4 py-3">{{ $record->work_date->format('M d, Y') }}</td>
                            <td class="px-4 py-3">{{ $record->user?->name }}</td>
                            <td class="px-4 py-3">{{ $record->time_in?->format('h:i A') ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $record->time_out?->format('h:i A') ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs text-muted">
                                @if($record->time_in_latitude && $record->time_in_longitude)
                                    {{ $record->time_in_latitude }}, {{ $record->time_in_longitude }}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No attendance logs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $records->links() }}</div>
    </div>
</div>

<script>
function attendanceCapture() {
    return {
        employeeId: '',
        employeeName: '',
        latitude: '',
        longitude: '',
        faceImage: '',
        message: 'Camera and GPS are required.',
        get canSubmit() {
            return this.employeeId && this.latitude && this.longitude && this.faceImage;
        },
        selectEmployee(id, name) {
            this.employeeId = id;
            this.employeeName = name;
            this.message = 'Open camera, allow GPS, then capture.';
            this.locate();
        },
        async startCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.$refs.video.srcObject = stream;
                this.message = 'Camera ready. Capture face before submitting.';
            } catch (error) {
                this.message = 'Camera permission is required.';
            }
        },
        locate() {
            if (!navigator.geolocation) {
                this.message = 'GPS is not supported by this browser.';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                position => {
                    this.latitude = position.coords.latitude.toFixed(7);
                    this.longitude = position.coords.longitude.toFixed(7);
                },
                () => this.message = 'Location permission is required.',
                { enableHighAccuracy: true, timeout: 12000 }
            );
        },
        capture() {
            const video = this.$refs.video;
            if (!video.videoWidth) {
                this.message = 'Open the camera first.';
                return;
            }

            const canvas = this.$refs.canvas;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            this.faceImage = canvas.toDataURL('image/jpeg', 0.88);
            this.locate();
            this.message = 'Face captured.';
        },
        prepareSubmit(event) {
            if (!this.canSubmit) {
                event.preventDefault();
                this.message = 'Select employee, capture face, and allow GPS first.';
            }
        },
    };
}
</script>
@endsection
