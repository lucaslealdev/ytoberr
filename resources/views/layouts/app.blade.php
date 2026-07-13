<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Ytoberr is a self-hosted panel for archiving and monitoring YouTube channels and videos.">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='1' y='1' width='22' height='22' rx='5' fill='%23111827' stroke='%234b5563' stroke-width='1.5'/%3E%3Cpath d='M9 7.5l8 4.5-8 4.5v-9z' fill='%23f87171'/%3E%3C/svg%3E">
    <title>@yield('title', 'Dashboard') | Ytoberr</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 text-gray-100 h-full flex">
    @include('components.sidebar')
    
    <div class="flex-1 flex flex-col h-full overflow-y-auto">
        <nav class="bg-gray-900 shadow p-4 flex justify-between items-center gap-4 text-gray-100">
            <div class="flex items-center gap-4 flex-shrink-0">
                <button id="hamburger" class="md:hidden" aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                </button>
                <h2 class="text-xl font-bold">@yield('title', 'Dashboard')</h2>
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
</body>
</html>
