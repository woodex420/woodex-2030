<h2>Particulars</h2>
<table class="lines">
    <thead>
        <tr>
            <th style="width: 6%;">SR</th>
            <th style="width: 46%;">Description</th>
            <th class="right" style="width: 10%;">Qty</th>
            <th style="width: 10%;">Unit</th>
            <th class="right" style="width: 14%;">Rate</th>
            <th class="right" style="width: 14%;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($s['items'] as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item['description'] }}</td>
                <td class="right">{{ rtrim(rtrim(number_format((float) $item['qty'], 3, '.', ','), '0'), '.') }}</td>
                <td>{{ $item['unit_label'] ?? $item['unit'] }}</td>
                <td class="right">{{ $item['rate'] }}</td>
                <td class="right">{{ $item['line_total'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
