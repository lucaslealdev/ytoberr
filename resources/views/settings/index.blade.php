@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h2 class="text-2xl font-bold mb-6">Settings</h2>

    <div class="columns-1 xl:columns-2 gap-6">
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Profile</h3>
            <form action="/settings/profile" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Name</label>
                    <input type="text" name="name" value="{{ old('name', auth()->user()->name) }}" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
                </div>
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
                </div>
                <div>
                    <label class="block text-gray-400 text-sm mb-1">New Password (leave blank to keep current)</label>
                    <input type="password" name="password" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100">
                </div>
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Confirm New Password</label>
                    <input type="password" name="password_confirmation" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100">
                </div>
                <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Update Profile</button>
            </form>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">System Tools</h3>
            <div class="space-y-4">
                <div>
                    <p class="text-gray-400 text-sm">yt-dlp version: <span id="ytdlp-version" class="text-white">Checking&hellip;</span></p>
                </div>
                <form action="/settings/update-tools" method="POST">
                    @csrf
                    <button type="submit" class="bg-green-600 text-white p-2 rounded hover:bg-green-700">Check for Updates & Update</button>
                </form>
            </div>

            <div class="pt-4 mt-4 border-t border-gray-800">
                <form action="/settings/ytdlp-delay" method="POST" class="space-y-2">
                    @csrf
                    <label class="block text-gray-400 text-sm mb-1">Request Delay (seconds)</label>
                    <p class="text-xs text-gray-500">Sleep between yt-dlp requests and downloads, to avoid triggering YouTube's IP rate-limiting.</p>
                    <div class="flex items-center gap-2">
                        <input type="number" name="ytdlp_delay_seconds" min="0" max="120" value="{{ old('ytdlp_delay_seconds', $ytdlpDelaySeconds) }}" class="w-24 p-2 bg-gray-800 border border-gray-700 rounded text-gray-100">
                        <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 text-sm">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">YouTube Cookies</h3>
            <p class="text-xs text-gray-500 mb-4">
                If yt-dlp starts failing with "Sign in to confirm you're not a bot", providing cookies from a logged-in browser session works around it.
            </p>

            <p class="text-sm mb-4">
                @if ($cookiesConfigured)
                    <span class="text-green-400">✅ Cookies configured</span>
                    <span class="text-gray-500">(updated {{ $cookiesUpdatedAt->diffForHumans() }})</span>
                @else
                    <span class="text-yellow-500">⚠️ No cookies configured</span>
                @endif
            </p>

            <form action="{{ route('settings.cookies.update') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Upload cookies.txt</label>
                    <input type="file" name="cookies_file" accept=".txt" class="w-full text-xs text-gray-300 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-gray-800 file:text-gray-200 file:text-xs hover:file:bg-gray-700">
                </div>
                <p class="text-center text-xs text-gray-600">— or paste its contents —</p>
                <div>
                    <textarea name="cookies_text" rows="4" placeholder="# Netscape HTTP Cookie File&#10;.youtube.com&#9;TRUE&#9;/&#9;TRUE&#9;1799999999&#9;SID&#9;..." class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100 text-xs font-mono"></textarea>
                </div>
                @error('cookies_file')
                    <p class="text-red-400 text-xs">{{ $message }}</p>
                @enderror
                <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 text-sm">Save Cookies</button>
            </form>

            @if ($cookiesConfigured)
                <form action="{{ route('settings.cookies.delete') }}" method="POST" class="mt-3" onsubmit="return confirm('Remove the configured cookies?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Remove Cookies</button>
                </form>
            @endif

            <details class="mt-4">
                <summary class="cursor-pointer text-blue-400 text-xs">View expected file format (example)</summary>
                <pre class="mt-2 text-xs text-gray-300 whitespace-pre overflow-x-auto bg-gray-950 p-3 rounded"># Netscape HTTP Cookie File
# This file is generated by yt-dlp. Do not edit.

