            @foreach ($section['items'] as $ii => $item)
                <tr @class(['optional' => $item['is_optional']])>
                    <td>{{ ($si + 1).'.'.($ii + 1) }}</td>
                    <td>@if (($item['image'] ?? null) !== null)<img src="{{ $item['image'] }}" alt="" style="float:left;width:52px;height:52px;object-fit:cover;margin:0 8px 2px 0;border-radius:4px;">@endif{!! nl2br(e($item['description'])) !!}@if ($item['is_optional']) (optional, not in total)@endif
                        @if ($item['discount_minor'] > 0)<br><span class="muted">Discount {{ $money($item['discount_minor']) }}</span>@endif</td>
                    @if ($layout === 'project')
                        <td>{{ $item['unit_label'] }}</td>
                        <td class="right">{{ (float) $item['qty'] !== 0.0 ? $qty($item['qty']) : '–' }}</td>
                    @else
                    <td class="right">{{ (float) $item['qty'] !== 0.0 ? $qty($item['qty']) : '–' }}</td>
                    <td>{{ $item['unit_label'] }}</td>
                    @endif
                    <td class="right">{{ $item['rate_minor'] !== 0 ? $plain($item['rate_minor']) : '–' }}</td>
                    <td class="right">{{ $item['line_total_minor'] !== 0 ? $plain($item['line_total_minor']) : '–' }}</td>
                </tr>
            @endforeach
