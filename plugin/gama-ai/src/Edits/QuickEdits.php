<?php
/**
 * Simple requests handled without the AI.
 *
 * @package GamaAI
 */

namespace GamaAI\Edits;

use GamaAI\Avada\Node;
use GamaAI\Avada\Registry;

/**
 * Turns short, unambiguous requests such as "delete the container",
 * "duplicate the second button", "hide the heading on mobile" or "show
 * this section" into edit operations directly, so they cost no AI tokens.
 *
 * Only a request that is nothing but one of these commands is handled
 * here. Anything longer or unclear goes to the AI. When the command is
 * clear but its target is not (three containers, "delete the container"),
 * the answer asks which one instead of guessing or paying the AI to guess.
 *
 * The operations are the same ones the AI sends, so they go through the
 * same EditCompiler checks and the same draft.
 */
class QuickEdits {

	/** Avada's visibility values: listed screen sizes show the element. */
	const DEVICES = [
		'small'  => 'small-visibility',
		'medium' => 'medium-visibility',
		'large'  => 'large-visibility',
	];

	/** Device words and the Avada screen size they mean. */
	const DEVICE_WORDS = [
		'mobile'        => 'small',
		'mobiles'       => 'small',
		'phone'         => 'small',
		'phones'        => 'small',
		'small screen'  => 'small',
		'small screens' => 'small',
		'tablet'        => 'medium',
		'tablets'       => 'medium',
		'medium screen' => 'medium',
		'medium screens' => 'medium',
		'desktop'       => 'large',
		'desktops'      => 'large',
		'computer'      => 'large',
		'computers'     => 'large',
		'laptop'        => 'large',
		'laptops'       => 'large',
		'large screen'  => 'large',
		'large screens' => 'large',
	];

