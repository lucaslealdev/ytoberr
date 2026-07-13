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
                </p>
                <div class="mt-2 flex flex-wrap items-center justify-center md:justify-start gap-4 text-xs text-gray-300">
                    <form action="/channels/{{ $channel->id }}/cutoff" method="POST" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <span>Cut-off Date:</span>
                        <input type="date" name="cutoff_date" value="{{ $channel->cutoff_date }}" class="bg-gray-800 border border-gray-700 text-gray-100 rounded p-1 cursor-pointer">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-2.5 py-1 transition duration-200">Save</button>
                    </form>

                    <form action="/channels/{{ $channel->id }}/quality" method="POST" class="flex items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <span>Quality:</span>
                        <select name="quality" onchange="this.form.submit()" class="bg-gray-800 text-gray-100 rounded border border-gray-700 p-1 cursor-pointer hover:bg-gray-700">
                            <option value="480p" {{ $channel->download_quality === '480p' ? 'selected' : '' }}>480p</option>
                            <option value="720p" {{ $channel->download_quality === '720p' ? 'selected' : '' }}>720p</option>
                            <option value="1080p" {{ $channel->download_quality === '1080p' ? 'selected' : '' }}>1080p</option>
                        </select>
                    </form>
                </div>
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
@endsection
