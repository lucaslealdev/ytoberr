{{-- Single shared toast for flash messages, included once by the layout. session('status') is
     auto-shown here on page load, so every page gets the same self-dismissing toast instead of
     a mix of static green banners (one per page) and the channel actions' own one-off JS toast.
     Any other page's JS can trigger the same toast via the global window.showToast(message,
     isError) helper defined below (see channels/_channel-modals.blade.php for an example). --}}
<div id="flash-toast" class="hidden fixed bottom-6 right-6 z-50 text-sm text-white bg-gray-800 border border-gray-700 rounded px-4 py-2 shadow-xl max-w-sm"></div>

@if (session('status'))
    <p id="flash-toast-session-status" class="hidden">{{ session('status') }}</p>
@endif

<script>
    window.showToast = function (message, isError) {
        const toast = document.getElementById('flash-toast');
        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.toggle('text-red-400', Boolean(isError));
        toast.classList.remove('hidden');
        clearTimeout(window.showToast._timer);
        window.showToast._timer = setTimeout(function () {
            toast.classList.add('hidden');
        }, 3000);
    };

    document.addEventListener('DOMContentLoaded', function () {
        const sessionStatus = document.getElementById('flash-toast-session-status');
        if (sessionStatus) {
            window.showToast(sessionStatus.textContent);
        }
    });
</script>
