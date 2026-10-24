{{-- One usage breakdown table. Expects $title, $rows, $label (callable key → text). --}}
<h2>{{ $title }}</h2>
<div class="scroll">
<table>
    <tr><th>{{ $heading }}</th><th class="num">Requests</th><th class="num">Tokens</th><th class="num">Input</th><th class="num">Output</th><th class="num">Estimated</th><th class="num">Confirmed</th></tr>
    @forelse ($rows as $row)
        <tr>
            <td>{!! $label($row['key']) !!}</td>
            <td class="num">{{ number_format($row['requests']) }}</td>
            <td class="num">{{ number_format($row['tokens']) }}</td>
            <td class="num">{{ number_format($row['input_tokens']) }}</td>
            <td class="num">{{ number_format($row['output_tokens']) }}</td>
            <td class="num">{{ \App\Support\Money::usd($row['estimated_cost_micros']) }}</td>
            <td class="num">{{ $row['confirmed'] ? \App\Support\Money::usd($row['provider_cost_micros']) : '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No usage this month.</td></tr>
    @endforelse
</table>
</div>
