<?php
/**
 * Tests for the Avada element registry and default stripping.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\PageModel;
use GamaAI\Avada\Registry;
use GamaAI\Avada\ShortcodeParser;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase {

	/**
	 * Params shaped like Avada Builder 3.15's fusion_text map
	 * (shortcodes/fusion-text.php), trimmed to the kinds that matter.
	 */
	private function text_params() {
		return [
			[
				'type'       => 'tinymce',
				'param_name' => 'element_content',
				'value'      => 'Your Content Goes Here',
			],
			[
				'type'       => 'select',
				'param_name' => 'rule_style',
				'value'      => [
					''      => 'Default',
					'none'  => 'None',
					'solid' => 'Solid',
				],
				'default'    => '',
			],
			[
				'type'       => 'range',
				'param_name' => 'rule_size',
				'default'    => '1',
				'min'        => '1',
				'max'        => '50',
			],
			[
				'type'       => 'radio_button_set',
				'param_name' => 'user_select',
				'value'      => [
					''     => 'Default',
					'auto' => 'Selectable',
				],
			],
			[
				'type'       => 'checkbox_button_set',
				'param_name' => 'hide_on_mobile',
				'value'      => [
					'small-visibility'  => 'Small',
					'medium-visibility' => 'Medium',
					'large-visibility'  => 'Large',
				],
				'default'    => [ 'small-visibility', 'medium-visibility', 'large-visibility' ],
			],
			[
				'type'       => 'textfield',
				'param_name' => 'width',
				'value'      => '',
				'responsive' => [ 'state' => 'large' ],
			],
			'fusion_sticky_visibility_placeholder' => [],
		];
	}

	public function test_normalises_avada_params() {
		$registry = Registry::from_avada_params( '3.15.6', [ 'fusion_text' => $this->text_params() ] );
		$params   = $registry->params( 'fusion_text' );

		$this->assertSame( [ 'type' => 'select', 'options' => [ '', 'none', 'solid' ], 'default' => '' ], $params['rule_style'] );
		$this->assertSame( '1', $params['rule_size']['default'] );
		$this->assertSame( 'Your Content Goes Here', $params['element_content']['default'] );
		$this->assertArrayNotHasKey( 'default', $params['user_select'] );
		$this->assertSame( 'small-visibility,medium-visibility,large-visibility', $params['hide_on_mobile']['default'] );
		$this->assertSame( 'large', $params['width']['responsive'] );
		$this->assertCount( 6, $params, 'Unexpanded placeholders are skipped.' );
	}

	public function test_is_default_only_for_known_defaults() {
		$registry = Registry::from_avada_params( '3.15.6', [ 'fusion_text' => $this->text_params() ] );

		$this->assertTrue( $registry->is_default( 'fusion_text', 'rule_size', '1' ) );
		$this->assertFalse( $registry->is_default( 'fusion_text', 'rule_size', '2' ) );
		$this->assertFalse( $registry->is_default( 'fusion_text', 'user_select', 'auto' ), 'No default known.' );
		$this->assertFalse( $registry->is_default( 'fusion_text', 'unknown', '1' ) );
		$this->assertFalse( $registry->is_default( 'fusion_title', 'rule_size', '1' ) );
	}

	public function test_round_trips_through_array_export() {
		$registry = Registry::from_avada_params( '3.15.6', [ 'fusion_text' => $this->text_params() ] );
		$copy     = Registry::from_array( json_decode( json_encode( $registry->to_array() ), true ) );

		$this->assertSame( $registry->to_array(), $copy->to_array() );
		$this->assertSame( '3.15.6', $copy->builder_version() );
		$this->assertSame( 1, $copy->count() );
	}

	public function test_page_model_leaves_out_default_settings() {
		$registry = Registry::from_avada_params( '3.15.6', [ 'fusion_text' => $this->text_params() ] );
		$root     = ( new ShortcodeParser() )->parse(
			'[fusion_text rule_size="1" rule_style="solid" hide_on_mobile="small-visibility,medium-visibility,large-visibility" user_select="auto" class="intro"]Hi[/fusion_text]'
		);

		$with    = ( new PageModel( $registry ) )->build( $root );
		$without = ( new PageModel() )->build( $root );

		$this->assertSame( [ 'rule_style' => 'solid', 'user_select' => 'auto', 'class' => 'intro' ], $with[0]['attrs'] );
		$this->assertCount( 5, $without[0]['attrs'] );
	}
}
