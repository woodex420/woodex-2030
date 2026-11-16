@if ($s['milestones'] !== [])
    <div class="avoid">
        <h2>Payment schedule</h2>
        <table class="lines">
            <thead><tr><th style="width: 60%;">Milestone</th><th class="right" style="width: 15%;">Share</th><th class="right" style="width: 25%;">Amount</th></tr></thead>
            <tbody>
                @foreach ($s['milestones'] as $milestone)
                    <tr><td>{{ $milestone['name'] }}</td><td class="right">{{ rtrim(rtrim(number_format($milestone['percent_bp'] / 100, 2), '0'), '.') }}%</td><td class="right">{{ $money($milestone['amount_minor']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
