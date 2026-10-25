<?php
/**
 * Tests for turning edit operations into Avada markup.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\Registry;
use GamaAI\Edits\EditCompiler;
use GamaAI\Edits\EditException;
use PHPUnit\Framework\TestCase;

class EditCompilerTest extends TestCase {

	const PAGE = '[fusion_builder_container][fusion_builder_row]'
		. '[fusion_builder_column type="1_2"][fusion_title size="2"]Hello[/fusion_title][fusion_text]Intro[/fusion_text][/fusion_builder_column]'
		. '[fusion_builder_column type="1_2"][fusion_imageframe image_id="14|full"]https://example.com/a.jpg[/fusion_imageframe][/fusion_builder_column]'
		. '[/fusion_builder_row][/fusion_builder_container]';

	/**
	 * A small registry shaped like Avada's.
	 */
	private function registry() {
		return Registry::from_avada_params(
			'3.15.6',
			[
				'fusion_builder_container' => [
					[ 'type' => 'colorpickeralpha', 'param_name' => 'background_color', 'default' => '' ],
				],
				'fusion_builder_row'       => [],
				'fusion_builder_column'    => [
					[ 'type' => 'textfield', 'param_name' => 'type', 'value' => '1_1' ],
				],
				'fusion_title'             => [
					[ 'type' => 'textarea', 'param_name' => 'element_content', 'value' => 'Your Content Goes Here' ],
					[
						'type'       => 'radio_button_set',
						'param_name' => 'size',
						'value'      => [ '1' => 'H1', '2' => 'H2', '3' => 'H3' ],
						'default'    => '1',
					],
					[
						'type'       => 'checkbox_button_set',
						'param_name' => 'hide_on_mobile',
						'value'      => [ 'small-visibility' => 'S', 'medium-visibility' => 'M', 'large-visibility' => 'L' ],
					],
					[ 'type' => 'textfield', 'param_name' => 'class', 'value' => '' ],
				],
				'fusion_text'              => [
					[ 'type' => 'tinymce', 'param_name' => 'element_content', 'value' => '' ],
				],
				'fusion_imageframe'        => [
					[ 'type' => 'upload', 'param_name' => 'element_content', 'value' => '' ],
					[ 'type' => 'textfield', 'param_name' => 'image_id', 'value' => '' ],
				],
			]
		);
	}

	private function apply( array $operations, $content = self::PAGE ) {
		return ( new EditCompiler( $this->registry() ) )->apply( $content, $operations );
	}

	private function assertRejected( array $operations, $message ) {
		try {
			$this->apply( $operations );
			$this->fail( 'Expected the operations to be rejected.' );
		} catch ( EditException $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}
	}

	public function test_replace_text_changes_only_that_element() {
		$out = $this->apply( [ [ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Welcome' ] ] );
		$this->assertSame( str_replace( ']Hello[', ']Welcome[', self::PAGE ), $out );
	}

	public function test_replace_text_escapes_shortcode_brackets() {
		$out = $this->apply( [ [ 'op' => 'replace_text', 'target' => '0.0.0.1', 'text' => 'Hi [fusion_alert]x[/fusion_alert]' ] ] );
		$this->assertStringContainsString( ']Hi &#91;fusion_alert&#93;x&#91;/fusion_alert&#93;[/fusion_text]', $out );
	}

	public function test_replace_text_runs_the_text_sanitizer() {
		$compiler = new EditCompiler(
			$this->registry(),
			function ( $text ) {
				return strip_tags( $text );
			}
		);
		$out = $compiler->apply( self::PAGE, [ [ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => '<script>x</script>Hi' ] ] );
		$this->assertStringContainsString( ']xHi[/fusion_title]', $out );
	}

	public function test_image_elements_take_a_web_address_as_content() {
		$out = $this->apply(
			[
				[ 'op' => 'replace_text', 'target' => '0.0.1.0', 'text' => ' https://example.com/new.jpg ' ],
				[ 'op' => 'insert_element', 'parent' => '0.0.0', 'type' => 'fusion_imageframe', 'attrs' => [ 'image_id' => '7|full' ], 'text' => 'https://example.com/b.jpg' ],
			]
		);
		$this->assertStringContainsString( '[fusion_imageframe image_id="14|full"]https://example.com/new.jpg[/fusion_imageframe]', $out );
		$this->assertStringContainsString( '[fusion_imageframe image_id="7|full"]https://example.com/b.jpg[/fusion_imageframe]', $out );
		$this->assertRejected( [ [ 'op' => 'replace_text', 'target' => '0.0.1.0', 'text' => 'javascript:alert(1)' ] ], 'needs a web address' );
		$this->assertRejected( [ [ 'op' => 'replace_text', 'target' => '0.0.1.0', 'text' => 'https://x.com/a.jpg][fusion_code]' ] ], 'needs a web address' );
	}

	public function test_replace_text_needs_a_text_setting() {
		$this->assertRejected( [ [ 'op' => 'replace_text', 'target' => '0.0.1.0', 'text' => 'x' ] ], 'fusion_imageframe needs a web address' );
		$this->assertRejected( [ [ 'op' => 'replace_text', 'target' => '0.0.0', 'text' => 'x' ] ], 'has no editable text' );
	}

	public function test_set_attribute_rebuilds_only_that_tag() {
		$out = $this->apply( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'size', 'value' => '3' ] ] );
		$this->assertSame( str_replace( 'size="2"', 'size="3"', self::PAGE ), $out );
	}

	public function test_set_attribute_checks_choices_and_names() {
		$this->assertRejected( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'size', 'value' => '9' ] ], 'Allowed: 1, 2, 3' );
		$this->assertRejected( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'onclick', 'value' => 'x' ] ], 'has no setting "onclick"' );
		$this->assertRejected( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'element_content', 'value' => 'x' ] ], 'has no setting' );
		$this->assertRejected( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'class', 'value' => "a\nb" ] ], 'control characters' );
	}

	public function test_set_attribute_accepts_multi_choice_lists() {
		$out = $this->apply( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'hide_on_mobile', 'value' => 'small-visibility,large-visibility' ] ] );
		$this->assertStringContainsString( 'hide_on_mobile="small-visibility,large-visibility"', $out );
		$this->assertRejected( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'hide_on_mobile', 'value' => 'small-visibility,tiny' ] ], '"tiny" is not a valid value' );
	}

	public function test_set_attribute_escapes_quotes() {
		$out = $this->apply( [ [ 'op' => 'set_attribute', 'target' => '0.0.0.0', 'name' => 'class', 'value' => 'a" onclick="x' ] ] );
		$this->assertStringContainsString( 'class="a&quot; onclick=&quot;x"', $out );
	}

	public function test_insert_element() {
		$out = $this->apply(
			[
				[
					'op'     => 'insert_element',
					'parent' => '0.0.0',
					'index'  => 1,
					'type'   => 'fusion_title',
					'attrs'  => [ 'size' => '3' ],
					'text'   => 'Sub',
				],
			]
		);
		$this->assertStringContainsString( '[/fusion_title][fusion_title size="3"]Sub[/fusion_title][fusion_text]', $out );
	}

	public function test_insert_appends_by_default_and_builds_structure() {
		// A container left empty gets a row and a full-width column, as in Avada Builder.
		$out = $this->apply( [ [ 'op' => 'insert_element', 'parent' => '', 'type' => 'fusion_builder_container' ] ] );
		$this->assertStringEndsWith( '[/fusion_builder_container][fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', $out );
	}

	public function test_refs_build_a_whole_new_section_in_one_list() {
		$out = $this->apply(
			[
				[ 'op' => 'insert_element', 'parent' => '', 'index' => 0, 'type' => 'fusion_builder_container', 'ref' => 'box' ],
				[ 'op' => 'insert_element', 'parent' => 'box', 'type' => 'fusion_builder_row', 'ref' => 'row' ],
				[ 'op' => 'insert_element', 'parent' => 'row', 'type' => 'fusion_builder_column', 'attrs' => [ 'type' => '1_2' ], 'ref' => 'left' ],
				[ 'op' => 'insert_element', 'parent' => 'row', 'type' => 'fusion_builder_column', 'attrs' => [ 'type' => '1_2' ] ],
				[ 'op' => 'insert_element', 'parent' => 'left', 'type' => 'fusion_title', 'text' => 'New' ],
				[ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Old ids still work' ],
			]
		);
		$this->assertStringStartsWith(
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_2"][fusion_title]New[/fusion_title][/fusion_builder_column]'
			. '[fusion_builder_column type="1_2"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]',
			$out
		);
		$this->assertStringContainsString( ']Old ids still work[/fusion_title]', $out );
	}

	public function test_refs_must_be_names_and_unique() {
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => '', 'type' => 'fusion_builder_container', 'ref' => '0.1' ] ], 'must start with a letter' );
		$this->assertRejected(
			[
				[ 'op' => 'insert_element', 'parent' => '', 'type' => 'fusion_builder_container', 'ref' => 'a' ],
				[ 'op' => 'insert_element', 'parent' => '', 'type' => 'fusion_builder_container', 'ref' => 'a' ],
			],
			'already used'
		);
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => 'nope', 'type' => 'fusion_title' ] ], 'No element with id "nope"' );
	}

	public function test_insert_checks_element_and_place() {
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => '0.0.0', 'type' => 'fusion_unknown' ] ], 'Unknown element' );
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => '', 'type' => 'fusion_title' ] ], 'cannot be placed in the page root' );
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => '0', 'type' => 'fusion_builder_column' ] ], 'cannot be placed in fusion_builder_container' );
		$this->assertRejected( [ [ 'op' => 'insert_element', 'parent' => '0.0.0', 'type' => 'fusion_title', 'attrs' => [ 'bad' => '1' ] ] ], 'has no setting "bad"' );
	}

	public function test_remove_element() {
		$out = $this->apply( [ [ 'op' => 'remove_element', 'target' => '0.0.0.1' ] ] );
		$this->assertStringNotContainsString( 'fusion_text', $out );
		$this->assertStringContainsString( 'Hello', $out );
	}

	public function test_duplicate_element_keeps_the_original_markup_and_can_be_moved() {
		$out = $this->apply(
			[
				[ 'op' => 'duplicate_element', 'target' => '0.0.0.0' ],
				[ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Changed original' ],
			]
		);
		$this->assertStringContainsString( '[fusion_title size="2"]Changed original[/fusion_title][fusion_title size="2"]Hello[/fusion_title][fusion_text]Intro', $out );

		$whole = $this->apply( [ [ 'op' => 'duplicate_element', 'target' => '0' ] ] );
		$this->assertSame( self::PAGE . self::PAGE, $whole );
	}

	public function test_move_element_within_its_parent() {
		$out = $this->apply( [ [ 'op' => 'move_element', 'target' => '0.0.1', 'index' => 0 ] ] );
		$this->assertLessThan( strpos( $out, 'fusion_title' ), strpos( $out, 'fusion_imageframe' ) );
	}

	public function test_ids_refer_to_the_page_as_the_model_showed_it() {
		$out = $this->apply(
			[
				[ 'op' => 'remove_element', 'target' => '0.0.0.0' ],
				[ 'op' => 'replace_text', 'target' => '0.0.0.1', 'text' => 'Still the text element' ],
			]
		);
		$this->assertStringContainsString( ']Still the text element[/fusion_text]', $out );
	}

	public function test_removed_elements_cannot_be_edited() {
		$this->assertRejected(
			[
				[ 'op' => 'remove_element', 'target' => '0.0.0' ],
				[ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'x' ],
			],
			'Operation 1: Element "0.0.0.0" was removed'
		);
	}

	public function test_failures_name_the_operation() {
		try {
			$this->apply(
				[
					[ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'ok' ],
					[ 'op' => 'replace_text', 'target' => '9', 'text' => 'x' ],
				]
			);
			$this->fail( 'Expected a failure.' );
		} catch ( EditException $e ) {
			$this->assertSame( 1, $e->operation_index() );
			$this->assertStringContainsString( 'No element with id "9"', $e->getMessage() );
		}
	}

	public function test_rejects_bad_requests() {
		$this->assertRejected( [], 'No operations' );
		$this->assertRejected( [ [ 'op' => 'delete_everything' ] ], 'Unknown operation' );
		$this->assertRejected( [ [ 'op' => 'move_element', 'target' => '0', 'index' => -1 ] ], '"index" must be' );
		$this->assertRejected( array_fill( 0, EditCompiler::MAX_OPERATIONS + 1, [ 'op' => 'remove_element', 'target' => '0' ] ), 'Too many operations' );
	}

	public function test_real_avada_page_changes_only_the_title_text() {
		$page = file_get_contents( __DIR__ . '/../fixtures/real-test-page-two-columns.txt' );
		$out  = $this->apply( [ [ 'op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'New title' ] ], $page );
		$this->assertSame( str_replace( ']Testing title[/fusion_title]', ']New title[/fusion_title]', $page ), $out );
	}
}
