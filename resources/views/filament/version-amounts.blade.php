{{-- Montants d'une version budgétaire (y compris archivée), ligne par ligne --}}
<table class="w-full text-sm">
    <thead>
        <tr class="border-b text-left text-gray-500">
            <th class="py-2 pe-3">Code</th>
            <th class="py-2 pe-3">Intitulé</th>
            <th class="py-2 text-right">Montant ({{ $currency }})</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($amounts as $a)
            <tr class="border-b border-gray-100">
                <td class="py-1.5 pe-3">{{ $a->line->code }}</td>
                <td class="py-1.5 pe-3">{{ $a->line->label }}</td>
                <td class="py-1.5 text-right">{{ \App\Support\Money::fmt($a->amount) }}</td>
            </tr>
        @endforeach
        <tr class="font-semibold">
            <td class="py-2 pe-3" colspan="2">Total</td>
            <td class="py-2 text-right">{{ \App\Support\Money::fmt(\App\Support\Money::sum($amounts->pluck('amount'))) }}</td>
        </tr>
    </tbody>
</table>
