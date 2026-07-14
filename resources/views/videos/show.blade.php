@extends('layouts.app')

@section('title', $video->title)
@section('header', 'Video Details')

@section('content')
    <!-- Breadcrumb -->
    <nav class="text-sm text-gray-400 mb-6">
        <a href="/videos" class="hover:text-white transition duration-200">Videos</a>
        @if ($video->channel)
            <span class="mx-2">&gt;</span>
            <a href="/channels/{{ $video->channel->id }}" class="hover:text-white transition duration-200">{{ $video->channel->name }}</a>
        @endif
        <span class="mx-2">&gt;</span>
        <span class="text-gray-100 font-semibold line-clamp-1">{{ $video->title }}</span>
    </nav>

    <div class="2xl:flex 2xl:items-start 2xl:gap-8">
        <!-- Main column: player + video info -->
        <div class="2xl:flex-1 2xl:min-w-0">
            <!-- Player -->
            <div class="bg-black rounded-lg overflow-hidden shadow-lg border border-gray-800 mb-6">
                @if ($video->videoUrl())
                    <video controls class="w-full aspect-video bg-black" poster="{{ $video->thumbnailUrl() }}" preload="metadata">
                        <source src="{{ $video->videoUrl() }}">
                        Your browser does not support the video tag.
                    </video>
                @else
                    <div class="w-full aspect-video flex items-center justify-center bg-gray-900 text-gray-500">
                        Video file not available.
                    </div>
                @endif
            </div>

            <!-- Video Info -->
            <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <h1 class="text-2xl font-bold text-white">{{ $video->title }}</h1>
                    @if ($video->videoUrl())
                        <a href="{{ $video->videoUrl() }}" download class="shrink-0 bg-blue-600 hover:bg-blue-700 text-white rounded px-2.5 py-1 transition duration-200 text-sm font-semibold">Download Original File</a>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-400 mb-4">
                    @if ($video->channel)
                        <a href="/channels/{{ $video->channel->id }}" class="flex items-center gap-2 hover:text-white transition duration-200">
                            @if ($video->channel->profile_image_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($video->channel->profile_image_path))
                                <img src="{{ asset('storage/' . $video->channel->profile_image_path) }}" alt="{{ $video->channel->name }}" class="w-8 h-8 rounded-full object-cover">
                            @endif
                            <span class="font-semibold text-gray-200">{{ $video->channel->name }}</span>
                        </a>
                        <span>&bull;</span>
                    @endif
                    <span>{{ $video->published_at ? \Carbon\Carbon::parse($video->published_at)->format('M d, Y') : 'Unknown date' }}</span>
                </div>
                @if ($video->description)
                    <p class="text-gray-300 text-sm whitespace-pre-line leading-relaxed">{{ $video->description }}</p>
                @endif
            </div>

            <!-- More from this channel: stays in the main column, below the description -->
            @if ($channelVideos->isNotEmpty())
                <div class="mt-10">
                    <h3 class="text-lg font-bold text-white mb-4">More from {{ $video->channel->name ?? 'this channel' }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        @foreach ($channelVideos as $related)
                            @include('videos._channel-video-card', ['video' => $related])
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Suggested videos: full-width below the main column normally, right-hand sidebar on very high resolutions -->
        @if ($suggestedVideos->isNotEmpty())
            <div class="2xl:w-96 2xl:flex-shrink-0 mt-10 2xl:mt-0">
                <h3 class="text-lg font-bold text-white mb-4">Suggested Videos</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-1 gap-6 2xl:gap-3">
                    @foreach ($suggestedVideos as $suggestion)
                        @include('videos._channel-video-card', ['video' => $suggestion])
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
