<?php
/**
 * Tests for requests handled without the AI.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\ShortcodeParser;
use GamaAI\Edits\EditCompiler;
use GamaAI\Edits\QuickEdits;
use GamaAI\Tests\Support\TestRegistry;
use PHPUnit\Framework\TestCase;

class QuickEditsTest extends TestCase {

	const ONE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title]Welcome[/fusion_title][fusion_text]Hi[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	const THREE = '[fusion_builder_container id="hero"][fusion_builder_row][fusion_builder_column][fusion_title]Welcome[/fusion_title][fusion_button link="https://example.com"]Go[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]'
		. '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_title]About us[/fusion_title][fusion_button]More[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]'
		. '[fusion_builder_container status="draft"][fusion_builder_row][fusion_builder_column][fusion_text]Old[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private function handle( $instruction, $content = self::THREE, $selected = null ) {
		return ( new QuickEdits( TestRegistry::make() ) )->handle( $instruction, ( new ShortcodeParser() )->parse( $content ), $selected );
	}

	private function apply( array $result, $content = self::THREE ) {
		return ( new EditCompiler( TestRegistry::make() ) )->apply( $content, $result['operations'] );
	}

	public function test_the_reported_request_needs_no_ai_on_a_one_container_page() {
		$result = $this->handle( 'delete the container entirely', self::ONE );

		$this->assertSame( [ [ 'op' => 'remove_element', 'target' => '0' ] ], $result['operations'] );
		$this->assertSame( '', $this->apply( $result, self::ONE ) );
	}

	public function test_asks_which_one_instead_of_guessing() {
		$result = $this->handle( 'Delete the container.' );

		$this->assertSame( [], $result['operations'] );
		$this->assertStringContainsString( 'This page has 3 containers', $result['summary'] );
	}

	public function test_ordinals_numbers_last_and_quoted_text_pick_one_element() {
		$cases = [
			'delete the second container'         => '1',
			'remove section 3'                    => '2',
			'delete the last container please'    => '2',
			'remove the 1st section'              => '0',
			'delete the heading "about us"'       => '1.0.0.0',
			'remove the button that says "More"'  => '1.0.0.1',
			'delete the last button'              => '1.0.0.1',
		];
		foreach ( $cases as $instruction => $id ) {
			$result = $this->handle( $instruction );
			$this->assertSame( $id, $result['operations'][0]['target'], $instruction );
		}
	}

	public function test_this_means_the_picked_section() {
		$this->assertSame( '1', $this->handle( 'delete this', self::THREE, '1' )['operations'][0]['target'] );
		$this->assertSame( '2', $this->handle( 'remove the container', self::THREE, '2' )['operations'][0]['target'] );
		$this->assertSame( [], $this->handle( 'delete this' )['operations'] );
	}

	public function test_roman_urdu_word_order() {
		$this->assertSame( '1', $this->handle( 'second container ko delete karo' )['operations'][0]['target'] );
		$result = $this->handle( 'is section ko mobile par hide karo', self::THREE, '0' );
		$this->assertSame( 'medium-visibility,large-visibility', $result['operations'][0]['value'] );
	}

	public function test_missing_targets_change_nothing() {
		$this->assertStringContainsString( 'only 3 containers', $this->handle( 'delete the fifth container' )['summary'] );
		$this->assertStringContainsString( 'no button like that', $this->handle( 'delete the button "Buy"' )['summary'] );
	}

	public function test_anything_more_goes_to_the_ai() {
		foreach ( [
			'delete the container and add a new heading',
			'make the second container blue',
			'show me a nicer layout',
			'copy the text from the heading into the button',
			'remove everything that looks old',
		] as $instruction ) {
			$this->assertNull( $this->handle( $instruction ), $instruction );
		}
	}

	public function test_duplicate_copies_right_after_and_clears_html_ids() {
		$result = $this->handle( 'duplicate the first container' );
		$out    = $this->apply( $result );

		$this->assertSame( 4, substr_count( $out, '[fusion_builder_container' ) );
		$this->assertSame( 1, substr_count( $out, 'id="hero"' ) );
		$this->assertSame( 2, substr_count( $out, 'Welcome' ) );
		$this->assertLessThan( strpos( $out, 'About us' ), strrpos( $out, 'Welcome' ) );
	}

	public function test_hide_a_container_uses_its_native_draft_status() {
		$result = $this->handle( 'hide the second section' );
		$this->assertSame( [ 'op' => 'set_attribute', 'target' => '1', 'name' => 'status', 'value' => 'draft' ], $result['operations'][0] );

		$this->assertSame( 'published', $this->handle( 'show the third section' )['operations'][0]['value'] );
		$this->assertStringContainsString( 'already hidden', $this->handle( 'hide the third container' )['summary'] );
	}

	public function test_hide_per_screen_size_edits_the_visibility_setting() {
		$result = $this->handle( 'hide the second button on mobile' );
		$this->assertSame( [ 'op' => 'set_attribute', 'target' => '1.0.0.1', 'name' => 'hide_on_mobile', 'value' => 'medium-visibility,large-visibility' ], $result['operations'][0] );
		$this->assertStringContainsString( 'hide_on_mobile="medium-visibility,large-visibility"', $this->apply( $result ) );

		$hidden = '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_title hide_on_mobile="large-visibility"]X[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$this->assertSame( 'small-visibility,large-visibility', $this->handle( 'show the heading on phones', $hidden )['operations'][0]['value'] );
		$this->assertStringContainsString( 'every screen size', $this->handle( 'hide the heading on desktop', $hidden )['summary'] );
	}

	public function test_hiding_an_element_everywhere_is_not_guessed() {
		$result = $this->handle( 'hide the first heading' );
		$this->assertSame( [], $result['operations'] );
		$this->assertStringContainsString( 'only per screen size', $result['summary'] );
	}

	public function test_add_a_section_builds_container_row_and_columns_without_ai() {
		$result = $this->handle( 'add a new section with 2 columns below the first section' );
		$out    = $this->apply( $result );

		$this->assertSame( 1, $result['operations'][0]['index'] );
		$this->assertStringContainsString(
			'[/fusion_builder_container][fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_2"][/fusion_builder_column][fusion_builder_column type="1_2"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container][fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_title]About us',
			$out
		);
		$this->assertStringContainsString( 'below the 1st container', $result['summary'] );
	}

	public function test_add_a_section_on_an_empty_page_and_at_the_top() {
		$empty = '[fusion_builder_container][/fusion_builder_container]';
		$out   = $this->apply( $this->handle( 'create a simple container', $empty ), $empty );
		$this->assertSame( $empty . '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', $out );

		$this->assertSame( 0, $this->handle( 'add a section at the top' )['operations'][0]['index'] );
		$this->assertSame( 2, $this->handle( 'add a container above this', self::THREE, '2' )['operations'][0]['index'] );
		$this->assertStringContainsString( 'Say which one', $this->handle( 'add a section below the container' )['summary'] );
	}

	public function test_adding_with_content_goes_to_the_ai() {
		$this->assertNull( $this->handle( 'add a section with a heading and a button' ) );
		$this->assertNull( $this->handle( 'i just deleted that container. create a new one with 2 sub containers' ) );
	}
}
