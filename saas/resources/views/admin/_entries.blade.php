{{-- Ledger rows. Expects $entries. --}}
<div class="scroll">
<table>
    <tr><th>Time (UTC)</th><th>Kind</th><th>Account</th><th>Site</th><th>Type</th><th>Model</th><th class="num">Tokens</th><th class="num">In / out</th><th class="num">Estimated</th><th class="num">Confirmed</th><th>Note</th></tr>
    @forelse ($entries as $entry)
        <tr>
            <td>{{ $entry->created_at->utc()->format('Y-m-d H:i:s') }}</td>
            <td>{{ $entry->kind }}</td>
            <td>{{ $entry->account?->name }}</td>
            <td>{{ $entry->site?->name ?? '—' }}</td>
            <td>{{ $entry->purpose ?? '—' }}</td>
            <td>{{ $entry->model ?? '—' }}</td>
            <td class="num">{{ number_format($entry->tokens) }}</td>
            <td class="num">{{ $entry->kind === 'settle' ? number_format($entry->input_tokens).' / '.number_format($entry->output_tokens) : '' }}</td>
            <td class="num">{{ $entry->kind === 'settle' ? \App\Support\Money::usd($entry->estimated_cost_micros) : '' }}</td>
            <td class="num">{{ $entry->kind === 'settle' ? \App\Support\Money::usd($entry->provider_cost_micros) : '' }}</td>
            <td class="muted">
                @if (isset($entry->meta['reason'])) {{ $entry->meta['reason'] }}
                @elseif (isset($entry->meta['page_id'])) Page {{ $entry->meta['page_id'] }}@isset($entry->meta['context_bytes']), sent {{ number_format($entry->meta['context_bytes'] / 1024, 1) }} KB{{ ! empty($entry->meta['scope']) ? ' (one section)' : '' }}, max answer {{ number_format($entry->meta['max_output_tokens']) }}@endisset
                @else
                    @if (isset($entry->meta['status']) && $entry->meta['status'] !== 'ok') {{ ucfirst(str_replace('_', ' ', $entry->meta['status'])) }}@isset($entry->meta['retry_after']), wait {{ $entry->meta['retry_after'] }}s @endisset. @endif
                    @if (($entry->meta['attempts'] ?? 1) > 1) {{ $entry->meta['attempts'] }} calls. @endif
                    @isset($entry->meta['duration_ms']) {{ number_format($entry->meta['duration_ms'] / 1000, 1) }}s. @endisset
                    @isset($entry->meta['cache_read_tokens']) Cache read {{ number_format($entry->meta['cache_read_tokens']) }}, write {{ number_format($entry->meta['cache_write_tokens']) }}. @endisset
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="11" class="muted">No rows.</td></tr>
    @endforelse
</table>
</div>
