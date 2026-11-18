        <thead>
            <tr>
                <th style="width: 6%;">{{ $layout === 'project' ? 'Item #' : 'Sr' }}</th>
                <th style="width: 44%;">{{ $layout === 'project' ? 'Description' : 'Particulars' }}</th>
                @if ($layout === 'project')
                    <th style="width: 9%;">Unit</th>
                    <th class="right" style="width: 11%;">No's / qty</th>
                @else
                    <th class="right" style="width: 11%;">Qty / Area</th>
                    <th style="width: 9%;">Unit</th>
                @endif
                <th class="right" style="width: 14%;">Rate (Rs)</th>
                <th class="right" style="width: 16%;">Amount (Rs)</th>
            </tr>
        </thead>
