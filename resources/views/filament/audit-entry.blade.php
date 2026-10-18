{{-- Détail d'une entrée du journal d'audit : avant / après, et chaînage --}}
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="mb-1 font-semibold">Avant</p>
            <pre class="max-h-80 overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $entry->before ? json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
        </div>
        <div>
            <p class="mb-1 font-semibold">Après</p>
            <pre class="max-h-80 overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $entry->after ? json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre>
        </div>
    </div>
    <div class="text-xs text-gray-500">
        <p>Empreinte : <span class="font-mono">{{ $entry->hash }}</span></p>
        <p>Empreinte précédente : <span class="font-mono">{{ $entry->prev_hash ?? '(première entrée)' }}</span></p>
        <p>Adresse IP : {{ $entry->ip_address ?? '—' }}</p>
    </div>
</div>
