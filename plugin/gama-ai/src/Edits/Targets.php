<?php
/**
 * Finds the element a request in plain words points at.
 *
 * @package GamaAI
 */

namespace GamaAI\Edits;

use GamaAI\Avada\Node;

/**
 * Resolves phrases such as "the container", "the second heading",
 * "button 2", "the heading "Welcome"" or "this" (the element picked on the
 * page) to one element id of the page model. It never guesses: a phrase
 * that fits several elements is reported as ambiguous.
 */
class Targets {

	/** Words for element types, singular. */
	const WORDS = [
		'container' => [ 'fusion_builder_container' ],
		'section'   => [ 'fusion_builder_container' ],
		'row'       => [ 'fusion_builder_row', 'fusion_builder_row_inner' ],
		'column'    => [ 'fusion_builder_column', 'fusion_builder_column_inner' ],
		'heading'   => [ 'fusion_title' ],
		'headline'  => [ 'fusion_title' ],
		'title'     => [ 'fusion_title' ],
		'text'      => [ 'fusion_text' ],
		'paragraph' => [ 'fusion_text' ],
		'button'    => [ 'fusion_button' ],
		'image'     => [ 'fusion_imageframe' ],
		'picture'   => [ 'fusion_imageframe' ],
		'photo'     => [ 'fusion_imageframe' ],
	];

	/** Ordinal words. */
	const ORDINALS = [
		'first'   => 1,
		'second'  => 2,
		'third'   => 3,
		'fourth'  => 4,
		'fifth'   => 5,
		'sixth'   => 6,
		'seventh' => 7,
		'eighth'  => 8,
		'ninth'   => 9,
		'tenth'   => 10,
		'last'    => -1,
		'top'     => 1,
		'bottom'  => -1,
	];

	/** Phrases that mean "the element picked on the page". */
	const SELECTED = [ 'it', 'this', 'that', 'this one', 'that one', 'selected', 'the selected one', 'the selected element', 'the selection', 'selection', 'is', 'ise', 'isko', 'is ko', 'ye', 'yeh' ];

	/** @var Node */
	private $root;

	/** @var array<string,Node> Path id => node, in document order. */
	private $ids = [];

	/**
	 * Constructor.
	 *
	 * @param Node $root Parsed page.
	 */
	public function __construct( Node $root ) {
		$this->root = $root;
		$this->index( $root, '' );
	}

	/**
	 * The node with a path id, if any.
	 *
	 * @param string $id Path id.
	 * @return Node|null
	 */
	public function node( $id ) {
		return isset( $this->ids[ $id ] ) ? $this->ids[ $id ] : null;
	}

	/**
	 * Regex fragment matching a target phrase, for use inside bigger patterns.
	 *
	 * @return string
	 */
	public static function phrase_pattern() {
		$words    = implode( '|', array_keys( self::WORDS ) );
		$ordinals = implode( '|', array_keys( self::ORDINALS ) );
		return '(?:(?:the|this|that|is|ye|yeh)\s+)?(?:(?:' . $ordinals . '|\d+(?:st|nd|rd|th))\s+)?(?:' . $words . ')s?'
			. '(?:\s+(?:number\s+|no\.?\s*|#)?\d+)?'
			. '(?:\s+(?:that\s+says|saying|with\s+(?:the\s+)?text|called|named))?(?:\s+["\x{201C}\'][^"\x{201D}\']+["\x{201D}\'])?';
	}

