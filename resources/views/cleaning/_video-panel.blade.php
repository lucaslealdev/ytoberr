{{-- One tab's worth of the Cleaning page: a select-all checkbox, a "Delete Selected" button
     and a table of videos. Shared by both the Biggest Videos and Oldest Videos tabs — only the
     $videos query and $summary copy differ between them. Selection/tab-switching/delete-modal
     wiring lives in cleaning/_cleaning-delete-modal.blade.php and is scoped per panel via the
     shared "cleaning-*" classes below (not IDs, since both panels render on the same page). --}}
<div class="cleaning-tab-panel {{ $active ? '' : 'hidden' }}" data-tab-panel="{{ $tab }}">
    <p class="text-gray-500 text-sm mb-4">{{ $summary }}</p>

    @if ($videos->isEmpty())
        <div class="bg-gray-900 p-8 rounded-lg text-center text-gray-400 border border-gray-800">
            No videos found.
        </div>
    @else
        <div class="flex items-center justify-between mb-3">
            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer select-none">
                <input type="checkbox" class="cleaning-select-all rounded border-gray-600 bg-gray-800">
                Select all
            </label>
            <button type="button" class="cleaning-delete-selected bg-red-600 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed text-white rounded px-4 py-2 text-sm font-semibold hover:bg-red-700 transition duration-200" disabled>
                Delete Selected (<span class="cleaning-selected-count">0</span>)
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm text-gray-300">
                <thead>
                    <tr class="border-b border-gray-800 text-gray-400 font-medium text-xs uppercase tracking-wider">
                        <th class="pb-3 pr-4 w-8"></th>
                        <th class="pb-3 pr-4">Video</th>
                        <th class="pb-3 px-4">Channel</th>
                        <th class="pb-3 px-4">Published</th>
                        <th class="pb-3 pl-4 text-right">Size</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($videos as $video)
                        <tr>
                            <td class="py-3 pr-4 align-top">
                                <input type="checkbox" class="cleaning-video-checkbox rounded border-gray-600 bg-gray-800" value="{{ $video->id }}">
                            </td>
                            <td class="py-3 pr-4">
                                <a href="/videos/{{ $video->id }}" class="flex items-center gap-3">
                                    @if ($video->thumbnailUrl())
                                        <img src="{{ $video->thumbnailUrl() }}" alt="" class="w-20 h-12 object-cover rounded flex-shrink-0" loading="lazy">
                                    @else
                                        <div class="w-20 h-12 flex-shrink-0 flex items-center justify-center bg-gray-800 text-gray-600 rounded">
                                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                    @endif
                                    <span class="font-semibold text-gray-100 hover:text-blue-400 transition duration-200 line-clamp-2" title="{{ $video->title }}">{{ $video->title }}</span>
                                </a>
                            </td>
                            <td class="py-3 px-4 text-gray-400 align-top">{{ $video->channel->name ?? 'Unknown' }}</td>
                            <td class="py-3 px-4 text-gray-500 text-xs align-top">{{ $video->publishedAtLocal()?->format('M d, Y') ?? 'Unknown date' }}</td>
                            <td class="py-3 pl-4 text-right font-mono text-gray-100 align-top">{{ $video->fileSize() ? \Illuminate\Support\Number::fileSize($video->fileSize(), precision: 1) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
