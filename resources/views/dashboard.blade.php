@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h2 class="text-2xl font-bold">Welcome, {{ auth()->user()->name }}!</h2>
    <p class="mt-4 text-gray-300 mb-8">This is the Ytoberr management dashboard.</p>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Monitored Channels</h3>
            <p class="text-4xl font-bold text-white mt-2">{{ $channelsCount }}</p>
        </div>
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Archived Videos</h3>
            <p class="text-4xl font-bold text-white mt-2">{{ $videosCount }}</p>
        </div>
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Pending Downloads</h3>
            <p class="text-4xl font-bold {{ $pendingVideosCount > 0 ? 'text-blue-400' : 'text-white' }} mt-2">{{ $pendingVideosCount }}</p>
        </div>
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
            <h3 class="text-gray-400 text-sm font-medium uppercase tracking-wider">Failed Videos</h3>
            <p class="text-4xl font-bold {{ $failedVideosCount > 0 ? 'text-red-400' : 'text-white' }} mt-2">{{ $failedVideosCount }}</p>
        </div>
    </div>

    <div class="mt-6 bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
        <h3 class="text-lg font-bold text-white mb-4">System Health</h3>

        @if ($queueSuspended)
            <div class="mb-4 bg-red-950/50 border border-red-900 text-red-300 text-sm rounded p-3">
                ⚠️ Downloads are currently suspended after 3 consecutive failures. Check <a href="/settings" class="underline">Settings</a> for warnings, or retry from the <a href="/processes" class="underline">Processes</a> page once resolved.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 text-sm">
            <div>
                <p class="text-gray-400">Warnings</p>
                <p class="mt-1 text-2xl font-bold {{ $warningsCount > 0 ? 'text-red-400' : 'text-white' }}">{{ $warningsCount }}</p>
                @if ($warningsCount > 0)
                    <a href="/settings" class="text-xs text-blue-400 hover:text-blue-300">Review in Settings &rarr;</a>
                @endif
            </div>
            <div class="sm:col-span-2">
                <div class="flex justify-between text-gray-400 mb-1">
                    <span>Disk usage</span>
                    <span>{{ $diskUsedPercent }}%</span>
                </div>
                <div class="w-full bg-gray-800 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full {{ $diskBarColor }}" style="width: {{ min(100, max(0, $diskUsedPercent)) }}%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
        <h3 class="text-lg font-bold text-white mb-1">Storage Growth</h3>
        <p class="text-xs text-gray-500 mb-4">Cumulative downloaded size over the last 30 days.</p>

        @php
            $seriesValues = array_column($storageGrowthSeries, 'bytes');
            $hasGrowth = ! empty($seriesValues) && max($seriesValues) > 0;
        @endphp

        @if ($hasGrowth)
            @php
                $chartWidth = 600;
                $chartHeight = 120;
                $minValue = min($seriesValues);
                $maxValue = max($seriesValues);
                $range = max($maxValue - $minValue, 1);
                $stepX = count($storageGrowthSeries) > 1 ? $chartWidth / (count($storageGrowthSeries) - 1) : 0;

                $polylinePoints = collect($storageGrowthSeries)->map(function ($point, $index) use ($stepX, $chartHeight, $minValue, $range) {
                    $x = round($index * $stepX, 1);
                    $y = round($chartHeight - (($point['bytes'] - $minValue) / $range) * $chartHeight, 1);

                    return "{$x},{$y}";
                })->implode(' ');
            @endphp

            <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" class="w-full h-32">
                <polyline points="{{ $polylinePoints }}" fill="none" stroke="#3b82f6" class="storage-growth-line" stroke-width="2" vector-effect="non-scaling-stroke" />
            </svg>

            <div class="flex justify-between text-xs text-gray-500 mt-2">
                <span>{{ $storageGrowthSeries[0]['date'] }} &middot; {{ \Illuminate\Support\Number::fileSize($minValue, precision: 1) }}</span>
                <span>{{ $storageGrowthSeries[array_key_last($storageGrowthSeries)]['date'] }} &middot; {{ \Illuminate\Support\Number::fileSize($maxValue, precision: 1) }}</span>
            </div>
        @else
            <p class="text-gray-500 text-sm italic">Not enough download history yet to chart storage growth.</p>
        @endif
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
