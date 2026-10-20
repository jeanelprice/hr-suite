@php $rows = $this->getRows(); @endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Échéances du personnel" description="Contrats à 30 jours de leur terme, périodes d'essai à 15 jours (RG-19)." icon="heroicon-o-calendar-days">
        @if (empty($rows))
            <p style="font-size:14px;opacity:.7">Aucune échéance proche.</p>
        @else
            <div style="display:flex;flex-direction:column">
                @foreach ($rows as $r)
                    <a href="{{ $r['url'] }}" style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;font-size:14px;{{ $loop->first ? '' : 'border-top:1px solid rgba(148,163,184,.35);' }}">
                        <span style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <x-filament::badge :color="$r['days'] < 0 ? 'danger' : ($r['days'] <= 7 ? 'warning' : 'gray')">{{ $r['kind'] }}</x-filament::badge>
                            <span style="font-weight:600">{{ $r['name'] }}</span>
                            <span style="opacity:.6">{{ $r['number'] }}</span>
                        </span>
                        <span style="white-space:nowrap;{{ $r['days'] < 0 ? 'color:rgb(220,38,38)' : 'opacity:.8' }}">
                            {{ $r['label'] }} · {{ $r['days'] < 0 ? 'dépassée de '.abs($r['days']).' j' : ($r['days'] === 0 ? "aujourd'hui" : 'dans '.$r['days'].' j') }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
