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
			return $this->result( 'Pick the part of the page first with "Pick a section", or name it, for example "' . $verb . ' the second container".', [] );
		}
		if ( 'ambiguous' === $target['status'] ) {
			return $this->result( 'This page has ' . $count . ' ' . $word . 's. Say which one, for example "' . $verb . ' the second ' . $word . '" or "' . $verb . ' the last ' . $word . '", or pick it with "Pick a section". Nothing was changed.', [] );
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
