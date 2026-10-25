<?php
/**
 * Tests for what AI requests send.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\ElementSettings;
use GamaAI\Avada\ShortcodeParser;
use GamaAI\Edits\AiContext;
use GamaAI\Tests\Support\TestRegistry;
use PHPUnit\Framework\TestCase;

class AiContextTest extends TestCase {

	private function build( $instruction, $selected = null, $content = QuickEditsTest::THREE ) {
		return ( new AiContext( TestRegistry::make() ) )->build( $instruction, ( new ShortcodeParser() )->parse( $content ), $selected );
	}

	public function test_a_picked_section_sends_only_that_container_with_page_wide_ids() {
		$context = $this->build( 'Make the heading say Hello', '1' );

		$this->assertCount( 1, $context['sections'] );
		$this->assertSame( '1', $context['sections'][0]['id'] );
		$this->assertSame( '1.0.0.0', $context['sections'][0]['children'][0]['children'][0]['children'][0]['id'] );
		$this->assertStringContainsString( 'Only the 2nd container of 3 is shown', $context['scope'] );
		$this->assertSame( '1', $context['stats']['scope'] );
	}

	public function test_a_named_element_narrows_to_its_container() {
		$context = $this->build( 'change the background of the third container to blue' );

		$this->assertSame( '2', $context['sections'][0]['id'] );
		$this->assertSame( '2', $context['stats']['focus'] );
	}

	public function test_without_a_target_the_whole_page_is_sent() {
		$context = $this->build( 'make all headings shorter' );

		$this->assertCount( 3, $context['sections'] );
		$this->assertNull( $context['scope'] );
	}

	public function test_settings_are_only_those_the_request_names() {
		$elements = $this->build( 'Change the background colour of the second container', '1' )['elements'];

		$this->assertSame( [ 'background_color' => 'colorpickeralpha', 'link_color' => 'colorpickeralpha' ], $elements['fusion_builder_container'] );
		$this->assertSame( [ 'element_content' => 'textarea', 'text_color' => 'colorpickeralpha' ], $elements['fusion_title'] );
		$this->assertArrayNotHasKey( 'fusion_text', $elements, 'Only types in the picked section, and nothing to insert.' );
	}

	public function test_a_text_change_sends_no_settings_at_all() {
		$elements = $this->build( 'Change the heading to "Fresh flowers"', '0' )['elements'];

		$this->assertSame( [ 'element_content' => 'textarea' ], $elements['fusion_title'] );
		$this->assertSame( [], $elements['fusion_builder_container'] );
	}

	public function test_adding_something_includes_the_insertable_types() {
		$elements = $this->build( 'Add a button under the heading', '1' )['elements'];

		$this->assertArrayHasKey( 'fusion_text', $elements );
		$this->assertArrayHasKey( 'fusion_title', $elements );
	}

	public function test_synonyms_find_native_settings() {
		$keys = function ( $instruction ) {
			$elements = ElementSettings::for_request( TestRegistry::make(), ( new ShortcodeParser() )->parse( QuickEditsTest::ONE )->shortcode_children(), $instruction );
			return array_merge( array_keys( $elements['fusion_builder_container'] ), array_keys( $elements['fusion_title'] ) );
		};
		$this->assertContains( 'border_radius', $keys( 'give it rounded corners' ) );
		$this->assertContains( 'content_align', $keys( 'center the heading' ) );
		$this->assertContains( 'size', $keys( 'make the heading an H2' ) );
		$this->assertContains( 'padding_dimensions', $keys( 'more spacing please' ) );
		$this->assertNotContains( 'overflow', $keys( 'give it rounded corners' ) );
	}

	public function test_the_narrowed_context_is_much_smaller() {
		$root    = ( new ShortcodeParser() )->parse( QuickEditsTest::THREE );
		$builder = new AiContext( TestRegistry::make() );
		$before  = $builder->full_size( $root );
		$after   = $builder->build( 'Change the heading to Hello', $root, '1' )['stats'];

		$this->assertLessThan( ( $before['page_bytes'] + $before['settings_bytes'] ) / 3, $after['page_bytes'] + $after['settings_bytes'] );
	}
}
