{{-- Single shared "Channel Settings" and "Delete Channel" modal pair for the whole page.
     Rather than rendering one modal per channel card, these are populated on open from the
     data-attributes of whichever card's kebab menu (channels/_channel-actions.blade.php) was
     clicked, and the settings form saves asynchronously via fetch. --}}
<div id="channel-settings-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-5">
        <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Channel Settings</h3>

        <form id="channel-settings-form" class="space-y-5">
            <p id="channel-settings-error" class="hidden text-sm text-red-400 bg-red-950/50 border border-red-900 rounded p-2"></p>

            <div class="space-y-2">
                <label class="block text-gray-400 text-sm">Cut-off Date</label>
                <p class="text-xs text-gray-500">Videos published before this date are never downloaded.</p>
                <input type="date" id="channel-settings-cutoff-date" name="cutoff_date" class="w-full bg-gray-800 border border-gray-700 text-gray-100 rounded p-2">
            </div>

            <div class="space-y-2">
                <label class="block text-gray-400 text-sm">Download Quality</label>
                <select id="channel-settings-quality" name="quality" class="w-full bg-gray-800 text-gray-100 rounded border border-gray-700 p-2 cursor-pointer">
                    <option value="480p">480p</option>
                    <option value="720p">720p</option>
                    <option value="1080p">1080p</option>
                </select>
            </div>

            <div class="flex items-center justify-between gap-2">
                <label for="channel-settings-download-shorts" class="text-sm text-gray-400">
                    Download Shorts
                    <span class="block text-xs text-gray-500 mt-0.5">Off by default — Shorts are skipped when checking for new videos.</span>
                </label>
                <input type="checkbox" id="channel-settings-download-shorts" name="download_shorts" value="1" class="flex-shrink-0 rounded border-gray-600 bg-gray-800">
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-800">
                <button type="button" id="btn-close-channel-settings" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Close</button>
                <button type="submit" id="btn-save-channel-settings" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm transition duration-200 disabled:opacity-50 disabled:cursor-not-allowed">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-channel-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-70 flex items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-lg max-w-md w-full p-6 shadow-2xl space-y-4">
        <h3 class="text-xl font-bold text-white border-b border-gray-800 pb-2">Delete Channel</h3>

        <p class="text-gray-300 text-sm">
            Are you sure you want to remove <strong id="delete-channel-name" class="text-white"></strong>? This cannot be undone.
        </p>

        <form id="delete-channel-form" method="POST">
            @csrf
            @method('DELETE')
            <label class="flex items-start gap-2 text-sm text-gray-300 bg-gray-950 border border-gray-800 rounded p-3 cursor-pointer select-none">
                <input type="checkbox" name="delete_files" value="1" class="mt-0.5 rounded border-gray-600 bg-gray-800">
                <span>
                    Also delete downloaded files and images from disk
                    <span class="block text-xs text-gray-500 mt-0.5">Permanently removes this channel's downloaded videos, thumbnails and metadata files, as well as its stored poster, banner and fanart images. This cannot be undone.</span>
                </span>
            </label>

            <div class="flex justify-end space-x-3 pt-4 mt-4 border-t border-gray-800">
                <button type="button" id="btn-cancel-delete-channel" class="bg-gray-800 text-gray-300 px-4 py-2 rounded hover:bg-gray-700 text-sm">Cancel</button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Delete Channel</button>
            </div>
        </form>
    </div>
</div>

