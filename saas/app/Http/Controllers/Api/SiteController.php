<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Usage\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Routes the WordPress plugin calls with its site token.
 */
class SiteController extends Controller
{
    public function __construct(private readonly UsageMeter $meter) {}

    /**
     * POST /api/v1/site/check-in: the plugin reports its versions.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plugin_version' => ['required', 'string', 'max:32'],
            'avada_builder_version' => ['nullable', 'string', 'max:32'],
            'avada_verified' => ['required', 'boolean'],
        ]);

        $site = $this->site($request);
        $site->fill($data + ['last_seen_at' => now()])->save();

        return response()->json([
            'site' => $site->only(['id', 'name', 'url', 'plugin_version', 'avada_builder_version', 'avada_verified']),
            'usage' => $this->meter->summary($site->account)->toArray(),
        ]);
    }

    /**
     * GET /api/v1/site/usage: this month's usage for the site's account.
     */
    public function usage(Request $request): JsonResponse
    {
        return response()->json($this->meter->summary($this->site($request)->account)->toArray());
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
