@extends('layouts.app')

@section('title', 'Videos')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="text-sm text-gray-400">
            @if ($search !== '')
                Showing results for &quot;<span class="text-gray-200 font-semibold">{{ $search }}</span>&quot; &mdash; {{ $videos->total() }} {{ Str::plural('result', $videos->total()) }}
            @else
                {{ $videos->total() }} {{ Str::plural('video', $videos->total()) }}
            @endif
        </div>

        <div class="flex gap-2 items-center text-sm">
            <span class="text-gray-400">Order by:</span>
            <select onchange="window.location.href='?search={{ urlencode($search) }}&amp;sort='+this.value" class="bg-gray-800 text-gray-100 p-2 rounded border border-gray-700 cursor-pointer">
                @if ($search !== '')
                    <option value="relevance" {{ $sort === 'relevance' ? 'selected' : '' }}>Relevance</option>
                @endif
                <option value="newest" {{ $sort === 'newest' ? 'selected' : '' }}>Newest</option>
                <option value="oldest" {{ $sort === 'oldest' ? 'selected' : '' }}>Oldest</option>
                <option value="title" {{ $sort === 'title' ? 'selected' : '' }}>Title (A-Z)</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($videos as $video)
            @include('videos._list-item', ['video' => $video])
        @empty
            <div class="col-span-full p-8 bg-gray-900 rounded-lg text-center text-gray-400">
                @if ($search !== '')
                    No videos found for &quot;{{ $search }}&quot;.
                @else
                    No videos archived yet.
                @endif
            </div>
        @endforelse
    </div>

    {{ $videos->links('components.pagination') }}

    @include('videos._video-modals')
@endsection
