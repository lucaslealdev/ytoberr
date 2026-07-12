<div id="sidebar" class="hidden md:block w-64 bg-gray-900 h-full p-4 fixed md:static z-50">
    <div class="flex justify-between items-center mb-8">
        <a href="/" class="text-xl font-bold text-gray-100">Ytoberr</a>
        <button id="close-sidebar" class="md:hidden text-gray-400 hover:text-white" aria-label="Close menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    <nav>
        <ul class="space-y-2">
            <li>
                <a href="/" class="block p-2 rounded border-l-4 {{ request()->is('/') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">🏠 Dashboard</a>
            </li>
            <li>
                <a href="/channels" class="block p-2 rounded border-l-4 {{ request()->is('channels*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">📺 Channels</a>
            </li>
            <li>
                <a href="/videos" class="block p-2 rounded border-l-4 {{ request()->is('videos*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">🎥 Videos</a>
            </li>
            <li>
                <a href="/settings" class="rounded border-l-4 flex items-center justify-between p-2 {{ request()->is('settings*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">
                    <span>⚙️ Settings</span>
                    @if(($pendingQueueCount ?? 0) > 0)
                        <span class="bg-blue-600 text-white text-xs rounded-full px-2 py-0.5">{{ $pendingQueueCount }}</span>
                    @endif
                </a>
            </li>
        </ul>
    </nav>
</div>
<div id="sidebar-overlay" class="hidden md:hidden fixed inset-0 bg-black bg-opacity-50 z-40"></div>
