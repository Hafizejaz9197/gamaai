<?php
/**
 * Parses Avada layout markup (post_content) into a tree.
 *
 * Avada Builder registers its elements with add_shortcode() and stores layouts
 * as nested shortcodes in post_content (verified on Avada Builder 3.15.6,
 * see docs/phase0-avada-findings.md). This parser builds a tree of those
 * shortcodes without running them, and keeps the raw source of every tag so
 * the tree can be written back unchanged.
 *
 * It does not need WordPress, so it can be unit tested on its own.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Shortcode tree parser.
 */
class ShortcodeParser {

	/**
	 * Tag pattern. The attribute part mirrors WordPress' get_shortcode_regex():
	 * no "]" inside a tag, and "/" only when not directly before "]".
	 */
	const TAG_PATTERN = '/\[(\/)?([A-Za-z0-9_-]+)(?=[\s\/\]])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(\/)?\]/';

	/**
	 * Shortcode name prefixes treated as Avada layout markup.
	 * Anything else stays as plain text and is preserved verbatim.
	 *
	 * @var string[]
	 */
	private $prefixes;

	/**
	 * Constructor.
	 *
	 * @param string[] $prefixes Shortcode name prefixes to parse.
	 */
	public function __construct( array $prefixes = [ 'fusion_', 'awb_' ] ) {
		$this->prefixes = $prefixes;
	}

	/**
	 * Parse markup into a root node whose children are the top-level nodes.
	 *
	 * @param string $content Post content.
	 * @return Node
	 */
	public function parse( $content ) {
		$root  = Node::shortcode( '', '', [] );
		$stack = [ $root ];
		$pos   = 0;

		preg_match_all( self::TAG_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $matches as $m ) {
			$raw    = $m[0][0];
			$offset = $m[0][1];
			$name   = strtolower( $m[2][0] );

			if ( ! $this->is_managed( $name ) || $this->is_escaped( $content, $offset, strlen( $raw ) ) ) {
				continue;
			}

			if ( $offset > $pos ) {
				$this->append( $stack, Node::text( substr( $content, $pos, $offset - $pos ) ) );
			}
			$pos = $offset + strlen( $raw );

			$is_close = '' !== $m[1][0];
			if ( $is_close ) {
				if ( ! $this->close( $stack, $name, $raw ) ) {
					// Stray closing tag: keep it as text so nothing is lost.
					$this->append( $stack, Node::text( $raw ) );
				}
				continue;
			}

			$node = Node::shortcode( $name, $raw, self::parse_attrs( $m[3][0] ) );
			$this->append( $stack, $node );

			$self_closing = isset( $m[4] ) && '/' === $m[4][0];
			if ( ! $self_closing ) {
				$stack[] = $node;
			}
		}

		if ( $pos < strlen( $content ) ) {
			$this->append( $stack, Node::text( substr( $content, $pos ) ) );
		}

		// Anything still open never got a closing tag: it is a void shortcode.
		while ( count( $stack ) > 1 ) {
			$this->make_void( $stack );
		}

		return $root;
	}

	/**
	 * Parse an attribute string the way WordPress' shortcode_parse_atts() does.
	 * Positional (unnamed) values are kept under numeric keys.
	 *
	 * @param string $text Attribute text.
	 * @return array<string|int,string>
	 */
	public static function parse_attrs( $text ) {
		$atts    = [];
		$pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
		$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );

		if ( ! preg_match_all( $pattern, $text, $found, PREG_SET_ORDER ) ) {
			return $atts;
		}
		foreach ( $found as $m ) {
			if ( ! empty( $m[1] ) ) {
				$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
			} elseif ( ! empty( $m[3] ) ) {
				$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
			} elseif ( ! empty( $m[5] ) ) {
				$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
			} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
				$atts[] = stripcslashes( $m[7] );
			} elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
				$atts[] = stripcslashes( $m[8] );
			} elseif ( isset( $m[9] ) ) {
				$atts[] = stripcslashes( $m[9] );
			}
		}
		return $atts;
	}

	/**
	 * Whether a shortcode name is parsed as layout markup.
	 *
	 * @param string $name Shortcode name.
	 * @return bool
	 */
	private function is_managed( $name ) {
		foreach ( $this->prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WordPress treats [[tag]] as an escaped, literal tag.
	 *
	 * @param string $content Full content.
	 * @param int    $offset  Tag offset.
	 * @param int    $length  Tag length.
	 * @return bool
	 */
	private function is_escaped( $content, $offset, $length ) {
		$before = $offset > 0 ? $content[ $offset - 1 ] : '';
		$after  = $offset + $length < strlen( $content ) ? $content[ $offset + $length ] : '';
		return '[' === $before && ']' === $after;
	}

	/**
	 * Append a node to the innermost open shortcode.
	 *
	 * @param Node[] $stack Open nodes.
	 * @param Node   $node  Node to append.
	 * @return void
	 */
	private function append( array $stack, Node $node ) {
		$stack[ count( $stack ) - 1 ]->children[] = $node;
	}

	/**
	 * Close the nearest open shortcode with this name.
	 *
	 * @param Node[] $stack Open nodes (by reference).
	 * @param string $name  Shortcode name.
	 * @param string $raw   Raw closing tag.
	 * @return bool False when nothing open matches.
	 */
	private function close( array &$stack, $name, $raw ) {
		for ( $i = count( $stack ) - 1; $i > 0; $i-- ) {
			if ( $stack[ $i ]->name === $name ) {
				while ( count( $stack ) - 1 > $i ) {
					$this->make_void( $stack );
				}
				$closed            = array_pop( $stack );
				$closed->raw_close = $raw;
				return true;
			}
		}
		return false;
	}

	/**
	 * Pop the innermost open node as void: its children become its next siblings.
	 *
	 * @param Node[] $stack Open nodes (by reference).
	 * @return void
	 */
	private function make_void( array &$stack ) {
		$node           = array_pop( $stack );
		$children       = $node->children;
		$node->children = [];
		foreach ( $children as $child ) {
			$this->append( $stack, $child );
		}
	}
}
