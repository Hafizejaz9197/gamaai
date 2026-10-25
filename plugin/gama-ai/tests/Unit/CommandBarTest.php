<?php
/**
 * Tests for the section picker's container list.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Frontend\CommandBar;
use PHPUnit\Framework\TestCase;

class CommandBarTest extends TestCase {

	public function test_lists_rendered_containers_and_skips_draft_ones() {
		$this->assertSame( [ '0', '1' ], CommandBar::rendered_containers( QuickEditsTest::THREE ) );
		$this->assertSame( [ '0', '2' ], CommandBar::rendered_containers( '[fusion_builder_container][/fusion_builder_container][fusion_builder_container status="draft"][/fusion_builder_container][fusion_builder_container status="published"][/fusion_builder_container]' ) );
	}

	public function test_gives_up_when_screen_order_cannot_be_trusted() {
		$this->assertNull( CommandBar::rendered_containers( '[fusion_builder_container status="publish_after"][/fusion_builder_container]' ) );
		$this->assertNull( CommandBar::rendered_containers( '[fusion_text]Loose[/fusion_text][fusion_builder_container][/fusion_builder_container]' ) );
	}
}
