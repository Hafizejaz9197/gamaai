<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function edit(): View
    {
        return view('admin.ai', ['setting' => AiSetting::current(), 'envKeySet' => (string) config('services.anthropic.key') !== '']);
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

        return view('admin.ai', ['setting' => AiSetting::current(), 'envKeySet' => (string) config('services.anthropic.key') !== '', 'test' => $test]);
    }
}
