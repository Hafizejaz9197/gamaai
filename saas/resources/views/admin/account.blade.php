@extends('admin.layout')
@section('title', $account->name)
@section('content')
<p><a href="{{ route('admin.dashboard') }}">&larr; All accounts</a></p>
<h1>{{ $account->name }}</h1>
<p class="muted">
    This month: {{ number_format($summary->used) }} used, {{ number_format($summary->held) }} held,
    {{ $summary->budget === null ? 'no budget limit' : number_format($summary->available()).' of '.number_format($summary->budget).' left' }}.
</p>
@include('admin._months')
@include('admin._tiles')

<h2>Sites</h2>
<div class="scroll">
<table>
    <tr><th>Site</th><th>Token</th><th>Plugin</th><th>Avada Builder</th><th>Last seen</th><th class="num">Tokens ({{ $report->start->format('M') }})</th><th></th></tr>
    @forelse ($sites as $site)
        <tr>
            <td>{{ $site->name }}<br>
                @if (\Illuminate\Support\Str::startsWith($site->url, ['https://', 'http://']))
                    <a class="muted" href="{{ $site->url }}" rel="noopener noreferrer" target="_blank">{{ $site->url }}</a>
                @else
                    <span class="muted">{{ $site->url }}</span>
                @endif
            </td>
            <td><code>{{ $site->token_prefix }}…</code></td>
            <td>{{ $site->plugin_version ?? '—' }}</td>
            <td>{{ $site->avada_builder_version ?? '—' }} {{ $site->avada_verified ? '✓' : '' }}</td>
            <td>{{ $site->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
            <td class="num">{{ number_format($bySite->get($site->id)['tokens'] ?? 0) }}</td>
            <td>
                <form method="post" action="{{ route('admin.sites.rotate', $site) }}" onsubmit="return confirm('The current token stops working at once. The plugin needs the new one. Continue?')">
                    @csrf <button class="link">New token</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No sites yet.</td></tr>
    @endforelse
</table>
</div>

<h2>Add a site</h2>
<form class="inline panel" method="post" action="{{ route('admin.sites.store', $account) }}">
    @csrf
    <label>Name <input name="name" required maxlength="255"></label>
    <label>WordPress URL <input name="url" type="url" required placeholder="https://example.com"></label>
    <button>Add site and show token</button>
</form>

<h2>Settings</h2>
<form class="inline panel" method="post" action="{{ route('admin.accounts.update', $account) }}">
    @csrf @method('put')
    <label>Name <input name="name" value="{{ $account->name }}" required maxlength="255"></label>
    <label>Monthly token budget <input name="monthly_token_budget" type="number" min="0" value="{{ $account->monthly_token_budget }}" placeholder="Empty = no limit"></label>
    <button>Save</button>
</form>

<h2>Adjust usage</h2>
<form class="inline panel" method="post" action="{{ route('admin.accounts.adjust', $account) }}">
    @csrf
    <label>Tokens (negative refunds) <input name="tokens" type="number" required></label>
    <label>Reason <input name="reason" required maxlength="255"></label>
    <button>Record</button>
</form>

@include('admin._breakdown', ['title' => 'By model', 'heading' => 'Model', 'rows' => $byModel, 'label' => fn ($m) => $m === null ? '<span class="muted">Manual adjustment</span>' : e($m)])
@include('admin._breakdown', ['title' => 'By request type', 'heading' => 'Type', 'rows' => $byPurpose, 'label' => fn ($p) => e($p ?? '—')])

<h2>Latest ledger rows</h2>
@include('admin._entries', ['entries' => $recent])
<p><a href="{{ route('admin.ledger', ['account' => $account->id, 'month' => $report->start->format('Y-m')]) }}">Full ledger for this account</a></p>
@endsection