	/**
	 * Resolve a phrase.
	 *
	 * @param string      $phrase   Phrase such as "the second container".
	 * @param string|null $selected Id of the element picked on the page.
	 * @return array{status:string,id?:string,count?:int,label?:string,word?:string}
	 *         status is found, ambiguous (count > 1), none (count 0) or unknown (not a target phrase).
	 */
	public function resolve( $phrase, $selected = null ) {
		$phrase = self::normalise( $phrase );
		if ( in_array( $phrase, self::SELECTED, true ) ) {
			if ( null !== $selected && isset( $this->ids[ $selected ] ) ) {
				return [
					'status' => 'found',
					'id'     => $selected,
					'label'  => $this->label( $selected ),
				];
			}
			return [
				'status' => 'none',
				'count'  => 0,
				'word'   => 'element',
			];
		}

		$words    = implode( '|', array_keys( self::WORDS ) );
		$ordinals = implode( '|', array_keys( self::ORDINALS ) );
		$pattern  = '/^(?:(the|this|that|is|ye|yeh)\s+)?(?:(' . $ordinals . '|\d+)(?:st|nd|rd|th)?\s+)?(' . $words . ')s?'
			. '(?:\s+(?:number\s+|no\.?\s*|#)?(\d+))?'
			. '(?:\s+(?:that\s+says|saying|with\s+(?:the\s+)?text|called|named))?(?:\s+["\x{201C}\']([^"\x{201D}\']+)["\x{201D}\'])?$/u';
		if ( ! preg_match( $pattern, $phrase, $m ) ) {
			return [ 'status' => 'unknown' ];
		}
		$word       = $m[3];
		$position   = null;
		if ( isset( $m[2] ) && '' !== $m[2] ) {
			$position = isset( self::ORDINALS[ $m[2] ] ) ? self::ORDINALS[ $m[2] ] : (int) $m[2];
		} elseif ( isset( $m[4] ) && '' !== $m[4] ) {
			$position = (int) $m[4];
		}
		$quote = isset( $m[5] ) && '' !== $m[5] ? $m[5] : null;

		$candidates = $this->of_types( self::WORDS[ $word ] );
		if ( null !== $quote ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					function ( $id ) use ( $quote ) {
						return false !== strpos( self::lower( $this->text( $this->ids[ $id ] ) ), $quote );
					}
				)
			);
		}

		if ( null === $position && null !== $selected && in_array( $selected, $candidates, true ) ) {
			$candidates = [ $selected ]; // "this container" or "the container" while one is picked.
		}

		if ( null !== $position ) {
			$count = count( $candidates );
			$index = $position < 0 ? $count + $position : $position - 1;
			if ( 0 === $position || $index < 0 || $index >= $count ) {
				return [
					'status' => 'none',
					'count'  => $count,
					'word'   => $word,
				];
			}
			$candidates = [ $candidates[ $index ] ];
		}

		if ( 1 === count( $candidates ) ) {
			return [
				'status' => 'found',
				'id'     => $candidates[0],
				'label'  => $this->label( $candidates[0] ),
				'word'   => $word,
			];
		}
		return [
			'status' => $candidates ? 'ambiguous' : 'none',
			'count'  => count( $candidates ),
			'word'   => $word,
		];
	}

	/**
	 * Find the one element a longer request talks about, such as "the second
	 * container" in "make the second container blue". Null when the request
	 * names none, or names several different ones.
	 *
	 * @param string      $instruction Request.
	 * @param string|null $selected    Id of the element picked on the page.
	 * @return string|null Path id.
	 */
	public function find_in( $instruction, $selected = null ) {
		$text = self::normalise( $instruction );
		if ( ! preg_match_all( '/\b' . self::phrase_pattern() . '/u', $text, $matches ) ) {
			return null;
		}
		$found = [];
		foreach ( $matches[0] as $phrase ) {
			$result = $this->resolve( $phrase, $selected );
			if ( 'found' === $result['status'] ) {
				$found[ $result['id'] ] = true;
			}
		}
		return 1 === count( $found ) ? (string) key( $found ) : null;
	}

	/**
	 * Words for an element, such as "the 2nd container".
	 *
	 * @param string $id Path id.
	 * @return string
	 */
	public function label( $id ) {
		$node = $this->ids[ $id ];
		$word = 'element';
		foreach ( self::WORDS as $candidate => $types ) {
			if ( in_array( $node->name, $types, true ) ) {
				$word = $candidate;
				break;
			}
		}
		$same = $this->of_types( isset( self::WORDS[ $word ] ) ? self::WORDS[ $word ] : [ $node->name ] );
		if ( count( $same ) < 2 ) {
			return 'the ' . $word;
		}
		$n = array_search( $id, $same, true ) + 1;
		return 'the ' . $n . self::suffix( $n ) . ' ' . $word;
	}

	/**
	 * Ids of elements of the given types, in page order.
	 *
	 * @param string[] $types Shortcode names.
	 * @return string[]
	 */
	public function of_types( array $types ) {
		$out = [];
		foreach ( $this->ids as $id => $node ) {
			if ( in_array( $node->name, $types, true ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Visible text in an element and everything in it, without HTML.
	 *
	 * @param Node $node Node.
	 * @return string
	 */
	public function text( Node $node ) {
		$text = '';
		foreach ( $node->children as $child ) {
			$text .= ' ' . ( $child->is_shortcode() ? $this->text( $child ) : $child->raw );
		}
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Lowercase, single spaces, no trailing punctuation or "please".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalise( $text ) {
		$text = self::lower( trim( preg_replace( '/\s+/u', ' ', (string) $text ) ) );
		$text = preg_replace( '/^please\s+|\s*\bplease$|[\s.!]+$/u', '', $text );
		return trim( (string) $text );
	}

	/**
	 * Lowercase, multibyte-aware when the mbstring extension is there.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * English ordinal suffix.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function suffix( $n ) {
		if ( in_array( $n % 100, [ 11, 12, 13 ], true ) ) {
			return 'th';
		}
		$suffixes = [ 1 => 'st', 2 => 'nd', 3 => 'rd' ];
		return isset( $suffixes[ $n % 10 ] ) ? $suffixes[ $n % 10 ] : 'th';
	}

	/**
	 * Record path ids like PageModel does.
	 *
	 * @param Node   $node   Node.
	 * @param string $prefix Its path id.
	 * @return void
	 */
	private function index( Node $node, $prefix ) {
		foreach ( $node->shortcode_children() as $i => $child ) {
			$id               = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$this->ids[ $id ] = $child;
			$this->index( $child, $id );
		}
	}
}
