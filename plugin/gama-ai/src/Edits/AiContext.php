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

	/** Words that mean the request needs images from the media library. */
	const MEDIA_WORDS = '/\\b(?:images?|pictures?|photos?|logos?|media|gallery|galleries|tasweer|tasveer|tasweeren)\\b/u';

	/** How many media library images a request gets to choose from. */
	const MEDIA_LIMIT = 12;

	/** @var Registry */
	private $registry;

	/** @var callable|null Returns recent images: list of {id, url, title}. */
	private $media;

	/**
	 * Constructor.
	 *
	 * @param Registry      $registry Avada registry of the running builder version.
	 * @param callable|null $media    Given a limit, returns recent media library images.
	 */
	public function __construct( Registry $registry, $media = null ) {
		$this->registry = $registry;
		$this->media    = $media;
	}

	/**
	 * Build the context.
	 *
	 * @param string      $instruction Request.
	 * @param Node        $root        Parsed page (its draft, if open).
	 * @param string|null $selected    Id of the element picked on the page.
	 * @return array{sections:array<int,array<string,mixed>>,elements:array<string,mixed>,media:array<int,array<string,mixed>>,scope:string|null,stats:array<string,mixed>}
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
		$media    = $this->media_for( $instruction );

		$page_bytes     = strlen( (string) json_encode( $sections ) );
		$settings_bytes = strlen( (string) json_encode( (object) $elements ) );
		$settings_count = 0;
		foreach ( $elements as $settings ) {
			$settings_count += count( $settings );
		}

		return [
			'sections' => $sections,
			'elements' => $elements,
			'media'    => $media,
			'scope'    => self::scope_text( $targets, $scope, $focus, $selected, count( $top ) ),
			'stats'    => [
				'focus'          => $focus,
				'scope'          => $scope,
				'page_bytes'     => $page_bytes,
				'settings_bytes' => $settings_bytes,
				'settings_count' => $settings_count,
				'element_types'  => count( $elements ),
				'media_count'    => count( $media ),
			],
		];
	}

	/**
	 * What the AI is told about the part of the page it sees and the
	 * element the request is about. A pinned element is named outright,
	 * so "this", "here" and "it" point at it.
	 *
	 * @param Targets     $targets  Page targets.
	 * @param string|null $scope    Container shown, or null for all.
	 * @param string|null $focus    Element the request is about.
	 * @param string|null $selected Element pinned on the page.
	 * @param int         $count    Containers on the page.
	 * @return string|null
	 */
	private static function scope_text( Targets $targets, $scope, $focus, $selected, $count ) {
		$parts = [];
		if ( null !== $scope ) {
			$parts[] = 'Only ' . $targets->label( $scope ) . ' of ' . $count . ' is shown. Ids are page-wide.';
		}
		if ( null !== $focus && $focus === $selected ) {
			$node    = $targets->node( $focus );
			$empty   = '' === $targets->text( $node ) && ! $node->shortcode_children();
			$parts[] = 'The site owner pinned element ' . $focus . ' (' . $node->name . ( $empty ? ', currently empty' : '' ) . ') on the page: "this", "here", "it" and "that element" mean it, and the request is about it.';
		} elseif ( null !== $focus && $focus !== $scope ) {
			$parts[] = 'The request is about element ' . $focus . '.';
		}
		return $parts ? implode( ' ', $parts ) : null;
	}

	/**
	 * Recent media library images, only when the request talks about images.
	 *
	 * @param string $instruction Request.
	 * @return array<int,array{id:int,url:string,title:string}>
	 */
	private function media_for( $instruction ) {
		if ( null === $this->media || ! preg_match( self::MEDIA_WORDS, Targets::normalise( $instruction ) ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) call_user_func( $this->media, self::MEDIA_LIMIT ) as $item ) {
			if ( empty( $item['id'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$out[] = [
				'id'    => (int) $item['id'],
				'url'   => (string) $item['url'],
				'title' => isset( $item['title'] ) ? (string) $item['title'] : '',
			];
		}
		return array_slice( $out, 0, self::MEDIA_LIMIT );
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
