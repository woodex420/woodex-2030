@php
    $signers = array_slice($s['signatories'], 0, 2);
    $cellWidth = round(100 / (count($signers) + 1), 2);
@endphp
<table class="sign avoid">
    <tr>
        @foreach ($signers as $signatory)
            <td style="width: {{ $cellWidth }}%;"><div class="line"><strong>{{ $signatory['name'] }}</strong><br><span class="muted">{{ $signatory['title'] }}, {{ $company['brand'] }}</span></div></td>
        @endforeach
        <td style="width: {{ $cellWidth }}%;"><div class="line"><strong>Accepted by client</strong><br><span class="muted">{{ $client['name'] }}<br>Signature · date · stamp</span></div></td>
    </tr>
</table>
