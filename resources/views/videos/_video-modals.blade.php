{{-- Single shared "Delete Video" modal for the whole page. Rather than rendering one modal
     per video card, it's populated on open from the data-attributes of whichever card's kebab
     menu (videos/_video-actions.blade.php) was clicked, then submits as a native form so the
     server's redirect (back to the current listing, or wherever destroy() decides) is simply
     followed by the browser — same pattern as channels/_channel-modals.blade.php. --}}
<div id="delete-video-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Delete Video</h3>

        <p class="text-gray-300 text-sm">
            Are you sure you want to remove <strong id="delete-video-title" class="text-white"></strong>? This cannot be undone.
        </p>

        <form id="delete-video-form" method="POST">
            @csrf
            @method('DELETE')
            <input type="hidden" name="redirect_to" id="delete-video-redirect-to">

            <div class="space-y-3">
                <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                    <input type="checkbox" name="delete_files" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                    <span>
                        Also delete the files
                        <span class="block text-xs text-gray-500 mt-0.5">Permanently removes this video's downloaded file, thumbnail and metadata from disk. This cannot be undone.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                    <input type="checkbox" name="prevent_redownload" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                    <span>
                        Don't download this video again
                        <span class="block text-xs text-gray-500 mt-0.5">Keeps a hidden record so it's never re-queued by a future channel check. Leave unchecked to fully erase it instead — a still-recent upload could then be downloaded again.</span>
                    </span>
                </label>
            </div>

            <div class="flex justify-end space-x-3 pt-4 mt-4 border-t border-gray-800">
                <button type="button" id="btn-cancel-delete-video" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Cancel</button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Delete Video</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        function closeAllVideoDropdowns(except) {
            document.querySelectorAll('.video-actions-dropdown').forEach(function (dropdown) {
                if (dropdown !== except) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        // --- Kebab dropdowns (delegated: any number of video cards can exist on the page) ---
        document.addEventListener('click', function (event) {
            const toggle = event.target.closest('.video-actions-toggle');

            if (toggle) {
                event.stopPropagation();
                const dropdown = toggle.closest('.video-actions-menu-wrapper').querySelector('.video-actions-dropdown');
                const isHidden = dropdown.classList.contains('hidden');
                closeAllVideoDropdowns();
                dropdown.classList.toggle('hidden', !isHidden);
                toggle.setAttribute('aria-expanded', String(isHidden));

                return;
            }

            if (!event.target.closest('.video-actions-dropdown')) {
                closeAllVideoDropdowns();
            }
        });

        // --- Delete video modal ---
        const deleteModal = document.getElementById('delete-video-modal');
        const deleteForm = document.getElementById('delete-video-form');
        const deleteVideoTitle = document.getElementById('delete-video-title');
        const deleteVideoRedirectTo = document.getElementById('delete-video-redirect-to');

        function closeDeleteVideoModal() {
            deleteModal.classList.add('hidden');
        }

        document.addEventListener('click', function (event) {
            const openBtn = event.target.closest('.video-open-delete');
            if (!openBtn) {
                return;
            }
            closeAllVideoDropdowns();
            const wrapper = openBtn.closest('.video-actions-menu-wrapper');
            deleteForm.reset();
            deleteForm.action = wrapper.dataset.deleteUrl;
            deleteVideoTitle.textContent = wrapper.dataset.videoTitle;
            deleteVideoRedirectTo.value = wrapper.dataset.redirectTo || '';
            deleteModal.classList.remove('hidden');
        });

        document.getElementById('btn-cancel-delete-video').addEventListener('click', closeDeleteVideoModal);
        deleteModal.addEventListener('click', function (event) {
            if (event.target === deleteModal) {
                closeDeleteVideoModal();
            }
        });
        // No preventDefault(): deleting still submits natively and follows the server's
        // redirect — back to the current listing page by default, or to the video's channel
        // (or the videos index) when deleted from its own show page.
    });
</script>
