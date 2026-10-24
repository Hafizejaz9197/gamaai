<?php
/**
 * Tests for the compact page model.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\PageModel;
use GamaAI\Avada\ShortcodeParser;
use PHPUnit\Framework\TestCase;

class PageModelTest extends TestCase {

	private function root() {
		return ( new ShortcodeParser() )->parse( file_get_contents( dirname( __DIR__ ) . '/fixtures/synthetic-two-sections.txt' ) );
	}

	public function test_outline_has_path_ids_types_and_text() {
		$model = ( new PageModel() )->build( $this->root() );

		$this->assertCount( 2, $model );
		$this->assertSame( '0', $model[0]['id'] );
		$this->assertSame( 'fusion_builder_container', $model[0]['type'] );

		$title = $model[0]['children'][0]['children'][0]['children'][0];
		$this->assertSame( '0.0.0.0', $title['id'] );
		$this->assertSame( 'fusion_title', $title['type'] );
		$this->assertSame( 'Welcome to Sabahat', $title['text'] );

		$text = $model[0]['children'][0]['children'][0]['children'][1];
		$this->assertSame( 'We build websites that are fast & easy to edit. Second paragraph with a [bracket] in it.', $text['text'] );
	}

	public function test_long_attribute_values_are_summarised() {
		$root  = ( new ShortcodeParser() )->parse( '[fusion_text class="' . str_repeat( 'x', 200 ) . '" id=""]t[/fusion_text]' );
		$model = ( new PageModel() )->build( $root );
		$this->assertSame( [ 'class' => '[200 chars]' ], $model[0]['attrs'] );
	}

	public function test_find_resolves_ids_from_the_outline() {
		$root = $this->root();
		$this->assertSame( 'fusion_li_item', PageModel::find( $root, '1.0.1.0.1' )->name );
		$this->assertNull( PageModel::find( $root, '9' ) );
		$this->assertNull( PageModel::find( $root, '0..1' ) );
	}
}
