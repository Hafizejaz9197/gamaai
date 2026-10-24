<?php
/**
 * Tests for the element settings sent with a page.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\ElementSettings;
use GamaAI\Avada\Registry;
use GamaAI\Avada\ShortcodeParser;
use PHPUnit\Framework\TestCase;

class ElementSettingsTest extends TestCase {

	public function test_lists_page_and_insertable_types_compactly() {
		$registry = Registry::from_avada_params(
			'3.15.6',
			[
				'fusion_builder_container' => [ [ 'type' => 'colorpickeralpha', 'param_name' => 'background_color', 'default' => '' ] ],
				'fusion_title'             => [
					[ 'type' => 'textarea', 'param_name' => 'element_content', 'value' => '' ],
					[ 'type' => 'radio_button_set', 'param_name' => 'size', 'value' => [ '1' => 'H1', '2' => 'H2' ], 'default' => '1' ],
				],
				'fusion_text'              => [ [ 'type' => 'tinymce', 'param_name' => 'element_content', 'value' => '' ] ],
				'fusion_alert'             => [ [ 'type' => 'textarea', 'param_name' => 'element_content', 'value' => '' ] ],
			]
		);
		$root = ( new ShortcodeParser() )->parse( '[fusion_builder_container][fusion_title]Hi[/fusion_title][/fusion_builder_container]' );

		$this->assertSame(
			[
				'fusion_builder_container' => [ 'background_color' => 'colorpickeralpha' ],
				'fusion_text'              => [ 'element_content' => 'tinymce' ],
				'fusion_title'             => [
					'element_content' => 'textarea',
					'size'            => [ '1', '2' ],
				],
			],
			ElementSettings::for_page( $registry, $root )
		);
	}
}
