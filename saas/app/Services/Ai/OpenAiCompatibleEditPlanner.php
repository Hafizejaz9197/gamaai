<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

    public function plan(string $instruction, array $sections, array $elements): EditPlan
    {
        $messages = [
            ['role' => 'system', 'content' => AnthropicEditPlanner::SYSTEM_PROMPT
                ."\n\nAnswer with one JSON object only, matching this JSON schema:\n"
                .json_encode(AnthropicEditPlanner::schema(), JSON_UNESCAPED_SLASHES)],
            ['role' => 'user', 'content' => json_encode(['page' => $sections, 'elements' => (object) $elements], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ."\n\nSite owner's request:\n".$instruction],
        ];

        // Not every provider takes a JSON schema; fall back to plain JSON mode.
        $formats = [
            ['type' => 'json_schema', 'json_schema' => ['name' => 'edit_plan', 'schema' => AnthropicEditPlanner::schema()]],
            ['type' => 'json_object'],
        ];
        foreach ($formats as $i => $format) {
            $response = $this->send($messages, $format);
            if ($response->status() !== 400 || $i === count($formats) - 1) {
                break;
            }
        }

        if ($response->failed()) {
            throw new EditPlannerException($this->errorMessage($response));
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
        );

        $choice = $data['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new EditPlannerException('The AI answer was cut off. Try a smaller request.', $plan('', []));
        }

        $text = is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
        // Some models wrap JSON in a Markdown code fence despite JSON mode.
        $text = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $text);

        try {
            [$summary, $operations] = AnthropicEditPlanner::parse((string) $text);
        } catch (EditPlannerException $e) {
            throw new EditPlannerException($e->getMessage(), $plan('', []));
        }

        return $plan($summary, $operations);
    }

    public function estimateTokens(string $instruction, array $sections, array $elements): int
    {
        $input = strlen(AnthropicEditPlanner::SYSTEM_PROMPT) * 2 + strlen($instruction)
            + strlen((string) json_encode(['page' => $sections, 'elements' => $elements]));

        return intdiv($input, 3) + 1 + $this->maxTokens;
    }

    /**
     * @param  list<array<string, string>>  $messages
     * @param  array<string, mixed>  $format
     *
     * @throws EditPlannerException
     */
    private function send(array $messages, array $format): Response
    {
        try {
            return Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => $this->maxTokens,
                    'response_format' => $format,
                ]);
        } catch (ConnectionException) {
            throw new EditPlannerException('The AI service could not be reached.');
        }
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();
        $detail = $body[0]['error']['message'] ?? $body['error']['message'] ?? null;
        $detail = is_string($detail) ? ': '.mb_substr($detail, 0, 200) : '';

        return match (true) {
            $response->status() === 429 => 'The AI service is busy or the free limit is used up. Try again later'.$detail.'.',
            in_array($response->status(), [401, 403], true) => 'The AI service did not accept the API key'.$detail.'.',
            default => 'The AI service returned an error ('.$response->status().$detail.').',
        };
    }
}
