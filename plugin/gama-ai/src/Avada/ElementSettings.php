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
	 * Settings an AI request is likely to need, instead of every setting of
	 * every element type on the page. A container alone has about 180
	 * settings, most with long lists of choices, so sending them all costs
	 * thousands of tokens on every request.
	 *
	 * Included: the types inside the part of the page being edited, plus the
	 * insertable types when the request asks to add something. For each type,
	 * only the settings whose names share a word with the request (after
	 * common synonyms, such as "colour" for color or "rounded" for radius),
	 * and the element's text setting. A request that names no setting gets
	 * no settings at all, which is enough to change text, remove or move.
	 *
	 * @param Registry $registry    Registry.
	 * @param Node[]   $scope       Elements being edited (with everything in them).
	 * @param string   $instruction The request.
	 * @return array<string,array<string,string|string[]>>
	 */
	public static function for_request( Registry $registry, array $scope, $instruction ) {
		$types = [];
		foreach ( $scope as $node ) {
			$types[] = $node->name;
			self::collect_types( $node, $types );
		}
		$words = self::words( $instruction );
		if ( array_intersect( $words, self::INSERT_WORDS ) ) {
			$types = array_merge( $types, self::INSERTABLE );
		}
		$keys       = self::keys( $words );
		$responsive = (bool) array_intersect( $words, [ 'mobile', 'tablet', 'phone', 'phones', 'responsive', 'screen', 'screens' ] );
		$hover      = in_array( 'hover', $words, true );

		// Score each setting by how many request words its name has. When
		// some setting has several ("background" and "color" in
		// background_color), settings with fewer are left out, so a
		// background colour request does not bring every background_* and
		// *_color setting.
		$scores = [];
		foreach ( array_unique( $types ) as $type ) {
			foreach ( $registry->params( $type ) as $name => $param ) {
				if ( ( ! $responsive && isset( $param['responsive'] ) && 'large' !== $param['responsive'] )
					|| ( ! $hover && '_hover' === substr( $name, -6 ) ) ) {
					continue; // Per-screen and hover variants only when asked for.
				}
				$score = self::score( $name, $keys );
				if ( $score > 0 ) {
					$scores[ $type ][ $name ] = $score;
				}
			}
		}
		$best = 0;
		foreach ( $scores as $named ) {
			$best = max( $best, max( $named ) );
		}

		$out = [];
		foreach ( array_unique( $types ) as $type ) {
			if ( ! $registry->has( $type ) ) {
				continue;
			}
			$settings = [];
			foreach ( $registry->params( $type ) as $name => $param ) {
				if ( 'element_content' === $name || ( isset( $scores[ $type ][ $name ] ) && $scores[ $type ][ $name ] === $best ) ) {
					$settings[ $name ] = isset( $param['options'] ) ? $param['options'] : $param['type'];
				}
			}
			$out[ $type ] = $settings;
		}
		ksort( $out );
		return $out;
	}

	/** Words that ask for a new element. */
	const INSERT_WORDS = [ 'add', 'insert', 'new', 'create', 'append', 'put', 'include', 'place', 'another' ];

	/** Words that name no setting. */
	const STOP_WORDS = [
		'the', 'and', 'for', 'with', 'from', 'into', 'this', 'that', 'these', 'those', 'make', 'change', 'set', 'please',
		'page', 'section', 'container', 'row', 'column', 'element', 'heading', 'headline', 'title', 'paragraph', 'text',
		'content', 'type', 'button', 'all', 'more', 'less', 'very', 'bit', 'little', 'should', 'want', 'would', 'like',
		'its', 'their', 'our', 'your', 'them', 'too', 'also', 'then', 'than', 'instead', 'use', 'using', 'entire', 'entirely',
	];

	/** Request words and the setting-name words they stand for. */
	const SYNONYMS = [
		'colour'      => [ 'color' ],
		'colours'     => [ 'color' ],
		'bg'          => [ 'background' ],
		'center'      => [ 'align', 'alignment' ],
		'centre'      => [ 'align', 'alignment' ],
		'centered'    => [ 'align', 'alignment' ],
		'centred'     => [ 'align', 'alignment' ],
		'left'        => [ 'align', 'alignment' ],
		'right'       => [ 'align', 'alignment' ],
		'bigger'      => [ 'size' ],
		'smaller'     => [ 'size' ],
		'larger'      => [ 'size' ],
		'big'         => [ 'size' ],
		'small'       => [ 'size' ],
		'bold'        => [ 'font', 'weight', 'typography' ],
		'italic'      => [ 'font', 'style', 'typography' ],
		'typography'  => [ 'font', 'typography' ],
		'space'       => [ 'padding', 'margin', 'spacing' ],
		'spacing'     => [ 'padding', 'margin', 'spacing' ],
		'gap'         => [ 'spacing', 'gap' ],
		'round'       => [ 'radius' ],
		'rounded'     => [ 'radius' ],
		'corner'      => [ 'radius' ],
		'corners'     => [ 'radius' ],
		'outline'     => [ 'border' ],
		'tall'        => [ 'height' ],
		'taller'      => [ 'height' ],
		'shorter'     => [ 'height' ],
		'wide'        => [ 'width', 'hundred' ],
		'wider'       => [ 'width', 'hundred' ],
		'narrow'      => [ 'width' ],
		'full'        => [ 'hundred', 'width' ],
		'url'         => [ 'link' ],
		'href'        => [ 'link' ],
		'tab'         => [ 'target' ],
		'animate'     => [ 'animation' ],
		'photo'       => [ 'image' ],
		'picture'     => [ 'image' ],
		'hide'        => [ 'hide', 'visibility' ],
		'hidden'      => [ 'hide', 'visibility' ],
		'visible'     => [ 'hide', 'visibility' ],
		'mobile'      => [ 'hide', 'visibility' ],
		'tablet'      => [ 'hide', 'visibility' ],
		'desktop'     => [ 'hide', 'visibility' ],
		'transparent' => [ 'opacity' ],
		'h1'          => [ 'size' ],
		'h2'          => [ 'size' ],
		'h3'          => [ 'size' ],
		'h4'          => [ 'size' ],
		'h5'          => [ 'size' ],
		'h6'          => [ 'size' ],
		'level'       => [ 'size' ],
	];

	/**
	 * Lowercase words of a request.
	 *
	 * @param string $instruction Request.
	 * @return string[]
	 */
	private static function words( $instruction ) {
		$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $instruction ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( $words ? $words : [] ) );
	}

	/**
	 * Setting-name words to look for.
	 *
	 * @param string[] $words Request words.
	 * @return string[]
	 */
	private static function keys( array $words ) {
		$keys = [];
		foreach ( $words as $word ) {
			if ( isset( self::SYNONYMS[ $word ] ) ) {
				$keys = array_merge( $keys, self::SYNONYMS[ $word ] );
				continue;
			}
			if ( strlen( $word ) < 3 || in_array( $word, self::STOP_WORDS, true ) || in_array( $word, self::INSERT_WORDS, true ) ) {
				continue;
			}
			$keys[] = strlen( $word ) > 4 && 's' === substr( $word, -1 ) ? substr( $word, 0, -1 ) : $word;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * How many of the words a setting name contains, such as 2 for
	 * background_color with "background" and "color". A word matches a name
	 * part exactly or, from four letters, as its start ("align" in alignment).
	 *
	 * @param string   $name Setting name.
	 * @param string[] $keys Words.
	 * @return int
	 */
	private static function score( $name, array $keys ) {
		$parts = explode( '_', $name );
		$score = 0;
		foreach ( $keys as $key ) {
			foreach ( $parts as $part ) {
				if ( $part === $key || ( strlen( $key ) >= 4 && 0 === strpos( $part, $key ) ) ) {
					++$score;
					break;
				}
			}
		}
		return $score;
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
