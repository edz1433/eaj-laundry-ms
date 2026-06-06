<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $appPrimaryColor }}">
    <title>Employee Time Clock - {{ $appBusinessName }}</title>
    <link rel="icon" href="{{ $appBusinessLogo }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
</head>

<body class="min-h-dvh bg-[#f6f7f4] text-dark dark:bg-gray-950 dark:text-gray-100">
    <main x-data="publicTimeClock()" x-init="init()" class="mx-auto flex min-h-dvh w-full max-w-5xl items-center justify-center p-3 pb-24 sm:p-4 sm:pb-24">
        <section class="grid w-full overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:grid-cols-[minmax(0,1fr)_23rem]">
            <div class="min-h-[28rem] bg-gray-950 p-3">
                <div class="relative h-full min-h-[28rem] overflow-hidden rounded-lg border border-gray-800">
                    <video x-ref="video" x-show="!proofPreview" autoplay muted playsinline class="h-full w-full -scale-x-100 object-cover"></video>
                    <img x-show="proofPreview" x-cloak :src="proofPreview" alt="Captured attendance proof" class="h-full w-full object-cover">
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <div class="absolute inset-x-3 top-3 flex flex-wrap items-center justify-between gap-2">
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-text="proofPreview ? 'Captured Proof' : (cameraReady ? 'Live Camera' : 'Camera Required')"></span>
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-show="latitude && longitude">GPS Active</span>
                    </div>

                    <div class="absolute inset-x-3 bottom-3 rounded-lg bg-black/70 p-3 text-white shadow-lg backdrop-blur">
                        <p class="text-sm font-semibold" x-text="employeeName || 'Employee proof photo'"></p>
                        <p class="mt-1 text-xs text-white/80" x-text="branchName || 'Login and verify location to show branch details.'"></p>
                        <p class="mt-1 text-xs text-white/80" x-text="branchAddress || 'No branch address'"></p>
                        <p class="mt-1 text-xs text-white/80" x-show="latitude && longitude">GPS: <span x-text="latitude"></span>, <span x-text="longitude"></span></p>
                        <p class="mt-2 text-xs" x-text="message"></p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col p-4">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-10 w-10 shrink-0 rounded-md border border-border bg-white object-contain dark:border-gray-800 dark:bg-gray-950">
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium uppercase text-muted">Employee Time Clock</p>
                        <h1 class="truncate text-base font-semibold tracking-normal">{{ $employee->name }}</h1>
                        <p class="truncate text-xs text-muted">{{ $employee->branch?->name }}</p>
                    </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <div class="text-right">
                            <p class="text-[11px] text-muted">{{ now()->format('M d, Y') }}</p>
                            <p class="text-lg font-semibold tabular-nums" x-text="currentTime"></p>
                        </div>
                        <form method="POST" action="{{ route('attendance.logout') }}">
                            @csrf
                            <button type="submit" title="Logout" aria-label="Logout" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                <span data-lucide="logout" class="h-4 w-4"></span>
                            </button>
                        </form>
                    </div>
                </div>

                <div x-show="activeTab === 'clock'" class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="startCamera()" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="eye" class="h-4 w-4"></span>
                            Camera
                        </button>
                        <button type="button" @click="locate()" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="attendance" class="h-4 w-4"></span>
                            GPS
                        </button>
                    </div>

                    <button type="button" @click="prepare()" :disabled="!latitude || !longitude || verifying" class="h-10 w-full rounded-md bg-primary text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                        Verify Branch Location
                    </button>

                    <button type="button" @click="captureProof()" :disabled="!verified || !cameraReady" class="h-10 w-full rounded-md border border-border text-sm font-semibold hover:bg-smoke disabled:opacity-50 dark:border-gray-800 dark:hover:bg-gray-950">
                        Capture Proof Photo
                    </button>

                    <div x-show="lastResult" x-cloak class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-800">
                        <p x-text="lastResult"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-2">
                        <button type="button" @click="submit(@js(route('attendance.public-time-in')))" :disabled="!canSubmit || submitting" class="h-11 rounded-md bg-primary text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-50">
                            Time In
                        </button>
                        <button type="button" @click="submit(@js(route('attendance.public-time-out')))" :disabled="!canSubmit || submitting" class="h-11 rounded-md border border-border text-sm font-semibold hover:bg-smoke disabled:opacity-50 dark:border-gray-800 dark:hover:bg-gray-950">
                            Time Out
                        </button>
                    </div>
                </div>

                <div x-cloak x-show="activeTab === 'tasks'" class="min-h-0 flex-1 space-y-3 overflow-y-auto">
                    <div class="rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                        <p class="text-sm font-semibold">End-of-Day Tasks</p>
                        <p class="mt-1 text-xs text-muted">{{ \Illuminate\Support\Carbon::parse($workDate)->format('M d, Y') }} - {{ $employee->branch?->name }}</p>
                    </div>

                    @forelse($dailyTasks as $task)
                        @php($completion = $task->completions->first())
                        <div class="rounded-md border border-border p-3 dark:border-gray-800">
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">{{ $task->name }}</p>
                                    <p class="text-xs text-muted">{{ $completion ? 'Uploaded '.$completion->completed_at?->format('h:i A') : 'Pending proof' }}</p>
                                </div>
                                <span class="{{ \App\Support\StatusBadge::classes($completion ? 'completed' : 'pending') }}">{{ $completion ? 'Done' : 'Pending' }}</span>
                            </div>

                            @if($completion)
                                <a href="{{ asset('storage/'.$completion->photo_path) }}" target="_blank" class="mb-2 block overflow-hidden rounded-md border border-border dark:border-gray-800">
                                    <img src="{{ asset('storage/'.$completion->photo_path) }}" alt="{{ $task->name }} proof" class="h-28 w-full object-cover">
                                </a>
                            @endif

                            <form method="POST" action="{{ route('attendance.daily-tasks.complete', $task) }}" enctype="multipart/form-data" class="space-y-2">
                                @csrf
                                <input type="file" name="photo" accept="image/*" capture="environment" required class="w-full rounded-md border border-border bg-white px-2 py-2 text-xs dark:border-gray-800 dark:bg-gray-950">
                                <textarea name="remarks" rows="2" placeholder="Remarks" class="w-full rounded-md border border-border bg-white px-2 py-2 text-xs dark:border-gray-800 dark:bg-gray-950"></textarea>
                                <button class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:opacity-90">
                                    <span data-lucide="check" class="h-4 w-4"></span>
                                    {{ $completion ? 'Replace Proof' : 'Upload Proof' }}
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-md border border-border p-6 text-center text-sm text-muted dark:border-gray-800">No tasks configured.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-white/95 px-4 py-2 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-900/95">
            <div class="mx-auto grid max-w-md grid-cols-2 gap-2">
                <button type="button" @click="activeTab = 'clock'; refreshIcons()" class="flex h-12 flex-col items-center justify-center rounded-md text-xs font-semibold" :class="activeTab === 'clock' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">
                    <span data-lucide="attendance" class="h-4 w-4"></span>
                    Clock
                </button>
                <button type="button" @click="activeTab = 'tasks'; refreshIcons()" class="flex h-12 flex-col items-center justify-center rounded-md text-xs font-semibold" :class="activeTab === 'tasks' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">
                    <span data-lucide="check" class="h-4 w-4"></span>
                    Tasks
                </button>
            </div>
        </nav>
    </main>

    <script>
        function publicTimeClock() {
            return {
                stream: null,
                activeTab: 'clock',
                cameraReady: false,
                employeeName: @js($employee->name),
                branchName: @js($employee->branch?->name),
                branchAddress: @js($employee->branch?->address ?: 'No branch address'),
                latitude: '',
                longitude: '',
                locationAccuracy: null,
                verified: false,
                verifying: false,
                submitting: false,
                proofImage: '',
                proofPreview: '',
                lastResult: '',
                currentTime: '',
                message: 'Allow GPS, capture proof, then clock in or clock out.',
                get canSubmit() {
                    return this.verified && this.proofImage && this.latitude && this.longitude;
                },
                init() {
                    this.startClock();
                    this.$nextTick(() => {
                        this.startCamera();
                        this.locate();
                    });
                },
                startClock() {
                    const update = () => {
                        this.currentTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    };
                    update();
                    setInterval(update, 1000);
                },
                async startCamera() {
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                        this.$refs.video.srcObject = this.stream;
                        this.cameraReady = true;
                        this.message = 'Camera ready. Verify employee and branch.';
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
                            this.locationAccuracy = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
                        },
                        () => this.message = 'Location permission is required.',
                        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
                    );
                },
                async prepare() {
                    this.verifying = true;
                    this.verified = false;
                    this.proofImage = '';
                    this.proofPreview = '';
                    this.lastResult = '';
                    this.message = 'Verifying employee login and branch location...';

                    const response = await this.sendJson(@js(route('attendance.prepare')), {
                        latitude: this.latitude,
                        longitude: this.longitude,
                    });

                    this.verifying = false;

                    if (!response.ok) {
                        this.message = response.message;
                        return;
                    }

                    this.employeeName = response.data.employee.name;
                    this.branchName = response.data.branch.name;
                    this.branchAddress = response.data.branch.address || 'No branch address';
                    this.verified = true;
                    this.message = 'Verified. Capture proof photo with overlay.';
                },
                captureProof() {
                    const video = this.$refs.video;
                    if (!video.videoWidth) {
                        this.message = 'Open the camera first.';
                        return;
                    }

                    const maxWidth = 900;
                    const scale = Math.min(1, maxWidth / video.videoWidth);
                    const width = Math.round(video.videoWidth * scale);
                    const height = Math.round(video.videoHeight * scale);
                    const canvas = this.$refs.canvas;
                    const context = canvas.getContext('2d');
                    canvas.width = width;
                    canvas.height = height;

                    context.save();
                    context.scale(-1, 1);
                    context.drawImage(video, -width, 0, width, height);
                    context.restore();

                    const lines = [
                        this.employeeName,
                        this.branchName,
                        this.branchAddress,
                        `GPS: ${this.latitude}, ${this.longitude}${this.locationAccuracy ? ' +/- ' + this.locationAccuracy + 'm' : ''}`,
                        new Date().toLocaleString(),
                    ];
                    const wrappedLines = this.overlayLines(context, lines, width - 48);
                    const overlayHeight = Math.min(height - 24, 30 + (wrappedLines.length * 19));
                    const overlayTop = height - overlayHeight - 12;

                    context.fillStyle = 'rgba(0, 0, 0, 0.72)';
                    context.fillRect(12, overlayTop, width - 24, overlayHeight);
                    context.fillStyle = '#fff';
                    context.font = 'bold 18px Arial';
                    context.fillText(wrappedLines[0] || this.employeeName, 24, overlayTop + 28);
                    context.font = '14px Arial';
                    wrappedLines.slice(1).forEach((line, index) => context.fillText(line, 24, overlayTop + 52 + (index * 18)));

                    this.proofImage = canvas.toDataURL('image/jpeg', 0.72);
                    this.proofPreview = this.proofImage;
                    this.message = `Proof captured (${Math.round(this.proofImage.length * 0.75 / 1024)} KB approx).`;
                },
                overlayLines(context, lines, maxWidth) {
                    const wrapped = [];

                    lines.forEach((line, index) => {
                        context.font = index === 0 ? 'bold 18px Arial' : '14px Arial';
                        const words = String(line || '').split(/\s+/).filter(Boolean);
                        let current = '';

                        words.forEach(word => {
                            const next = current ? `${current} ${word}` : word;
                            if (context.measureText(next).width <= maxWidth) {
                                current = next;
                                return;
                            }

                            if (current) {
                                wrapped.push(current);
                            }
                            current = word;
                        });

                        if (current) {
                            wrapped.push(current);
                        }
                    });

                    return wrapped;
                },
                async submit(url) {
                    if (!this.canSubmit) {
                        this.message = 'Verify employee, capture proof, and allow GPS first.';
                        return;
                    }

                    this.submitting = true;
                    this.lastResult = '';
                    this.message = 'Saving attendance...';

                    const response = await this.sendJson(url, {
                        latitude: this.latitude,
                        longitude: this.longitude,
                        face_image: this.proofImage,
                    });

                    this.submitting = false;

                    if (!response.ok) {
                        this.message = response.message;
                        return;
                    }

                    this.lastResult = response.data.message;
                    this.message = `${response.data.employee} recorded at ${response.data.time}.`;
                    this.proofImage = '';
                    this.proofPreview = '';
                    this.$nextTick(() => {
                        if (this.stream && this.$refs.video) {
                            this.$refs.video.srcObject = this.stream;
                            this.$refs.video.play?.();
                        } else {
                            this.startCamera();
                        }
                    });
                },
                refreshIcons() {
                    this.$nextTick(() => window.renderLucideIcons());
                },
                async sendJson(url, payload) {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || @js(csrf_token()),
                        },
                        body: JSON.stringify(payload),
                    });

                    const data = await response.json().catch(() => ({}));

                    return {
                        ok: response.ok,
                        data,
                        message: Object.values(data.errors || {})[0]?.[0] || data.message || 'Attendance failed.',
                    };
                },
            };
        }
    </script>
</body>
</html>
