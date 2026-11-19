@props(['chosen' => false, 'hover' => true])
{{-- A row of <x-admin.table>. chosen: the row is among the chosen ones (its tick box says so to a screen reader). --}}
<tr {{ $attributes->class(['hover:bg-gray-50 dark:hover:bg-white/[0.02]' => $hover, 'bg-navy-50/50 dark:bg-white/[0.03]' => $chosen]) }}>{{ $slot }}</tr>
