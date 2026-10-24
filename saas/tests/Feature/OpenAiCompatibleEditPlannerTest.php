<?php

namespace Tests\Feature;

use App\Services\Ai\EditPlannerException;
use App\Services\Ai\OpenAiCompatibleEditPlanner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleEditPlannerTest extends TestCase
{
    private const URL = 'https://ai.example.com/v1/chat/completions';

    private function planner(): OpenAiCompatibleEditPlanner
    {
        return new OpenAiCompatibleEditPlanner('https://ai.example.com/v1/', 'free-key', 'test-model', 4000);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $content, string $finish = 'stop'): array
    {
        return [
            'model' => 'test-model-001',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80, 'prompt_tokens_details' => ['cached_tokens' => 200]],
        ];
    }

    public function test_sends_the_page_and_reads_the_operations(): void
    {
        Http::fake([self::URL => Http::response($this->answer(json_encode([
            'summary' => 'Renamed the heading.',
            'operations' => [['op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Hello']],
        ])))]);

        $plan = $this->planner()->plan('Rename it', [['id' => '0']], ['fusion_title' => ['size' => ['1']]]);

        $this->assertSame('Renamed the heading.', $plan->summary);
        $this->assertSame('replace_text', $plan->operations[0]['op']);
        $this->assertSame('test-model-001', $plan->model);
        $this->assertSame(1000, $plan->inputTokens);
        $this->assertSame(200, $plan->cacheReadTokens);
        $this->assertSame(80, $plan->outputTokens);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer free-key')
            && $r['model'] === 'test-model'
            && $r['response_format']['type'] === 'json_schema'
            && str_contains($r['messages'][1]['content'], 'Rename it'));
    }

    public function test_strips_code_fences(): void
    {
        Http::fake([self::URL => Http::response($this->answer("```json\n{\"summary\":\"Nothing to do.\",\"operations\":[]}\n```"))]);

        $this->assertSame('Nothing to do.', $this->planner()->plan('x', [], [])->summary);
    }

    public function test_falls_back_to_json_mode_when_schemas_are_not_supported(): void
    {
        Http::fakeSequence(self::URL)
            ->push(['error' => ['message' => 'response_format json_schema is not supported']], 400)
            ->push($this->answer('{"summary":"ok","operations":[]}'));

        $this->assertSame('ok', $this->planner()->plan('x', [], [])->summary);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['response_format']['type'] === 'json_object');
    }

    public function test_errors_explain_what_went_wrong(): void
    {
        $cases = [[401, 'did not accept the API key'], [429, 'free limit is used up'], [500, 'returned an error (500']];
        $sequence = Http::fakeSequence(self::URL);
        foreach ($cases as [$status]) {
            $sequence->push(['error' => ['message' => 'nope']], $status);
        }
        foreach ($cases as [, $message]) {
            try {
                $this->planner()->plan('x', [], []);
                $this->fail('Expected an error.');
            } catch (EditPlannerException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertNull($e->plan);
            }
        }
    }

    public function test_charged_failures_carry_the_usage(): void
    {
        Http::fakeSequence(self::URL)
            ->push($this->answer('{"summary":', 'length'))
            ->push($this->answer('not json'));
        try {
            $this->planner()->plan('x', [], []);
            $this->fail('Expected an error.');
        } catch (EditPlannerException $e) {
            $this->assertStringContainsString('cut off', $e->getMessage());
            $this->assertSame(80, $e->plan->outputTokens);
        }

        try {
            $this->planner()->plan('x', [], []);
            $this->fail('Expected an error.');
        } catch (EditPlannerException $e) {
            $this->assertSame(1000, $e->plan->inputTokens);
        }
    }
}
