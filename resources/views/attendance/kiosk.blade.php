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

<body class="h-dvh overflow-hidden bg-[#f6f7f4] text-dark dark:bg-gray-950 dark:text-gray-100">
    <main
        x-data="publicTimeClock()"
        x-init="init()"
        class="mx-auto flex h-dvh w-full max-w-5xl items-stretch justify-center p-2 sm:p-4"
    >
        <section class="flex min-h-0 w-full min-w-0 flex-col overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border p-3 dark:border-gray-800 sm:p-4">
                <div class="flex min-w-0 items-center gap-3">
                    <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-10 w-10 shrink-0 rounded-md border border-border bg-white object-contain dark:border-gray-800 dark:bg-gray-950 sm:h-12 sm:w-12">
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium uppercase text-muted sm:text-xs">Employee Time Clock</p>
                        <h1 class="truncate text-base font-semibold tracking-normal sm:text-xl">{{ $appBusinessName }}</h1>
                    </div>
                </div>

                <div class="shrink-0 rounded-md border border-border bg-smoke px-2.5 py-1.5 text-right dark:border-gray-800 dark:bg-gray-950 sm:px-3 sm:py-2">
                    <p class="text-[11px] text-muted sm:text-xs">{{ now()->format('M d, Y') }}</p>
                    <p class="text-lg font-semibold tabular-nums sm:text-2xl" x-text="currentTime"></p>
                </div>
            </div>

            <div class="min-h-0 flex-1 p-2 sm:p-4">
                <div class="relative h-full overflow-hidden rounded-lg border border-border bg-gray-950 dark:border-gray-800">
                    <video x-ref="video" autoplay muted playsinline class="h-full w-full -scale-x-100 object-cover"></video>
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <div class="absolute inset-x-3 top-3 flex items-center justify-between gap-2">
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-text="modelsLoaded ? 'Live Camera' : 'Opening Camera'"></span>
                        <span class="rounded-md bg-black/65 px-2.5 py-1 text-xs font-medium text-white backdrop-blur" x-show="latitude && longitude">GPS Active</span>
                    </div>

                    <div class="absolute inset-x-3 bottom-3 space-y-3">
                        <div class="rounded-lg bg-black/65 p-3 text-white shadow-lg backdrop-blur">
                            <p class="text-sm font-medium" x-text="message"></p>
                            <div class="mt-2 grid grid-cols-3 gap-1.5 text-[11px]">
                                <template x-for="(step, index) in challengeSequence" :key="`${challengeNonce}-${index}`">
                                    <div class="rounded-md px-2 py-1 text-center capitalize" :class="index < challengeResult.length ? 'bg-green-500 text-white' : (index === challengeIndex ? 'bg-primary text-white' : 'bg-white/15 text-white/75')" x-text="step"></div>
                                </template>
                            </div>
                        </div>

                        <div x-show="lastResult" x-cloak class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-800 shadow-lg">
                            <p x-text="lastResult"></p>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="submit(@js(route('attendance.public-time-in')))" :disabled="!canSubmit || submitting" class="h-12 rounded-md bg-primary text-sm font-semibold text-white shadow-lg transition hover:opacity-90 disabled:opacity-50">
                                Time In
                            </button>
                            <button type="button" @click="submit(@js(route('attendance.public-time-out')))" :disabled="!canSubmit || submitting" class="h-12 rounded-md bg-white text-sm font-semibold text-dark shadow-lg transition hover:bg-smoke disabled:opacity-50">
                                Time Out
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        function publicTimeClock() {
            return {
                stream: null,
                modelsLoaded: false,
                descriptor: null,
                faceImage: '',
                latitude: '',
                longitude: '',
                locationAccuracy: null,
                locationWatchId: null,
                submitting: false,
                lastResult: '',
                currentTime: '',
                message: 'Opening live camera...',
                challengeNonce: '',
                challengeSequence: [],
                challengeResult: [],
                challengeIndex: 0,
                eyesWereOpen: false,
                monitorId: null,
                get canSubmit() {
                    return this.descriptor && this.latitude && this.longitude && this.challengeSequence.length && this.challengeResult.length === this.challengeSequence.length;
                },
                init() {
                    this.startClock();
                    this.$nextTick(() => this.start());
                },
                startClock() {
                    const update = () => {
                        this.currentTime = new Date().toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                        });
                    };

                    update();
                    setInterval(update, 1000);
                },
                async fetchChallenge() {
                    const response = await fetch(@js(route('attendance.challenge')), {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await response.json();
                    this.challengeNonce = data.nonce;
                    this.challengeSequence = data.sequence || [];
                    this.challengeResult = [];
                    this.challengeIndex = 0;
                    this.eyesWereOpen = false;
                },
                async loadModels() {
                    if (this.modelsLoaded) return;
                    this.message = 'Loading face recognition models...';
                    const faceapi = await window.loadFaceApi();
                    await Promise.all([
                        faceapi.nets.tinyFaceDetector.load('/models/face-api'),
                        faceapi.nets.faceLandmark68TinyNet.load('/models/face-api'),
                        faceapi.nets.faceRecognitionNet.load('/models/face-api'),
                    ]);
                    this.modelsLoaded = true;
                },
                async start() {
                    try {
                        await this.fetchChallenge();
                        await this.loadModels();
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                        this.$refs.video.srcObject = this.stream;
                        this.locate();
                        clearInterval(this.monitorId);
                        this.monitorId = setInterval(() => this.checkLiveFace(), 450);
                        this.message = this.nextChallengeMessage();
                    } catch (error) {
                        this.message = 'Camera permission is required.';
                    }
                },
                locate() {
                    if (!navigator.geolocation) {
                        this.message = 'GPS is not supported by this browser.';
                        return;
                    }

                    if (this.locationWatchId) {
                        navigator.geolocation.clearWatch(this.locationWatchId);
                    }

                    this.locationWatchId = navigator.geolocation.watchPosition(
                        position => {
                            this.latitude = position.coords.latitude.toFixed(7);
                            this.longitude = position.coords.longitude.toFixed(7);
                            this.locationAccuracy = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
                        },
                        () => this.message = 'Location permission is required.',
                        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
                    );
                },
                eyeAspectRatio(points) {
                    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
                    return (distance(points[1], points[5]) + distance(points[2], points[4])) / (2 * distance(points[0], points[3]));
                },
                nextChallengeMessage() {
                    const step = this.challengeSequence[this.challengeIndex];
                    if (!step) return 'Face verified. Choose Time In or Time Out.';

                    return {
                        blink: 'Blink once for the live face check.',
                        left: 'Turn your face to the left.',
                        right: 'Turn your face to the right.',
                    }[step] || 'Complete the live face check.';
                },
                completeCurrentChallenge(action) {
                    const expected = this.challengeSequence[this.challengeIndex];
                    if (expected !== action) return;

                    this.challengeResult.push(action);
                    this.challengeIndex += 1;
                    this.eyesWereOpen = false;
                    this.message = this.nextChallengeMessage();
                },
                faceQualityIsOk(result) {
                    const video = this.$refs.video;
                    const box = result.detection.box;
                    const faceWidthRatio = box.width / video.videoWidth;

                    return result.detection.score >= 0.7 && faceWidthRatio >= 0.18 && faceWidthRatio <= 0.75;
                },
                async checkLiveFace() {
                    const video = this.$refs.video;
                    if (!video.videoWidth) return;

                    const faceapi = window.faceapi;
                    const result = await faceapi
                        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.65 }))
                        .withFaceLandmarks(true)
                        .withFaceDescriptor();

                    if (!result) {
                        this.message = 'Keep one clear live face in the frame.';
                        return;
                    }

                    if (!this.faceQualityIsOk(result)) {
                        this.descriptor = null;
                        this.message = 'Move closer with good lighting and keep one clear face in frame.';
                        return;
                    }

                    const landmarks = result.landmarks;
                    const leftEye = landmarks.getLeftEye();
                    const rightEye = landmarks.getRightEye();
                    const ear = (this.eyeAspectRatio(leftEye) + this.eyeAspectRatio(rightEye)) / 2;

                    if (ear > 0.28) this.eyesWereOpen = true;
                    if (this.eyesWereOpen && ear < 0.22) this.completeCurrentChallenge('blink');

                    const box = result.detection.box;
                    const nose = landmarks.getNose()[3];
                    const noseRatio = (nose.x - box.x) / box.width;
                    if (noseRatio > 0.57) this.completeCurrentChallenge('left');
                    if (noseRatio < 0.43) this.completeCurrentChallenge('right');

                    this.descriptor = Array.from(result.descriptor);
                    if (!this.canSubmit) {
                        this.message = this.latitude && this.longitude ? this.nextChallengeMessage() : 'Allow GPS and complete the live face check.';
                    }
                },
                capture() {
                    const video = this.$refs.video;
                    if (!video.videoWidth) {
                        this.message = 'Start the camera first.';
                        return;
                    }

                    const canvas = this.$refs.canvas;
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    this.faceImage = canvas.toDataURL('image/jpeg', 0.88);
                    this.locate();
                    this.message = this.canSubmit ? 'Ready to time in or time out.' : 'Finish the live face challenge.';
                },
                captureFaceImage() {
                    const video = this.$refs.video;
                    if (!video.videoWidth) return '';

                    const canvas = this.$refs.canvas;
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);

                    return canvas.toDataURL('image/jpeg', 0.88);
                },
                async submit(url) {
                    if (!this.canSubmit) {
                        this.message = 'Complete live face verification and GPS first.';
                        return;
                    }

                    this.submitting = true;
                    this.lastResult = '';
                    this.message = 'Matching employee face...';
                    this.faceImage = this.captureFaceImage();

                    if (!this.faceImage) {
                        this.submitting = false;
                        this.message = 'Live camera is not ready yet.';
                        return;
                    }

                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || @js(csrf_token()),
                        },
                        body: JSON.stringify({
                            descriptor: this.descriptor,
                            latitude: this.latitude,
                            longitude: this.longitude,
                            face_image: this.faceImage,
                            challenge_nonce: this.challengeNonce,
                            challenge_result: this.challengeResult,
                        }),
                    });

                    const data = await response.json().catch(() => ({}));
                    this.submitting = false;

                    if (!response.ok) {
                        this.message = Object.values(data.errors || {})[0]?.[0] || 'Attendance failed.';
                        await this.fetchChallenge();
                        return;
                    }

                    this.lastResult = data.message;
                    this.message = `${data.employee} recorded at ${data.time}.`;
                    await this.fetchChallenge();
                },
            };
        }
    </script>
</body>
</html>