.youtube.com	TRUE	/	TRUE	1799999999	VISITOR_INFO1_LIVE	AbCdEfGhIjK
.youtube.com	TRUE	/	TRUE	1799999999	LOGIN_INFO	AFmmF2swRQIh_example_value_only
.youtube.com	TRUE	/	FALSE	1799999999	PREF	tz=America.Sao_Paulo
.youtube.com	TRUE	/	TRUE	1799999999	SID	g.a000_example_value_only
.youtube.com	TRUE	/	TRUE	1799999999	HSID	AbCdEfGhIjKlMnOp
.youtube.com	TRUE	/	TRUE	1799999999	SSID	AbCdEfGhIjKlMnOp</pre>
                <p class="text-xs text-gray-500 mt-2">
                    Fields are tab-separated: domain, include-subdomains, path, secure, expiration, name, value. The first line must be exactly <code class="text-gray-400"># HTTP Cookie File</code> or <code class="text-gray-400"># Netscape HTTP Cookie File</code>.
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    Export real cookies from a logged-in browser session using an extension such as <a href="https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies" target="_blank" rel="noopener" class="text-blue-400 underline">"Get cookies.txt LOCALLY"</a> for Chrome, or <a href="https://addons.mozilla.org/en-US/firefox/addon/cookies-txt/" target="_blank" rel="noopener" class="text-blue-400 underline">"cookies.txt"</a> for Firefox.
                </p>
                <p class="text-xs text-red-400 mt-2">
                    Treat this file as sensitive — it can grant access to the YouTube/Google account it was exported from. Never share it.
                </p>
            </details>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Storage Settings</h3>
            <form action="/settings/storage-path" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-gray-400 text-sm mb-1">Downloads Directory Path</label>
                    <input type="text" name="storage_path" value="{{ old('storage_path', $storagePath) }}" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
                    <p class="text-xs text-gray-500 mt-1">Files will be saved here. Priorities: Database Setting (this) > ENV ('DOWNLOADS_PATH') > Default Directory (storage/app/public/downloads).</p>
                    @error('storage_path')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-center space-x-2">
                    <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Update Path</button>
                    <button type="button" id="btn-update-index" class="bg-yellow-600 text-white p-2 rounded hover:bg-yellow-700">Update Index</button>
                </div>
            </form>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Cache Settings</h3>
            <div class="space-y-4">
                <p class="text-gray-400 text-sm">
                    Total cached yt-dlp metadata queries: <span class="text-white font-semibold">{{ $cacheCount }}</span>
                </p>
                <form action="/settings/reset-cache" method="POST" onsubmit="return confirm('Are you sure you want to clear the yt-dlp metadata cache?');">
                    @csrf
                    <button type="submit" class="bg-red-600 text-white p-2 rounded hover:bg-red-700">Clear Cache</button>
                </form>
            </div>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Theme</h3>
            <p class="text-xs text-gray-500 mb-4">Switches the panel to a light background with a red accent. The dark theme is the default.</p>
            <form action="/settings/light-mode" method="POST">
                @csrf
                <label class="flex items-center gap-2 text-gray-300 text-sm">
                    <input type="checkbox" name="light_mode" value="1" {{ $lightModeEnabled ? 'checked' : '' }} onchange="this.form.submit()" class="rounded bg-gray-800 border-gray-700">
                    Enable Light Mode
                </label>
            </form>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Advanced Mode</h3>
            <p class="text-xs text-gray-500 mb-4">Adds a "Processes" page to the sidebar showing what's running in the background right now (downloads, channel checks, and the raw Laravel queue), with controls to cancel, retry, or remove individual items.</p>
            <form action="/settings/advanced-mode" method="POST">
                @csrf
                <label class="flex items-center gap-2 text-gray-300 text-sm">
                    <input type="checkbox" name="advanced_mode" value="1" {{ $advancedModeEnabled ? 'checked' : '' }} onchange="this.form.submit()" class="rounded bg-gray-800 border-gray-700">
                    Enable Advanced Mode
                </label>
            </form>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">Backup &amp; Restore</h3>
            <div class="space-y-4">
                <form action="{{ route('settings.backups.create') }}" method="POST">
                    @csrf
                    <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Create Backup</button>
                </form>

                @if ($backupsList->isNotEmpty())
                    <ul class="divide-y divide-gray-800 text-sm">
                        @foreach ($backupsList as $backup)
                            <li class="py-2 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-gray-200 truncate" title="{{ $backup['name'] }}">{{ $backup['name'] }}</p>
                                    <p class="text-gray-500 text-xs">{{ $backup['created_at']->diffForHumans() }} &middot; {{ number_format($backup['size'] / 1024, 1) }} KB</p>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <a href="{{ route('settings.backups.download', $backup['name']) }}" class="text-blue-400 hover:text-blue-300 text-xs">Download</a>
                                    <form action="{{ route('settings.backups.restore', $backup['name']) }}" method="POST" onsubmit="return confirm('This will REPLACE the current database with this backup. This cannot be undone. Continue?');">
                                        @csrf
                                        <button type="submit" class="text-yellow-400 hover:text-yellow-300 text-xs">Restore</button>
                                    </form>
                                    <form action="{{ route('settings.backups.delete', $backup['name']) }}" method="POST" onsubmit="return confirm('Delete this backup?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Delete</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-gray-500 text-sm italic">No backups yet.</p>
                @endif

                <div class="pt-4 border-t border-gray-800">
                    <form action="{{ route('settings.backups.restore-upload') }}" method="POST" enctype="multipart/form-data" class="space-y-2" onsubmit="return confirm('This will REPLACE the current database with the uploaded file. This cannot be undone. Continue?');">
                        @csrf
                        <label class="block text-gray-400 text-sm mb-1">Restore from an uploaded backup file</label>
                        <input type="file" name="backup_file" accept=".sqlite" class="w-full text-xs text-gray-300 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-gray-800 file:text-gray-200 file:text-xs hover:file:bg-gray-700" required>
                        @error('backup_file')
                            <p class="text-red-400 text-xs">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="bg-yellow-700 text-white p-2 rounded hover:bg-yellow-800 text-sm">Upload &amp; Restore</button>
                    </form>
                </div>

                <p class="text-xs text-gray-500">Backups only include the database (channels, video index, settings) — downloaded videos themselves are not included and should be backed up separately (e.g. by snapshotting the Docker volume).</p>
            </div>
        </div>

        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-6 break-inside-avoid">
            <h3 class="text-lg font-semibold text-white mb-4">About</h3>
            <div class="text-gray-400 text-sm space-y-2">
                <p>
                    Version: <span class="text-white font-semibold">v{{ config('app.version') }}</span>
                    @if ($updateAvailable)
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900/40 text-yellow-400 border border-yellow-800/60">
                            Update available: v{{ $latestVersion }}
                        </span>
                    @endif
                </p>

                @if ($updateAvailable)
                    <div class="bg-gray-950 border border-yellow-800/60 rounded p-3 space-y-2">
                        <p class="text-yellow-400">A new version is available. To update:</p>
                        <pre class="text-xs text-gray-300 bg-gray-900 p-2 rounded overflow-x-auto">docker compose pull
