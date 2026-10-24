<?php
/**
 * Compact element settings sent to the AI with a page.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Picks the registry entries an edit may need and writes them compactly:
 * each setting is its type, or its list of allowed values for choice
 * settings. Defaults are left out; the page model already omits them.
 */
class ElementSettings {

	/** Element types the AI may insert on any page, besides those already on it. */
	const INSERTABLE = [ 'fusion_title', 'fusion_text', 'fusion_button' ];

	/**
	 * Settings for the element types on a page plus the insertable ones.
	 *
	 * @param Registry $registry Registry.
	 * @param Node     $root     Parsed page.
	 * @return array<string,array<string,string|string[]>>
	 */
	public static function for_page( Registry $registry, Node $root ) {
		$types = self::INSERTABLE;
		self::collect_types( $root, $types );

		$out = [];
		foreach ( array_unique( $types ) as $type ) {
			if ( ! $registry->has( $type ) ) {
				continue;
			}
			$out[ $type ] = [];
			foreach ( $registry->params( $type ) as $name => $param ) {
				$out[ $type ][ $name ] = isset( $param['options'] ) ? $param['options'] : $param['type'];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Shortcode names used in a tree.
	 *
	 * @param Node     $node  Node.
	 * @param string[] $types Collected names.
	 * @return void
	 */
	private static function collect_types( Node $node, array &$types ) {
		foreach ( $node->shortcode_children() as $child ) {
			$types[] = $child->name;
			self::collect_types( $child, $types );
		}
	}
}
