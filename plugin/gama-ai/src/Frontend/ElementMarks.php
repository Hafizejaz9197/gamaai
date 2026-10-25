<?php
/**
 * Marks each rendered Avada element with its path id.
 *
 * @package GamaAI
 */

namespace GamaAI\Frontend;

use GamaAI\Avada\Node;
use GamaAI\Avada\ShortcodeParser;

/**
 * While the page's own content is rendered for an editor, adds
 * data-gama-id="0.1.2" and data-gama-type to the first HTML tag each Avada
 * shortcode outputs, so a click on the page can be turned into the element
 * id that edits use. Nothing is saved: only that response's HTML changes.
 *
 * Shortcodes are rendered parent first (pre_do_shortcode_tag), children
 * inside, then the parent's output is done (do_shortcode_tag), so a stack
 * gives each one its path id. Every id is checked against the parsed
 * layout; at the first difference marking stops, so a mark is never wrong.
 */
class ElementMarks {

	/** @var Node|null Parsed layout of the content being rendered. */
	private $root;

	/** @var array<int,array{id:string|null,children:int}> Open shortcodes. */
	private $stack = [];

	/** @var bool Marking stopped after a difference from the layout. */
	private $broken = false;

	/** @var int Root elements seen so far. */
	private $top = 0;

	/** @var array<int,string>|null What happened, when tracing (for diagnosis). */
	public $trace = null;

	/**
	 * Start marking a render of this layout.
	 *
	 * @param string $content Layout markup about to be rendered.
	 * @return void
	 */
	public function begin( $content ) {
		$this->root   = ( new ShortcodeParser() )->parse( (string) $content );
		$this->stack  = [];
		$this->broken = false;
		$this->top    = 0;
	}

	/**
	 * Stop marking.
	 *
	 * @return void
	 */
	public function end() {
		$this->root  = null;
		$this->stack = [];
	}

	/**
	 * Is a render being marked?
	 *
	 * @return bool
	 */
	public function active() {
		return null !== $this->root && ! $this->broken;
	}

	/**
	 * A shortcode starts rendering.
	 *
	 * @param string $tag Shortcode name.
	 * @return void
	 */
	public function open( $tag ) {
		if ( null === $this->root || ! self::avada( $tag ) ) {
			return;
		}
		$id = null;
		if ( ! $this->broken ) {
			if ( $this->stack ) {
				$parent = &$this->stack[ count( $this->stack ) - 1 ];
				$id     = null === $parent['id'] ? null : $parent['id'] . '.' . $parent['children'];
				++$parent['children'];
				unset( $parent );
			} else {
				$id = (string) $this->top;
				++$this->top;
			}
			$node = null === $id ? null : $this->node( $id );
			if ( null !== $this->trace ) {
				$this->trace[] = 'open ' . $tag . ' as ' . $id . ' (layout has ' . ( $node ? $node->name : 'nothing' ) . ')';
			}
			if ( null === $node || $node->name !== $tag ) {
				$this->broken = true;
				$id           = null;
			}
		}
		$this->stack[] = [
			'id'       => $id,
			'children' => 0,
		];
	}

	/**
	 * A shortcode finished rendering: mark its output.
	 *
	 * @param string $output HTML.
	 * @param string $tag    Shortcode name.
	 * @return string
	 */
	public function close( $output, $tag ) {
		if ( null === $this->root || ! $this->stack || ! self::avada( $tag ) ) {
			return $output;
		}
		$entry = array_pop( $this->stack );
		if ( null !== $this->trace && 'fusion_imageframe' === $tag ) {
			$this->trace[] = 'close ' . $tag . ' ' . $entry['id'] . ': ' . substr( (string) $output, 0, 160 );
		}
		if ( null === $entry['id'] || ! is_string( $output ) ) {
			return $output;
		}
		// The first real element, skipping inline <style>, <script> and comments some elements print first.
		return (string) preg_replace(
			'/<(?!(?:style|script|link|noscript)\b)([a-z][a-z0-9-]*)(?=[\s>\/])/i',
			'<$1 data-gama-id="' . $entry['id'] . '" data-gama-type="' . preg_replace( '/[^a-z0-9_]/', '', $tag ) . '"',
			$output,
			1
		);
	}

	/**
	 * Is this an Avada shortcode, as the layout parser reads them?
	 *
	 * @param string $tag Shortcode name.
	 * @return bool
	 */
	private static function avada( $tag ) {
		return 0 === strpos( (string) $tag, 'fusion_' ) || 0 === strpos( (string) $tag, 'awb_' );
	}

	/**
	 * The layout node with a path id.
	 *
	 * @param string $id Path id.
	 * @return Node|null
	 */
	private function node( $id ) {
		$node = $this->root;
		foreach ( explode( '.', $id ) as $index ) {
			$children = $node->shortcode_children();
			if ( ! isset( $children[ (int) $index ] ) ) {
				return null;
			}
			$node = $children[ (int) $index ];
		}
		return $node;
	}
}
