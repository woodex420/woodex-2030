<table class="financials"><tr><td class="space"></td><td><table class="summary"><tbody>@foreach ($summary as [$label, $amount, $class])<tr class="{{ $class }}"><td>{{ $label }}</td><td class="num amount">{{ $amount }}</td></tr>@endforeach</tbody></table></td></tr></table>
@if ($text['tax_note'])<p class="muted">{{ $text['tax_note'] }}</p>@endif
