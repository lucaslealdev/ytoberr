@extends('layouts.app')

@section('title', 'Cleaning')

@section('content')
    <h2 class="text-2xl font-bold mb-2">Cleaning</h2>
    <p class="text-gray-500 text-sm mb-6">Find and remove videos taking up disk space.</p>

    <div class="flex gap-6 border-b border-gray-800 mb-6">
        <button type="button" class="cleaning-tab-btn pb-3 text-sm font-semibold uppercase tracking-wide border-b-2 border-transparent text-gray-400 hover:text-gray-200 transition duration-200" data-tab-target="biggest">Biggest Videos</button>
        <button type="button" class="cleaning-tab-btn pb-3 text-sm font-semibold uppercase tracking-wide border-b-2 border-transparent text-gray-400 hover:text-gray-200 transition duration-200" data-tab-target="oldest">Oldest Videos</button>
    </div>

    @include('cleaning._video-panel', [
        'videos' => $biggestVideos,
        'tab' => 'biggest',
        'active' => true,
        'summary' => 'The '.count($biggestVideos).' largest downloaded '.Str::plural('video', count($biggestVideos)).' on disk, heaviest first.',
    ])

    @include('cleaning._video-panel', [
        'videos' => $oldestVideos,
        'tab' => 'oldest',
        'active' => false,
        'summary' => 'The '.count($oldestVideos).' oldest downloaded '.Str::plural('video', count($oldestVideos)).', oldest first.',
    ])

    @include('cleaning._cleaning-delete-modal')
@endsection
