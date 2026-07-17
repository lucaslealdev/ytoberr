<div data-video-card class="relative">
    <a href="/videos/{{ $video->id }}" class="bg-gray-900 p-4 pr-14 rounded-lg shadow border border-gray-800 hover:border-gray-700 transition duration-200 flex items-center gap-4">
        @if ($video->thumbnailUrl())
            <img src="{{ $video->thumbnailUrl() }}" alt="{{ $video->title }}" class="w-32 h-20 object-cover rounded flex-shrink-0" loading="lazy">
        @else
            <div class="w-32 h-20 flex-shrink-0 flex items-center justify-center bg-gray-800 text-gray-600 rounded">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
            </div>
        @endif
        <div class="min-w-0">
            <h4 class="font-semibold text-gray-100 truncate" title="{{ $video->title }}">{{ $video->title }}</h4>
            <p class="text-sm text-gray-500">{{ $video->channel->name ?? 'Unknown channel' }}</p>
            <p class="text-xs text-gray-600 mt-1">
                {{ $video->published_at ? \Carbon\Carbon::parse($video->published_at)->diffForHumans() : 'Unknown date' }}
            </p>
        </div>
    </a>

    <div class="absolute top-1/2 right-3 -translate-y-1/2 z-20">
        @include('videos._video-actions', ['video' => $video])
    </div>
</div>
