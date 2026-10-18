{{-- Grand livre d'une ligne : chaque écriture, dans l'ordre, en ajout seul (RG-01) --}}
<div class="overflow-x-auto">
    @if ($movements->isEmpty())
        <p class="text-sm text-gray-500">Aucun mouvement sur cette ligne pour l'instant.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-500">
                    <th class="py-2 pe-3">Date</th>
                    <th class="py-2 pe-3">Nature</th>
                    <th class="py-2 pe-3 text-right">Montant</th>
                    <th class="py-2 pe-3">Libellé</th>
                    <th class="py-2">Par</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $m)
                    <tr class="border-b border-gray-100">
                        <td class="py-1.5 pe-3 whitespace-nowrap">{{ $m->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="py-1.5 pe-3">{{ $m->kind->getLabel() }}</td>
                        <td class="py-1.5 pe-3 text-right whitespace-nowrap {{ str_starts_with((string) $m->amount, '-') ? 'text-success-600' : '' }}">
                            {{ \App\Support\Money::fmt($m->amount) }}
                        </td>
                        <td class="py-1.5 pe-3">{{ $m->note }}{{ $m->derogation_id ? ' — dérogation' : '' }}</td>
                        <td class="py-1.5">{{ $m->createdBy?->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-3 text-xs text-gray-500">Montants négatifs : libérations (conversion, paiement, clôture ou annulation).</p>
    @endif
</div>
