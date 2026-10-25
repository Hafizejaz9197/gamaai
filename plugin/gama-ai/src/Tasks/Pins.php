<?php
/**
 * Pins: the element a task was left on.
 *
 * @package GamaAI
 */

namespace GamaAI\Tasks;

use GamaAI\Avada\Node;
use GamaAI\Avada\PageModel;
use GamaAI\Edits\Targets;

/**
 * A pin keeps the element's id, type and the start of its text. Ids move
 * when elements are added or removed before it, so when a task is fixed
 * later the pin is found again by type and text. It is never guessed:
 * when that fits no element, or several, the pin is lost.
 */
class Pins {

	/** How much of the element's text a pin keeps. */
	const SNIPPET_LENGTH = 60;

	/**
	 * Pin an element of the page.
	 *
	 * @param Node   $root Parsed page.
	 * @param string $id   Path id.
	 * @return array{target:string,type:string,snippet:string,label:string}|null Null when there is no such element.
	 */
	public static function make( Node $root, $id ) {
		$node = PageModel::find( $root, (string) $id );
		if ( null === $node ) {
			return null;
		}
		$targets = new Targets( $root );
		return [
			'target'  => (string) $id,
			'type'    => $node->name,
			'snippet' => self::snippet( $targets->text( $node ) ),
			'label'   => $targets->label( (string) $id ),
		];
	}

	/**
	 * Where a pin's element is now.
	 *
	 * @param Node                $root Parsed page as it is now.
	 * @param array<string,mixed> $pin  Pin from make().
	 * @return string|null Path id, or null when the element cannot be told apart any more.
	 */
	public static function locate( Node $root, array $pin ) {
		$type    = isset( $pin['type'] ) ? (string) $pin['type'] : '';
		$snippet = isset( $pin['snippet'] ) ? (string) $pin['snippet'] : '';
		$target  = isset( $pin['target'] ) ? (string) $pin['target'] : '';
		$targets = new Targets( $root );

		$node = '' === $target ? null : PageModel::find( $root, $target );
		if ( null !== $node && $node->name === $type && self::snippet( $targets->text( $node ) ) === $snippet ) {
			return $target;
		}
		if ( '' === $snippet ) {
			return null; // Empty elements of one type look alike.
		}
		$found = [];
		foreach ( $targets->of_types( [ $type ] ) as $id ) {
			if ( self::snippet( $targets->text( $targets->node( $id ) ) ) === $snippet ) {
				$found[] = $id;
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * The start of a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function snippet( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SNIPPET_LENGTH, 'UTF-8' ) : substr( $text, 0, self::SNIPPET_LENGTH );
	}
}