docker compose up -d</pre>
                        <a href="https://github.com/{{ config('services.github_repo') }}/tags" target="_blank" rel="noopener" class="text-blue-400 underline text-xs">View releases on GitHub ↗</a>
                    </div>
                @endif

                <p>Ytoberr is licensed under the <a href="https://opensource.org/licenses/MIT" class="text-blue-400 underline">MIT License</a>.</p>
                <p>This is Free and Open Source Software (FOSS). It is free to use, modify, and distribute.</p>
                <p class="italic text-gray-500">Provided "as is", without warranty of any kind, express or implied.</p>

                <details class="mt-4">
                    <summary class="cursor-pointer text-blue-400">View Full License</summary>
                    <pre class="mt-2 text-xs text-gray-300 whitespace-pre-wrap bg-gray-800 p-4 rounded">
Copyright (c) 2026 Ytoberr Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the “Software”), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
                    </pre>
                </details>
            </div>
        </div>
    </div>

    <!-- Warnings Section -->
    <div class="mt-8 bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white">
                Warnings
                @if ($warnings->isNotEmpty())
                    <span class="ml-2 bg-red-600 text-white text-xs rounded-full px-2 py-0.5 align-middle">{{ $warnings->count() }}</span>
                @endif
            </h3>
            @if ($warnings->isNotEmpty())
                <form action="{{ route('settings.warnings.clear-all') }}" method="POST" onsubmit="return confirm('Clear all {{ $warnings->count() }} warnings? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-gray-400 hover:text-white text-xs">Clear all</button>
                </form>
            @endif
        </div>

        @if ($warnings->isEmpty())
            <p class="text-gray-500 text-sm italic">No warnings. Everything looks healthy.</p>
        @else
            <div class="space-y-3">
                @foreach ($warnings as $warning)
                    <div class="bg-gray-950 border border-red-900/40 rounded p-3">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-red-400 font-semibold text-sm">{{ $warning->message }}</p>
                                <p class="text-gray-500 text-xs mt-0.5">{{ $warning->source }} &middot; {{ $warning->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                @if ($warning->video)
                                    <form action="{{ route('videos.retry', $warning->video) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="text-green-400 hover:text-green-300 text-xs">Retry Download</button>
                                    </form>
                                @endif
                                <form action="{{ route('settings.warnings.delete', $warning) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-gray-400 hover:text-white text-xs">Dismiss</button>
                                </form>
                            </div>
                        </div>
                        @if ($warning->details)
                            <details class="mt-2">
                                <summary class="cursor-pointer text-blue-400 text-xs">View details</summary>
                                <pre class="mt-2 text-xs text-gray-300 whitespace-pre-wrap bg-gray-900 p-3 rounded max-h-60 overflow-y-auto">{{ $warning->details }}</pre>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Modal for Missing Videos Summary -->
    <div id="missing-videos-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-lg w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Missing Videos Summary</h3>
            
            <div id="modal-content" class="text-gray-300 text-sm max-h-60 overflow-y-auto space-y-2">
                <!-- Will be populated dynamically via JS -->
                <p>Checking for missing videos, please wait...</p>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-800">
                <button type="button" id="btn-close-modal" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Cancel</button>
                <form id="clean-index-form" action="/settings/clean-missing-videos" method="POST" class="hidden">
                    @csrf
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Confirm & Clean Index</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // yt-dlp takes the better part of a second to report its own version, so it's
            // fetched here instead of blocking the page's initial render.
            fetch('/settings/ytdlp-version')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('ytdlp-version').textContent = data.version;
                })
                .catch(() => {
                    document.getElementById('ytdlp-version').textContent = 'Unknown';
                });

            const modal = document.getElementById('missing-videos-modal');
            const modalContent = document.getElementById('modal-content');
            const btnUpdateIndex = document.getElementById('btn-update-index');
            const btnCloseModal = document.getElementById('btn-close-modal');
            const cleanIndexForm = document.getElementById('clean-index-form');

            btnUpdateIndex.addEventListener('click', function () {
                // Show modal with loading state
                modal.classList.remove('hidden');
                modalContent.innerHTML = '<p class="text-gray-400">Scanning downloads folder and checking against the database...</p>';
                cleanIndexForm.classList.add('hidden');

                // AJAX call to check missing videos
                fetch('/settings/check-missing-videos')
                    .then(response => response.json())
                    .then(data => {
                        if (data.length === 0) {
                            modalContent.innerHTML = `
                                <div class="text-center py-4">
                                    <svg class="mx-auto h-12 w-12 text-green-500 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="text-white font-semibold">Your index is perfectly clean!</p>
                                    <p class="text-gray-400 text-xs mt-1">All video records in the database exist on disk.</p>
                                </div>
                            `;
                        } else {
                            let listHtml = '<p class="text-yellow-500 font-semibold mb-2">The following ' + data.length + ' videos exist in the database but their files are missing from the downloads folder:</p>';
                            listHtml += '<ul class="list-disc list-inside space-y-1 bg-gray-950 p-3 rounded border border-gray-800 text-xs">';
                            data.forEach(video => {
                                listHtml += `<li><strong class="text-white">${video.title}</strong> <span class="text-gray-500">(${video.channel})</span></li>`;
                            });
                            listHtml += '</ul>';
                            listHtml += '<p class="text-xs text-red-400 mt-3 font-semibold">Warning: Confirming will permanently remove these ' + data.length + ' missing video records from your database index.</p>';
                            
                            modalContent.innerHTML = listHtml;
                            cleanIndexForm.classList.remove('hidden');
                        }
                    })
                    .catch(error => {
                        modalContent.innerHTML = '<p class="text-red-500">Error occurred while checking index. Please try again.</p>';
                        console.error('Error:', error);
                    });
            });

            btnCloseModal.addEventListener('click', function () {
                modal.classList.add('hidden');
            });
        });
    </script>
@endsection
