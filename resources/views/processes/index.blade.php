@extends('layouts.app')

@section('title', 'Processes')

@section('content')
    <h2 class="text-2xl font-bold mb-2">Processes</h2>
    <p class="text-gray-500 text-sm mb-6">What's running in the background right now, and the raw Laravel queue behind it.</p>

    @if (session('status'))
        <div class="bg-green-600 text-white p-4 rounded mb-6">
            {{ session('status') }}
        </div>
    @endif

    <!-- Live Activity -->
    <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-8">
        <h3 class="text-lg font-semibold text-white mb-4">Live Activity</h3>

        @if (! $downloadingVideo && ! $checkingChannel)
            <p class="text-gray-500 text-sm italic">Idle — nothing running right now.</p>
        @else
            <div class="space-y-2 text-sm">
                @if ($downloadingVideo)
                    <p class="text-blue-400">
                        ⬇️ Downloading: <span class="text-white font-semibold">{{ $downloadingVideo->title }}</span> <span class="text-gray-500">({{ $downloadingVideo->channel->name ?? 'Unknown' }})</span>
                        @if (! is_null($downloadingVideo->progress_percent))
                            <span class="text-gray-400">&mdash; {{ $downloadingVideo->progress_percent }}%</span>
                        @endif
                    </p>
                    @if (! is_null($downloadingVideo->progress_percent))
                        <div class="w-full bg-gray-800 rounded-full h-1.5 overflow-hidden max-w-md">
                            <div class="h-1.5 rounded-full bg-blue-500 transition-all" style="width: {{ $downloadingVideo->progress_percent }}%"></div>
                        </div>
                    @endif
                @endif
                @if ($checkingChannel)
                    <p class="text-blue-400">🔄 Checking for new videos: <span class="text-white font-semibold">{{ $checkingChannel['channelName'] ?? 'Unknown channel' }}</span></p>
                @endif
            </div>
        @endif

        <p class="text-xs text-gray-600 mt-4">Downloads run via a scheduled command every 2 minutes, not the Laravel queue below — this section reflects the videos table directly.</p>
    </div>

    <!-- Pending Videos -->
    <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-8">
        <h3 class="text-lg font-semibold text-white mb-4">
            Pending Downloads
            @if ($pendingVideos->total() > 0)
                <span class="ml-2 bg-blue-600 text-white text-xs rounded-full px-2 py-0.5 align-middle">{{ $pendingVideos->total() }}</span>
            @endif
        </h3>

        @if ($pendingVideos->isEmpty())
            <p class="text-gray-500 text-sm italic">Nothing pending.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm text-gray-300">
                    <thead>
                        <tr class="border-b border-gray-800 text-gray-400 font-medium text-xs uppercase tracking-wider">
                            <th class="pb-3 pr-4">Video</th>
                            <th class="pb-3 px-4">Channel</th>
                            <th class="pb-3 px-4 text-center">Retries</th>
                            <th class="pb-3 px-4">Queued At</th>
                            <th class="pb-3 pl-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($pendingVideos as $video)
                            <tr>
                                <td class="py-3 pr-4 max-w-xs truncate font-semibold text-gray-100" title="{{ $video->title }}">{{ $video->title }}</td>
                                <td class="py-3 px-4 text-gray-400">{{ $video->channel->name ?? 'Unknown' }}</td>
                                <td class="py-3 px-4 text-center font-mono">{{ $video->retries }} / 3</td>
                                <td class="py-3 px-4 text-gray-500 text-xs">{{ $video->created_at->diffForHumans() }}</td>
                                <td class="py-3 pl-4">
                                    <form action="{{ route('processes.videos.destroy', $video) }}" method="POST" onsubmit="return confirm('Remove this video from the queue?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $pendingVideos->links('components.pagination') }}
        @endif
    </div>

    <!-- Failed Videos -->
    <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-8">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white">
                Failed Videos
                @if ($failedVideos->total() > 0)
                    <span class="ml-2 bg-red-600 text-white text-xs rounded-full px-2 py-0.5 align-middle">{{ $failedVideos->total() }}</span>
                @endif
            </h3>

            @if ($failedVideos->total() > 0)
                <div class="flex items-center gap-3">
                    <form action="{{ route('processes.failed-videos.retry-all') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-green-400 hover:text-green-300 text-xs">Retry All Failed</button>
                    </form>
                    <form action="{{ route('processes.failed-videos.destroy-all') }}" method="POST" onsubmit="return confirm('Remove all {{ $failedVideos->total() }} failed videos permanently? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Delete All Failed</button>
                    </form>
                </div>
            @endif
        </div>

        @if ($failedVideos->isEmpty())
            <p class="text-gray-500 text-sm italic">No failed videos.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm text-gray-300">
                    <thead>
                        <tr class="border-b border-gray-800 text-gray-400 font-medium text-xs uppercase tracking-wider">
                            <th class="pb-3 pr-4">Video</th>
                            <th class="pb-3 px-4">Channel</th>
                            <th class="pb-3 px-4 text-center">Retries</th>
                            <th class="pb-3 px-4">Details / Errors</th>
                            <th class="pb-3 pl-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($failedVideos as $video)
                            <tr>
                                <td class="py-3 pr-4 max-w-xs truncate font-semibold text-gray-100" title="{{ $video->title }}">{{ $video->title }}</td>
                                <td class="py-3 px-4 text-gray-400">{{ $video->channel->name ?? 'Unknown' }}</td>
                                <td class="py-3 px-4 text-center font-mono">{{ $video->retries }} / 3</td>
                                <td class="py-3 px-4 text-xs max-w-sm">
                                    @if ($video->last_error)
                                        <span class="text-red-400 line-clamp-1 italic" title="{{ $video->last_error }}">{{ $video->last_error }}</span>
                                    @elseif ($video->prevent_download)
                                        <span class="text-yellow-500 italic">Prevented: {{ $video->unavailable_reason ?? 'Excluded' }}</span>
                                    @else
                                        <span class="text-gray-500 italic">No errors logged</span>
                                    @endif
                                </td>
                                <td class="py-3 pl-4">
                                    <div class="flex items-center gap-3">
                                        <form action="{{ route('videos.retry', $video) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="text-green-400 hover:text-green-300 text-xs">Retry</button>
                                        </form>
                                        <form action="{{ route('processes.videos.destroy', $video) }}" method="POST" onsubmit="return confirm('Remove this video permanently?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $failedVideos->links('components.pagination') }}
        @endif
    </div>

    <!-- Laravel Queue Jobs -->
    <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800 mb-8">
        <h3 class="text-lg font-semibold text-white mb-4">
            Queued Jobs
            @if ($jobs->total() > 0)
                <span class="ml-2 bg-blue-600 text-white text-xs rounded-full px-2 py-0.5 align-middle">{{ $jobs->total() }}</span>
            @endif
        </h3>

        @if ($jobs->isEmpty())
            <p class="text-gray-500 text-sm italic">No jobs waiting in the Laravel queue.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm text-gray-300">
                    <thead>
                        <tr class="border-b border-gray-800 text-gray-400 font-medium text-xs uppercase tracking-wider">
                            <th class="pb-3 pr-4">Job</th>
                            <th class="pb-3 px-4">Status</th>
                            <th class="pb-3 px-4 text-center">Attempts</th>
                            <th class="pb-3 px-4">Queued At</th>
                            <th class="pb-3 pl-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($jobs as $job)
                            <tr>
                                <td class="py-3 pr-4 text-gray-100">
                                    {{ $job['label'] }}
                                    @if ($job['channelName'])
                                        <span class="text-gray-500">({{ $job['channelName'] }})</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    @if ($job['reserved'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-900/40 text-blue-400 border border-blue-800/60">Running</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-900/40 text-yellow-400 border border-yellow-800/60">Pending</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-center font-mono">{{ $job['attempts'] }}</td>
                                <td class="py-3 px-4 text-gray-500 text-xs">{{ $job['queuedAt']->diffForHumans() }}</td>
                                <td class="py-3 pl-4">
                                    <form action="{{ route('processes.jobs.destroy', $job['id']) }}" method="POST" onsubmit="return confirm('Cancel this job? If it is already running, this will not stop it, but it will not be retried.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $jobs->links('components.pagination') }}
        @endif
    </div>

    <!-- Laravel Failed Jobs -->
    <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-800">
        <h3 class="text-lg font-semibold text-white mb-4">
            Failed Jobs
            @if ($failedJobs->total() > 0)
                <span class="ml-2 bg-red-600 text-white text-xs rounded-full px-2 py-0.5 align-middle">{{ $failedJobs->total() }}</span>
            @endif
        </h3>

        @if ($failedJobs->isEmpty())
            <p class="text-gray-500 text-sm italic">No failed jobs.</p>
        @else
            <div class="space-y-3">
                @foreach ($failedJobs as $job)
                    <div class="bg-gray-950 border border-red-900/40 rounded p-3">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-gray-100 font-semibold text-sm">
                                    {{ $job['label'] }}
                                    @if ($job['channelName'])
                                        <span class="text-gray-500 font-normal">({{ $job['channelName'] }})</span>
                                    @endif
                                </p>
                                <p class="text-red-400 text-xs mt-0.5">{{ $job['exceptionSummary'] }}</p>
                                <p class="text-gray-500 text-xs mt-0.5">{{ $job['failedAt']->diffForHumans() }}</p>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <form action="{{ route('processes.failed-jobs.retry', $job['uuid']) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="text-green-400 hover:text-green-300 text-xs">Retry</button>
                                </form>
                                <form action="{{ route('processes.failed-jobs.destroy', $job['uuid']) }}" method="POST" onsubmit="return confirm('Forget this failed job permanently?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-gray-400 hover:text-white text-xs">Forget</button>
                                </form>
                            </div>
                        </div>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-blue-400 text-xs">View full exception</summary>
                            <pre class="mt-2 text-xs text-gray-300 whitespace-pre-wrap bg-gray-900 p-3 rounded max-h-60 overflow-y-auto">{{ $job['exceptionDetails'] }}</pre>
                        </details>
                    </div>
                @endforeach
            </div>

            {{ $failedJobs->links('components.pagination') }}
        @endif
    </div>
@endsection
