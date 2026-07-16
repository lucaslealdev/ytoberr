{{-- Kebab menu for a single video (a listing/grid card, or the video's own show page).
     Mirrors channels/_channel-actions.blade.php: carries this video's data as data-attributes
     so the single shared modal in videos/_video-modals.blade.php can populate itself and
     submit, without a separate modal instance per card.

     $redirectTo should only be passed for the kebab on a video's own show page — once that
     video is deleted, the page it was on no longer exists, so it needs to point elsewhere
     (its channel, or the videos index) instead of relying on the default back-to-referer
     behavior every other instance of this menu uses. --}}
<div
    class="relative video-actions-menu-wrapper"
    data-video-id="{{ $video->id }}"
    data-video-title="{{ $video->title }}"
    data-delete-url="{{ route('videos.destroy', $video) }}"
    data-redirect-to="{{ $redirectTo ?? '' }}"
>
    <button
        type="button"
        class="video-actions-toggle p-2 rounded-full bg-gray-900/70 hover:bg-gray-800 text-gray-300 hover:text-white backdrop-blur transition duration-200"
        aria-label="Video actions"
        aria-haspopup="true"
        aria-expanded="false"
    >
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" /></svg>
    </button>

    <div class="video-actions-dropdown hidden absolute right-0 mt-2 w-52 bg-gray-800 border border-gray-700 rounded-lg shadow-xl py-1 text-sm z-30">
        <button type="button" class="video-open-delete w-full text-left px-4 py-2 text-red-400 hover:bg-gray-700 flex items-center gap-2">
            <span>🗑️</span> Delete Video
        </button>
    </div>
</div>
