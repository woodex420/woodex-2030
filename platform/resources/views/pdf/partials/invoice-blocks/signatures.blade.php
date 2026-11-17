@php $signatory = $s['signatory'] ?? null; @endphp
<table class="sign avoid">
    <tr>
        <td style="width: 50%;"><div class="line"><strong>{{ ! empty($signatory['name']) ? $signatory['name'] : 'Prepared by' }}</strong><br><span class="muted">{{ ! empty($signatory['title']) ? $signatory['title'].', ' : 'For ' }}{{ $s['business']['name'] }}</span></div></td>
        <td style="width: 50%;"><div class="line"><strong>Received by</strong><br><span class="muted">{{ ! empty($client['company']) ? $client['company'] : $client['name'] }}<br>Signature · date · stamp</span></div></td>
    </tr>
</table>
