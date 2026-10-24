@extends('admin.layout')
@section('content')
<h1>Usage</h1>
@include('admin._months')
@include('admin._tiles')
@if ($releases)
    <p class="muted">{{ $releases }} {{ \Illuminate\Support\Str::plural('request', $releases) }} released without an AI call (for example over budget or a connection error).</p>
@endif

<h2>Accounts</h2>
<div class="scroll">
<table>
    <tr><th>Account</th><th class="num">Sites</th><th class="num">Tokens ({{ $report->start->format('M') }})</th><th class="num">Estimated ({{ $report->start->format('M') }})</th><th class="num">Budget / month</th><th class="num">Left now</th></tr>
    @forelse ($accounts as $row)
        <tr>
            <td><a href="{{ route('admin.accounts.show', $row['account']) }}">{{ $row['account']->name }}</a></td>
            <td class="num">{{ $row['account']->sites_count }}</td>
            <td class="num">{{ number_format($row['usage']['tokens'] ?? 0) }}</td>
            <td class="num">{{ \App\Support\Money::usd($row['usage']['estimated_cost_micros'] ?? 0) }}</td>
            <td class="num">{{ $row['summary']->budget === null ? 'No limit' : number_format($row['summary']->budget) }}</td>
            <td class="num">{{ $row['summary']->available() === null ? '—' : number_format($row['summary']->available()) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No accounts yet.</td></tr>
    @endforelse
</table>
</div>

<h2>New account</h2>
<form class="inline panel" method="post" action="{{ route('admin.accounts.store') }}">
    @csrf
    <label>Name <input name="name" required maxlength="255"></label>
    <label>Monthly token budget <input name="monthly_token_budget" type="number" min="0" placeholder="Empty = no limit"></label>
    <button>Create account</button>
</form>

@include('admin._breakdown', ['title' => 'By site', 'heading' => 'Site', 'rows' => $bySite, 'label' => fn ($id) => $id === null ? '<span class="muted">No site</span>' : e(($sites[$id]->name ?? "Site {$id}").' · '.($sites[$id]->account->name ?? ''))])
@include('admin._breakdown', ['title' => 'By model', 'heading' => 'Model', 'rows' => $byModel, 'label' => fn ($m) => $m === null ? '<span class="muted">Manual adjustment</span>' : e($m)])
@include('admin._breakdown', ['title' => 'By request type', 'heading' => 'Type', 'rows' => $byPurpose, 'label' => fn ($p) => e($p ?? '—')])
@endsection
