{{-- Month totals. Expects $totals. --}}
<div class="tiles">
    <div class="panel tile"><div class="label">Tokens charged</div><div class="value">{{ number_format($totals['tokens']) }}</div></div>
    <div class="panel tile"><div class="label">AI requests</div><div class="value">{{ number_format($totals['requests']) }}</div></div>
    <div class="panel tile"><div class="label">Estimated cost</div><div class="value">{{ \App\Support\Money::usd($totals['estimated_cost_micros']) }}</div></div>
    <div class="panel tile"><div class="label">Provider-confirmed cost</div><div class="value">{{ $totals['confirmed'] ? \App\Support\Money::usd($totals['provider_cost_micros']) : '—' }}</div>
        <div class="label">{{ $totals['confirmed'] }} of {{ $totals['requests'] }} requests confirmed</div></div>
    <div class="panel tile"><div class="label">Average per request</div><div class="value">{{ $totals['requests'] ? number_format(intdiv($totals['tokens'], $totals['requests'])) : '—' }}</div><div class="label">tokens</div></div>
</div>
