<!DOCTYPE html>
<html lang="en" x-data x-init="$store.theme.init()" class="scroll-smooth" style="--color-primary: {{ $appPrimaryColor }};">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page_title', 'Dashboard') - {{ $appBusinessName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.appDarkModeDefault = @js($appDarkModeDefault);
        window.appPrimaryColor = @js($appPrimaryColor);
    </script>
</head>

<body class="app-surface text-dark dark:text-gray-100">
    <div x-data="{ sidebarOpen: false }" class="min-h-screen">

        @include('partials.sidebar')

        <div class="flex min-h-screen min-w-0 flex-col lg:pl-64">
            @include('partials.topbar')

            <main class="flex-1 p-4 lg:p-5">
                <div class="mx-auto w-full max-w-7xl">
                    @include('partials.alerts')

                    {{ $slot ?? '' }}

                    @yield('content')
                </div>
            </main>

            @include('partials.footer')
        </div>
    </div>
</body>
</html>
