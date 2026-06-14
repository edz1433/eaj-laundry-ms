<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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

<body class="h-dvh overflow-hidden bg-[#eef2ed] text-dark overscroll-none dark:bg-gray-950 dark:text-gray-100">
    <main x-data="publicTimeClock()" x-init="init()" class="mx-auto flex h-dvh w-full max-w-6xl flex-col overflow-hidden bg-white dark:bg-gray-900 lg:my-3 lg:h-[calc(100dvh-1.5rem)] lg:rounded-2xl lg:border lg:border-border lg:shadow-xl dark:lg:border-gray-800">
        <header class="flex h-[4.25rem] shrink-0 items-center justify-between gap-3 border-b border-border px-3 dark:border-gray-800 sm:px-4">
            <div class="flex min-w-0 items-center gap-3">
                <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-10 w-10 shrink-0 rounded-xl border border-border bg-white object-contain p-1 dark:border-gray-800 dark:bg-gray-950">
                <div class="min-w-0">
                    <p class="truncate text-[10px] font-semibold uppercase tracking-[0.14em] text-primary">{{ $appBusinessName }}</p>
                    <h1 class="truncate text-base font-bold">{{ $employee->name }}</h1>
                    <p class="truncate text-xs text-muted">{{ $employee->branch?->name }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold min-[390px]:inline-flex" :class="online ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    <span x-text="online ? 'ONLINE' : 'OFFLINE'"></span>
                </span>
                <div class="text-right">
                    <p class="text-lg font-bold leading-none tabular-nums" x-text="currentTime"></p>
                    <p class="mt-1 text-[10px] font-medium text-muted">{{ now()->format('D, M d') }}</p>
                </div>
                <form method="POST" action="{{ route('attendance.logout') }}">
                    @csrf
                    <button type="submit" title="Logout" aria-label="Logout" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-white text-muted shadow-sm active:scale-95 dark:border-gray-800 dark:bg-gray-950">
                        <span data-lucide="logout" class="h-4 w-4"></span>
                    </button>
                </form>
            </div>
        </header>

        <section
            class="grid min-h-0 flex-1 overflow-hidden sm:grid-cols-[minmax(0,1fr)_22rem] sm:grid-rows-1 lg:grid-cols-[minmax(0,1fr)_24rem]"
            :class="activeTab === 'tasks' ? 'grid-rows-[minmax(0,1fr)]' : 'grid-rows-[minmax(12rem,38dvh)_minmax(0,1fr)]'"
        >
            <div x-show="activeTab !== 'tasks'" class="min-h-0 bg-gray-950 p-2 sm:p-3">
                <div class="relative h-full min-h-[12rem] overflow-hidden rounded-xl border border-gray-800">
                    <video x-ref="video" x-show="!proofPreview" autoplay muted playsinline class="absolute inset-0 h-full w-full -scale-x-100 object-cover"></video>
                    <img x-show="proofPreview" x-cloak :src="proofPreview" alt="Captured attendance proof" class="absolute inset-0 h-full w-full object-cover">
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <div class="absolute inset-x-2 top-2 flex items-center justify-between gap-2">
                        <span class="rounded-full bg-black/70 px-3 py-1 text-[11px] font-semibold text-white backdrop-blur" x-text="activeTab === 'scan' ? 'Job Order Scanner' : (proofPreview ? 'Proof Captured' : (cameraReady ? 'Live Camera' : 'Camera Required'))"></span>
                        <div class="flex gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold backdrop-blur" :class="cameraReady ? 'bg-green-500/90 text-white' : 'bg-black/70 text-white/70'">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span> CAM
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold backdrop-blur" :class="latitude && longitude ? 'bg-green-500/90 text-white' : 'bg-black/70 text-white/70'">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span> GPS
                            </span>
                        </div>
                    </div>

                    <div class="absolute inset-x-2 bottom-2 rounded-xl bg-black/75 px-3 py-2 text-white shadow-lg backdrop-blur">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold" x-text="message"></p>
                                <p class="mt-0.5 truncate text-[10px] text-white/70" x-text="branchAddress"></p>
                            </div>
                            <button x-show="activeTab === 'clock' && proofPreview" type="button" @click="proofImage = ''; proofPreview = ''; message = 'Capture a new proof photo.'" class="shrink-0 rounded-lg bg-white/15 px-2.5 py-1.5 text-[10px] font-semibold">Retake</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex min-h-0 flex-col overflow-hidden p-3 sm:p-4" :class="activeTab === 'tasks' ? 'sm:col-span-2 sm:px-6 lg:px-8' : ''">
                <div x-show="!online" x-cloak class="mb-2 shrink-0 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                    No server connection. Check Wi-Fi or mobile data. Attendance submission is paused.
                </div>

                <div x-show="!secureContext" x-cloak class="mb-2 shrink-0 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    Camera and GPS require HTTPS. Open the secure application URL.
                </div>

                <div x-show="cameraHelp" x-cloak class="mb-2 shrink-0 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                    <p x-text="cameraHelp"></p>
                </div>

                <div x-show="activeTab === 'clock'" class="flex min-h-0 flex-1 flex-col justify-center gap-2.5">
                    <div class="grid grid-cols-3 gap-1.5">
                        <div class="rounded-xl border px-2 py-2 text-center" :class="latitude && longitude ? 'border-green-200 bg-green-50 text-green-700' : 'border-border bg-smoke text-muted dark:border-gray-800 dark:bg-gray-950'">
                            <p class="text-[9px] font-bold uppercase tracking-wide">Location</p>
                            <p class="mt-0.5 truncate text-[11px] font-semibold" x-text="latitude && longitude ? 'Ready' : 'Waiting'"></p>
                        </div>
                        <div class="rounded-xl border px-2 py-2 text-center" :class="verified ? 'border-green-200 bg-green-50 text-green-700' : 'border-border bg-smoke text-muted dark:border-gray-800 dark:bg-gray-950'">
                            <p class="text-[9px] font-bold uppercase tracking-wide">Branch</p>
                            <p class="mt-0.5 truncate text-[11px] font-semibold" x-text="verified ? 'Verified' : 'Check'"></p>
                        </div>
                        <div class="rounded-xl border px-2 py-2 text-center" :class="proofImage ? 'border-green-200 bg-green-50 text-green-700' : 'border-border bg-smoke text-muted dark:border-gray-800 dark:bg-gray-950'">
                            <p class="text-[9px] font-bold uppercase tracking-wide">Photo</p>
                            <p class="mt-0.5 truncate text-[11px] font-semibold" x-text="proofImage ? 'Captured' : 'Needed'"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="locate()" :disabled="verifying" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-border bg-white px-3 text-sm font-semibold shadow-sm active:scale-[.98] disabled:opacity-50 dark:border-gray-800 dark:bg-gray-950">
                            <span data-lucide="attendance" class="h-4 w-4 text-primary"></span>
                            Refresh GPS
                        </button>
                        <button type="button" @click="prepare()" :disabled="!online || !latitude || !longitude || verifying" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary px-3 text-sm font-semibold text-white shadow-sm active:scale-[.98] disabled:opacity-40">
                            <span data-lucide="shieldCheck" class="h-4 w-4"></span>
                            <span x-text="verifying ? 'Checking...' : 'Verify Branch'"></span>
                        </button>
                    </div>

                    <button type="button" @click="captureProof()" :disabled="!verified || !cameraReady" class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-primary bg-primary/5 text-sm font-bold text-primary active:scale-[.99] disabled:border-border disabled:bg-smoke disabled:text-muted disabled:opacity-60 dark:disabled:border-gray-800 dark:disabled:bg-gray-950">
                        <span data-lucide="eye" class="h-4 w-4"></span>
                        <span x-text="proofImage ? 'Capture Again' : 'Capture Proof Photo'"></span>
                    </button>

                    <div x-show="lastResult" x-cloak class="rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-center text-xs font-semibold text-green-800">
                        <p x-text="lastResult"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="submit(@js(route('attendance.public-time-in')))" :disabled="!online || !canSubmit || submitting" class="h-14 rounded-xl bg-primary text-base font-bold text-white shadow-md active:scale-[.98] disabled:opacity-40">
                            TIME IN
                        </button>
                        <button type="button" @click="submit(@js(route('attendance.public-time-out')))" :disabled="!online || !canSubmit || submitting" class="h-14 rounded-xl border-2 border-primary bg-white text-base font-bold text-primary shadow-sm active:scale-[.98] disabled:border-border disabled:text-muted disabled:opacity-40 dark:bg-gray-950">
                            TIME OUT
                        </button>
                    </div>
                </div>

                <div x-cloak x-show="activeTab === 'tasks'" class="flex min-h-0 flex-1 flex-col">
                    <div class="mb-2 flex shrink-0 items-center justify-between rounded-xl bg-primary px-3 py-2.5 text-white">
                        <div>
                            <p class="text-sm font-bold">End-of-Day Tasks</p>
                            <p class="text-[10px] text-white/75">{{ \Illuminate\Support\Carbon::parse($workDate)->format('M d, Y') }}</p>
                        </div>
                        <span class="rounded-full bg-white/15 px-2.5 py-1 text-xs font-bold">{{ $dailyTasks->filter(fn ($task) => $task->completions->isNotEmpty())->count() }}/{{ $dailyTasks->count() }} done</span>
                    </div>

                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain pr-1">
                        @forelse($dailyTasks as $task)
                            @php($completion = $task->completions->first())
                            <div class="rounded-xl border border-border bg-white p-2.5 shadow-sm dark:border-gray-800 dark:bg-gray-950">
                                <button type="button" @click="openTaskId = openTaskId === {{ $task->id }} ? null : {{ $task->id }}; refreshIcons()" class="flex w-full items-center justify-between gap-3 text-left">
                                    <span class="flex min-w-0 items-center gap-2.5">
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $completion ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
                                            <span data-lucide="{{ $completion ? 'check' : 'attendance' }}" class="h-4 w-4"></span>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold">{{ $task->name }}</span>
                                            <span class="block text-[10px] text-muted">{{ $completion ? 'Completed '.$completion->completed_at?->format('h:i A') : 'Tap to upload proof' }}</span>
                                        </span>
                                    </span>
                                    <span class="text-xs font-bold {{ $completion ? 'text-green-700' : 'text-amber-700' }}">{{ $completion ? 'DONE' : 'PENDING' }}</span>
                                </button>

                                <form x-cloak x-show="openTaskId === {{ $task->id }}" x-transition method="POST" action="{{ route('attendance.daily-tasks.complete', $task) }}" enctype="multipart/form-data" class="mt-2 space-y-2 border-t border-border pt-2 dark:border-gray-800">
                                    @csrf
                                    @if($completion)
                                        <a href="{{ asset('storage/'.$completion->photo_path) }}" target="_blank" class="block text-xs font-semibold text-primary">View current proof</a>
                                    @endif
                                    <input type="file" name="photo" accept="image/*" capture="environment" required class="w-full rounded-lg border border-border bg-white px-2 py-2 text-xs dark:border-gray-800 dark:bg-gray-900">
                                    <div class="grid grid-cols-[1fr_auto] gap-2">
                                        <input name="remarks" placeholder="Optional remarks" class="h-10 min-w-0 rounded-lg border border-border bg-white px-3 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        <button class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-4 text-xs font-bold text-white">
                                            Upload
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted dark:border-gray-800">No tasks configured.</div>
                        @endforelse
                    </div>
                </div>

                <div x-cloak x-show="activeTab === 'scan'" class="flex min-h-0 flex-1 flex-col justify-center gap-3">
                    <div class="text-center">
                        <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <span data-lucide="qr" class="h-6 w-6"></span>
                        </span>
                        <p class="mt-2 text-sm font-bold">Receive Laundry for Production</p>
                        <p class="mt-1 text-xs text-muted">Scan the job order QR assigned to {{ $employee->branch?->name }}.</p>
                    </div>

                    <button type="button" @click="startQrScanner()" class="inline-flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary px-3 text-base font-bold text-white shadow-md active:scale-[.98]">
                        <span data-lucide="qr" class="h-5 w-5"></span>
                        Scan Job Order
                    </button>

                    <div class="rounded-xl border border-border bg-smoke p-2 dark:border-gray-800 dark:bg-gray-950">
                        <label class="mb-1.5 block px-1 text-[10px] font-bold uppercase tracking-wide text-muted">Manual JO number or QR URL</label>
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <input x-model="manualQrText" type="text" inputmode="text" placeholder="JO-000123" class="h-11 min-w-0 rounded-lg border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-900">
                            <button type="button" @click="acceptScannedCode(manualQrText)" :disabled="scanSubmitting || !manualQrText" class="h-11 rounded-lg bg-primary px-4 text-sm font-bold text-white disabled:opacity-40">
                                Accept
                            </button>
                        </div>
                    </div>

                    <div x-show="scanResult" x-cloak class="rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-center text-xs font-semibold text-green-800">
                        <p x-text="scanResult"></p>
                    </div>
                    <div x-show="scanError" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-center text-xs font-semibold text-red-700">
                        <p x-text="scanError"></p>
                    </div>
                </div>
            </div>
        </section>

        <nav class="shrink-0 border-t border-border bg-white px-3 pb-[max(env(safe-area-inset-bottom),0.5rem)] pt-2 dark:border-gray-800 dark:bg-gray-900">
            <div class="mx-auto grid max-w-md grid-cols-3 gap-2">
                <button type="button" @click="switchTab('clock')" class="flex h-12 items-center justify-center gap-2 rounded-xl text-xs font-bold active:scale-[.98]" :class="activeTab === 'clock' ? 'bg-primary text-white shadow-sm' : 'text-muted'">
                    <span data-lucide="attendance" class="h-4 w-4"></span>
                    Clock
                </button>
                <button type="button" @click="switchTab('scan')" class="flex h-12 items-center justify-center gap-2 rounded-xl text-xs font-bold active:scale-[.98]" :class="activeTab === 'scan' ? 'bg-primary text-white shadow-sm' : 'text-muted'">
                    <span data-lucide="qr" class="h-4 w-4"></span>
                    Receive
                </button>
                <button type="button" @click="switchTab('tasks')" class="flex h-12 items-center justify-center gap-2 rounded-xl text-xs font-bold active:scale-[.98]" :class="activeTab === 'tasks' ? 'bg-primary text-white shadow-sm' : 'text-muted'">
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
                locationWatchId: null,
                cameraOpenId: 0,
                activeTab: 'clock',
                openTaskId: null,
                cameraReady: false,
                secureContext: window.isSecureContext,
                online: navigator.onLine,
                connectivityTimer: null,
                cameraHelp: '',
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
                manualQrText: '',
                qrScanning: false,
                scanSubmitting: false,
                scanResult: '',
                scanError: '',
                currentTime: '',
                message: 'Allow GPS, capture proof, then clock in or clock out.',
                get canSubmit() {
                    return this.verified && this.proofImage && this.latitude && this.longitude;
                },
                init() {
                    this.startClock();
                    this.bindDeviceEvents();
                    this.checkConnectivity();
                    this.connectivityTimer = window.setInterval(() => this.checkConnectivity(), 15000);
                    this.$nextTick(() => {
                        if (!this.secureContext) {
                            this.message = 'Camera and GPS need HTTPS on phones. Plain IP/HTTP access is blocked by the browser.';
                            return;
                        }

                        this.startCamera();
                        this.locate();
                    });
                },
                bindDeviceEvents() {
                    window.addEventListener('online', () => this.checkConnectivity());
                    window.addEventListener('offline', () => {
                        this.online = false;
                        this.message = 'No internet connection. Check Wi-Fi or mobile data.';
                    });
                    document.addEventListener('visibilitychange', () => {
                        if (document.hidden) {
                            this.qrScanning = false;
                            this.stopCamera();
                            return;
                        }

                        this.checkConnectivity();
                        this.locate();

                        if (this.activeTab === 'clock') {
                            this.startCamera();
                        } else if (this.activeTab === 'scan') {
                            this.startQrScanner();
                        }
                    });
                    window.addEventListener('beforeunload', () => {
                        this.stopCamera();
                        this.stopLocationWatch();
                        window.clearInterval(this.connectivityTimer);
                    });
                },
                async checkConnectivity() {
                    if (!navigator.onLine) {
                        this.online = false;
                        return false;
                    }

                    const controller = new AbortController();
                    const timeout = window.setTimeout(() => controller.abort(), 7000);

                    try {
                        const response = await fetch(@js(route('attendance.connectivity')), {
                            method: 'GET',
                            headers: { 'Accept': 'application/json' },
                            cache: 'no-store',
                            credentials: 'same-origin',
                            signal: controller.signal,
                        });
                        this.online = response.ok;
                    } catch (error) {
                        this.online = false;
                    } finally {
                        window.clearTimeout(timeout);
                    }

                    return this.online;
                },
                startClock() {
                    const update = () => {
                        this.currentTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });
                    };
                    update();
                    setInterval(update, 1000);
                },
                switchTab(tab) {
                    this.qrScanning = false;
                    this.activeTab = tab;

                    if (tab === 'tasks') {
                        this.stopCamera();
                    } else if (tab === 'clock') {
                        this.proofPreview = '';
                        this.proofImage = '';
                        this.startCamera();
                    }

                    this.refreshIcons();
                },
                async startCamera() {
                    await this.openCamera('user', 'Camera ready. Verify employee and branch.');
                },
                async startQrScanner() {
                    this.proofPreview = '';
                    this.proofImage = '';
                    this.scanResult = '';
                    this.scanError = '';
                    this.message = 'Point the camera at the job order QR.';

                    const opened = await this.openCamera('environment', 'Point the camera at the job order QR.');
                    if (!opened) {
                        this.scanError = this.cameraHelp || 'Camera permission is required. You can paste the JO number manually.';
                        return;
                    }

                    if (!('BarcodeDetector' in window)) {
                        this.scanError = 'QR auto-scan is not supported on this browser. Paste the JO number or QR URL manually.';
                        return;
                    }

                    this.qrScanning = true;
                    const detector = new BarcodeDetector({ formats: ['qr_code'] });
                    const scanFrame = async () => {
                        if (!this.qrScanning || this.activeTab !== 'scan') return;

                        try {
                            const codes = await detector.detect(this.$refs.video);
                            const qrValue = codes[0]?.rawValue;
                            if (qrValue) {
                                this.qrScanning = false;
                                this.manualQrText = qrValue;
                                await this.acceptScannedCode(qrValue);
                                return;
                            }
                        } catch (error) {
                            this.scanError = 'Could not read QR yet. Keep the code inside the camera frame.';
                        }

                        requestAnimationFrame(scanFrame);
                    };

                    requestAnimationFrame(scanFrame);
                },
                async openCamera(facingMode, successMessage) {
                    const openId = ++this.cameraOpenId;
                    this.cameraHelp = '';

                    if (!this.secureContext) {
                        this.cameraReady = false;
                        this.cameraHelp = 'Phone browsers block camera and GPS on HTTP/IP access. Use HTTPS for this system URL.';
                        this.message = this.cameraHelp;
                        return false;
                    }

                    if (!navigator.mediaDevices?.getUserMedia) {
                        this.cameraReady = false;
                        this.cameraHelp = 'This browser does not support live camera access. Use Chrome or Safari over HTTPS.';
                        this.message = this.cameraHelp;
                        return false;
                    }

                    let nextStream = null;

                    try {
                        this.stopCamera();
                        nextStream = await navigator.mediaDevices.getUserMedia({
                            video: { facingMode: { ideal: facingMode } },
                            audio: false,
                        });
                    } catch (error) {
                        try {
                            nextStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                        } catch (fallbackError) {
                            this.cameraReady = false;
                            this.cameraHelp = this.cameraErrorMessage(fallbackError);
                            this.message = this.cameraHelp;
                            return false;
                        }
                    }

                    if (openId !== this.cameraOpenId) {
                        nextStream?.getTracks().forEach(track => track.stop());
                        return false;
                    }

                    this.stream = nextStream;
                    this.$refs.video.srcObject = this.stream;
                    const playing = await this.safePlayVideo();
                    if (!playing && !this.$refs.video?.videoWidth) {
                        this.stopCamera();
                        return false;
                    }

                    this.cameraReady = true;
                    this.message = successMessage;
                    return true;
                },
                stopCamera() {
                    if (!this.stream) return;

                    this.stream.getTracks().forEach(track => track.stop());
                    this.stream = null;
                    this.cameraReady = false;
                },
                async safePlayVideo() {
                    try {
                        await this.$refs.video?.play?.();
                        return true;
                    } catch (error) {
                        if (error?.name === 'AbortError') {
                            return false;
                        }

                        this.cameraHelp = this.cameraErrorMessage(error);
                        this.message = this.cameraHelp;
                        return false;
                    }
                },
                cameraErrorMessage(error) {
                    if (!this.secureContext) {
                        return 'Phone browsers block camera and GPS on HTTP/IP access. Use HTTPS for this system URL.';
                    }

                    if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
                        return 'Camera permission was blocked. Allow camera permission in the browser, then tap Camera again.';
                    }

                    if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') {
                        return 'No camera was found on this device.';
                    }

                    if (error?.name === 'NotReadableError' || error?.name === 'TrackStartError') {
                        return 'The camera is already being used by another app. Close it, then try again.';
                    }

                    return 'Camera could not open. Use HTTPS and allow camera permission, then try again.';
                },
                locate() {
                    if (!this.secureContext) {
                        this.message = 'GPS needs HTTPS on phones. Plain IP/HTTP access is blocked by the browser.';
                        return;
                    }

                    if (!navigator.geolocation) {
                        this.message = 'GPS is not supported by this browser.';
                        return;
                    }

                    this.stopLocationWatch();
                    this.message = 'Getting accurate GPS location...';
                    this.locationWatchId = navigator.geolocation.watchPosition(
                        position => {
                            this.latitude = position.coords.latitude.toFixed(7);
                            this.longitude = position.coords.longitude.toFixed(7);
                            this.locationAccuracy = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
                            this.message = this.locationAccuracy
                                ? `GPS ready (accuracy about ${this.locationAccuracy}m).`
                                : 'GPS location ready.';

                            if (this.locationAccuracy !== null && this.locationAccuracy <= 50) {
                                this.stopLocationWatch();
                            }
                        },
                        error => {
                            this.stopLocationWatch();
                            this.message = this.locationErrorMessage(error);
                        },
                        { enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 }
                    );
                },
                stopLocationWatch() {
                    if (this.locationWatchId === null) return;

                    navigator.geolocation.clearWatch(this.locationWatchId);
                    this.locationWatchId = null;
                },
                locationErrorMessage(error) {
                    if (!this.secureContext) {
                        return 'GPS requires an HTTPS application URL.';
                    }

                    if (error?.code === 1) {
                        return 'Location permission is blocked. Enable precise location for this app, then tap Refresh GPS.';
                    }

                    if (error?.code === 2) {
                        return 'GPS location is unavailable. Turn on device Location and try again.';
                    }

                    if (error?.code === 3) {
                        return 'GPS timed out. Move near a window or outdoors, then tap Refresh GPS.';
                    }

                    return 'Could not get the device location.';
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
                        new Date().toLocaleString([], { hour12: true }),
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
                            this.safePlayVideo();
                        } else {
                            this.startCamera();
                        }
                    });
                },
                async acceptScannedCode(qrText) {
                    if (!qrText || this.scanSubmitting) return;

                    this.scanSubmitting = true;
                    this.scanResult = '';
                    this.scanError = '';
                    this.message = 'Accepting laundry into production...';

                    const response = await this.sendJson(@js(route('attendance.job-orders.scan')), {
                        qr_text: qrText,
                    });

                    this.scanSubmitting = false;

                    if (!response.ok) {
                        this.scanError = response.message;
                        this.message = 'Scan failed. Check assignment branch or QR code.';
                        return;
                    }

                    this.scanResult = response.data.message;
                    this.message = `${response.data.job_order_number} accepted for production.`;
                },
                refreshIcons() {
                    this.$nextTick(() => window.renderLucideIcons());
                },
                async sendJson(url, payload) {
                    if (!await this.checkConnectivity()) {
                        return {
                            ok: false,
                            data: {},
                            message: 'No server connection. Check Wi-Fi or mobile data and try again.',
                        };
                    }

                    const controller = new AbortController();
                    const timeout = window.setTimeout(() => controller.abort(), 30000);
                    let response;

                    try {
                        response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || @js(csrf_token()),
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify(payload),
                            signal: controller.signal,
                        });
                        this.online = true;
                    } catch (error) {
                        this.online = false;

                        return {
                            ok: false,
                            data: {},
                            message: error?.name === 'AbortError'
                                ? 'The server took too long to respond. Check the connection and try again.'
                                : 'Could not reach the server. Check Wi-Fi or mobile data.',
                        };
                    } finally {
                        window.clearTimeout(timeout);
                    }

                    const data = await response.json().catch(() => ({}));

                    if (response.status === 401 && data.redirect) {
                        window.location.assign(data.redirect);
                    }

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
