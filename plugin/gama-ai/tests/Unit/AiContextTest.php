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

		// background_color names both words, so link_color and text_color (one word) are left out,
		// and so is the tablet variant, because the request names no screen size.
		$this->assertSame( [ 'background_color' => 'colorpickeralpha' ], $elements['fusion_builder_container'] );
		$this->assertSame( [ 'element_content' => 'textarea' ], $elements['fusion_title'] );

		$tablet = $this->build( 'Change the background colour on tablet', '1' )['elements'];
		$this->assertArrayHasKey( 'background_color_medium', $tablet['fusion_builder_container'] );
		$this->assertArrayHasKey( 'text_color', $this->build( 'Make the heading color red', '1' )['elements']['fusion_title'] );
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

	public function test_building_a_new_section_sends_the_layout_types_and_column_widths() {
		$elements = $this->build( 'create a new section with 2 sub containers and a heading', null, '[fusion_builder_container][/fusion_builder_container]' )['elements'];

		foreach ( [ 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_title', 'fusion_text', 'fusion_button' ] as $type ) {
			$this->assertArrayHasKey( $type, $elements );
		}
		$this->assertSame( 'column_width', $elements['fusion_builder_column']['type'] );
	}

	public function test_a_named_element_type_is_allowed_with_its_media_library_settings() {
		$elements = $this->build( 'put an image in each column', null, QuickEditsTest::THREE )['elements'];

		$this->assertArrayHasKey( 'fusion_imageframe', $elements );
		$this->assertArrayHasKey( 'element_content', $elements['fusion_imageframe'] );
		$this->assertArrayHasKey( 'image_id', $elements['fusion_imageframe'] );
		$this->assertArrayHasKey( 'alt', $elements['fusion_imageframe'] );
		$this->assertArrayNotHasKey( 'max_width', $elements['fusion_imageframe'] );
	}

	public function test_image_requests_get_recent_media_library_images() {
		$asked   = null;
		$media   = function ( $limit ) use ( &$asked ) {
			$asked = $limit;
			return [
				[ 'id' => 7, 'url' => 'https://example.com/a.jpg', 'title' => 'Team' ],
				[ 'id' => 0, 'url' => 'https://example.com/broken.jpg' ],
			];
		};
		$root    = ( new ShortcodeParser() )->parse( QuickEditsTest::THREE );
		$context = ( new AiContext( TestRegistry::make(), $media ) )->build( 'add a photo from media to the first container', $root );

		$this->assertSame( AiContext::MEDIA_LIMIT, $asked );
		$this->assertSame( [ [ 'id' => 7, 'url' => 'https://example.com/a.jpg', 'title' => 'Team' ] ], $context['media'] );
		$this->assertSame( 1, $context['stats']['media_count'] );
	}

	public function test_other_requests_do_not_load_the_media_library() {
		$media = function () {
			throw new \RuntimeException( 'not needed' );
		};
		$root  = ( new ShortcodeParser() )->parse( QuickEditsTest::THREE );

		$this->assertSame( [], ( new AiContext( TestRegistry::make(), $media ) )->build( 'make the heading blue', $root )['media'] );
	}

	public function test_a_pinned_element_is_named_for_the_ai() {
		$content = '[fusion_builder_container][/fusion_builder_container][fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_imageframe][/fusion_imageframe][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$scope   = $this->build( 'add any image from media here', '1.0.0.0', $content )['scope'];

		$this->assertStringContainsString( 'pinned element 1.0.0.0 (fusion_imageframe, currently empty)', $scope );
		$this->assertLessThan( 300, strlen( $scope ) );
	}
}
