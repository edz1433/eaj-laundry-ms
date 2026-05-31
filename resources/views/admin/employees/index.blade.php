@extends('layouts.app')

@section('page_title', 'Employees')

@section('content')
<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="employees" class="h-3.5 w-3.5"></span>
                Employee module
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Employees</h1>
            <p class="text-sm text-muted">Maintain monthly salary and face enrollment for attendance.</p>
        </div>

        <form method="GET" class="grid grid-cols-1 gap-2 sm:grid-cols-[12rem_16rem_auto]">
            @if(auth()->user()->isAdmin())
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <input name="search" value="{{ request('search') }}" placeholder="Search employee..." class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white">
                <span data-lucide="search" class="h-4 w-4"></span>
                Search
            </button>
        </form>
    </div>

    <div class="grid gap-3 xl:grid-cols-2">
        @forelse($employees as $employee)
            <form
                method="POST"
                action="{{ route('admin.employees.update', $employee) }}"
                x-data="faceEnrollment()"
                class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"
            >
                @csrf
                @method('PUT')
                <input type="hidden" name="face_image" x-model="faceImage">
                <input type="hidden" name="face_descriptors" x-model="faceDescriptorsJson">

                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $employee->name }}</p>
                        <p class="truncate text-sm text-muted">{{ str_replace('_', ' ', $employee->role) }} - {{ $employee->branch?->name ?? 'All branches' }}</p>
                    </div>
                    <span class="shrink-0 {{ \App\Support\StatusBadge::classes($employee->status) }}">
                        {{ \App\Support\StatusBadge::label($employee->status) }}
                    </span>
                </div>

                <div class="grid gap-3 md:grid-cols-[1fr_10rem]">
                    <label class="text-sm font-medium">
                        Monthly Salary
                        <input name="monthly_salary" type="number" min="0" step="0.01" value="{{ old('monthly_salary', $employee->monthly_salary ?? 0) }}" class="mt-1.5 h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    </label>

                    <div class="text-sm">
                        <p class="mb-1.5 font-medium">Face</p>
                        <div class="flex h-9 items-center rounded-md border border-border px-3 text-xs {{ $employee->face_enrolled_at ? 'text-green-700' : 'text-muted' }} dark:border-gray-800">
                            {{ $employee->face_enrolled_at ? '4 samples saved' : 'Not enrolled' }}
                        </div>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-[12rem_1fr]">
                    <div class="overflow-hidden rounded-md border border-border bg-smoke dark:border-gray-800 dark:bg-gray-950">
                        <video x-ref="video" autoplay muted playsinline class="aspect-video w-full -scale-x-100 object-cover"></video>
                        <canvas x-ref="canvas" class="hidden"></canvas>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <button type="button" @click="startCamera()" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="eye" class="h-4 w-4"></span>
                            Camera
                        </button>
                        <button type="button" @click="capture()" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="user" class="h-4 w-4"></span>
                            Capture Sample
                        </button>
                        <button type="button" @click="resetSamples()" class="inline-flex h-9 items-center justify-center rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">Reset</button>
                        <button class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-white">
                            Save
                        </button>
                        <p class="w-full text-xs font-medium text-primary">Samples: <span x-text="descriptors.length"></span>/4</p>
                        <p x-show="message" x-text="message" class="w-full text-xs text-muted"></p>
                    </div>
                </div>
            </form>
        @empty
            <div class="rounded-lg border border-border bg-white p-10 text-center text-sm text-muted dark:border-gray-800 dark:bg-gray-900">
                No employees found.
            </div>
        @endforelse
    </div>

    <div>{{ $employees->links() }}</div>
</div>

<script>
function faceEnrollment() {
    return {
        faceImage: '',
        faceDescriptorsJson: '',
        descriptors: [],
        message: '',
        modelsLoaded: false,
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
        async startCamera() {
            try {
                await this.loadModels();
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.$refs.video.srcObject = stream;
                this.message = 'Camera ready. Capture 4 clear samples: center, left, right, then center again.';
            } catch (error) {
                this.message = 'Camera permission is required.';
            }
        },
        async capture() {
            const video = this.$refs.video;
            if (!video.videoWidth) {
                this.message = 'Open the camera first.';
                return;
            }

            await this.loadModels();
            const faceapi = window.faceapi;
            const detection = await faceapi
                .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.65 }))
                .withFaceLandmarks(true)
                .withFaceDescriptor();

            if (!detection) {
                this.message = 'No clear face found. Face the camera with good lighting.';
                return;
            }

            const canvas = this.$refs.canvas;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            this.faceImage = canvas.toDataURL('image/jpeg', 0.88);
            this.descriptors.push(Array.from(detection.descriptor));
            this.descriptors = this.descriptors.slice(-4);
            this.faceDescriptorsJson = JSON.stringify(this.descriptors);
            this.message = this.descriptors.length >= 4 ? '4 samples captured. Click Save.' : `Sample ${this.descriptors.length} captured. Need ${4 - this.descriptors.length} more.`;
        },
        resetSamples() {
            this.faceImage = '';
            this.faceDescriptorsJson = '';
            this.descriptors = [];
            this.message = 'Samples cleared.';
        },
    };
}
</script>
@endsection
