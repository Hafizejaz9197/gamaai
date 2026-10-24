<?php
/**
 * Writes a shortcode tree back to Avada layout markup.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Tree to markup.
 */
class Serializer {

	/**
	 * Serialize a root node (as returned by ShortcodeParser::parse()).
	 *
	 * @param Node $root Root node.
	 * @return string
	 */
	public function serialize( Node $root ) {
		$out = '';
		foreach ( $root->children as $child ) {
			$out .= $this->node( $child );
		}
		return $out;
	}

	/**
	 * Serialize one node and its children.
	 *
	 * @param Node $node Node.
	 * @return string
	 */
	private function node( Node $node ) {
		if ( ! $node->is_shortcode() ) {
			return $node->raw;
		}

		$out = $node->dirty ? self::build_open( $node ) : $node->raw;
		foreach ( $node->children as $child ) {
			$out .= $this->node( $child );
		}
		if ( null !== $node->raw_close ) {
			$out .= $node->raw_close;
		}
		return $out;
	}

	/**
	 * Build an opening tag from a node's attributes.
	 *
	 * Values cannot contain characters that would end the attribute or the
	 * tag, so quotes and square brackets are written as HTML entities.
	 *
	 * @param Node $node Node.
	 * @return string
	 */
	public static function build_open( Node $node ) {
		$parts = [ $node->name ];
		foreach ( $node->attrs as $key => $value ) {
			$value = str_replace( [ '"', '[', ']' ], [ '&quot;', '&#91;', '&#93;' ], (string) $value );
			if ( is_int( $key ) ) {
				$parts[] = '"' . $value . '"';
			} else {
				$parts[] = $key . '="' . $value . '"';
			}
		}
		$self_closing = null === $node->raw_close && empty( $node->children ) && '/]' === substr( $node->raw, -2 );
		return '[' . implode( ' ', $parts ) . ( $self_closing ? ' /]' : ']' );
	}
}
