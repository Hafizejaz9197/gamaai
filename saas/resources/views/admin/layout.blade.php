<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Dashboard') · Gama AI admin</title>
    <style>
        :root { --bg: #f6f7f9; --panel: #fff; --text: #1c2024; --muted: #60646c; --line: #e0e1e6; --accent: #3e63dd; --ok: #e9f6ec; --ok-line: #30a46c; --bad: #fdecec; --bad-line: #e5484d; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111113; --panel: #18191b; --text: #edeef0; --muted: #b0b4ba; --line: #2e3135; --accent: #8da4ef; --ok: #132d21; --bad: #3b1219; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
        a { color: var(--accent); }
        header { display: flex; gap: 16px; align-items: center; padding: 12px 24px; background: var(--panel); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
        header strong { margin-right: auto; }
        header form { margin: 0; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        h2 { font-size: 17px; margin: 28px 0 10px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .tile .label { color: var(--muted); font-size: 13px; }
        .tile .value { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        th { font-size: 13px; color: var(--muted); font-weight: 500; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }
        .notice { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; background: var(--ok); border-left: 4px solid var(--ok-line); }
        .errors { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; background: var(--bad); border-left: 4px solid var(--bad-line); }
        form.inline { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
        label { display: flex; flex-direction: column; font-size: 13px; color: var(--muted); gap: 4px; }
        input, select { font: inherit; padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--text); }
        button { font: inherit; padding: 7px 14px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer; }
        button.link { background: none; border: none; color: var(--accent); padding: 0; text-decoration: underline; }
        .months { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; }
        code.token { display: block; padding: 12px; font-size: 15px; word-break: break-all; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; user-select: all; }
    </style>
</head>
<body>
@auth
<header>
    <strong>Gama AI admin</strong>
    <a href="{{ route('admin.dashboard') }}">Dashboard</a>
    <a href="{{ route('admin.ledger') }}">Usage ledger</a>
    <a href="{{ route('admin.ai') }}">AI provider</a>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button class="link">Log out</button></form>
</header>
@endauth
<main>
    @if (session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
</body>
</html>
