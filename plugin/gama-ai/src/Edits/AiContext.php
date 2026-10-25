<?php
/**
 * What an AI request sends about the page.
 *
 * @package GamaAI
 */

namespace GamaAI\Edits;

use GamaAI\Avada\ElementSettings;
use GamaAI\Avada\Node;
use GamaAI\Avada\PageModel;
use GamaAI\Avada\Registry;

/**
 * Picks the smallest part of the page an AI request needs.
 *
 * When the editor picked a section on the page, or the request names one
 * element ("make the second container blue"), only the container holding
 * it is sent, with its page-wide ids, so operations still point at the
 * right elements. Otherwise the whole page outline is sent. Element
 * settings are narrowed to the request (ElementSettings::for_request).
 */
class AiContext {

	/** @var Registry */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Avada registry of the running builder version.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Build the context.
	 *
	 * @param string      $instruction Request.
	 * @param Node        $root        Parsed page (its draft, if open).
	 * @param string|null $selected    Id of the element picked on the page.
	 * @return array{sections:array<int,array<string,mixed>>,elements:array<string,mixed>,scope:string|null,stats:array<string,mixed>}
	 */
	public function build( $instruction, Node $root, $selected = null ) {
		$targets = new Targets( $root );
		$model   = new PageModel( $this->registry );
		$focus   = null !== $selected && null !== $targets->node( $selected ) ? $selected : $targets->find_in( $instruction, $selected );

		$top   = $root->shortcode_children();
		$scope = null;
		if ( null !== $focus && count( $top ) > 1 ) {
			$container = strtok( $focus, '.' );
			$scope     = $container;
			$sections  = [ $model->entry( $top[ (int) $container ], $container ) ];
			$nodes     = [ $top[ (int) $container ] ];
		} else {
			$sections = $model->build( $root );
			$nodes    = $top;
		}
		$elements = ElementSettings::for_request( $this->registry, $nodes, $instruction );

		$page_bytes     = strlen( (string) json_encode( $sections ) );
		$settings_bytes = strlen( (string) json_encode( (object) $elements ) );
		$settings_count = 0;
		foreach ( $elements as $settings ) {
			$settings_count += count( $settings );
		}

		return [
			'sections' => $sections,
			'elements' => $elements,
			'scope'    => null === $scope ? null : 'Only ' . $targets->label( $scope ) . ' of ' . count( $top ) . ' is shown' . ( $focus !== $scope ? '; the request is about element ' . $focus : '' ) . '. Ids are page-wide.',
			'stats'    => [
				'focus'          => $focus,
				'scope'          => $scope,
				'page_bytes'     => $page_bytes,
				'settings_bytes' => $settings_bytes,
				'settings_count' => $settings_count,
				'element_types'  => count( $elements ),
			],
		];
	}

	/**
	 * Size of what requests sent before this narrowing: the whole page and
	 * every setting of every element type on it. For comparison only.
	 *
	 * @param Node $root Parsed page.
	 * @return array{page_bytes:int,settings_bytes:int}
	 */
	public function full_size( Node $root ) {
		return [
			'page_bytes'     => strlen( (string) json_encode( ( new PageModel( $this->registry ) )->build( $root ) ) ),
			'settings_bytes' => strlen( (string) json_encode( (object) ElementSettings::for_page( $this->registry, $root ) ) ),
		];
	}
}
