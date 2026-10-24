<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Choose the AI provider, model and API key. The key is stored encrypted
 * and never sent back to the browser.
 */
class AiSettingsController extends Controller
{
    /** A tiny page used by "Test": one heading to rename. */
    public const TEST_SECTIONS = [[
        'id' => '0', 'type' => 'fusion_builder_container', 'children' => [[
            'id' => '0.0', 'type' => 'fusion_builder_row', 'children' => [[
                'id' => '0.0.0', 'type' => 'fusion_builder_column', 'children' => [[
                    'id' => '0.0.0.0', 'type' => 'fusion_title', 'attrs' => ['size' => '1'], 'text' => 'Welcome',
                ]],
            ]],
        ]],
    ]];

    public const TEST_ELEMENTS = ['fusion_title' => ['element_content' => 'textarea', 'size' => ['1', '2', '3', '4', '5', '6']]];

    public const TEST_INSTRUCTION = 'Change the main heading to "Hello from Gama AI".';

    /** Model names that are not chat models (speech, embeddings, moderation). */
    public const NON_CHAT = '/whisper|tts|embed|guard|moderation|audio|image|veo|imagen|aqa/i';

    public function edit(): View
    {
        return $this->page();
    }

    /**
     * Ask the saved provider which models this key can use, free ones first.
     */
    public function models(): View
    {
        $setting = AiSetting::current();
        $anthropic = $setting === null || $setting->provider === AiSetting::ANTHROPIC;
        $key = (string) ($setting?->api_key ?: ($anthropic ? config('services.anthropic.key') : ''));
        if ($key === '') {
            return $this->page(['modelsError' => 'Save an API key first.']);
        }

        try {
            $response = $anthropic
                ? Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)->get('https://api.anthropic.com/v1/models', ['limit' => 100])
                : Http::withToken($key)->acceptJson()->timeout(30)->get(rtrim((string) $setting->base_url, '/').'/models');
        } catch (ConnectionException) {
            return $this->page(['modelsError' => 'The provider could not be reached.']);
        }
        if ($response->failed()) {
            return $this->page(['modelsError' => 'The provider returned an error ('.$response->status().').']);
        }

        $models = [];
        foreach ((array) $response->json('data', []) as $item) {
            $id = is_array($item) && is_string($item['id'] ?? null) ? preg_replace('#^models/#', '', $item['id']) : null;
            if ($id === null || preg_match(self::NON_CHAT, $id)) {
                continue;
            }
            $free = str_ends_with($id, ':free')
                || (isset($item['pricing']['prompt'], $item['pricing']['completion']) && (float) $item['pricing']['prompt'] === 0.0 && (float) $item['pricing']['completion'] === 0.0);
            $models[$id] = ['id' => $id, 'free' => $free];
        }
        $models = collect($models)->sortBy(fn ($m) => [$m['free'] ? 0 : 1, $m['id']])->take(300)->values()->all();

        return $this->page(['models' => $models, 'modelsError' => $models ? null : 'The provider listed no chat models for this key.']);
    }

    public function update(Request $request): RedirectResponse
    {
        $current = AiSetting::current();
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiSetting::PROVIDERS))],
            'base_url' => ['nullable', 'required_if:provider,'.AiSetting::OPENAI_COMPATIBLE, 'url:https', 'max:255'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/@-]+$/'],
            'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
        ]);

        // An empty key field keeps the saved key, unless the provider changed.
        $key = $data['api_key'] ?? null;
        if ($key === null && $current?->provider === $data['provider']) {
            $key = $current->api_key;
        }
        if ($key === null && $data['provider'] === AiSetting::OPENAI_COMPATIBLE) {
            return back()->withInput()->withErrors(['api_key' => 'Enter the API key for this provider.']);
        }

        AiSetting::create([
            'provider' => $data['provider'],
            'base_url' => $data['provider'] === AiSetting::OPENAI_COMPATIBLE ? $data['base_url'] : null,
            'model' => $data['model'],
            'api_key' => $key,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.ai')->with('status', 'AI settings saved. New edit requests use them straight away.');
    }

    /**
     * Send a tiny sample page to the provider and show what came back. Not
     * counted against any account.
     */
    public function test(EditPlanner $planner): View
    {
        try {
            $plan = $planner->plan(self::TEST_INSTRUCTION, self::TEST_SECTIONS, self::TEST_ELEMENTS);
            $test = ['ok' => true, 'plan' => $plan];
        } catch (EditPlannerException $e) {
            $test = ['ok' => false, 'message' => $e->getMessage(), 'plan' => $e->plan];
        }

        return $this->page(['test' => $test]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(array $extra = []): View
    {
        return view('admin.ai', $extra + ['setting' => AiSetting::current(), 'envKeySet' => (string) config('services.anthropic.key') !== '']);
    }
}
