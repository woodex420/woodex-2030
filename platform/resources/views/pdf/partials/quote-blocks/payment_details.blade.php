@php $wallets = $s['wallets'] ?? []; @endphp
@if ($s['bank_accounts'] !== [] || $wallets !== [])
    <div class="avoid">
        <h2>Payment details</h2>
        <table class="meta">
            <tr>
                @if ($s['bank_accounts'] !== [])
                    <td style="width: {{ $wallets !== [] ? '49%' : '100%' }};">
                        @foreach ($s['bank_accounts'] as $account)
                            <div class="box" @if (! $loop->first) style="margin-top: 8px;" @endif>
                                <strong>{{ $account['bank_name'] }}</strong>
                                <table style="margin-top: 4px;">
                                    <tr><td class="paylabel">Account title</td><td>{{ $account['title'] }}</td></tr>
                                    @if ($account['account_number'])<tr><td class="paylabel">Account no.</td><td>{{ $account['account_number'] }}</td></tr>@endif
                                    @if ($account['iban'])<tr><td class="paylabel">IBAN</td><td>{{ $account['iban'] }}</td></tr>@endif
                                    @if ($account['branch'])<tr><td class="paylabel">Branch</td><td>{{ $account['branch'] }}@if (! empty($account['branch_code'])) ({{ $account['branch_code'] }})@endif</td></tr>@endif
                                </table>
                            </div>
                        @endforeach
                    </td>
                @endif
                @if ($s['bank_accounts'] !== [] && $wallets !== [])
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
