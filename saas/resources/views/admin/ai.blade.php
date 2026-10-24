@extends('admin.layout')
@section('title', 'AI provider')
@section('content')
<h1>AI provider</h1>
<p class="muted">
    In use now:
    @if ($setting)
        <strong>{{ \App\Models\AiSetting::PROVIDERS[$setting->provider] ?? $setting->provider }}</strong>, model <code>{{ $setting->model }}</code>
        @if ($setting->base_url) at <code>{{ $setting->base_url }}</code>@endif
        · API key {{ $setting->api_key ? 'saved' : 'from .env' }} · changed {{ $setting->updated_at->diffForHumans() }}.
    @else
        <strong>Anthropic Claude</strong> from <code>.env</code>, model <code>{{ config('gama.ai.model') }}</code> · API key {{ $envKeySet ? 'set' : 'not set' }}.
    @endif
</p>

@isset($test)
    <div class="{{ $test['ok'] ? 'notice' : 'errors' }}">
        @if ($test['ok'])
            <div><strong>The provider answered.</strong> {{ $test['plan']->summary }}</div>
            <div>{{ count($test['plan']->operations) }} {{ \Illuminate\Support\Str::plural('operation', count($test['plan']->operations)) }}: <code>{{ json_encode($test['plan']->operations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></div>
        @else
            <div><strong>The test failed.</strong> {{ $test['message'] }}</div>
        @endif
        @if ($test['plan'])
            <div class="muted">Model {{ $test['plan']->model }} · {{ number_format($test['plan']->inputTokens + $test['plan']->cacheReadTokens) }} input, {{ number_format($test['plan']->outputTokens) }} output tokens.</div>
        @endif
    </div>
@endisset

<form class="panel" method="post" action="{{ route('admin.ai.update') }}" style="display: grid; gap: 12px; max-width: 640px;">
    @csrf @method('put')
    <label>Quick fill
        <select id="preset">
            <option value="">Choose a provider to fill in the fields…</option>
            <option data-provider="openai_compatible" data-url="https://generativelanguage.googleapis.com/v1beta/openai" data-model="gemini-2.5-flash">Google Gemini (free tier, key from aistudio.google.com)</option>
            <option data-provider="openai_compatible" data-url="https://api.groq.com/openai/v1" data-model="llama-3.3-70b-versatile">Groq (free tier, key from console.groq.com)</option>
            <option data-provider="openai_compatible" data-url="https://openrouter.ai/api/v1" data-model="">OpenRouter (key from openrouter.ai; pick a model ending in :free at openrouter.ai/models)</option>
            <option data-provider="anthropic" data-url="" data-model="claude-opus-5-5">Anthropic Claude Opus 5.5 (paid, key from console.anthropic.com)</option>
        </select>
    </label>
    <label>Provider
        <select name="provider" id="provider">
            @foreach (\App\Models\AiSetting::PROVIDERS as $value => $label)
                <option value="{{ $value }}" @selected(old('provider', $setting->provider ?? 'anthropic') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label id="base-url-field">API base URL
        <input name="base_url" id="base_url" type="url" value="{{ old('base_url', $setting->base_url ?? '') }}" placeholder="https://…/v1">
    </label>
    <label>Model
        <input name="model" id="model" required value="{{ old('model', $setting->model ?? config('gama.ai.model')) }}">
    </label>
    <label>API key
        <input name="api_key" type="password" autocomplete="new-password" placeholder="{{ $setting?->api_key ? 'Saved. Leave empty to keep it.' : 'Paste the key' }}">
    </label>
    <div><button>Save</button></div>
</form>

<h2>Test</h2>
<form class="inline" method="post" action="{{ route('admin.ai.test') }}">
    @csrf
    <button>Send a test request</button>
    <span class="muted">Asks the saved provider to rename the heading of a tiny sample page. Uses a few thousand tokens, not counted in any account.</span>
</form>

<script>
    (function () {
        var provider = document.getElementById('provider');
        var urlField = document.getElementById('base-url-field');
        function sync() { urlField.style.display = provider.value === 'openai_compatible' ? '' : 'none'; }
        document.getElementById('preset').addEventListener('change', function () {
            var o = this.selectedOptions[0];
            if (!o.dataset.provider) { return; }
            provider.value = o.dataset.provider;
            document.getElementById('base_url').value = o.dataset.url;
            document.getElementById('model').value = o.dataset.model;
            sync();
        });
        provider.addEventListener('change', sync);
        sync();
    }());
</script>
@endsection
