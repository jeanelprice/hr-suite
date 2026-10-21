@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Absences" description="Cette semaine et la suivante (congés approuvés)." icon="heroicon-o-user-minus">
        @if (empty($rows))
            <p style="font-size:14px;opacity:.7">Personne n'est absent sur la période.</p>
        @else
            <div style="display:flex;flex-direction:column">
                @foreach ($rows as $r)
                    <a href="{{ $r['url'] }}" style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;font-size:14px;{{ $loop->first ? '' : 'border-top:1px solid rgba(148,163,184,.35);' }}">
                        <span style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <x-filament::badge :color="$r['now'] ? 'warning' : 'gray'">{{ $r['now'] ? 'Absent aujourd\'hui' : 'À venir' }}</x-filament::badge>
                            <span style="font-weight:600">{{ $r['name'] }}</span>
                            <span style="opacity:.6">{{ $r['type'] }}</span>
                            @if ($r['interim'])<span style="opacity:.6">· intérim : {{ $r['interim'] }}</span>@endif
                        </span>
                        <span style="white-space:nowrap;opacity:.8">{{ $r['period'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
