<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="{{ $appPrimaryColor }}">
    <title>@yield('page_title', 'Dashboard') - {{ $appBusinessName }}</title>
    <link rel="icon" href="{{ $appBusinessLogo }}">
    <link rel="apple-touch-icon" href="{{ $appBusinessLogo }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
</head>

<body class="app-surface text-dark dark:text-gray-100">
    <div x-data="{ sidebarOpen: false }" class="min-h-screen flex">

        @include('partials.sidebar')

        <div class="flex-1 flex flex-col min-w-0 lg:pl-64">
            @include('partials.topbar')

            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                @include('partials.alerts')

                {{ $slot ?? '' }}

                @yield('content')
            </main>

            @unless(trim($__env->yieldContent('hide_footer')))
                @include('partials.footer')
            @endunless
        </div>
    </div>
</body>
</html>
