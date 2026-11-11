<section class="terms">
    <h3>Payment schedule</h3>
    <table class="lines">
        <thead><tr><th>Payment request</th><th class="right">Share</th><th class="right">Amount (PKR)</th></tr></thead>
        <tbody>
            <tr><td>Advance with work order</td><td class="right">{{ $advancePercent }}%</td><td class="right">{{ $money((int) $t['advance_minor']) }}</td></tr>
            <tr><td>Remaining after advance</td><td class="right">{{ $qty((10000 - (int) $t['advance_bp']) / 100) }}%</td><td class="right">{{ $money((int) $t['after_advance_minor']) }}</td></tr>
        </tbody>
    </table>
    <p class="muted">Requested payments only; this quotation does not record money received.</p>
</section>
