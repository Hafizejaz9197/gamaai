@extends('admin.layout')
@section('title', 'Site token')
@section('content')
<p><a href="{{ route('admin.accounts.show', $site->account_id) }}">&larr; Back to the account</a></p>
<h1>{{ $rotated ? 'New token for' : 'Site added:' }} {{ $site->name }}</h1>
<div class="panel">
    <p><strong>Copy this token now.</strong> It is shown only on this page and is not stored anywhere in plain form. Don't send it in chat or email.</p>
    <code class="token">{{ $token }}</code>
    @if ($rotated)
        <p>The old token has stopped working. Put this one in the plugin.</p>
    @endif
    <p>In WordPress, go to <strong>Settings → Gama AI</strong>, enter the service URL <code>{{ url('/') }}</code> and this token, then click <strong>Save and test connection</strong>.</p>
</div>
@endsection
