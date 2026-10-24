<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Services\Ai\AnthropicEditPlanner;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnthropicEditPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_turns_attribute_pairs_into_an_object(): void
    {
        [$summary, $operations] = AnthropicEditPlanner::parse(json_encode([
            'summary' => 'Added a note.',
            'operations' => [
                ['op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Hi'],
                ['op' => 'insert_element', 'parent' => '0.0.0', 'type' => 'fusion_title', 'attrs' => [['name' => 'size', 'value' => '3']], 'text' => 'Note'],
            ],
        ]));

        $this->assertSame('Added a note.', $summary);
        $this->assertSame('Hi', $operations[0]['text']);
        $this->assertEquals((object) ['size' => '3'], $operations[1]['attrs']);
        $this->assertSame('{"size":"3"}', json_encode($operations[1]['attrs']));
    }

    public function test_parse_keeps_empty_attrs_as_an_object(): void
    {
        [, $operations] = AnthropicEditPlanner::parse('{"summary":"","operations":[{"op":"insert_element","parent":"","type":"fusion_builder_container"}]}');

        $this->assertSame('{}', json_encode($operations[0]['attrs']));
    }

    public function test_parse_rejects_bad_answers(): void
    {
        foreach (['not json', '{"summary":"x"}', '{"summary":"x","operations":[{"op":"delete_site"}]}'] as $text) {
            try {
                AnthropicEditPlanner::parse($text);
                $this->fail("Expected {$text} to be rejected.");
            } catch (EditPlannerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_schema_is_strict(): void
    {
        $schema = AnthropicEditPlanner::schema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertCount(5, $schema['properties']['operations']['items']['anyOf']);
        foreach ($schema['properties']['operations']['items']['anyOf'] as $variant) {
            $this->assertFalse($variant['additionalProperties']);
            $this->assertContains('op', $variant['required']);
        }
    }

    public function test_estimate_covers_the_answer(): void
    {
        $planner = new AnthropicEditPlanner(new Client(apiKey: 'test'), 'claude-opus-5-5', 'medium', 16_000);

        $this->assertGreaterThan(16_000, $planner->estimateTokens('Change the title', [['id' => '0']], []));
    }

    public function test_container_builds_the_claude_planner(): void
    {
        config(['services.anthropic.key' => 'test']);

        $this->assertInstanceOf(AnthropicEditPlanner::class, $this->app->make(EditPlanner::class));
    }
}
