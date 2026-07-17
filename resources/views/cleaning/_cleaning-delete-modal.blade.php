{{-- Bulk-delete modal for the Cleaning page, shared by both tabs (see
     cleaning/_video-panel.blade.php). Mirrors videos/_video-modals.blade.php's single-video
     "Delete Video" modal (same two opt-in checkboxes, submitted to the same
     VideoDeletionService under the hood via CleaningController::destroy), just scoped to
     however many videos are checked in whichever panel triggered it. --}}
<div id="cleaning-delete-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Delete Videos</h3>

        <p class="text-gray-300 text-sm">
            Are you sure you want to remove <strong id="cleaning-delete-count" class="text-white"></strong>? This cannot be undone.
        </p>

        <form id="cleaning-delete-form" method="POST" action="{{ route('cleaning.videos.destroy') }}">
            @csrf
            @method('DELETE')
            <div id="cleaning-delete-video-ids"></div>
            <input type="hidden" name="tab" id="cleaning-delete-tab">

            <div class="space-y-3">
                <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                    <input type="checkbox" name="delete_files" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                    <span>
                        Also delete the files
                        <span class="block text-xs text-gray-500 mt-0.5">Permanently removes each selected video's downloaded file, thumbnail and metadata from disk. This cannot be undone.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                    <input type="checkbox" name="prevent_redownload" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                    <span>
                        Don't download these videos again
                        <span class="block text-xs text-gray-500 mt-0.5">Keeps a hidden record so they're never re-queued by a future channel check. Leave unchecked to fully erase them instead — a still-recent upload could then be downloaded again.</span>
                    </span>
                </label>
            </div>

            <div class="flex justify-end space-x-3 pt-4 mt-4 border-t border-gray-800">
                <button type="button" id="btn-cancel-cleaning-delete" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Cancel</button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Delete Videos</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // --- Tabs ---
        const tabButtons = document.querySelectorAll('.cleaning-tab-btn');
        const tabPanels = document.querySelectorAll('.cleaning-tab-panel');

        function activateTab(tab) {
            tabButtons.forEach(function (btn) {
                const isActive = btn.dataset.tabTarget === tab;
                btn.classList.toggle('border-blue-500', isActive);
                btn.classList.toggle('text-white', isActive);
                btn.classList.toggle('border-transparent', !isActive);
                btn.classList.toggle('text-gray-400', !isActive);
            });

            tabPanels.forEach(function (panel) {
                panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab);
            });
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateTab(btn.dataset.tabTarget);
            });
        });

        const requestedTab = new URLSearchParams(window.location.search).get('tab');
        const availableTabs = Array.from(tabPanels).map(function (panel) { return panel.dataset.tabPanel; });
        activateTab(availableTabs.includes(requestedTab) ? requestedTab : (availableTabs[0] || 'biggest'));

        // --- Delete modal (shared across every panel) ---
        const modal = document.getElementById('cleaning-delete-modal');
        const countLabel = document.getElementById('cleaning-delete-count');
        const idsContainer = document.getElementById('cleaning-delete-video-ids');
        const tabInput = document.getElementById('cleaning-delete-tab');

        function closeModal() {
            modal.classList.add('hidden');
        }

        document.getElementById('btn-cancel-cleaning-delete').addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        // --- Wire up each panel's own select-all / checkboxes / delete button independently,
        // so selections in one tab never affect the other. ---
        tabPanels.forEach(function (panel) {
            const selectAll = panel.querySelector('.cleaning-select-all');
            const deleteSelectedBtn = panel.querySelector('.cleaning-delete-selected');
            const selectedCountLabel = panel.querySelector('.cleaning-selected-count');
            const checkboxes = panel.querySelectorAll('.cleaning-video-checkbox');

            if (!selectAll || !deleteSelectedBtn) {
                return;
            }

            function selectedIds() {
                return Array.from(checkboxes).filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
            }

            function refreshSelectionState() {
                const ids = selectedIds();
                selectedCountLabel.textContent = ids.length;
                deleteSelectedBtn.disabled = ids.length === 0;
                selectAll.checked = ids.length > 0 && ids.length === checkboxes.length;
            }

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
                refreshSelectionState();
            });

            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', refreshSelectionState);
            });

            deleteSelectedBtn.addEventListener('click', function () {
                const ids = selectedIds();
                if (ids.length === 0) {
                    return;
                }

                idsContainer.innerHTML = '';
                ids.forEach(function (id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'video_ids[]';
                    input.value = id;
                    idsContainer.appendChild(input);
                });

                tabInput.value = panel.dataset.tabPanel;
                countLabel.textContent = ids.length + (ids.length === 1 ? ' video' : ' videos');
                modal.classList.remove('hidden');
            });
        });
    });
</script>
