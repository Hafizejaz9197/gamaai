<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Asks any API that speaks OpenAI's chat completions format for edit
 * operations: Google Gemini, Groq, OpenRouter, OpenAI and others, many with
 * free tiers for testing. It uses the same instructions and answer format
 * as the Claude planner; the plugin checks every operation either way.
 */
class OpenAiCompatibleEditPlanner implements EditPlanner
{
    public const TIMEOUT = 120;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function plan(string $instruction, array $sections, array $elements, ?string $scope = null): EditPlan
    {
        $user = ['role' => 'user', 'content' => AnthropicEditPlanner::pageText($sections, $elements, $scope)
            ."\n\nSite owner's request:\n".$instruction];

        // Providers that take a JSON schema get it once, as the answer format.
        // Plain JSON mode needs the schema spelled out in the instructions.
        $formats = [
            [['type' => 'json_schema', 'json_schema' => ['name' => 'edit_plan', 'schema' => AnthropicEditPlanner::schema()]], AnthropicEditPlanner::SYSTEM_PROMPT],
            [['type' => 'json_object'], AnthropicEditPlanner::SYSTEM_PROMPT
                ."\n\nAnswer with one JSON object only, matching this JSON schema:\n"
                .json_encode(AnthropicEditPlanner::schema(), JSON_UNESCAPED_SLASHES)],
        ];
        $attempts = 0;
        foreach ($formats as $i => [$format, $system]) {
            $response = $this->send([['role' => 'system', 'content' => $system], $user], $format, $attempts);
            if ($response->status() !== 400 || $i === count($formats) - 1) {
                break;
            }
        }

        if ($response->status() === 429) {
            $retryAfter = $this->retryAfter($response);
            throw new EditPlannerException(
                $this->errorMessage($response, $retryAfter),
                rateLimited: true,
                retryAfter: $retryAfter,
                attempts: $attempts,
            );
        }
        if ($response->failed()) {
            throw new EditPlannerException($this->errorMessage($response), attempts: $attempts);
        }

        $data = $response->json();
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $plan = fn (string $summary, array $operations) => new EditPlan(
            summary: $summary,
            operations: $operations,
            model: is_string($data['model'] ?? null) ? $data['model'] : $this->model,
            inputTokens: max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            cacheReadTokens: $cached,
            attempts: $attempts,
        );

        $choice = $data['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new EditPlannerException('The AI answer was cut off. Try a smaller request.', $plan('', []), attempts: $attempts);
        }

        $text = is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
        // Some models wrap JSON in a Markdown code fence despite JSON mode.
        $text = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $text);

        try {
            [$summary, $operations] = AnthropicEditPlanner::parse((string) $text);
        } catch (EditPlannerException $e) {
            throw new EditPlannerException($e->getMessage(), $plan('', []), attempts: $attempts);
        }

        return $plan($summary, $operations);
    }

    public function estimateTokens(string $instruction, array $sections, array $elements, ?string $scope = null): int
    {
        // The JSON-mode fallback repeats the schema in the instructions, so count it.
        $input = strlen(AnthropicEditPlanner::SYSTEM_PROMPT) + 2 * strlen((string) json_encode(AnthropicEditPlanner::schema()))
            + strlen($instruction) + strlen(AnthropicEditPlanner::pageText($sections, $elements, $scope));

        return intdiv($input, 3) + 1 + $this->maxTokens;
    }

    public function maxOutputTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Send one chat completion. A rate limit with a short wait is retried
     * once after that wait. This is safe: the provider rejected the call,
     * nothing was generated, and asking for a plan changes nothing anywhere;
     * the plugin writes the draft only after a successful answer. Longer
     * waits go back to the site owner, who decides whether to try again.
     *
     * @param  list<array<string, string>>  $messages
     * @param  array<string, mixed>  $format
     *
     * @throws EditPlannerException
     */
    private function send(array $messages, array $format, int &$attempts): Response
    {
        $maxWait = (int) config('gama.ai.rate_limit_retry_max_seconds', 10);
        while (true) {
            $attempts++;
            try {
                $response = Http::withToken($this->apiKey)
                    ->acceptJson()
                    ->timeout(self::TIMEOUT)
                    ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                        'model' => $this->model,
                        'messages' => $messages,
                        'max_tokens' => $this->maxTokens,
                        'response_format' => $format,
                    ]);
            } catch (ConnectionException) {
                // Not retried: the provider may have received the call and be charging for it.
                throw new EditPlannerException('The AI service could not be reached.', attempts: $attempts);
            }

            $wait = $response->status() === 429 ? $this->retryAfter($response) : null;
            Log::info('gama.ai.call', [
                'provider' => 'openai_compatible',
                'model' => $this->model,
                'status' => $response->status(),
                'attempt' => $attempts,
                'max_tokens' => $this->maxTokens,
                'response_format' => $format['type'],
                'request_bytes' => strlen((string) json_encode($messages)),
                'usage' => $response->successful() ? $response->json('usage') : null,
                'retry_after' => $wait,
                'rate_limit' => array_filter([
                    'limit_tokens' => $response->header('x-ratelimit-limit-tokens'),
                    'remaining_tokens' => $response->header('x-ratelimit-remaining-tokens'),
                    'reset_tokens' => $response->header('x-ratelimit-reset-tokens'),
                ]),
            ]);

            if ($wait === null || $wait > $maxWait || $attempts >= 2) {
                return $response;
            }
            Sleep::for($wait)->seconds();
        }
    }

    private function retryAfter(Response $response): ?int
    {
        $body = $response->json();
        $message = $body[0]['error']['message'] ?? $body['error']['message'] ?? null;

        return RetryAfter::seconds(
            $response->header('Retry-After'),
            $response->header('x-ratelimit-reset-tokens'),
            is_string($message) ? $message : null,
        );
    }

    private function errorMessage(Response $response, ?int $retryAfter = null): string
    {
        $body = $response->json();
        $detail = $body[0]['error']['message'] ?? $body['error']['message'] ?? null;
        $detail = is_string($detail) ? ': '.mb_substr($detail, 0, 200) : '';

        return match (true) {
            // The provider's own text names account ids and limits; it is in the log, not shown to the site owner.
            $response->status() === 429 => 'The AI service is busy or its free limit is used up for now. '
                .($retryAfter ? "Try again in {$retryAfter} seconds." : 'Try again in a minute.'),
            in_array($response->status(), [401, 403], true) => 'The AI service did not accept the API key'.$detail.'.',
            default => 'The AI service returned an error ('.$response->status().$detail.').',
        };
    }
}
