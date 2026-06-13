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
    <main x-data="publicTimeClock()" x-init="init()" class="mx-auto min-h-dvh w-full max-w-5xl px-3 pb-[5.75rem] pt-3 sm:px-4 sm:pb-24 sm:pt-4 lg:flex lg:items-center lg:justify-center">
        <section class="grid w-full overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:max-h-[calc(100dvh-2rem)] lg:grid-cols-[minmax(0,1fr)_23rem]">
            <div class="bg-gray-950 p-2 sm:p-3 lg:min-h-0">
                <div class="relative h-[min(58dvh,26rem)] min-h-[20rem] overflow-hidden rounded-lg border border-gray-800 sm:h-[min(56dvh,32rem)] lg:h-full lg:min-h-[32rem]">
                    <video x-ref="video" x-show="!proofPreview" autoplay muted playsinline class="absolute inset-0 h-full w-full -scale-x-100 object-cover"></video>
                    <img x-show="proofPreview" x-cloak :src="proofPreview" alt="Captured attendance proof" class="absolute inset-0 h-full w-full object-cover">
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <div class="absolute inset-x-2 top-2 flex flex-wrap items-center justify-between gap-2 sm:inset-x-3 sm:top-3">
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-text="proofPreview ? 'Captured Proof' : (cameraReady ? 'Live Camera' : 'Camera Required')"></span>
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-show="latitude && longitude">GPS Active</span>
                    </div>

                    <div class="absolute inset-x-2 bottom-2 rounded-lg bg-black/70 p-2.5 text-white shadow-lg backdrop-blur sm:inset-x-3 sm:bottom-3 sm:p-3">
                        <p class="truncate text-sm font-semibold" x-text="employeeName || 'Employee proof photo'"></p>
                        <p class="mt-1 truncate text-xs text-white/80" x-text="branchName || 'Login and verify location to show branch details.'"></p>
                        <p class="mt-1 line-clamp-2 text-xs text-white/80" x-text="branchAddress || 'No branch address'"></p>
                        <p class="mt-1 truncate text-xs text-white/80" x-show="latitude && longitude">GPS: <span x-text="latitude"></span>, <span x-text="longitude"></span></p>
                        <p class="mt-2 text-xs" x-text="message"></p>
                    </div>
                </div>
            </div>

            <div class="flex min-h-0 flex-col p-3 sm:p-4">
                <div class="mb-3 flex items-center justify-between gap-2 sm:mb-4 sm:gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-10 w-10 shrink-0 rounded-md border border-border bg-white object-contain dark:border-gray-800 dark:bg-gray-950">
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium uppercase text-muted">Employee Time Clock</p>
                            <h1 class="truncate text-base font-semibold tracking-normal">{{ $employee->name }}</h1>
                            <p class="truncate text-xs text-muted">{{ $employee->branch?->name }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                        <div class="hidden text-right min-[360px]:block">
                            <p class="text-[11px] text-muted">{{ now()->format('M d, Y') }}</p>
                            <p class="text-base font-semibold tabular-nums sm:text-lg" x-text="currentTime"></p>
                        </div>
                        <form method="POST" action="{{ route('attendance.logout') }}">
                            @csrf
                            <button type="submit" title="Logout" aria-label="Logout" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                <span data-lucide="logout" class="h-4 w-4"></span>
                            </button>
                        </form>
                    </div>
                </div>

                <div x-show="!secureContext" x-cloak class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    <p>Camera and GPS are blocked on phones when opened through plain HTTP/IP address.</p>
                    <p class="mt-1">Open this kiosk using HTTPS, then tap Camera or JO again.</p>
                </div>

                <div x-show="cameraHelp" x-cloak class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                    <p x-text="cameraHelp"></p>
                </div>

                <div x-show="activeTab === 'clock'" class="space-y-3">
                    <div class="flex items-center gap-2 rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                        <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-9 w-9 shrink-0 rounded-md bg-white object-contain p-1 dark:bg-gray-900">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ $appBusinessName }}</p>
                            <p class="truncate text-xs text-muted">Time attendance</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="startCamera()" class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="eye" class="h-4 w-4"></span>
                            Camera
                        </button>
                        <button type="button" @click="locate()" class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="attendance" class="h-4 w-4"></span>
                            GPS
                        </button>
                    </div>

                    <button type="button" @click="prepare()" :disabled="!latitude || !longitude || verifying" class="h-11 w-full rounded-md bg-primary text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                        Verify Branch Location
                    </button>

                    <button type="button" @click="captureProof()" :disabled="!verified || !cameraReady" class="h-11 w-full rounded-md border border-border text-sm font-semibold hover:bg-smoke disabled:opacity-50 dark:border-gray-800 dark:hover:bg-gray-950">
                        Capture Proof Photo
                    </button>

                    <div x-show="lastResult" x-cloak class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-800">
                        <p x-text="lastResult"></p>
                    </div>

                    <div class="grid grid-cols-1 gap-2 pt-2 min-[360px]:grid-cols-2">
                        <button type="button" @click="submit(@js(route('attendance.public-time-in')))" :disabled="!canSubmit || submitting" class="h-12 rounded-md bg-primary text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-50">
                            Time In
                        </button>
                        <button type="button" @click="submit(@js(route('attendance.public-time-out')))" :disabled="!canSubmit || submitting" class="h-12 rounded-md border border-border text-sm font-semibold hover:bg-smoke disabled:opacity-50 dark:border-gray-800 dark:hover:bg-gray-950">
                            Time Out
                        </button>
                    </div>
                </div>

                <div x-cloak x-show="activeTab === 'tasks'" class="min-h-0 flex-1 space-y-3 overflow-y-auto lg:pr-1">
                    <div class="flex items-center gap-2 rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                        <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-9 w-9 shrink-0 rounded-md bg-white object-contain p-1 dark:bg-gray-900">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">End-of-Day Tasks</p>
                            <p class="truncate text-xs text-muted">{{ \Illuminate\Support\Carbon::parse($workDate)->format('M d, Y') }} - {{ $employee->branch?->name }}</p>
                        </div>
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
                                <input type="file" name="photo" accept="image/*" capture="environment" required class="w-full rounded-md border border-border bg-white px-2 py-2.5 text-sm dark:border-gray-800 dark:bg-gray-950">
                                <textarea name="remarks" rows="2" placeholder="Remarks" class="w-full rounded-md border border-border bg-white px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-950"></textarea>
                                <button class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:opacity-90">
                                    <span data-lucide="check" class="h-4 w-4"></span>
                                    {{ $completion ? 'Replace Proof' : 'Upload Proof' }}
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-md border border-border p-6 text-center text-sm text-muted dark:border-gray-800">No tasks configured.</div>
                    @endforelse
                </div>

                <div x-cloak x-show="activeTab === 'scan'" class="min-h-0 flex-1 space-y-3 overflow-y-auto lg:pr-1">
                    <div class="flex items-center gap-2 rounded-md border border-border bg-smoke p-3 dark:border-gray-800 dark:bg-gray-950">
                        <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-9 w-9 shrink-0 rounded-md bg-white object-contain p-1 dark:bg-gray-900">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">Scan Job Order from Pickup</p>
                            <p class="line-clamp-2 text-xs text-muted">{{ $employee->branch?->name }} will accept the laundry into production after a valid QR scan.</p>
                        </div>
                    </div>

                    <button type="button" @click="startQrScanner()" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:opacity-90">
                        <span data-lucide="qr" class="h-4 w-4"></span>
                        Open QR Scanner
                    </button>

                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <label class="mb-1.5 block text-sm font-medium">Manual QR / Job Order</label>
                        <div class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-[1fr_auto]">
                            <input x-model="manualQrText" type="text" placeholder="Paste QR URL or JO number" class="h-11 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <button type="button" @click="acceptScannedCode(manualQrText)" :disabled="scanSubmitting || !manualQrText" class="inline-flex h-11 items-center justify-center rounded-md bg-primary px-4 text-sm font-semibold text-white disabled:opacity-50">
                                Accept
                            </button>
                        </div>
                    </div>

                    <div x-show="scanResult" x-cloak class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-800">
                        <p x-text="scanResult"></p>
                    </div>
                    <div x-show="scanError" x-cloak class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
                        <p x-text="scanError"></p>
                    </div>
                </div>
            </div>
        </section>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-white/95 px-3 pb-[calc(env(safe-area-inset-bottom)+0.5rem)] pt-2 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 sm:px-4">
            <div class="mx-auto grid max-w-md grid-cols-3 gap-2">
                <button type="button" @click="activeTab = 'clock'; refreshIcons()" class="flex h-12 flex-col items-center justify-center rounded-md text-xs font-semibold" :class="activeTab === 'clock' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">
                    <span data-lucide="attendance" class="h-4 w-4"></span>
                    Clock
                </button>
                <button type="button" @click="activeTab = 'scan'; refreshIcons()" class="flex h-12 flex-col items-center justify-center rounded-md text-xs font-semibold" :class="activeTab === 'scan' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">
                    <span data-lucide="qr" class="h-4 w-4"></span>
                    JO
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
                cameraOpenId: 0,
                activeTab: 'clock',
                cameraReady: false,
                secureContext: window.isSecureContext,
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
                    this.$nextTick(() => {
                        if (!this.secureContext) {
                            this.message = 'Camera and GPS need HTTPS on phones. Plain IP/HTTP access is blocked by the browser.';
                            return;
                        }

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
                    await this.safePlayVideo();
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
