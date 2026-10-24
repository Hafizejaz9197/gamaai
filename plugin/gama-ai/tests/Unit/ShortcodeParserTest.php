<?php
/**
 * Tests for the Avada shortcode parser and serializer.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\Serializer;
use GamaAI\Avada\ShortcodeParser;
use PHPUnit\Framework\TestCase;

class ShortcodeParserTest extends TestCase {

	/**
	 * Every fixture must serialize back byte for byte.
	 *
	 * @dataProvider fixtures
	 */
	public function test_fixtures_round_trip( $file ) {
		$content = file_get_contents( $file );
		$this->assertSame( $content, $this->round_trip( $content ) );
	}

	public function fixtures() {
		$out = [];
		foreach ( glob( dirname( __DIR__ ) . '/fixtures/*.txt' ) as $file ) {
			$out[ basename( $file ) ] = [ $file ];
		}
		return $out;
	}

	public function test_builds_container_row_column_tree() {
		$root = ( new ShortcodeParser() )->parse( file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-two-sections.txt' ) );

		$containers = $root->shortcode_children();
		$this->assertCount( 2, $containers );
		$this->assertSame( 'fusion_builder_container', $containers[0]->name );

		$row = $containers[0]->shortcode_children()[0];
		$this->assertSame( 'fusion_builder_row', $row->name );

		$elements = $row->shortcode_children()[0]->shortcode_children();
		$this->assertSame( [ 'fusion_title', 'fusion_text', 'fusion_button', 'fusion_separator' ], array_map( function ( $n ) {
			return $n->name;
		}, $elements ) );
		$this->assertSame( 'https://example.com/contact', $elements[2]->attrs['link'] );
	}

	public function test_reads_markup_saved_by_avada_builder() {
		$root = ( new ShortcodeParser() )->parse( file_get_contents( dirname( __DIR__ ) . '/fixtures/real-test-page-two-columns.txt' ) );

		$row     = $root->children[0]->children[0];
		$columns = $row->shortcode_children();
		$this->assertSame( 'fusion_builder_row', $row->name );
		$this->assertCount( 2, $columns );
		$this->assertSame( 'Testing title', $columns[0]->children[0]->children[0]->raw );
		$this->assertSame( '14|medium', $columns[1]->children[0]->attrs['image_id'] );
		// Avada Builder writes "type" twice on columns; WordPress keeps the last value.
		$this->assertSame( 2, substr_count( $columns[0]->raw, ' type="1_2"' ) );
		$this->assertSame( '1_2', $columns[0]->attrs['type'] );
	}

	public function test_self_closing_tag_has_no_children_or_close() {
		$root = ( new ShortcodeParser() )->parse( '[fusion_separator style_type="default" /][fusion_text]x[/fusion_text]' );
		$this->assertCount( 2, $root->children );
		$this->assertNull( $root->children[0]->raw_close );
		$this->assertSame( '[/fusion_text]', $root->children[1]->raw_close );
	}

	public function test_void_tag_without_closing_hands_children_to_parent() {
		$content = '[fusion_builder_column][fusion_fontawesome icon="fa-star"][fusion_text]a[/fusion_text][/fusion_builder_column]';
		$root    = ( new ShortcodeParser() )->parse( $content );

		$column = $root->children[0];
		$this->assertSame( [ 'fusion_fontawesome', 'fusion_text' ], array_map( function ( $n ) {
			return $n->name;
		}, $column->shortcode_children() ) );
		$this->assertSame( $content, $this->round_trip( $content ) );
	}

	public function test_non_avada_shortcodes_and_plain_brackets_stay_text() {
		$content = '[fusion_text]See [1] and [gallery ids="1,2"][/fusion_text][contact-form-7 id="12"]';
		$root    = ( new ShortcodeParser() )->parse( $content );

		$this->assertCount( 2, $root->children );
		$this->assertSame( 'fusion_text', $root->children[0]->name );
		$this->assertFalse( $root->children[1]->is_shortcode() );
		$this->assertSame( $content, $this->round_trip( $content ) );
	}

	public function test_escaped_tag_is_text() {
		$content = 'Use [[fusion_text]] to show the tag.';
		$root    = ( new ShortcodeParser() )->parse( $content );
		$this->assertCount( 1, $root->children );
		$this->assertFalse( $root->children[0]->is_shortcode() );
		$this->assertSame( $content, $this->round_trip( $content ) );
	}

	public function test_stray_closing_tag_is_kept_as_text() {
		$content = 'a[/fusion_text]b';
		$this->assertSame( $content, $this->round_trip( $content ) );
	}

	public function test_attribute_parsing_matches_wordpress_rules() {
		$atts = ShortcodeParser::parse_attrs( ' a="1" B=\'two\' c=3 "pos" flag' );
		$this->assertSame( [ 'a' => '1', 'b' => 'two', 'c' => '3', 0 => 'pos', 1 => 'flag' ], $atts );
	}

	public function test_changed_attribute_rebuilds_only_that_tag() {
		$content = '[fusion_builder_column type="1_1"][fusion_button link="#" color="default"]Go[/fusion_button][/fusion_builder_column]';
		$root    = ( new ShortcodeParser() )->parse( $content );

		$button = $root->children[0]->children[0];
		$button->set_attr( 'link', 'https://example.com/"quoted"' );

		$this->assertSame(
			'[fusion_builder_column type="1_1"][fusion_button link="https://example.com/&quot;quoted&quot;" color="default"]Go[/fusion_button][/fusion_builder_column]',
			( new Serializer() )->serialize( $root )
		);
	}

	public function test_rebuilt_self_closing_tag_stays_self_closing() {
		$root = ( new ShortcodeParser() )->parse( '[fusion_separator style_type="default" /]' );
		$root->children[0]->set_attr( 'style_type', 'none' );
		$this->assertSame( '[fusion_separator style_type="none" /]', ( new Serializer() )->serialize( $root ) );
	}

	private function round_trip( $content ) {
		return ( new Serializer() )->serialize( ( new ShortcodeParser() )->parse( $content ) );
	}
}
