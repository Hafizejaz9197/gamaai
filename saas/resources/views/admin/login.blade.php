@extends('admin.layout')
@section('title', 'Log in')
@section('content')
<div class="panel" style="max-width: 360px; margin: 40px auto;">
    <h1>Gama AI admin</h1>
    <form method="post" action="{{ route('admin.login') }}" style="display: grid; gap: 12px;">
        @csrf
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button>Log in</button>
    </form>
</div>
@endsection
