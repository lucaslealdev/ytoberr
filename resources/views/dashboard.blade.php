@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h2 class="text-2xl font-bold">Welcome, {{ auth()->user()->name }}!</h2>
    <p class="mt-4 text-gray-300 mb-8">This is the Ytoberr management dashboard.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Monitored Channels</h3>
            <p class="text-4xl font-bold text-white mt-2">{{ $channelsCount }}</p>
        </div>
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Archived Videos</h3>
            <p class="text-4xl font-bold text-white mt-2">{{ $videosCount }}</p>
        </div>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <h3 class="text-lg font-bold text-white">Recent Videos</h3>
        <a href="/videos" class="text-sm text-blue-400 hover:text-blue-300">View all &rarr;</a>
    </div>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($recentVideos as $video)
            @include('videos._list-item', ['video' => $video])
        @empty
            <div class="col-span-full p-8 bg-gray-900 rounded-lg text-center text-gray-400 border border-gray-800">
                No videos downloaded yet.
            </div>
        @endforelse
    </div>
@endsection
