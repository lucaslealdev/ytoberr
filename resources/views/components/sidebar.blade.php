<div id="sidebar" class="hidden md:flex md:flex-col w-64 bg-gray-900 h-full p-4 fixed md:static z-50">
    <div class="flex justify-between items-center mb-8">
        <a href="/" class="text-xl font-bold text-gray-100">Ytoberr</a>
        <button id="close-sidebar" class="md:hidden text-gray-400 hover:text-white" aria-label="Close menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    <nav class="md:flex-1">
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
                    <span class="flex items-center gap-1">
                        @if(($warningsCount ?? 0) > 0)
                            <span class="bg-red-600 text-white text-xs rounded-full px-2 py-0.5">{{ $warningsCount }}</span>
                        @endif
                    </span>
                </a>
            </li>
            @if($advancedModeEnabled ?? false)
                <li>
                    <a href="/processes" class="rounded border-l-4 flex items-center justify-between p-2 {{ request()->is('processes*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">
                        <span>🧩 Processes</span>
                        @if(($pendingQueueCount ?? 0) > 0)
                            <span class="bg-blue-600 text-white text-xs rounded-full px-2 py-0.5">{{ $pendingQueueCount }}</span>
                        @endif
                    </a>
                </li>
                <li>
                    <a href="/logs" class="block p-2 rounded border-l-4 {{ request()->is('logs*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">📋 Logs</a>
                </li>
                <li>
                    <a href="/cleaning" class="block p-2 rounded border-l-4 {{ request()->is('cleaning*') ? 'border-blue-500 bg-gray-800 text-white' : 'border-transparent hover:bg-gray-800 text-gray-300 hover:text-gray-100' }}">🧹 Cleaning</a>
                </li>
            @elseif((($pendingQueueCount ?? 0) > 0) || (($failedQueueCount ?? 0) > 0))
                {{-- Advanced Mode (and the Processes page behind it) is off, but there's still
                     pending or failed downloads a user should know about — a compact summary
                     here, rather than nothing, so they aren't left unaware without opting in to
                     the full queue internals. Links straight to /processes, which itself
                     redirects to Settings with a prompt to enable Advanced Mode. --}}
                <li>
                    <a href="/processes" class="block p-2 rounded border-l-4 border-transparent hover:bg-gray-800 text-gray-500 hover:text-gray-300 text-xs">
                        <span class="flex items-center gap-3">
                            @if(($pendingQueueCount ?? 0) > 0)
                                <span>⬇️ {{ $pendingQueueCount }} pending</span>
                            @endif
                            @if(($failedQueueCount ?? 0) > 0)
                                <span class="text-red-400">⚠️ {{ $failedQueueCount }} failed</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endif
        </ul>
    </nav>

    <div class="mt-4 pt-4 border-t border-gray-800">
        <div class="flex justify-between text-xs text-gray-400 mb-1">
            <span>Disk usage</span>
            <span>{{ $diskUsedPercent ?? 0 }}%</span>
        </div>
        <div class="w-full bg-gray-800 rounded-full h-2 overflow-hidden">
            <div class="h-2 rounded-full {{ $diskBarColor ?? 'bg-green-500' }}" style="width: {{ min(100, max(0, $diskUsedPercent ?? 0)) }}%"></div>
        </div>
    </div>
</div>
<div id="sidebar-overlay" class="hidden md:hidden fixed inset-0 bg-black bg-opacity-50 z-40"></div>
