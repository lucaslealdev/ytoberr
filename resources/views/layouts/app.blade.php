<!DOCTYPE html>
<html lang="en" class="h-full {{ ($lightModeEnabled ?? false) ? 'light-mode' : '' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Ytoberr is a self-hosted panel for archiving and monitoring YouTube channels and videos.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>@yield('title', 'Dashboard') | Ytoberr</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
</head>
<body class="bg-gray-950 text-gray-100 h-full flex">
    @include('components.sidebar')
    
    <div class="flex-1 flex flex-col h-full overflow-y-auto">
        <nav class="bg-gray-900 shadow p-4 flex justify-between items-center gap-4 text-gray-100">
            <div class="flex items-center gap-4 min-w-0">
                <button id="hamburger" class="md:hidden" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                </button>
                {{-- 'header' lets a page show something shorter/different here than in the
                     <title> tag above (e.g. the video show page, whose title can be very long
                     and already appears elsewhere on the page) --}}
                <h2 class="text-xl font-bold truncate">{{ $__env->yieldContent('header', $__env->yieldContent('title', 'Dashboard')) }}</h2>
            </div>
            <form action="/videos" method="GET" class="flex-1 max-w-md">
                <input type="search" name="search" value="{{ request()->query('search') }}" placeholder="Search videos by title or description..." class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100 text-sm">
            </form>
            <form action="/logout" method="POST" class="flex-shrink-0">
                @csrf
                <button type="submit" class="text-red-400 hover:text-red-300">➜ Logout</button>
            </form>
        </nav>
        <main class="p-8">
            @yield('content')
        </main>
        <footer class="p-4 text-center text-xs text-gray-600 border-t border-gray-800">
            Ytoberr v{{ config('app.version') }}
        </footer>
    </div>
    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const hamburger = document.getElementById('hamburger');
        const closeSidebar = document.getElementById('close-sidebar');

        function toggleSidebar() {
            sidebar.classList.toggle('hidden');
            overlay.classList.toggle('hidden');
        }

        hamburger.addEventListener('click', toggleSidebar);
        closeSidebar.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);
    </script>
    @include('components.flash-toast')
</body>
</html>
