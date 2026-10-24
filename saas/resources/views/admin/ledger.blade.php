@extends('admin.layout')
@section('title', 'Usage ledger')
@section('content')
<h1>Usage ledger</h1>
@include('admin._months')
<form class="inline" method="get" action="{{ route('admin.ledger') }}" style="margin-bottom: 16px;">
    <input type="hidden" name="month" value="{{ $report->start->format('Y-m') }}">
    <label>Account
        <select name="account">
            <option value="">All accounts</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}" @selected($accountId === $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
    </label>
    <label>Kind
        <select name="kind">
            <option value="">All kinds</option>
            @foreach (\App\Http\Controllers\Admin\LedgerController::KINDS as $k)
                <option value="{{ $k }}" @selected($kind === $k)>{{ $k }}</option>
            @endforeach
        </select>
    </label>
    <button>Filter</button>
</form>
<p class="muted">Rows are never changed or deleted. A request reserves tokens first, then settles with the real usage or is released.</p>
@include('admin._entries', ['entries' => $entries])
<div class="months" style="margin-top: 12px;">
    @if ($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">&larr; Newer</a>@endif
    <span class="muted">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }} · {{ number_format($entries->total()) }} rows</span>
    @if ($entries->nextPageUrl())<a href="{{ $entries->nextPageUrl() }}">Older &rarr;</a>@endif
</div>
@endsection
