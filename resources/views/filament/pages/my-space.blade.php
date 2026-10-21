@php
    $e = $this->employee();
    $b = $this->balances();
    $fmt = $b['fmt'];
    $a = $b['annual'];
    $r = $b['recovery'];
    $contract = $e->activeContract;
    $terms = $contract?->termsAt();
@endphp
<x-filament-panels::page>
    <style>
        .hr-me { display: grid; gap: 16px; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .hr-me { grid-template-columns: 1fr 1fr 1fr; } }
        .hr-me-id { display: flex; gap: 16px; align-items: center; }
        .hr-me-id img { width: 72px; height: 72px; border-radius: 9999px; object-fit: cover; flex: none; }
        .hr-me-name { font-size: 18px; font-weight: 700; }
        .hr-me-sub { font-size: 13px; opacity: .7; line-height: 1.5; }
        .hr-big { font-size: 34px; font-weight: 700; line-height: 1; }
        .hr-kv { display: grid; grid-template-columns: auto auto; gap: 4px 16px; font-size: 13px; margin-top: 12px; }
        .hr-kv span:nth-child(odd) { opacity: .65; }
    </style>

    <div class="hr-me">
        <x-filament::section>
            <div class="hr-me-id">
                <img src="{{ $this->photo() }}" alt="">
                <div>
                    <div class="hr-me-name">{{ $e->full_name }}</div>
                    <div class="hr-me-sub">{{ $e->matricule }} · {{ $e->position?->title ?? 'Poste non renseigné' }}</div>
                    <div class="hr-me-sub">{{ $e->dutyStation?->name }}@if ($e->supervisor) · Supérieur : {{ $e->supervisor->full_name }}@endif</div>
                </div>
            </div>
            <div class="hr-kv">
                <span>Statut</span><span>{{ $e->status->getLabel() }}</span>
                <span>Entrée en fonction</span><span>{{ $e->hired_at->format('d/m/Y') }} ({{ $e->seniorityYears() }} an(s))</span>
                @if ($contract)
                    <span>Contrat</span><span>{{ $contract->number }} · {{ $contract->type->getLabel() }}</span>
                    <span>Fin du contrat</span><span>{{ $terms['end_date'] ? \Illuminate\Support\Carbon::parse($terms['end_date'])->format('d/m/Y') : 'Indéterminée' }}</span>
                    @if ($terms['probation_end_date'] && $e->status === \App\Enums\EmployeeStatus::EN_ESSAI)
                        <span>Fin de l'essai</span><span>{{ \Illuminate\Support\Carbon::parse($terms['probation_end_date'])->format('d/m/Y') }}</span>
                    @endif
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Congé annuel" :description="'Année contractuelle du '.$a['from']->format('d/m/Y').' au '.$a['to']->format('d/m/Y')">
            <div class="hr-big">{{ $fmt($a['available'] - $a['pending']) }} <span style="font-size:15px;font-weight:500;opacity:.7">jour(s) disponible(s)</span></div>
            <div class="hr-kv">
                <span>Acquis cette année</span><span>{{ $fmt($a['acquired']) }} j (1,5 j par mois)</span>
                <span>Droit annuel complet</span><span>{{ $fmt($a['entitlement']) }} j</span>
                <span>Déjà pris</span><span>{{ $fmt($a['taken']) }} j</span>
                @if ($a['adjusted'] != 0)<span>Ajustements RH</span><span>{{ $fmt($a['adjusted']) }} j</span>@endif
                @if ($a['pending'] > 0)<span>En attente de décision</span><span>{{ $fmt($a['pending']) }} j</span>@endif
            </div>
            @if ($e->status === \App\Enums\EmployeeStatus::EN_ESSAI)
                <p class="hr-me-sub" style="margin-top:10px">Pendant la période d'essai, les jours s'acquièrent mais ne se prennent qu'après la confirmation.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Récupération" description="Jours accordés pour un travail un jour férié ou en heures longues">
            <div class="hr-big">{{ $fmt($r['available'] - $r['pending']) }} <span style="font-size:15px;font-weight:500;opacity:.7">jour(s)</span></div>
            <div class="hr-kv">
                <span>Accordés</span><span>{{ $fmt($r['acquired']) }} j</span>
                <span>Pris</span><span>{{ $fmt($r['taken']) }} j</span>
            </div>
            <p class="hr-me-sub" style="margin-top:10px">Le solde de congé annuel non pris expire à la fin de l'année contractuelle (Manuel §5.10.1).</p>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
