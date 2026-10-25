<?php

namespace Tests\Feature;

use App\Services\Ai\AnthropicEditPlanner;
use Tests\TestCase;

class PageTextTest extends TestCase
{
    public function test_media_is_only_sent_when_there_is_some(): void
    {
        $without = json_decode(AnthropicEditPlanner::pageText([], [], null), true);
        $with = json_decode(AnthropicEditPlanner::pageText([], [], null, [['id' => 7, 'url' => 'https://example.com/a.jpg']]), true);

        $this->assertArrayNotHasKey('media', $without);
        $this->assertSame('https://example.com/a.jpg', $with['media'][0]['url']);
        $this->assertStringContainsString('"ID|full"', AnthropicEditPlanner::SYSTEM_PROMPT);
    }
}
