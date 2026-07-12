@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h2 class="text-2xl font-bold mb-6">Settings</h2>

    @if (session('status'))
        <div class="bg-green-600 text-white p-4 rounded mb-6">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
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

        <div class="space-y-6">
            <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
                <h3 class="text-lg font-semibold text-white mb-4">System Tools</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-400 text-sm">yt-dlp version: <span class="text-white">{{ trim($ytDlpVersion) }}</span></p>
                    </div>
                    <form action="/settings/update-tools" method="POST">
                        @csrf
                        <button type="submit" class="bg-green-600 text-white p-2 rounded hover:bg-green-700">Check for Updates & Update</button>
                    </form>
                </div>
            </div>

            <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
                <h3 class="text-lg font-semibold text-white mb-4">Storage Settings</h3>
                <form action="/settings/storage-path" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-gray-400 text-sm mb-1">Downloads Directory Path</label>
                        <input type="text" name="storage_path" value="{{ old('storage_path', $storagePath) }}" class="w-full p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
                        <p class="text-xs text-gray-500 mt-1">Files will be saved here. Priorities: Database Setting (this) > ENV ('DOWNLOADS_PATH') > Default Directory (storage/app/public/downloads).</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Update Path</button>
                        <button type="button" id="btn-update-index" class="bg-yellow-600 text-white p-2 rounded hover:bg-yellow-700">Update Index</button>
                    </div>
                </form>
            </div>

            <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
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

            <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
                <h3 class="text-lg font-semibold text-white mb-4">About</h3>
                <div class="text-gray-400 text-sm space-y-2">
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
    </div>

    <!-- Download Queue Section -->
    <div class="mt-8 bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
        <h3 class="text-lg font-semibold text-white mb-4">Download Queue</h3>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm text-gray-300">
                <thead>
                    <tr class="border-b border-gray-800 text-gray-400 font-medium text-xs uppercase tracking-wider">
                        <th class="pb-3 pr-4">Video</th>
                        <th class="pb-3 px-4">Channel</th>
                        <th class="pb-3 px-4">Status</th>
                        <th class="pb-3 px-4 text-center">Retries</th>
                        <th class="pb-3 px-4">Queued At</th>
                        <th class="pb-3 pl-4">Details / Errors</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse ($queuedVideos as $video)
                        <tr class="hover:bg-gray-850/40 transition duration-150">
                            <td class="py-3.5 pr-4 max-w-xs font-semibold text-gray-100 truncate" title="{{ $video->title }}">
                                {{ $video->title }}
                            </td>
                            <td class="py-3.5 px-4 text-gray-400">
                                {{ $video->channel->name ?? 'Unknown' }}
                            </td>
                            <td class="py-3.5 px-4">
                                @if ($video->status === 'pending')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900/40 text-yellow-400 border border-yellow-800/60">Pending</span>
                                @elseif ($video->status === 'downloading')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-900/40 text-blue-400 border border-blue-800/60">Downloading</span>
                                @elseif ($video->status === 'failed')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-900/40 text-red-400 border border-red-800/60">Failed</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-800 text-gray-400 border border-gray-700">{{ ucfirst($video->status) }}</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-center font-mono">
                                {{ $video->retries }} / 3
                            </td>
                            <td class="py-3.5 px-4 text-gray-500 text-xs">
                                {{ $video->created_at->diffForHumans() }}
                            </td>
                            <td class="py-3.5 pl-4 text-xs max-w-sm">
                                @if ($video->last_error)
                                    <span class="text-red-400 line-clamp-1 italic" title="{{ $video->last_error }}">
                                        {{ $video->last_error }}
                                    </span>
                                @elseif ($video->prevent_download)
                                    <span class="text-yellow-500 italic">
                                        Prevented: {{ $video->unavailable_reason ?? 'Excluded' }}
                                    </span>
                                @else
                                    <span class="text-gray-500 italic">No errors logged</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-gray-500 italic">
                                No videos currently in the download queue. Your library is fully synced!
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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
