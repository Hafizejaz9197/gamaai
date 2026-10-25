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
use Illuminate\Support\Facades\Log;
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
            'scope' => ['nullable', 'string', 'max:300'],
            'context_stats' => ['nullable', 'array'],
            'media' => ['nullable', 'array', 'max:20'],
            'media.*.id' => ['required', 'integer', 'min:1'],
            'media.*.url' => ['required', 'url:http,https', 'max:500'],
            'media.*.title' => ['nullable', 'string', 'max:200'],
        ]);
        $media = array_map(
            fn (array $image) => array_intersect_key($image, array_flip(['id', 'url', 'title'])),
            array_values($data['media'] ?? []),
        );
        $scope = $data['scope'] ?? null;
        $context = (string) json_encode([$data['sections'], $data['elements']]);
        if (strlen($context) > config('gama.ai.max_context_bytes')) {
            return response()->json(['message' => 'This page is too large for one AI edit.'], 413);
        }

        /** @var Site $site */
        $site = $request->attributes->get('site');
        $estimate = $this->planner->estimateTokens($data['instruction'], $data['sections'], $data['elements'], $scope, $media);
        // What was sent and why it costs what it does, kept with the ledger row and in the log.
        $details = [
            'page_id' => $data['page_id'],
            'context_bytes' => strlen($context),
            'page_bytes' => strlen((string) json_encode($data['sections'])),
            'settings_bytes' => strlen((string) json_encode($data['elements'])),
            'scope' => $scope !== null,
            'media_count' => count($media),
            'estimated_tokens' => $estimate,
            'max_output_tokens' => $this->planner->maxOutputTokens(),
        ] + array_intersect_key($data['context_stats'] ?? [], array_flip(['settings_count', 'element_types', 'focus']));

        try {
            $requestId = $this->meter->reserve($site->account, $site, $estimate, 'small_edit', $details);
        } catch (BudgetExceeded $e) {
            Log::info('gama.edit_request', $details + ['site_id' => $site->id, 'status' => 'budget_exceeded']);

            return response()->json(['message' => $e->getMessage(), 'usage' => $this->meter->summary($site->account)->toArray()], 402);
        }

        $started = hrtime(true);
        try {
            $plan = $this->planner->plan($data['instruction'], $data['sections'], $data['elements'], $scope, $media);
        } catch (EditPlannerException $e) {
            $outcome = [
                'status' => $e->rateLimited ? 'rate_limited' : 'failed',
                'attempts' => $e->attempts,
                'retry_after' => $e->retryAfter,
                'duration_ms' => $this->since($started),
            ];
            if ($e->plan !== null) {
                $this->settle($requestId, $e->plan, $outcome);
            } else {
                // Nothing was charged: the provider refused the call or never got it.
                $this->meter->release($requestId, $outcome);
            }
            Log::info('gama.edit_request', $details + $outcome + [
                'site_id' => $site->id,
                'request_id' => $requestId,
                'input_tokens' => $e->plan?->inputTokens,
                'output_tokens' => $e->plan?->outputTokens,
            ]);

            if ($e->rateLimited) {
                $retryAfter = $e->retryAfter ?? 60;

                return response()->json(['message' => $e->getMessage(), 'retry_after' => $retryAfter], 429)
                    ->header('Retry-After', (string) $retryAfter);
            }

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            $this->meter->release($requestId, ['status' => 'error', 'duration_ms' => $this->since($started)]);
            throw $e;
        }

        $outcome = ['status' => 'ok', 'attempts' => $plan->attempts, 'duration_ms' => $this->since($started)];
        $entry = $this->settle($requestId, $plan, $outcome);
        Log::info('gama.edit_request', $details + $outcome + [
            'site_id' => $site->id,
            'request_id' => $requestId,
            'model' => $plan->model,
            'input_tokens' => $plan->inputTokens,
            'output_tokens' => $plan->outputTokens,
            'cache_read_tokens' => $plan->cacheReadTokens,
            'operations' => count($plan->operations),
        ]);

        return response()->json([
            'request_id' => $requestId,
            'summary' => $plan->summary,
            'operations' => $plan->operations,
            'usage' => [
                'model' => $plan->model,
                'tokens' => $entry->tokens,
                'input_tokens' => $plan->inputTokens + $plan->cacheReadTokens + $plan->cacheWriteTokens,
                'output_tokens' => $plan->outputTokens,
                'estimated_tokens' => $estimate,
                'max_output_tokens' => $details['max_output_tokens'],
                'estimated_cost_micros' => $entry->estimated_cost_micros,
                'account' => $this->meter->summary($site->account)->toArray(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function settle(string $requestId, EditPlan $plan, array $meta = []): UsageEntry
    {
        return $this->meter->settle(
            $requestId,
            $plan->model,
            $plan->inputTokens,
            $plan->outputTokens,
            cacheWriteTokens: $plan->cacheWriteTokens,
            cacheReadTokens: $plan->cacheReadTokens,
            meta: $meta,
        );
    }

    private function since(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}
