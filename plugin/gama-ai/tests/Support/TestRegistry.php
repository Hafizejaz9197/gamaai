<?php
/**
 * A registry shaped like Avada 3.15.6's for the elements the tests use.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Support;

use GamaAI\Avada\Registry;

class TestRegistry {

	/**
	 * Container, row, column, title, text and button, with the visibility
	 * and status settings as Avada maps them.
	 *
	 * @return Registry
	 */
	public static function make() {
		$visibility = [
			'type'       => 'checkbox_button_set',
			'param_name' => 'hide_on_mobile',
			'value'      => [ 'small-visibility' => 'Small', 'medium-visibility' => 'Medium', 'large-visibility' => 'Large' ],
			'default'    => [ 'small-visibility', 'medium-visibility', 'large-visibility' ],
		];
		$overflow   = [];
		foreach ( [ '', 'visible', 'hidden', 'scroll', 'auto', 'clip', 'visible hidden', 'visible scroll', 'hidden auto' ] as $o ) {
			$overflow[ $o ] = $o;
		}
		return Registry::from_avada_params(
			'3.15.6',
			[
				'fusion_builder_container' => [
					[ 'type' => 'textfield', 'param_name' => 'type', 'default' => 'flex' ],
					$visibility,
					[
						'type'       => 'radio_button_set',
						'param_name' => 'status',
						'value'      => [ 'published' => 'Published', 'published_until' => 'Until', 'publish_after' => 'After', 'draft' => 'Draft' ],
						'default'    => 'published',
					],
					[ 'type' => 'textfield', 'param_name' => 'id', 'default' => '' ],
					[ 'type' => 'textfield', 'param_name' => 'menu_anchor', 'default' => '' ],
					[ 'type' => 'colorpickeralpha', 'param_name' => 'background_color', 'default' => 'rgba(255,255,255,0)', 'responsive' => [ 'state' => 'large' ] ],
					[ 'type' => 'colorpickeralpha', 'param_name' => 'background_color_medium', 'default' => '', 'responsive' => [ 'state' => 'medium' ] ],
					[ 'type' => 'colorpickeralpha', 'param_name' => 'link_color', 'default' => 'var(--awb-color8)' ],
					[ 'type' => 'dimension', 'param_name' => 'border_radius' ],
					[ 'type' => 'dimension', 'param_name' => 'padding_dimensions' ],
					[ 'type' => 'select', 'param_name' => 'overflow', 'value' => $overflow, 'default' => '' ],
					[ 'type' => 'range', 'param_name' => 'filter_blur_hover', 'default' => '0' ],
				],
				'fusion_builder_row'       => [],
				'fusion_builder_column'    => [
					[ 'type' => 'textfield', 'param_name' => 'type', 'value' => '1_1' ],
					$visibility,
					[ 'type' => 'colorpickeralpha', 'param_name' => 'background_color', 'default' => '' ],
				],
				'fusion_title'             => [
					[ 'type' => 'textarea', 'param_name' => 'element_content', 'value' => '' ],
					[ 'type' => 'radio_button_set', 'param_name' => 'size', 'value' => [ '1' => 'H1', '2' => 'H2', '3' => 'H3' ], 'default' => '1' ],
					[ 'type' => 'radio_button_set', 'param_name' => 'content_align', 'value' => [ 'left' => 'L', 'center' => 'C', 'right' => 'R' ], 'default' => 'left' ],
					[ 'type' => 'colorpickeralpha', 'param_name' => 'text_color', 'default' => '' ],
					$visibility,
				],
				'fusion_text'              => [
					[ 'type' => 'tinymce', 'param_name' => 'element_content', 'value' => '' ],
					$visibility,
				],
				'fusion_button'            => [
					[ 'type' => 'textfield', 'param_name' => 'element_content', 'value' => 'Button' ],
					[ 'type' => 'link_selector', 'param_name' => 'link', 'default' => '' ],
					[ 'type' => 'colorpickeralpha', 'param_name' => 'button_gradient_top_color', 'default' => '' ],
					$visibility,
				],
			]
		);
	}
}
