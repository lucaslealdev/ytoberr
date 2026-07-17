<div data-video-card class="relative group">
    <a href="/videos/{{ $video->id }}" class="h-full bg-gray-900 rounded-lg overflow-hidden border border-gray-800 hover:border-gray-700 transition duration-200 flex flex-col shadow-lg">
        <!-- Video Thumbnail -->
        <div class="relative aspect-video bg-gray-950 overflow-hidden">
            @if ($video->thumbnailUrl())
                <img src="{{ $video->thumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy">
            @else
                <div class="w-full h-full flex items-center justify-center bg-gray-800 text-gray-600">
                    <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </div>
            @endif
        </div>

        <!-- Video Details -->
        <div class="p-4 flex-1 flex flex-col justify-between space-y-3">
            <div class="space-y-1.5">
                <h4 class="font-semibold text-gray-100 group-hover:text-blue-400 transition duration-200 line-clamp-2 text-sm leading-snug" title="{{ $video->title }}">
                    {{ $video->title }}
                </h4>
                <p class="text-xs text-gray-500">
                    {{ $video->published_at ? \Carbon\Carbon::parse($video->published_at)->diffForHumans() : 'Unknown date' }}
                </p>
            </div>

            @if ($video->description)
                <p class="text-gray-400 text-xs line-clamp-3 leading-relaxed">
                    {{ $video->description }}
                </p>
            @endif
        </div>
    </a>

    <div class="absolute top-2 right-2 z-20">
        @include('videos._video-actions', ['video' => $video])
    </div>
</div>
