@extends('layouts.app')

@section('title', $channel->name)
@section('header', 'Channel Details')

@section('content')
    @if (session('status'))
        <div class="bg-green-600 text-white p-4 rounded mb-6">
            {{ session('status') }}
        </div>
    @endif

    <!-- Breadcrumb -->
    <nav class="text-sm text-gray-400 mb-6">
        <a href="/channels" class="hover:text-white transition duration-200">Channels</a>
        <span class="mx-2">&gt;</span>
        <span class="text-gray-100 font-semibold">{{ $channel->name }}</span>
    </nav>

    <!-- Channel Header Banner -->
    <div class="relative bg-gray-900 rounded-lg overflow-hidden shadow-lg border border-gray-800 mb-8">
        @php
            $bannerPath = 'channels/' . $channel->id . '/banner.jpg';
            $fanartPath = 'channels/' . $channel->id . '/fanart.jpg';
            $hasBanner = \Illuminate\Support\Facades\Storage::disk('public')->exists($bannerPath);
            $hasFanart = \Illuminate\Support\Facades\Storage::disk('public')->exists($fanartPath);
            $coverUrl = null;
            if ($hasBanner) {
                $coverUrl = asset('storage/' . $bannerPath);
            } elseif ($hasFanart) {
                $coverUrl = asset('storage/' . $fanartPath);
            }
        @endphp

        @if ($coverUrl)
            <div class="h-48 md:h-64 w-full bg-cover bg-center" style="background-image: url('{{ $coverUrl }}');">
                <div class="absolute inset-0 bg-gradient-to-t from-gray-950 via-gray-950/40 to-transparent"></div>
            </div>
        @else
            <div class="h-24 md:h-32 w-full bg-gradient-to-r from-blue-900 to-indigo-950"></div>
        @endif

        <!-- Channel actions (kebab menu) -->
        <div class="absolute top-4 right-4 z-20" id="channel-actions-menu-wrapper">
            <button
                type="button"
                id="channel-actions-toggle"
                class="p-2 rounded-full bg-gray-900/70 hover:bg-gray-800 text-gray-300 hover:text-white backdrop-blur transition duration-200"
                aria-label="Channel actions"
                aria-haspopup="true"
                aria-expanded="false"
            >
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" /></svg>
            </button>

            <div id="channel-actions-dropdown" class="hidden absolute right-0 mt-2 w-60 bg-gray-800 border border-gray-700 rounded-lg shadow-xl py-1 text-sm">
                <button type="button" id="btn-open-channel-settings" class="w-full text-left px-4 py-2 text-gray-200 hover:bg-gray-700 flex items-center gap-2">
                    <span>⚙️</span> Channel Settings
                </button>
                <button type="button" id="btn-check-new-videos" class="w-full text-left px-4 py-2 text-gray-200 hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                    <span id="check-new-videos-icon">🔄</span>
                    <span id="check-new-videos-label">Check for New Videos</span>
                </button>
                <div class="border-t border-gray-700 my-1"></div>
                <button type="button" id="btn-open-delete-channel" class="w-full text-left px-4 py-2 text-red-400 hover:bg-gray-700 flex items-center gap-2">
                    <span>🗑️</span> Delete Channel
                </button>
            </div>

            <p id="check-new-videos-result" class="hidden absolute right-0 mt-2 w-60 text-xs text-gray-200 bg-gray-900 border border-gray-700 rounded px-3 py-2 shadow-xl"></p>
        </div>

        <div class="p-6 flex flex-col md:flex-row items-center md:items-end gap-6 {{ $coverUrl ? '-mt-16 md:-mt-20' : '' }} relative z-10">
            @if ($channel->profile_image_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($channel->profile_image_path))
                <img src="{{ asset('storage/' . $channel->profile_image_path) }}" alt="{{ $channel->name }}" class="w-24 h-24 md:w-32 md:h-32 rounded-full border-4 border-gray-900 object-cover shadow-2xl bg-gray-900">
            @else
                <div class="w-24 h-24 md:w-32 md:h-32 rounded-full border-4 border-gray-900 bg-gray-800 flex items-center justify-center text-gray-500 text-3xl font-bold shadow-2xl">?</div>
            @endif

            <div class="text-center md:text-left flex-1 space-y-1">
                <h1 class="text-2xl md:text-4xl font-bold text-white shadow-sm">{{ $channel->name }}</h1>
                <p class="text-gray-400 text-sm">
                    {{ $channel->youtube_id }}
                    <span class="mx-1.5">•</span>
                    {{ $videos->total() }} {{ Str::plural('video', $videos->total()) }} archived
                    <span class="mx-1.5">•</span>
                    {{ \Illuminate\Support\Number::fileSize($channel->totalDownloadedBytes(), precision: 1) }} total
                    <span class="mx-1.5">•</span>
                    {{ $channel->download_quality ?? '720p' }}
                </p>
            </div>
        </div>
    </div>

    <!-- Videos Grid -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <h3 class="text-lg font-bold text-white">Archived Videos</h3>
        <div class="flex gap-2 items-center text-sm">
            <span class="text-gray-400">Order by:</span>
            <select onchange="window.location.href='?video_sort='+this.value" class="bg-gray-800 text-gray-100 p-2 rounded border border-gray-700 cursor-pointer">
                <option value="newest" {{ $videoSort === 'newest' ? 'selected' : '' }}>Newest</option>
                <option value="oldest" {{ $videoSort === 'oldest' ? 'selected' : '' }}>Oldest</option>
                <option value="title" {{ $videoSort === 'title' ? 'selected' : '' }}>Title (A-Z)</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @forelse ($videos as $video)
            @include('videos._channel-video-card', ['video' => $video])
        @empty
            <div class="col-span-full p-12 bg-gray-900 border border-gray-800 rounded-lg text-center text-gray-400">
                <svg class="mx-auto h-12 w-12 text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <p class="font-semibold text-white">No archived videos yet</p>
                <p class="text-sm text-gray-500 mt-1">Check back later or run the polling command to discover new videos.</p>
            </div>
        @endforelse
    </div>

    {{ $videos->links('components.pagination') }}

    <!-- Channel Settings Modal -->
    <div id="channel-settings-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-5">
            <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Channel Settings</h3>

            <form action="/channels/{{ $channel->id }}/settings" method="POST" class="space-y-5">
                @csrf
                @method('PATCH')

                <div class="space-y-2">
                    <label class="block text-gray-400 text-sm">Cut-off Date</label>
                    <p class="text-xs text-gray-500">Videos published before this date are never downloaded.</p>
                    <input type="date" name="cutoff_date" value="{{ $channel->cutoff_date }}" class="w-full bg-gray-800 border border-gray-700 text-gray-100 rounded p-2">
                </div>

                <div class="space-y-2">
                    <label class="block text-gray-400 text-sm">Download Quality</label>
                    <select name="quality" class="w-full bg-gray-800 text-gray-100 rounded border border-gray-700 p-2 cursor-pointer">
                        <option value="480p" {{ $channel->download_quality === '480p' ? 'selected' : '' }}>480p</option>
                        <option value="720p" {{ $channel->download_quality === '720p' ? 'selected' : '' }}>720p</option>
                        <option value="1080p" {{ $channel->download_quality === '1080p' ? 'selected' : '' }}>1080p</option>
                    </select>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <label for="download_shorts" class="text-sm text-gray-400">
                        Download Shorts
                        <span class="block text-xs text-gray-500 mt-0.5">Off by default — Shorts are skipped when checking for new videos.</span>
                    </label>
                    <input type="checkbox" id="download_shorts" name="download_shorts" value="1" {{ $channel->download_shorts ? 'checked' : '' }} class="flex-shrink-0 rounded border-gray-600 bg-gray-800">
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                    <button type="button" id="btn-close-channel-settings" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Close</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm transition duration-200">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Channel Confirmation Modal -->
    <div id="delete-channel-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Delete Channel</h3>

            <p class="text-gray-300 text-sm">
                Are you sure you want to remove <strong class="text-white">{{ $channel->name }}</strong>? This cannot be undone.
            </p>

            <form action="/channels/{{ $channel->id }}" method="POST">
                @csrf
                @method('DELETE')
                <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                    <input type="checkbox" name="delete_files" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                    <span>
                        Also delete downloaded files and images from disk
                        <span class="block text-xs text-gray-500 mt-0.5">Permanently removes this channel's downloaded videos, thumbnails and metadata files, as well as its stored poster, banner and fanart images. This cannot be undone.</span>
                    </span>
                </label>

                <div class="flex justify-end space-x-3 pt-4 mt-4 border-t border-gray-800">
                    <button type="button" id="btn-cancel-delete-channel" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Cancel</button>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Delete Channel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Kebab dropdown ---
            const menuWrapper = document.getElementById('channel-actions-menu-wrapper');
            const menuToggle = document.getElementById('channel-actions-toggle');
            const menuDropdown = document.getElementById('channel-actions-dropdown');

            function closeMenu() {
                menuDropdown.classList.add('hidden');
                menuToggle.setAttribute('aria-expanded', 'false');
            }

            menuToggle.addEventListener('click', function (event) {
                event.stopPropagation();
                menuDropdown.classList.toggle('hidden');
                menuToggle.setAttribute('aria-expanded', String(!menuDropdown.classList.contains('hidden')));
            });

            document.addEventListener('click', function (event) {
                if (!menuWrapper.contains(event.target)) {
                    closeMenu();
                }
            });

            // --- Channel settings modal ---
            const settingsModal = document.getElementById('channel-settings-modal');
            const btnOpenSettings = document.getElementById('btn-open-channel-settings');
            const btnCloseSettings = document.getElementById('btn-close-channel-settings');

            btnOpenSettings.addEventListener('click', function () {
                closeMenu();
                settingsModal.classList.remove('hidden');
            });
            btnCloseSettings.addEventListener('click', function () {
                settingsModal.classList.add('hidden');
            });
            settingsModal.addEventListener('click', function (event) {
                if (event.target === settingsModal) {
                    settingsModal.classList.add('hidden');
                }
            });

            // --- Delete channel modal ---
            const deleteModal = document.getElementById('delete-channel-modal');
            const btnOpenDelete = document.getElementById('btn-open-delete-channel');
            const btnCancelDelete = document.getElementById('btn-cancel-delete-channel');

            btnOpenDelete.addEventListener('click', function () {
                closeMenu();
                deleteModal.classList.remove('hidden');
            });
            btnCancelDelete.addEventListener('click', function () {
                deleteModal.classList.add('hidden');
            });
            deleteModal.addEventListener('click', function (event) {
                if (event.target === deleteModal) {
                    deleteModal.classList.add('hidden');
                }
            });

            // --- Check for new videos (synchronous, runs from the dropdown) ---
            const btnCheckNewVideos = document.getElementById('btn-check-new-videos');
            const checkLabel = document.getElementById('check-new-videos-label');
            const checkIcon = document.getElementById('check-new-videos-icon');
            const checkResult = document.getElementById('check-new-videos-result');

            btnCheckNewVideos.addEventListener('click', function () {
                closeMenu();
                btnCheckNewVideos.disabled = true;
                checkIcon.textContent = '⏳';
                checkLabel.textContent = 'Checking...';
                checkResult.classList.add('hidden');
                checkResult.classList.remove('text-red-400');

                fetch('{{ route('channels.check-new-videos', $channel) }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Request failed');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        if (data.added > 0) {
                            checkResult.textContent = data.added + ' ' + (data.added === 1 ? 'video' : 'videos') + ' added to the download queue.';
                        } else {
                            checkResult.textContent = 'No new videos found.';
                        }
                    })
                    .catch(function () {
                        checkResult.classList.add('text-red-400');
                        checkResult.textContent = 'Error checking for new videos. Please try again.';
                    })
                    .finally(function () {
                        btnCheckNewVideos.disabled = false;
                        checkIcon.textContent = '🔄';
                        checkLabel.textContent = 'Check for New Videos';
                        checkResult.classList.remove('hidden');
                    });
            });
        });
    </script>
@endsection
