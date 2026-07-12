@if ($paginator->hasPages())
    <nav class="mt-8 flex items-center justify-between border-t border-gray-800 pt-4">
        <div>
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 text-sm text-gray-600 cursor-not-allowed">&larr; Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="px-3 py-1.5 text-sm text-gray-300 hover:text-white bg-gray-800 hover:bg-gray-700 rounded transition duration-200">&larr; Previous</a>
            @endif
        </div>

        <div class="text-sm text-gray-500">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
        </div>

        <div>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="px-3 py-1.5 text-sm text-gray-300 hover:text-white bg-gray-800 hover:bg-gray-700 rounded transition duration-200">Next &rarr;</a>
            @else
                <span class="px-3 py-1.5 text-sm text-gray-600 cursor-not-allowed">Next &rarr;</span>
            @endif
        </div>
    </nav>
@endif