<p id="channel-action-toast" class="hidden fixed bottom-6 right-6 z-50 text-sm text-white bg-gray-800 border border-gray-700 rounded px-4 py-2 shadow-xl"></p>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        function closeAllDropdowns(except) {
            document.querySelectorAll('.channel-actions-dropdown').forEach(function (dropdown) {
                if (dropdown !== except) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        function showToast(message, isError) {
            const toast = document.getElementById('channel-action-toast');
            toast.textContent = message;
            toast.classList.toggle('text-red-400', Boolean(isError));
            toast.classList.remove('hidden');
            clearTimeout(showToast._timer);
            showToast._timer = setTimeout(function () {
                toast.classList.add('hidden');
            }, 3000);
        }

        // --- Kebab dropdowns (delegated: any number of cards can exist on the page) ---
        document.addEventListener('click', function (event) {
            const toggle = event.target.closest('.channel-actions-toggle');

            if (toggle) {
                event.stopPropagation();
                const dropdown = toggle.closest('.channel-actions-menu-wrapper').querySelector('.channel-actions-dropdown');
                const isHidden = dropdown.classList.contains('hidden');
                closeAllDropdowns();
                dropdown.classList.toggle('hidden', !isHidden);
                toggle.setAttribute('aria-expanded', String(isHidden));

                return;
            }

            if (!event.target.closest('.channel-actions-dropdown')) {
                closeAllDropdowns();
            }
        });

        // --- Channel settings modal ---
        const settingsModal = document.getElementById('channel-settings-modal');
        const settingsForm = document.getElementById('channel-settings-form');
        const settingsError = document.getElementById('channel-settings-error');
        const cutoffDateInput = document.getElementById('channel-settings-cutoff-date');
        const qualitySelect = document.getElementById('channel-settings-quality');
        const downloadShortsCheckbox = document.getElementById('channel-settings-download-shorts');
        const btnSaveSettings = document.getElementById('btn-save-channel-settings');
        let activeSettingsWrapper = null;

        function openSettingsModal(wrapper) {
            activeSettingsWrapper = wrapper;
            cutoffDateInput.value = wrapper.dataset.cutoffDate || '';
            qualitySelect.value = wrapper.dataset.quality || '720p';
            downloadShortsCheckbox.checked = wrapper.dataset.downloadShorts === '1';
            settingsError.classList.add('hidden');
            settingsModal.classList.remove('hidden');
        }

        function closeSettingsModal() {
            settingsModal.classList.add('hidden');
            activeSettingsWrapper = null;
        }

        document.getElementById('btn-close-channel-settings').addEventListener('click', closeSettingsModal);
        settingsModal.addEventListener('click', function (event) {
            if (event.target === settingsModal) {
                closeSettingsModal();
            }
        });

        document.addEventListener('click', function (event) {
            const openBtn = event.target.closest('.channel-open-settings');
            if (!openBtn) {
                return;
            }
            closeAllDropdowns();
            openSettingsModal(openBtn.closest('.channel-actions-menu-wrapper'));
        });

        settingsForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!activeSettingsWrapper) {
                return;
            }

            const wrapper = activeSettingsWrapper;
            btnSaveSettings.disabled = true;
            settingsError.classList.add('hidden');

            fetch(wrapper.dataset.settingsUrl, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    cutoff_date: cutoffDateInput.value,
                    quality: qualitySelect.value,
                    download_shorts: downloadShortsCheckbox.checked ? '1' : '0',
                }),
            })
                .then(function (response) {
                    if (!response.ok) {
                        return response.json().then(function (data) {
                            throw new Error(data.message || 'Failed to save channel settings.');
                        });
                    }

                    return response.json();
                })
                .then(function (data) {
                    wrapper.dataset.cutoffDate = data.channel.cutoff_date;
                    wrapper.dataset.quality = data.channel.download_quality;
                    wrapper.dataset.downloadShorts = data.channel.download_shorts ? '1' : '0';

                    const card = wrapper.closest('[data-channel-card]');
                    if (card) {
                        const qualityLabel = card.querySelector('.channel-quality-label');
                        if (qualityLabel) {
                            qualityLabel.textContent = data.channel.download_quality;
                        }
                    }

                    closeSettingsModal();
                    showToast(data.message);
                })
                .catch(function (error) {
                    settingsError.textContent = error.message;
                    settingsError.classList.remove('hidden');
                })
                .finally(function () {
                    btnSaveSettings.disabled = false;
                });
        });

        // --- Delete channel modal ---
        const deleteModal = document.getElementById('delete-channel-modal');
        const deleteForm = document.getElementById('delete-channel-form');
        const deleteChannelName = document.getElementById('delete-channel-name');

        function closeDeleteModal() {
            deleteModal.classList.add('hidden');
        }

        document.addEventListener('click', function (event) {
            const openBtn = event.target.closest('.channel-open-delete');
            if (!openBtn) {
                return;
            }
            closeAllDropdowns();
            const wrapper = openBtn.closest('.channel-actions-menu-wrapper');
            deleteForm.action = wrapper.dataset.deleteUrl;
            deleteChannelName.textContent = wrapper.dataset.channelName;
            deleteModal.classList.remove('hidden');
        });

        document.getElementById('btn-cancel-delete-channel').addEventListener('click', closeDeleteModal);
        deleteModal.addEventListener('click', function (event) {
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        });
        // No preventDefault(): deleting still submits natively and follows the server's
        // redirect back to /channels, which is the simplest correct behavior on both the
        // listing page (already there) and a single channel's own page (navigates away).

        // --- Check for new videos (queues a background job, runs from the dropdown) ---
        document.addEventListener('click', function (event) {
            const btn = event.target.closest('.channel-check-new-videos');
            if (!btn) {
                return;
            }

            closeAllDropdowns();

            const wrapper = btn.closest('.channel-actions-menu-wrapper');
            const icon = wrapper.querySelector('.check-new-videos-icon');
            const label = wrapper.querySelector('.check-new-videos-label');
            const result = wrapper.querySelector('.check-new-videos-result');

            btn.disabled = true;
            icon.textContent = '⏳';
            label.textContent = 'Queuing...';
            result.classList.add('hidden');
            result.classList.remove('text-red-400');

            fetch(wrapper.dataset.checkNewVideosUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }
                    return response.json();
                })
                .then(function () {
                    result.textContent = 'Check queued. New videos will appear in the download queue shortly.';
                })
                .catch(function () {
                    result.classList.add('text-red-400');
                    result.textContent = 'Error queuing the check. Please try again.';
                })
                .finally(function () {
                    btn.disabled = false;
                    icon.textContent = '🔄';
                    label.textContent = 'Check for New Videos';
                    result.classList.remove('hidden');
                });
        });
    });
</script>
