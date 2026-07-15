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

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <form action="/channels" method="GET" class="flex-1 min-w-[200px]">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="search" name="search" value="{{ $search }}" placeholder="Search channels by name..." class="w-full max-w-sm p-2 bg-gray-800 border border-gray-700 rounded text-gray-100 text-sm">
        </form>

        <div class="flex gap-2 items-center text-sm">
            <span class="text-gray-400">Order by:</span>
            <select onchange="window.location.href='?search={{ urlencode($search) }}&amp;sort='+this.value" class="bg-gray-800 text-gray-100 p-2 rounded border border-gray-700 cursor-pointer">
                <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>Name (A-Z)</option>
                <option value="recent_video" {{ $sort === 'recent_video' ? 'selected' : '' }}>Recent Video</option>
                <option value="created_at" {{ $sort === 'created_at' ? 'selected' : '' }}>Added Date</option>
            </select>
        </div>
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
            {{-- overflow-hidden/rounding lives on the cover wrapper (not the card itself) so the
                 kebab dropdown below isn't clipped when it overflows past the card's height. --}}
            <div data-channel-card class="relative bg-gray-900 p-4 rounded shadow flex justify-between items-center border border-gray-800 hover:border-gray-700 transition duration-200">
                @if ($coverUrl)
                    <div class="absolute inset-0 rounded overflow-hidden pointer-events-none">
                        <div class="absolute inset-0 bg-cover bg-center opacity-10" style="background-image: url('{{ $coverUrl }}');"></div>
                    </div>
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
                            <span class="channel-quality-label">{{ $channel->download_quality ?? '720p' }}</span>
                            <span class="mx-1">&middot;</span>
                            {{ \Illuminate\Support\Number::fileSize($channel->totalDownloadedBytes(), precision: 1) }}
                        </p>
                    </div>
                </div>

                {{-- No z-index wrapper here: the dropdown inside needs to rank against the
                     whole page's stacking order, not get trapped in a per-card context tied
                     to every other card's identical z-index (which DOM order would then
                     resolve in favor of later cards, clipping this one's open dropdown). --}}
                @include('channels._channel-actions', ['channel' => $channel])
            </div>
        @empty
            <div class="col-span-full p-8 bg-gray-900 rounded-lg text-center text-gray-400">
                @if ($search !== '')
                    No channels found for &quot;{{ $search }}&quot;.
                @else
                    No channels registered yet.
                @endif
            </div>
        @endforelse
    </div>

    {{ $channels->links('components.pagination') }}

    @include('channels._channel-modals')
@endsection
