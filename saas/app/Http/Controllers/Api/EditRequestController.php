<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UsageEntry;
use App\Services\Ai\EditPlan;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use App\Services\Usage\BudgetExceeded;
use App\Services\Usage\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/v1/site/edit-requests: the plugin sends a page model and the
 * site owner's request, and gets edit operations back. Every call is
 * reserved against the account's budget first and settled with the real
 * usage afterwards, including calls that fail after the AI was charged.
 */
class EditRequestController extends Controller
{
    public function __construct(
        private readonly EditPlanner $planner,
        private readonly UsageMeter $meter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page_id' => ['required', 'integer', 'min:1'],
            'instruction' => ['required', 'string', 'max:2000'],
            'sections' => ['present', 'array'],
            'elements' => ['present', 'array'],
        ]);
        $context = (string) json_encode([$data['sections'], $data['elements']]);
        if (strlen($context) > config('gama.ai.max_context_bytes')) {
            return response()->json(['message' => 'This page is too large for one AI edit.'], 413);
        }

        /** @var Site $site */
        $site = $request->attributes->get('site');
        $estimate = $this->planner->estimateTokens($data['instruction'], $data['sections'], $data['elements']);

        try {
            $requestId = $this->meter->reserve($site->account, $site, $estimate, 'small_edit', ['page_id' => $data['page_id']]);
        } catch (BudgetExceeded $e) {
            return response()->json(['message' => $e->getMessage(), 'usage' => $this->meter->summary($site->account)->toArray()], 402);
        }

        try {
            $plan = $this->planner->plan($data['instruction'], $data['sections'], $data['elements']);
        } catch (EditPlannerException $e) {
            if ($e->plan !== null) {
                $this->settle($requestId, $e->plan);
            } else {
                $this->meter->release($requestId);
            }

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            $this->meter->release($requestId);
            throw $e;
        }

        $entry = $this->settle($requestId, $plan);

        return response()->json([
            'request_id' => $requestId,
            'summary' => $plan->summary,
            'operations' => $plan->operations,
            'usage' => [
                'model' => $plan->model,
                'tokens' => $entry->tokens,
                'estimated_cost_micros' => $entry->estimated_cost_micros,
                'account' => $this->meter->summary($site->account)->toArray(),
            ],
        ]);
    }

    private function settle(string $requestId, EditPlan $plan): UsageEntry
    {
        return $this->meter->settle(
            $requestId,
            $plan->model,
            $plan->inputTokens,
            $plan->outputTokens,
            cacheWriteTokens: $plan->cacheWriteTokens,
            cacheReadTokens: $plan->cacheReadTokens,
        );
    }
}
