{{-- Kebab menu for a single channel. Carries this channel's settings as data-attributes so the
     single shared modal in channels/_channel-modals.blade.php can populate itself and submit
     asynchronously, without a separate modal instance per card. --}}
<div
    class="relative channel-actions-menu-wrapper"
    data-channel-id="{{ $channel->id }}"
    data-channel-name="{{ $channel->name }}"
    data-cutoff-date="{{ $channel->cutoff_date }}"
    data-quality="{{ $channel->download_quality ?? '720p' }}"
    data-download-shorts="{{ $channel->download_shorts ? '1' : '0' }}"
    data-check-interval-hours="{{ $channel->check_interval_hours ?? '' }}"
    data-settings-url="{{ route('channels.settings.update', $channel) }}"
    data-delete-url="{{ route('channels.destroy', $channel) }}"
    data-check-new-videos-url="{{ route('channels.check-new-videos', $channel) }}"
>
    <button
        type="button"
        class="channel-actions-toggle p-2 rounded-full bg-gray-900/70 hover:bg-gray-800 text-gray-300 hover:text-white backdrop-blur transition duration-200"
        aria-label="Channel actions"
        aria-haspopup="true"
        aria-expanded="false"
    >
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" /></svg>
    </button>

    <div class="channel-actions-dropdown hidden absolute right-0 mt-2 w-60 bg-gray-800 border border-gray-700 rounded-lg shadow-xl py-1 text-sm z-30">
        <button type="button" class="channel-open-settings w-full text-left px-4 py-2 text-gray-200 hover:bg-gray-700 flex items-center gap-2">
            <span>⚙️</span> Channel Settings
        </button>
        <button type="button" class="channel-check-new-videos w-full text-left px-4 py-2 text-gray-200 hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
            <span class="check-new-videos-icon">🔄</span>
            <span class="check-new-videos-label">Check for New Videos</span>
        </button>
        <div class="border-t border-gray-700 my-1"></div>
        <button type="button" class="channel-open-delete w-full text-left px-4 py-2 text-red-400 hover:bg-gray-700 flex items-center gap-2">
            <span>🗑️</span> Delete Channel
        </button>
    </div>

    <p class="check-new-videos-result hidden absolute right-0 mt-2 w-60 text-xs text-gray-200 bg-gray-900 border border-gray-700 rounded px-3 py-2 shadow-xl z-30"></p>
</div>
