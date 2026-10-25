<?php
/**
 * Tests for marking rendered elements with their ids.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Frontend\ElementMarks;
use PHPUnit\Framework\TestCase;

class ElementMarksTest extends TestCase {

	const PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_2"][fusion_title]Hi[/fusion_title][fusion_text]Body[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]'
		. '[fusion_builder_container][/fusion_builder_container]';

	/**
	 * Render a tree of [tag, children] the way do_shortcode does: parent opens, children render, parent closes.
	 */
	private function render( ElementMarks $marks, array $tree ) {
		$html = '';
		foreach ( $tree as $item ) {
			list( $tag, $children ) = $item;
			$marks->open( $tag );
			$inner = $this->render( $marks, $children );
			$html .= $marks->close( '<style>.x{}</style><div class="' . $tag . '">' . $inner . '</div>', $tag );
		}
		return $html;
	}

	public function test_each_element_gets_its_page_wide_id() {
		$marks = new ElementMarks();
		$marks->begin( self::PAGE );
		$html = $this->render(
			$marks,
			[
				[ 'fusion_builder_container', [ [ 'fusion_builder_row', [ [ 'fusion_builder_column', [ [ 'fusion_title', [] ], [ 'gallery', [] ], [ 'fusion_text', [] ] ] ] ] ] ] ],
				[ 'fusion_builder_container', [] ],
			]
		);

		$this->assertStringContainsString( '<style>.x{}</style><div data-gama-id="0" data-gama-type="fusion_builder_container" class="fusion_builder_container">', $html );
		$this->assertStringContainsString( '<div data-gama-id="0.0.0.0" data-gama-type="fusion_title"', $html );
		$this->assertStringContainsString( '<div data-gama-id="0.0.0.1" data-gama-type="fusion_text"', $html );
		$this->assertStringContainsString( '<div class="gallery">', $html, 'Other shortcodes are left alone and do not shift ids.' );
		$this->assertStringContainsString( '<div data-gama-id="1" data-gama-type="fusion_builder_container"', $html );
	}

	public function test_marking_stops_at_the_first_difference_from_the_layout() {
		$marks = new ElementMarks();
		$marks->begin( self::PAGE );
		$html = $this->render(
			$marks,
			[
				[ 'fusion_builder_container', [ [ 'fusion_builder_row', [ [ 'fusion_builder_column', [ [ 'fusion_button', [] ], [ 'fusion_text', [] ] ] ] ] ] ] ],
				[ 'fusion_builder_container', [] ],
			]
		);

		$this->assertStringContainsString( 'data-gama-id="0.0.0"', $html );
		$this->assertStringNotContainsString( 'fusion_button"', str_replace( 'class="fusion_button"', '', $html ) );
		$this->assertStringNotContainsString( 'data-gama-id="0.0.0.1"', $html );
		$this->assertStringNotContainsString( 'data-gama-id="1"', $html );
		$this->assertFalse( $marks->active() );
	}

	public function test_nothing_is_marked_outside_a_begun_render() {
		$marks = new ElementMarks();
		$marks->open( 'fusion_title' );
		$this->assertSame( '<h2>x</h2>', $marks->close( '<h2>x</h2>', 'fusion_title' ) );
	}

	public function test_empty_elements_get_a_marked_box_for_editors() {
		$marks = new ElementMarks();
		$marks->begin( '[fusion_builder_container][fusion_imageframe][/fusion_imageframe][/fusion_builder_container]' );
		$marks->open( 'fusion_builder_container' );
		$marks->open( 'fusion_imageframe' );
		$this->assertSame( '<div class="gama-ai-empty" data-gama-id="0.0" data-gama-type="fusion_imageframe">Empty image</div>', $marks->close( '', 'fusion_imageframe' ) );
	}
}
