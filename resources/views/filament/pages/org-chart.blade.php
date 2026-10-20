@php
    $tree = $this->getTree();
    $photo = fn ($e) => \App\Filament\Resources\Employees\EmployeeResource::photoUrl($e) ?? \App\Filament\Resources\Employees\EmployeeResource::initialsAvatar($e);
@endphp
<x-filament-panels::page>
    <style>
        .hr-org { overflow-x: auto; padding: 8px 0 24px; }
        .hr-org ul { display: flex; justify-content: center; padding-top: 20px; position: relative; margin: 0; padding-left: 0; list-style: none; }
        .hr-org li { text-align: center; position: relative; padding: 20px 6px 0; }
        .hr-org li::before, .hr-org li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 20px; border-top: 1px solid rgb(148 163 184); }
        .hr-org li::after { right: auto; left: 50%; border-left: 1px solid rgb(148 163 184); }
        .hr-org li:only-child::before, .hr-org li:only-child::after { display: none; }
        .hr-org li:only-child { padding-top: 0; }
        .hr-org li:first-child::before, .hr-org li:last-child::after { border: 0 none; }
        .hr-org li:last-child::before { border-right: 1px solid rgb(148 163 184); border-radius: 0 6px 0 0; }
        .hr-org li:first-child::after { border-radius: 6px 0 0 0; }
        .hr-org ul ul::before { content: ''; position: absolute; top: 0; left: 50%; height: 20px; border-left: 1px solid rgb(148 163 184); }
        .hr-org > ul { padding-top: 0; }
        .hr-org-card { display: inline-flex; flex-direction: column; align-items: center; gap: 6px; width: 168px; padding: 12px 10px; border-radius: 12px; text-decoration: none; }
        .hr-org-card img { width: 56px; height: 56px; border-radius: 9999px; object-fit: cover; }
        .hr-org-name { font-weight: 600; font-size: 13px; line-height: 1.25; }
        .hr-org-role { font-size: 12px; line-height: 1.25; }
    </style>

    @if ($tree['roots']->isEmpty())
        <x-filament::section>Aucun employé enregistré pour l'instant.</x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-bottom:12px">
                Construit à partir du supérieur hiérarchique direct saisi dans chaque dossier. Cliquez sur une personne pour ouvrir son dossier.
            </p>
            <div class="hr-org">
                <ul>
                    @foreach ($tree['roots'] as $root)
                        @include('filament.pages.org-chart-node', ['employee' => $root, 'children' => $tree['children'], 'photo' => $photo, 'depth' => 0])
                    @endforeach
                </ul>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