	/** Verbs and the action they mean. */
	const VERBS = [
		'delete'      => 'delete',
		'remove'      => 'delete',
		'erase'       => 'delete',
		'get rid of'  => 'delete',
		'hatao'       => 'delete',
		'hata do'     => 'delete',
		'hatado'      => 'delete',
		'mita do'     => 'delete',
		'duplicate'   => 'duplicate',
		'clone'       => 'duplicate',
		'copy'        => 'duplicate',
		'hide'        => 'hide',
		'chupao'      => 'hide',
		'chhupao'     => 'hide',
		'chupa do'    => 'hide',
		'show'        => 'show',
		'unhide'      => 'show',
		'dikhao'      => 'show',
	];

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
	 * Handle a request if it is one of the simple commands.
	 *
	 * @param string      $instruction Request in plain words.
	 * @param Node        $root        Parsed page (its draft, if open).
	 * @param string|null $selected    Id of the element picked on the page.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}|null
	 *         Null when the AI should handle it. No operations means nothing
	 *         is changed and the summary says why.
	 */
	public function handle( $instruction, Node $root, $selected = null ) {
		$add = $this->parse_add( $instruction );
		if ( null !== $add ) {
			return $this->add_section( $add, $root, $selected );
		}

		$move = self::parse_move( $instruction );
		if ( null !== $move ) {
			return $this->move( $move, $root, $selected );
		}

		$command = $this->parse( $instruction );
		if ( null === $command ) {
			return null;
		}

		$targets = new Targets( $root );
		$target  = $targets->resolve( $command['target'], $selected );
		if ( 'unknown' === $target['status'] ) {
			return null;
		}
		if ( 'found' !== $target['status'] ) {
			return $this->no_target( $target, $command['verb'] );
		}

		$id    = $target['id'];
		$node  = $targets->node( $id );
		$label = $target['label'];
		switch ( $command['verb'] ) {
			case 'delete':
				return $this->result(
					'Deleted ' . $label . ' and everything in it.',
					[
						[
							'op'     => 'remove_element',
							'target' => $id,
						],
					]
				);
			case 'duplicate':
				return $this->result(
					'Added a copy of ' . $label . ' right after it.',
					[
						[
							'op'     => 'duplicate_element',
							'target' => $id,
						],
					]
				);
			default:
				return $this->visibility( $command['verb'], $command['device'], $id, $node, $label );
		}
	}

	/**
	 * Split a request into verb, target phrase and screen size.
	 *
	 * @param string $instruction Request.
	 * @return array{verb:string,target:string,device:string|null}|null
	 */
	public function parse( $instruction ) {
		$text = Targets::normalise( $instruction );
		if ( '' === $text || strlen( $text ) > 200 ) {
			return null;
		}
		$verbs   = implode( '|', array_map( 'preg_quote', array_keys( self::VERBS ) ) );
		$devices = implode( '|', array_map( 'preg_quote', array_keys( self::DEVICE_WORDS ) ) );
		$finish  = '(?:\s+(?:entirely|completely|fully|altogether|for\s+good|permanently))?';
		$device  = '(?:\s+(?:on|for|from|in)\s+(?:the\s+)?(' . $devices . ')(?:\s+(?:devices?|view))?)?';
		$urdu_do = '(?:\s+(?:karo|kar\s+do|kardo|karein|karen|kren|kar\s+dein|do))?';

		// English order: "delete the second container (on mobile)".
		if ( preg_match( '/^(?:(?:can|could)\s+you\s+)?(' . $verbs . ')\s+(.+?)' . $finish . $device . $finish . '$/u', $text, $m ) ) {
			return [
				'verb'   => self::VERBS[ $m[1] ],
				'target' => $m[2],
				'device' => isset( $m[3] ) && '' !== $m[3] ? self::DEVICE_WORDS[ $m[3] ] : null,
			];
		}
		// Urdu order: "container ko delete karo", "is section ko mobile par hide karo".
		$urdu_device = '(?:\s+(' . $devices . ')\s+(?:par|pe|per|mein|main))?';
		if ( preg_match( '/^(.+?)(?:\s+ko)?' . $urdu_device . '\s+(?:poora\s+|pura\s+)?(' . $verbs . ')' . $urdu_do . '$/u', $text, $m ) ) {
			return [
				'verb'   => self::VERBS[ $m[3] ],
				'target' => $m[1],
				'device' => isset( $m[2] ) && '' !== $m[2] ? self::DEVICE_WORDS[ $m[2] ] : null,
			];
		}
		return null;
	}

	/** Number words for column counts. */
	const COUNTS = [
		'one'   => 1,
		'two'   => 2,
		'three' => 3,
		'four'  => 4,
		'1'     => 1,
		'2'     => 2,
		'3'     => 3,
		'4'     => 4,
	];

	/**
	 * Read "add a section", "add a new container with 2 columns below the
	 * first section", "add an empty section at the top" and the like.
	 *
	 * @param string $instruction Request.
	 * @return array{columns:int,where:string|null,target:string|null}|null
	 */
	public function parse_add( $instruction ) {
		$text   = Targets::normalise( $instruction );
		$counts = implode( '|', array_keys( self::COUNTS ) );
		$where  = '(?:\s+(?:(below|after|under|above|before)\s+(.+?)|(?:at|to)\s+the\s+(top|bottom|end|start|beginning)(?:\s+of\s+the\s+page)?))?';
		$pattern = '/^(?:(?:can|could)\s+you\s+)?(?:add|insert|create|make)\s+(?:a|an|one)?\s*(?:(?:new|empty|blank|simple|plain)\s+)*(?:container|section)'
			. '(?:\s+with\s+(' . $counts . ')\s+(?:equal\s+)?(?:columns?|sub[\s-]?containers?))?' . $where . '$/u';
		if ( ! preg_match( $pattern, $text, $m ) ) {
			return null;
		}
		$columns = isset( $m[1] ) && '' !== $m[1] ? self::COUNTS[ $m[1] ] : 1;
		if ( isset( $m[4] ) && '' !== $m[4] ) {
			return [
				'columns' => $columns,
				'where'   => in_array( $m[4], [ 'top', 'start', 'beginning' ], true ) ? 'top' : null,
				'target'  => null,
			];
		}
		return [
			'columns' => $columns,
			'where'   => isset( $m[2] ) && '' !== $m[2] ? ( in_array( $m[2], [ 'above', 'before' ], true ) ? 'before' : 'after' ) : null,
			'target'  => isset( $m[3] ) && '' !== $m[3] ? $m[3] : null,
		];
	}

	/**
	 * Add an empty container with a row and equal columns.
	 *
	 * @param array{columns:int,where:string|null,target:string|null} $add      Parsed request.
	 * @param Node                                                    $root     Parsed page.
	 * @param string|null                                             $selected Id of the element picked on the page.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}
	 */
	private function add_section( array $add, Node $root, $selected ) {
		$index = null;
		$place = 'at the end of the page';
		if ( 'top' === $add['where'] ) {
			$index = 0;
			$place = 'at the top of the page';
		} elseif ( null !== $add['where'] ) {
			$targets = new Targets( $root );
			$target  = $targets->resolve( $add['target'], $selected );
			if ( 'unknown' === $target['status'] ) {
				return $this->result( 'Say where with a section, for example "below the second section", or pick one with "Pick a section". Nothing was changed.', [] );
			}
			if ( 'found' !== $target['status'] ) {
				return $this->no_target( $target, 'add a section below' );
			}
			$top   = (int) strtok( $target['id'], '.' );
			$index = 'before' === $add['where'] ? $top : $top + 1;
			$place = ( 'before' === $add['where'] ? 'above ' : 'below ' ) . $targets->label( (string) $top );
		}

		$container = [
			'op'     => 'insert_element',
			'parent' => '',
			'type'   => 'fusion_builder_container',
			'ref'    => 'new_section',
		];
		if ( null !== $index ) {
			$container['index'] = $index;
		}
		$operations = [
			$container,
			[
				'op'     => 'insert_element',
				'parent' => 'new_section',
				'type'   => 'fusion_builder_row',
				'ref'    => 'new_row',
			],
		];
		$width = [
			1 => '1_1',
			2 => '1_2',
			3 => '1_3',
			4 => '1_4',
		];
		for ( $i = 0; $i < $add['columns']; $i++ ) {
			$operations[] = [
				'op'     => 'insert_element',
				'parent' => 'new_row',
				'type'   => 'fusion_builder_column',
				'attrs'  => [ 'type' => $width[ $add['columns'] ] ],
			];
		}
		$columns = 1 === $add['columns'] ? 'one full-width column' : $add['columns'] . ' equal columns';
		return $this->result( 'Added an empty section with ' . $columns . ' ' . $place . '. Ask for content to fill it, or edit it in Avada Builder.', $operations );
	}

	/**
	 * Hide or show an element.
	 *
	 * Without a screen size, a container is hidden with its native status
	 * setting (Draft: visitors do not see it, Avada Builder still does). Other
	 * elements have no such setting, so hiding them everywhere is not done
	 * here. With a screen size, the native visibility setting
	 * (`hide_on_mobile`, which lists the screen sizes that show the element)
	 * loses or gains that size.
	 *
	 * @param string      $verb   hide or show.
	 * @param string|null $device small, medium, large or null.
	 * @param string      $id     Path id.
	 * @param Node        $node   Element.
	 * @param string      $label  Words for the element.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}
	 */
	private function visibility( $verb, $device, $id, Node $node, $label ) {
		$params = $this->registry->params( $node->name );

		if ( null === $device ) {
			if ( ! isset( $params['status']['options'] ) || ! in_array( 'draft', $params['status']['options'], true ) ) {
				return $this->result( 'Avada can hide ' . $label . ' only per screen size. Say for example "' . $verb . ' ' . $label . ' on mobile", or ask to delete it.', [] );
			}
			$current = $this->value( $node, 'status', $params );
			$wanted  = 'hide' === $verb ? 'draft' : 'published';
			if ( $current === $wanted || ( 'show' === $verb && 'draft' !== $current ) ) {
				return $this->result( ucfirst( $label ) . ' is already ' . ( 'hide' === $verb ? 'hidden from visitors' : 'shown to visitors' ) . '. Nothing was changed.', [] );
			}
			return $this->result(
				'hide' === $verb
					? 'Hid ' . $label . ' from visitors by setting its Avada status to Draft. It stays on the page in Avada Builder and can be shown again.'
					: 'Set ' . $label . ' back to Published, so visitors see it again.',
				[ $this->set( $id, 'status', $wanted ) ]
			);
		}

		if ( ! isset( $params['hide_on_mobile'] ) ) {
			return $this->result( ucfirst( $label ) . ' has no screen size visibility setting in Avada. Nothing was changed.', [] );
		}
		$shown = array_filter( explode( ',', $this->value( $node, 'hide_on_mobile', $params ) ) );
		$token = self::DEVICES[ $device ];
		$words = [
			'small'  => 'mobile',
			'medium' => 'tablet',
			'large'  => 'desktop',
		];
		if ( 'hide' === $verb ) {
			if ( ! in_array( $token, $shown, true ) ) {
				return $this->result( ucfirst( $label ) . ' is already hidden on ' . $words[ $device ] . '. Nothing was changed.', [] );
			}
			$shown = array_values( array_diff( $shown, [ $token ] ) );
			if ( ! $shown ) {
				return $this->result( 'That would hide ' . $label . ' on every screen size. Ask to delete it instead, or hide it on one screen size less.', [] );
			}
		} else {
			if ( in_array( $token, $shown, true ) ) {
				return $this->result( ucfirst( $label ) . ' is already shown on ' . $words[ $device ] . '. Nothing was changed.', [] );
			}
			$shown[] = $token;
		}
		// Keep Avada's own order: small, medium, large.
		$ordered = array_values( array_intersect( array_values( self::DEVICES ), $shown ) );
		return $this->result(
			( 'hide' === $verb ? 'Hid ' : 'Showed ' ) . $label . ' on ' . $words[ $device ] . ' screens.',
			[ $this->set( $id, 'hide_on_mobile', implode( ',', $ordered ) ) ]
		);
	}

	/**
	 * What to say when the target is missing or unclear.
	 *
	 * @param array<string,mixed> $target Result of Targets::resolve().
	 * @param string              $verb   Action.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}
	 */
	private function no_target( array $target, $verb ) {
		$word  = isset( $target['word'] ) ? $target['word'] : 'element';
		$count = isset( $target['count'] ) ? (int) $target['count'] : 0;
		if ( 'element' === $word && 0 === $count ) {
			return $this->result( 'Pick the part of the page first with "+ Pin", or name it, for example "' . $verb . ' the second container".', [] );
		}
		if ( 'ambiguous' === $target['status'] ) {
			return $this->result( 'This page has ' . $count . ' ' . $word . 's. Say which one, for example "' . $verb . ' the second ' . $word . '" or "' . $verb . ' the last ' . $word . '", or pin it with "+ Pin". Nothing was changed.', [] );
		}
		return $this->result( 0 === $count ? 'There is no ' . $word . ' like that on this page. Nothing was changed.' : 'This page has only ' . $count . ' ' . $word . ( 1 === $count ? '' : 's' ) . '. Nothing was changed.', [] );
	}

	/**
	 * A setting's current value: as set on the element, else Avada's default.
	 *
	 * @param Node                              $node   Element.
	 * @param string                            $name   Setting.
	 * @param array<string,array<string,mixed>> $params Registry params of the element.
	 * @return string
	 */
	private function value( Node $node, $name, array $params ) {
		if ( isset( $node->attrs[ $name ] ) && '' !== $node->attrs[ $name ] ) {
			return (string) $node->attrs[ $name ];
		}
		return isset( $params[ $name ]['default'] ) ? (string) $params[ $name ]['default'] : '';
	}

	/**
	 * A set_attribute operation.
	 *
	 * @param string $id    Path id.
	 * @param string $name  Setting.
	 * @param string $value Value.
	 * @return array<string,string>
	 */
	private function set( $id, $name, $value ) {
		return [
			'op'     => 'set_attribute',
			'target' => $id,
			'name'   => $name,
			'value'  => $value,
		];
	}

	/** Where words for moving an element put it. */
	const MOVE_PLACES = [
		'above'         => 'before',
		'before'        => 'before',
		'on top of'     => 'before',
		'over'          => 'before',
		'below'         => 'after',
		'after'         => 'after',
		'under'         => 'after',
		'underneath'    => 'after',
		'beneath'       => 'after',
		'to the top'    => 'first',
		'to the start'  => 'first',
		'first'         => 'first',
		'to the bottom' => 'last',
		'to the end'    => 'last',
		'last'          => 'last',
		'up'            => 'up',
		'down'          => 'down',
	];

	/**
	 * Read "move <element> above|below <element>", "move <element> to the
	 * top|bottom" and "move <element> up|down". Trailing words such as "of
	 * this container" only say where both are, so they are dropped.
	 *
	 * @param string $instruction Request.
	 * @return array{target:string,place:string,other:string|null}|null
	 */
	public static function parse_move( $instruction ) {
		$text   = Targets::normalise( $instruction );
		$places = implode( '|', array_map( 'preg_quote', array_keys( self::MOVE_PLACES ) ) );
		if ( ! preg_match( '/^(?:move|shift|put|place)\s+(.+?)\s+(' . $places . ')(?:\s+(.+?))?$/u', $text, $m ) ) {
			return null;
		}
		$place = self::MOVE_PLACES[ $m[2] ];
		$other = isset( $m[3] ) ? trim( (string) preg_replace( '/\s+(?:of|in|inside|within)\s+(?:this|that|the|its|it\'s)\s+(?:container|section|column|row|box)$/u', '', $m[3] ) ) : '';
		$other = trim( (string) preg_replace( '/^(?:of|in)\s+(?:this|that|the|its)\s+(?:container|section|column|row|box)$/u', '', $other ) );
		$needs = in_array( $place, [ 'before', 'after' ], true );
		if ( $needs !== ( '' !== $other ) ) {
			return null;
		}
		return [
			'target' => $m[1],
			'place'  => $place,
			'other'  => '' === $other ? null : $other,
		];
	}

	/**
	 * Move an element within its own column, row or container.
	 *
	 * @param array{target:string,place:string,other:string|null} $move     parse_move() result.
	 * @param Node                                                $root     Parsed page.
	 * @param string|null                                         $selected Picked element.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}|null
	 */
	private function move( array $move, Node $root, $selected ) {
		$targets = new Targets( $root );
		$target  = $targets->resolve( $move['target'], $selected );
		if ( 'unknown' === $target['status'] ) {
			return null;
		}
		if ( 'found' !== $target['status'] ) {
			return $this->no_target( $target, 'move' );
		}
		$id     = $target['id'];
		$dot    = strrpos( $id, '.' );
		$parent = false === $dot ? '' : substr( $id, 0, $dot );
		$own    = (int) ( false === $dot ? $id : substr( $id, $dot + 1 ) );
		$count  = count( ( '' === $parent ? $root : $targets->node( $parent ) )->shortcode_children() );

		if ( 'first' === $move['place'] ) {
			$index = 0;
		} elseif ( 'last' === $move['place'] ) {
			$index = $count - 1;
		} elseif ( 'up' === $move['place'] ) {
			$index = max( 0, $own - 1 );
		} elseif ( 'down' === $move['place'] ) {
			$index = min( $count - 1, $own + 1 );
		} else {
			$other = $this->sibling( $targets, $move['other'], $parent, $id );
			if ( is_string( $other ) && ! ctype_digit( $other ) ) {
				return $this->result( $other, [] );
			}
			$index = (int) $other;
			if ( $index > $own ) {
				--$index; // Positions count without the moved element.
			}
			if ( 'after' === $move['place'] ) {
				++$index;
			}
		}
		if ( $index === $own ) {
			return $this->result( ucfirst( $target['label'] ) . ' is already there, so nothing was changed.', [] );
		}
		return $this->result(
			'Moved ' . $target['label'] . ( null !== $move['other'] ? ' ' . ( 'before' === $move['place'] ? 'above' : 'below' ) . ' ' . $move['other'] : ( $index < $own ? ' up' : ' down' ) ) . '.',
			[
				[
					'op'     => 'move_element',
					'target' => $id,
					'index'  => $index,
				],
			]
		);
	}

	/**
	 * The position of the element a phrase names among the moved element's
	 * siblings, such as "the heading" in the same column.
	 *
	 * @param Targets $targets Page targets.
	 * @param string  $phrase  Phrase.
	 * @param string  $parent  Parent path id ('' for the page root).
	 * @param string  $moved   Id of the element being moved.
	 * @return string Position as digits, or words saying why it is not clear.
	 */
	private function sibling( Targets $targets, $phrase, $parent, $moved ) {
		$words    = implode( '|', array_keys( Targets::WORDS ) );
		$ordinals = implode( '|', array_keys( Targets::ORDINALS ) );
		if ( ! preg_match( '/^(?:(?:the|this|that|its)\s+)?(?:(' . $ordinals . '|\d+)(?:st|nd|rd|th)?\s+)?(' . $words . ')s?(?:\s+["\x{201C}\']([^"\x{201D}\']+)["\x{201D}\'])?$/u', $phrase, $m ) ) {
			return 'Gama AI could not tell which element "' . $phrase . '" is. Name it like "the heading" or "the button".';
		}
		$types = Targets::WORDS[ $m[2] ];
		$found = [];
		$node  = '' === $parent ? null : $targets->node( $parent );
		$kids  = null === $node ? [] : $node->shortcode_children();
		foreach ( $kids as $i => $child ) {
			$id = ( '' === $parent ? '' : $parent . '.' ) . $i;
			if ( $id !== $moved && in_array( $child->name, $types, true )
				&& ( ! isset( $m[3] ) || '' === $m[3] || false !== stripos( $targets->text( $child ), $m[3] ) ) ) {
				$found[] = $i;
			}
		}
		if ( isset( $m[1] ) && '' !== $m[1] ) {
			$n     = isset( Targets::ORDINALS[ $m[1] ] ) ? Targets::ORDINALS[ $m[1] ] : (int) $m[1];
			$pick  = $n < 0 ? count( $found ) + $n : $n - 1;
			$found = isset( $found[ $pick ] ) ? [ $found[ $pick ] ] : [];
		}
		if ( 1 === count( $found ) ) {
			return (string) $found[0];
		}
		if ( ! $found ) {
			return 'There is no ' . $m[2] . ' next to it in the same ' . ( null === $node ? 'page' : str_replace( 'fusion_builder_', '', $node->name ) ) . '. Gama AI moves elements within their own column for now.';
		}
		return 'There are ' . count( $found ) . ' ' . $m[2] . 's next to it. Say which one, for example "the first ' . $m[2] . '".';
	}

	/**
	 * Result shape.
	 *
	 * @param string                         $summary    Words for the site owner.
	 * @param array<int,array<string,mixed>> $operations Operations.
	 * @return array{summary:string,operations:array<int,array<string,mixed>>}
	 */
	private function result( $summary, array $operations ) {
		return [
			'summary'    => $summary,
			'operations' => $operations,
		];
	}
}
