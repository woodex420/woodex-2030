@php $wallets = $s['wallets'] ?? []; @endphp
@if ($bank || $wallets !== [])
    <div class="avoid">
        <h2>Payment details</h2>
        <table class="meta">
            <tr>
                @if ($bank)
                    <td style="width: {{ $wallets !== [] ? '49%' : '100%' }};">
                        <div class="box">
                            <strong>{{ $bank['bank_name'] }}</strong>
                            <table style="margin-top: 4px;">
                                <tr><td class="paylabel">Account title</td><td>{{ $bank['title'] }}</td></tr>
                                @if (! empty($bank['account_number']))<tr><td class="paylabel">Account no.</td><td>{{ $bank['account_number'] }}</td></tr>@endif
                                @if (! empty($bank['iban']))<tr><td class="paylabel">IBAN</td><td>{{ $bank['iban'] }}</td></tr>@endif
                                @if (! empty($bank['branch']))<tr><td class="paylabel">Branch</td><td>{{ $bank['branch'] }}@if (! empty($bank['branch_code'])) ({{ $bank['branch_code'] }})@endif</td></tr>@endif
                                @if (! empty($bank['wallet_provider']))<tr><td class="paylabel">Wallet</td><td>{{ $bank['wallet_provider'] }}</td></tr>@endif
                            </table>
                        </div>
                    </td>
                @endif
                @if ($bank && $wallets !== [])
                    <td style="width: 2%;"></td>
                @endif
                @if ($wallets !== [])
                    <td style="width: 49%;">
                        <div class="box">
                            <strong>Mobile wallets</strong>
                            <table style="margin-top: 4px;">
                                @foreach ($wallets as $wallet)
                                    <tr><td class="paylabel">{{ $wallet['provider'] }}</td><td>{{ $wallet['number'] }}</td></tr>
                                @endforeach
                            </table>
                        </div>
                    </td>
                @endif
            </tr>
        </table>
    </div>
@endif
