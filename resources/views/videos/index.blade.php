@extends('layouts.app')

@section('title', 'Videos')

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

    <form id="add-video-form" action="{{ route('videos.store') }}" method="POST" class="mb-8">
        @csrf
        <div class="flex flex-col sm:flex-row gap-4">
            <input type="url" name="url" placeholder="Video URL" class="flex-1 p-2 bg-gray-800 border border-gray-700 rounded text-gray-100" required>
            <select name="quality" class="p-2 bg-gray-800 border border-gray-700 rounded text-gray-100 cursor-pointer">
                <option value="480p">480p</option>
                <option value="720p" selected>720p</option>
                <option value="1080p">1080p</option>
            </select>
            <button id="add-video-btn" type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 w-full sm:w-auto disabled:opacity-75 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                <span id="add-video-btn-text">Add Video</span>
            </button>
        </div>
        <p class="text-xs text-gray-500 mt-1.5">Downloads a single video by URL, registering its channel first if it isn't already tracked. The chosen quality also becomes that channel's quality going forward.</p>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addVideoForm = document.getElementById('add-video-form');
            const addVideoBtn = document.getElementById('add-video-btn');
            const addVideoBtnText = document.getElementById('add-video-btn-text');

            addVideoForm.addEventListener('submit', function () {
                addVideoBtn.disabled = true;
                addVideoBtnText.textContent = 'Adding...';
                addVideoBtn.insertAdjacentHTML('afterbegin', `
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
