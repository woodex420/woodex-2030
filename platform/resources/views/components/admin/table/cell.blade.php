@props([
    'align' => 'left',      // left · right · center
    'numeric' => false,     // figures in columns of equal width
    'header' => false,      // the cell that names its row (read out before the others)
])
@aware(['dense' => false])
{{-- A cell of <x-admin.table>. Which columns a narrow screen drops is the screen's choice: class="hidden md:table-cell". --}}
@php $tag = $header ? 'th' : 'td'; @endphp
<{{ $tag }} @if ($header) scope="row" @endif
    {{ $attributes->class([$dense ? 'px-5 py-2.5' : 'px-5 py-3', 'text-right' => $align === 'right', 'text-center' => $align === 'center', 'tabular-nums' => $numeric, 'font-normal' => $header]) }}>{{ $slot }}</{{ $tag }}>
