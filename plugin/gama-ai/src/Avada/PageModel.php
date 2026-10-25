<?php
/**
 * Compact page model sent to the AI backend.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Turns a shortcode tree into a small, AI-friendly outline.
 *
 * Each shortcode gets a path id ("0.1.2" = third shortcode child of the
 * second child of the first container) so later edit operations can point
 * at it. Text runs between shortcodes are not addressed; text inside an
 * element is summarised as `text`.
 */
class PageModel {

	/** Attribute values longer than this are summarised, not sent. */
	const MAX_ATTR_LENGTH = 120;

	/** Inner text is cut to this many characters. */
	const MAX_TEXT_LENGTH = 400;

	/** @var Registry|null */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry|null $registry When given, settings left at Avada's
	 *                                default are left out of the outline.
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry;
	}

	/**
	 * Build the outline.
	 *
	 * @param Node $root Root node from ShortcodeParser::parse().
	 * @return array<int,array<string,mixed>>
	 */
	public function build( Node $root ) {
		return $this->children( $root, '' );
	}

	/**
	 * Find a node by its path id.
	 *
	 * @param Node   $root Root node.
	 * @param string $id   Path id such as "0.1.2".
	 * @return Node|null
	 */
	public static function find( Node $root, $id ) {
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $id ) ) {
			return null;
		}
		$node = $root;
		foreach ( explode( '.', $id ) as $index ) {
			$children = $node->shortcode_children();
			if ( ! isset( $children[ (int) $index ] ) ) {
				return null;
			}
			$node = $children[ (int) $index ];
		}
		return $node;
	}

	/**
	 * Outline the shortcode children of a node.
	 *
	 * @param Node   $node   Parent.
	 * @param string $prefix Parent path id.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( Node $node, $prefix ) {
		$out = [];
		foreach ( $node->shortcode_children() as $i => $child ) {
			$out[] = $this->entry( $child, '' === $prefix ? (string) $i : $prefix . '.' . $i );
		}
		return $out;
	}

	/**
	 * Outline one element and everything in it, keeping its page-wide id.
	 *
	 * @param Node   $node Shortcode node.
	 * @param string $id   Its path id.
	 * @return array<string,mixed>
	 */
	public function entry( Node $node, $id ) {
		$entry = [
			'id'   => $id,
			'type' => $node->name,
		];

		$attrs = $this->attrs( $node );
		if ( $attrs ) {
			$entry['attrs'] = $attrs;
		}

		$text = $this->text( $node );
		if ( '' !== $text ) {
			$entry['text'] = $text;
		}

		$nested = $this->children( $node, $id );
		if ( $nested ) {
			$entry['children'] = $nested;
		}
		return $entry;
	}

	/**
	 * Non-empty, non-default attributes, with long values summarised.
	 *
	 * @param Node $node Shortcode node.
	 * @return array<string|int,string>
	 */
	private function attrs( Node $node ) {
		$out = [];
		foreach ( $node->attrs as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( $this->registry && is_string( $key ) && $this->registry->is_default( $node->name, $key, $value ) ) {
				continue;
			}
			$out[ $key ] = strlen( $value ) > self::MAX_ATTR_LENGTH
				? '[' . strlen( $value ) . ' chars]'
				: $value;
		}
		return $out;
	}

	/**
	 * The element's own text, from its direct text runs, without HTML.
	 *
	 * @param Node $node Node.
	 * @return string
	 */
	private function text( Node $node ) {
		$text = '';
		foreach ( $node->children as $child ) {
			if ( ! $child->is_shortcode() ) {
				$text .= ' ' . $child->raw;
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH ) . '…';
		}
		return $text;
	}
}
