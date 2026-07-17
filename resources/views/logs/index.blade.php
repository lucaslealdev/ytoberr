@extends('layouts.app')

@section('title', 'Logs')

@section('content')
    <div class="flex items-center justify-between mb-2">
        <h2 class="text-2xl font-bold">Application Logs</h2>
        <div class="flex items-center gap-3">
            @if ($logSize > 0)
                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Number::fileSize($logSize, precision: 1) }} on disk</span>
            @endif
            @if (! empty($entries))
                <form action="{{ route('logs.clear') }}" method="POST" onsubmit="return confirm('Clear the log file? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Clear Logs</button>
                </form>
            @endif
        </div>
    </div>
    <p class="text-gray-500 text-sm mb-6">
        Most recent {{ count($entries) }} {{ Str::plural('entry', count($entries)) }} from <code class="text-gray-400">{{ $logPath }}</code>, newest first.
    </p>

    @if (empty($entries))
        <div class="bg-gray-900 p-8 rounded-lg text-center text-gray-400 border border-gray-800">
            No log entries found.
        </div>
    @else
        <div class="space-y-2">
            @foreach ($entries as $entry)
                @php
                    $levelClasses = match ($entry['level']) {
                        'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'bg-red-900/40 text-red-400 border-red-800/60',
                        'WARNING' => 'bg-yellow-900/40 text-yellow-400 border-yellow-800/60',
                        'NOTICE', 'INFO' => 'bg-blue-900/40 text-blue-400 border-blue-800/60',
                        default => 'bg-gray-800 text-gray-400 border-gray-700',
                    };
                    $copyText = "[{$entry['timestamp']}] {$entry['level']}: {$entry['message']}".($entry['details'] !== '' ? "\n\n{$entry['details']}" : '');
                @endphp
                <div class="bg-gray-900 border border-gray-800 rounded p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border {{ $levelClasses }}">{{ $entry['level'] }}</span>
                            <span class="text-gray-500 text-xs ml-2">{{ $entry['timestamp'] }}</span>
                        </div>
                        <button type="button" class="copy-log-entry shrink-0 text-gray-400 hover:text-white text-xs" data-copy-text="{{ $copyText }}">Copy</button>
                    </div>
                    <p class="text-gray-200 text-sm mt-1 break-words">{{ $entry['message'] }}</p>

                    @if ($entry['details'] !== '')
                        <details class="mt-2">
                            <summary class="cursor-pointer text-blue-400 text-xs">View details</summary>
                            <pre class="mt-2 text-xs text-gray-300 whitespace-pre-wrap bg-gray-950 p-3 rounded max-h-60 overflow-y-auto">{{ $entry['details'] }}</pre>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.copy-log-entry').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const text = button.dataset.copyText;

                        // navigator.clipboard requires a secure context (HTTPS or localhost) —
                        // fall back to the old execCommand trick for plain-HTTP self-hosted setups.
                        const onCopied = function () {
                            const original = button.textContent;
                            button.textContent = 'Copied!';
                            setTimeout(function () {
                                button.textContent = original;
                            }, 1500);
                        };

                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(onCopied);
                            return;
                        }

                        const textarea = document.createElement('textarea');
                        textarea.value = text;
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        onCopied();
                    });
                });
            });
        </script>
    @endif
@endsection
