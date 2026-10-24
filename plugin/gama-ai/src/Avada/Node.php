<?php
/**
 * A node in a parsed Avada layout.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Either a text run or a shortcode with children.
 *
 * Shortcode nodes keep the exact source text of their opening and closing
 * tags so an unmodified tree serializes back byte for byte.
 */
class Node {

	const TEXT      = 'text';
	const SHORTCODE = 'shortcode';

	/** @var string */
	public $kind;

	/** @var string Shortcode name, empty for text. */
	public $name = '';

	/** @var string Raw text for text nodes, raw opening tag for shortcodes. */
	public $raw = '';

	/** @var array<string,string> Parsed attributes (shortcodes only). */
	public $attrs = [];

	/** @var Node[] */
	public $children = [];

	/** @var string|null Raw closing tag, or null for a self-closing / void shortcode. */
	public $raw_close = null;

	/** @var bool True once attrs were changed and the opening tag must be rebuilt. */
	public $dirty = false;

	/**
	 * Build a text node.
	 *
	 * @param string $text Text.
	 * @return Node
	 */
	public static function text( $text ) {
		$node       = new self();
		$node->kind = self::TEXT;
		$node->raw  = $text;
		return $node;
	}

	/**
	 * Build a shortcode node.
	 *
	 * @param string               $name  Shortcode name.
	 * @param string               $raw   Raw opening tag.
	 * @param array<string,string> $attrs Attributes.
	 * @return Node
	 */
	public static function shortcode( $name, $raw, array $attrs ) {
		$node        = new self();
		$node->kind  = self::SHORTCODE;
		$node->name  = $name;
		$node->raw   = $raw;
		$node->attrs = $attrs;
		return $node;
	}

	/**
	 * Is this a shortcode node?
	 *
	 * @return bool
	 */
	public function is_shortcode() {
		return self::SHORTCODE === $this->kind;
	}

	/**
	 * Shortcode children only, skipping text runs.
	 *
	 * @return Node[]
	 */
	public function shortcode_children() {
		return array_values(
			array_filter(
				$this->children,
				function ( Node $child ) {
					return $child->is_shortcode();
				}
			)
		);
	}

	/**
	 * Set an attribute and mark the opening tag for rebuilding.
	 *
	 * @param string $key   Attribute name.
	 * @param string $value Attribute value.
	 * @return void
	 */
	public function set_attr( $key, $value ) {
		$this->attrs[ $key ] = (string) $value;
		$this->dirty         = true;
	}
}
