@extends('layouts.app')

@section('title', 'Channels')

@section('content')
    @if ($errors->any())
        <div class="bg-red-900/50 text-red-200 p-4 rounded mb-4">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="add-channel-form" action="/channels" method="POST" class="mb-8">
        @csrf
        <div class="flex flex-col sm:flex-row gap-4">
            <input type="url" name="url" placeholder="Channel URL" class="flex-1 p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
            <button id="add-channel-btn" type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 w-full sm:w-auto disabled:opacity-75 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                <span id="add-channel-btn-text">Add Channel</span>
            </button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addChannelForm = document.getElementById('add-channel-form');
            const addChannelBtn = document.getElementById('add-channel-btn');
            const addChannelBtnText = document.getElementById('add-channel-btn-text');

            addChannelForm.addEventListener('submit', function () {
                addChannelBtn.disabled = true;
                addChannelBtnText.textContent = 'Adding...';
                addChannelBtn.insertAdjacentHTML('afterbegin', `
                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                `);
                // No preventDefault(): the form still submits natively.
            });
        });
    </script>

    <div class="mb-6 flex gap-2 items-center text-sm">
        <span class="text-gray-400">Order by:</span>
        <select onchange="window.location.href='?sort='+this.value" class="bg-gray-800 text-gray-100 p-2 rounded border border-gray-700 cursor-pointer">
            <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>Name (A-Z)</option>
            <option value="recent_video" {{ $sort === 'recent_video' ? 'selected' : '' }}>Recent Video</option>
            <option value="created_at" {{ $sort === 'created_at' ? 'selected' : '' }}>Added Date</option>
        </select>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($channels as $channel)
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
            <div class="relative overflow-hidden bg-gray-900 p-4 rounded shadow flex justify-between items-center border border-gray-800 hover:border-gray-700 transition duration-200">
                @if ($coverUrl)
                    <div class="absolute inset-0 bg-cover bg-center opacity-10 pointer-events-none" style="background-image: url('{{ $coverUrl }}');"></div>
                @endif
                <div class="flex items-center gap-4 relative z-10">
                    <a href="/channels/{{ $channel->id }}" class="hover:opacity-80 transition duration-200">
                        @if ($channel->profile_image_path)
                            <img src="{{ asset('storage/' . $channel->profile_image_path) }}" alt="{{ $channel->name }}" class="w-12 h-12 rounded-full object-cover" loading="lazy">
                        @else
                            <div class="w-12 h-12 rounded-full bg-gray-700 flex items-center justify-center text-gray-500">?</div>
                        @endif
                    </a>
                    <div>
                        <a href="/channels/{{ $channel->id }}" class="hover:underline">
                            <h3 class="font-bold text-lg text-white">{{ $channel->name }}</h3>
                        </a>
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $channel->download_quality ?? '720p' }}
                            <span class="mx-1">&middot;</span>
                            {{ \Illuminate\Support\Number::fileSize($channel->totalDownloadedBytes(), precision: 1) }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="delete-channel-btn relative z-10 text-red-500 hover:text-red-700 font-bold text-xl"
                    aria-label="Delete channel"
                    data-channel-id="{{ $channel->id }}"
                    data-channel-name="{{ $channel->name }}"
                >&times;</button>
            </div>
        @empty
            <div class="col-span-full p-8 bg-gray-900 rounded-lg text-center text-gray-400">
                No channels registered yet.
            </div>
        @endforelse
    </div>

    {{ $channels->links('components.pagination') }}

    <!-- Delete Channel Confirmation Modal -->
    <div id="delete-channel-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Delete Channel</h3>

            <p class="text-gray-300 text-sm">
                Are you sure you want to remove <strong id="delete-channel-name" class="text-white"></strong>? This cannot be undone.
            </p>

            <form id="delete-channel-form" method="POST">
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
            const modal = document.getElementById('delete-channel-modal');
            const form = document.getElementById('delete-channel-form');
            const nameEl = document.getElementById('delete-channel-name');
            const checkbox = form.querySelector('input[name="delete_files"]');
            const btnCancel = document.getElementById('btn-cancel-delete-channel');

            document.querySelectorAll('.delete-channel-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    form.action = '/channels/' + btn.dataset.channelId;
                    nameEl.textContent = btn.dataset.channelName;
                    checkbox.checked = false;
                    modal.classList.remove('hidden');
                });
            });

            btnCancel.addEventListener('click', function () {
                modal.classList.add('hidden');
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                }
            });
        });
    </script>
@endsection
