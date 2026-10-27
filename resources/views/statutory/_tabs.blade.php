{{-- Tab bar shared by the Statutory screens --}}
@php
    $tabs = [
        'statutory.tax-tables' => ['Tax tables', 'statutory/tax-tables*'],
        'statutory.rates' => ['Contribution rates', 'statutory/rates*'],
        'statutory.employee-rates.index' => ['Employee rates', 'statutory/employee-rates*'],
    ];
@endphp
<nav class="mb-6 flex gap-1 border-b border-slate-200" aria-label="Statutory">
    @foreach ($tabs as $route => [$label, $pattern])
        <a href="{{ route($route) }}" @class([
            '-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium',
            'border-brand-700 text-brand-800' => request()->is('payroll/'.$pattern),
            'border-transparent text-slate-500 hover:text-slate-800' => ! request()->is('payroll/'.$pattern),
        ])>{{ $label }}</a>
    @endforeach
</nav>
