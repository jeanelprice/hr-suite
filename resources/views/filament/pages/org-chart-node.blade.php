<li>
    <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('view', ['record' => $employee]) }}"
       class="hr-org-card bg-white ring-1 ring-gray-950/10 shadow-sm hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
        <img src="{{ $photo($employee) }}" alt="">
        <span class="hr-org-name text-gray-950 dark:text-white">{{ $employee->full_name }}</span>
        <span class="hr-org-role text-gray-500 dark:text-gray-400">{{ $employee->position?->title ?? 'Poste non renseigné' }}@if ($employee->dutyStation) · {{ $employee->dutyStation->name }}@endif</span>
        @if ($employee->status === \App\Enums\EmployeeStatus::EN_ESSAI)
            <x-filament::badge color="warning" size="sm">En essai</x-filament::badge>
        @endif
    </a>
    @if ($depth < 12 && $children->has($employee->id))
        <ul>
            @foreach ($children->get($employee->id) as $child)
                @include('filament.pages.org-chart-node', ['employee' => $child, 'children' => $children, 'photo' => $photo, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
