<?php
/**
 * Turns typed edit operations into Avada layout markup.
 *
 * @package GamaAI
 */

namespace GamaAI\Edits;

use GamaAI\Avada\Node;
use GamaAI\Avada\Registry;
use GamaAI\Avada\Serializer;
use GamaAI\Avada\ShortcodeParser;

/**
 * Applies a list of edit operations to a page's markup.
 *
 * The AI never writes shortcodes. It sends operations that point at
 * elements by the path ids of the page model (PageModel), and each one is
 * checked against the Avada registry before anything changes:
 *
 * - replace_text     { target, text }
 * - set_attribute    { target, name, value }
 * - insert_element   { parent, index?, type, attrs?, text?, ref? }   parent "" = page root
 * - remove_element   { target }
 * - move_element     { target, index }                          within the same parent
 * - duplicate_element { target }                                 copy placed right after it
 *
 * Ids always refer to the page as the model showed it, so earlier
 * operations in the same list do not shift them. An inserted element can
 * be given a `ref` name, which later operations in the list use as a
 * `parent` or `target`, so a whole new section (container, row, columns,
 * content) can be built in one list. A new container or row left without
 * children gets Avada's basic structure: a row, and a full-width column. Indexes for insert and
 * move count the parent's elements at the time the operation runs. The
 * operations are all-or-nothing: the first invalid one throws and no
 * markup is returned.
 */
class EditCompiler {

	const MAX_OPERATIONS   = 50;
	const MAX_VALUE_LENGTH = 2000;
	const MAX_TEXT_LENGTH  = 20000;

	/** Structural elements and the parents they may be placed in. '' is the page root. */
	const STRUCTURE = [
		'fusion_builder_container'    => [ '' ],
		'fusion_builder_row'          => [ 'fusion_builder_container' ],
		'fusion_builder_column'       => [ 'fusion_builder_row' ],
		'fusion_builder_row_inner'    => [ 'fusion_builder_column' ],
		'fusion_builder_column_inner' => [ 'fusion_builder_row_inner' ],
	];

	/** Parents that hold content elements such as titles, text and images. */
	const CONTENT_PARENTS = [ 'fusion_builder_column', 'fusion_builder_column_inner' ];

	/** Setting types whose element_content may be replaced with text. */
	const TEXT_TYPES = [ 'tinymce', 'textarea', 'textfield' ];

	/** Setting types whose element_content is a file URL, such as an image element's image. */
	const URL_TYPES = [ 'upload' ];

	/** @var Registry */
	private $registry;

	/** @var callable(string):string */
	private $sanitize_text;

	/** @var callable(string,array):string */
	private $sanitize_attr;

	/** @var \SplObjectStorage Node => parent Node. */
	private $parents;

	/** @var \SplObjectStorage Removed nodes. */
	private $removed;

	/** @var Node */
	private $root;

	/** @var array<string,Node> Elements inserted in this run, by their ref name. */
	private $refs = [];

	/** @var \SplObjectStorage Elements inserted in this run. */
	private $inserted;

	/** Children a new structural element gets when it is left empty. */
	const SCAFFOLD = [
		'fusion_builder_container' => [ 'fusion_builder_row', [] ],
		'fusion_builder_row'       => [ 'fusion_builder_column', [ 'type' => '1_1' ] ],
		'fusion_builder_row_inner' => [ 'fusion_builder_column_inner', [ 'type' => '1_1' ] ],
	];

	/**
	 * Constructor.
	 *
	 * @param Registry      $registry      Avada registry of the running builder version.
	 * @param callable|null $sanitize_text Cleans element text (HTML allowed), e.g. wp_kses_post.
	 * @param callable|null $sanitize_attr Cleans a setting value; receives the value and the registry param.
	 */
	public function __construct( Registry $registry, $sanitize_text = null, $sanitize_attr = null ) {
		$identity            = function ( $value ) {
			return $value;
		};
		$this->registry      = $registry;
		$this->sanitize_text = $sanitize_text ? $sanitize_text : $identity;
		$this->sanitize_attr = $sanitize_attr ? $sanitize_attr : $identity;
	}

	/**
	 * Apply operations to markup.
	 *
	 * @param string                           $content    Current markup.
	 * @param array<int,array<string,mixed>> $operations Operations.
	 * @return string New markup.
	 * @throws EditException When an operation is invalid.
	 */
	public function apply( $content, array $operations ) {
		if ( ! $operations ) {
			throw new EditException( 'No operations given.' );
		}
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			throw new EditException( 'Too many operations; the limit is ' . self::MAX_OPERATIONS . '.' );
		}

		$this->root    = ( new ShortcodeParser() )->parse( $content );
		$this->parents = new \SplObjectStorage();
		$this->removed  = new \SplObjectStorage();
		$this->inserted = new \SplObjectStorage();
		$this->refs     = [];
		$ids            = [];
		$this->index( $this->root, '', $ids );

		foreach ( array_values( $operations ) as $i => $op ) {
			try {
				$this->run( is_array( $op ) ? $op : [], $ids );
			} catch ( EditException $e ) {
				throw new EditException( 'Operation ' . $i . ': ' . $e->getMessage(), $i );
			}
		}

		foreach ( $this->inserted as $node ) {
			$this->scaffold( $node );
		}

		$serializer = new Serializer();
		$out        = $serializer->serialize( $this->root );
		if ( $serializer->serialize( ( new ShortcodeParser() )->parse( $out ) ) !== $out ) {
			throw new EditException( 'The edited layout would not read back unchanged.' );
		}
		return $out;
	}

	/**
	 * Run one operation.
	 *
	 * @param array<string,mixed> $op  Operation.
	 * @param array<string,Node>  $ids Path id => node, from the original page.
	 * @return void
	 * @throws EditException When the operation is invalid.
	 */
	private function run( array $op, array $ids ) {
		$name = isset( $op['op'] ) ? $op['op'] : '';
		switch ( $name ) {
			case 'replace_text':
				$this->replace_text( $this->target( $op, $ids ), $this->string_field( $op, 'text', self::MAX_TEXT_LENGTH ) );
				return;
			case 'set_attribute':
				$node  = $this->target( $op, $ids );
				$key   = $this->string_field( $op, 'name', 100 );
				$value = $this->string_field( $op, 'value', self::MAX_VALUE_LENGTH );
				$node->set_attr( $key, $this->checked_attr( $node->name, $key, $value ) );
				return;
			case 'insert_element':
				$this->insert( $op, $ids );
				return;
			case 'remove_element':
				$this->remove( $this->target( $op, $ids ) );
				return;
			case 'move_element':
				$node = $this->target( $op, $ids );
				$this->place( $this->parents[ $node ], $node, $this->index_field( $op ) );
				return;
			case 'duplicate_element':
				$this->duplicate( $this->target( $op, $ids ) );
				return;
			default:
				throw new EditException( 'Unknown operation "' . (string) $name . '".' );
		}
	}

	/**
	 * Replace an element's text.
	 *
	 * @param Node   $node Element.
	 * @param string $text New text (HTML allowed).
	 * @return void
	 * @throws EditException When the element has no text or holds other elements.
	 */
	private function replace_text( Node $node, $text ) {
		$this->require_text_param( $node->name );
		if ( $node->shortcode_children() ) {
			throw new EditException( $node->name . ' holds other elements; edit those instead.' );
		}
		$node->children = [ Node::text( $this->clean_content( $node->name, $text ) ) ];
		if ( null === $node->raw_close ) {
			$node->raw_close = '[/' . $node->name . ']';
			$node->dirty     = true; // Drops a self-closing slash from the opening tag.
		}
	}

	/**
	 * Insert a new element.
	 *
	 * @param array<string,mixed> $op  Operation.
	 * @param array<string,Node>  $ids Path ids.
	 * @return void
	 * @throws EditException When the element or its place is not allowed.
	 */
	private function insert( array $op, array $ids ) {
		$type = $this->string_field( $op, 'type', 100 );
		if ( ! $this->known( $type ) ) {
			throw new EditException( 'Unknown element "' . $type . '".' );
		}

		$parent_id = isset( $op['parent'] ) ? (string) $op['parent'] : '';
		$parent    = '' === $parent_id ? $this->root : $this->lookup( $parent_id, $ids );
		$this->require_placement( $type, $parent );

		$attrs = isset( $op['attrs'] ) ? $op['attrs'] : [];
		if ( ! is_array( $attrs ) ) {
			throw new EditException( '"attrs" must be an object.' );
		}
		$checked = [];
		foreach ( $attrs as $key => $value ) {
			if ( ! is_scalar( $value ) || strlen( (string) $value ) > self::MAX_VALUE_LENGTH ) {
				throw new EditException( 'Setting "' . $key . '" must be a short text value.' );
			}
			$checked[ (string) $key ] = $this->checked_attr( $type, (string) $key, (string) $value );
		}

		$node            = Node::shortcode( $type, '', $checked );
		$node->raw_close = '[/' . $type . ']';
		$node->dirty     = true;
		if ( isset( $op['text'] ) ) {
			$this->require_text_param( $type );
			$node->children[] = Node::text( $this->clean_content( $type, $this->string_field( $op, 'text', self::MAX_TEXT_LENGTH ) ) );
		}

		$index = array_key_exists( 'index', $op ) ? $this->index_field( $op ) : PHP_INT_MAX;
		$this->place( $parent, $node, $index );
		$this->inserted->attach( $node );

		if ( isset( $op['ref'] ) && '' !== $op['ref'] ) {
			$ref = $this->string_field( $op, 'ref', 40 );
			if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/i', $ref ) ) {
				throw new EditException( '"ref" must start with a letter and use only letters, digits, - and _.' );
			}
			if ( isset( $this->refs[ $ref ] ) ) {
				throw new EditException( 'The ref "' . $ref . '" is already used in this list.' );
			}
			$this->refs[ $ref ] = $node;
		}
	}

	/**
	 * Whether an element type exists. Avada does not map
	 * fusion_builder_row with fusion_builder_map() (it has no settings of
	 * its own in Avada 3.15.6), so the layout types are known even when the
	 * registry lacks them.
	 *
	 * @param string $type Shortcode name.
	 * @return bool
	 */
	private function known( $type ) {
		return $this->registry->has( $type ) || isset( self::STRUCTURE[ $type ] );
	}

	/**
	 * Give a new container or row that was left empty Avada's basic
	 * structure, as Avada Builder does when one is added: a row, and in it
	 * one full-width column. Without it the container shows nothing and
	 * nothing can be placed in it.
	 *
	 * @param Node $node Inserted element.
	 * @return void
	 * @throws EditException When the registry does not know the column width setting.
	 */
	private function scaffold( Node $node ) {
		if ( $this->removed->contains( $node ) || ! isset( self::SCAFFOLD[ $node->name ] ) || $node->shortcode_children() ) {
			return;
		}
		list( $type, $attrs ) = self::SCAFFOLD[ $node->name ];
		if ( ! $this->known( $type ) ) {
			return;
		}
		$checked = [];
		foreach ( $attrs as $key => $value ) {
			$checked[ $key ] = $this->checked_attr( $type, $key, $value );
		}
		$child            = Node::shortcode( $type, '', $checked );
		$child->raw_close = '[/' . $type . ']';
		$child->dirty     = true;
		$this->place( $node, $child, PHP_INT_MAX );
		$this->scaffold( $child );
	}

	/**
	 * Remove an element and everything in it.
	 *
	 * @param Node $node Element.
	 * @return void
	 */
	private function remove( Node $node ) {
		$parent           = $this->parents[ $node ];
		$parent->children = array_values(
			array_filter(
				$parent->children,
				function ( Node $child ) use ( $node ) {
					return $child !== $node;
				}
			)
		);
		$this->mark_removed( $node );
	}

	/**
	 * Copy an element with everything in it and place the copy right after
	 * it. HTML ids (`id`, `menu_anchor`) are cleared in the copy, because a
	 * page must not have the same id twice.
	 *
	 * @param Node $node Element.
	 * @return void
	 */
	private function duplicate( Node $node ) {
		$parent = $this->parents[ $node ];
		$copy   = $node->deep_copy();
		$this->clear_html_ids( $copy );
		$index = array_search( $node, $parent->shortcode_children(), true );
		$this->place( $parent, $copy, (int) $index + 1 );
		$this->register_parents( $copy );
	}

	/**
	 * Clear `id` and `menu_anchor` settings in a tree.
	 *
	 * @param Node $node Node.
	 * @return void
	 */
	private function clear_html_ids( Node $node ) {
		foreach ( [ 'id', 'menu_anchor' ] as $key ) {
			if ( isset( $node->attrs[ $key ] ) && '' !== $node->attrs[ $key ] ) {
				unset( $node->attrs[ $key ] );
				$node->dirty = true;
			}
		}
		foreach ( $node->shortcode_children() as $child ) {
			$this->clear_html_ids( $child );
		}
	}

	/**
	 * Record the parents of a new subtree, so later operations can move it.
	 *
	 * @param Node $node Node.
	 * @return void
	 */
	private function register_parents( Node $node ) {
		foreach ( $node->shortcode_children() as $child ) {
			$this->parents[ $child ] = $node;
			$this->register_parents( $child );
		}
	}

	/**
	 * Put a node at an element index of a parent, taking it out of the
	 * parent first if it is already there.
	 *
	 * @param Node $parent Parent.
	 * @param Node $node   Node.
	 * @param int  $index  Position among the parent's elements; past the end appends.
	 * @return void
	 */
	private function place( Node $parent, Node $node, $index ) {
		$children = array_values(
			array_filter(
				$parent->children,
				function ( Node $child ) use ( $node ) {
					return $child !== $node;
				}
			)
		);

		$position = count( $children );
		$seen     = 0;
		foreach ( $children as $i => $child ) {
			if ( $child->is_shortcode() ) {
				if ( $seen === $index ) {
					$position = $i;
					break;
				}
				++$seen;
			}
		}
		array_splice( $children, $position, 0, [ $node ] );
		$parent->children        = $children;
		$this->parents[ $node ] = $parent;
	}

	/**
	 * Check a setting against the registry and clean its value.
	 *
	 * @param string $type  Element.
	 * @param string $key   Setting name.
	 * @param string $value Value.
	 * @return string
	 * @throws EditException When the setting or value is not allowed.
	 */
	private function checked_attr( $type, $key, $value ) {
		$params = $this->registry->params( $type );
		if ( 'element_content' === $key || ! isset( $params[ $key ] ) ) {
			throw new EditException( $type . ' has no setting "' . $key . '".' );
		}
		$param = $params[ $key ];

		if ( isset( $param['options'] ) ) {
			$multi = in_array( $param['type'], [ 'checkbox_button_set', 'multiple_select' ], true );
			if ( $multi && '' === $value ) {
				return $value; // Nothing selected.
			}
			foreach ( $multi ? explode( ',', $value ) : [ $value ] as $choice ) {
				if ( ! in_array( $choice, $param['options'], true ) ) {
					throw new EditException( '"' . $choice . '" is not a valid value for ' . $type . ' ' . $key . '. Allowed: ' . implode( ', ', $param['options'] ) . '.' );
				}
			}
			return $value;
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new EditException( 'Setting "' . $key . '" contains control characters.' );
		}
		return (string) call_user_func( $this->sanitize_attr, $value, $param );
	}

	/**
	 * The element must have text Gama AI can replace.
	 *
	 * @param string $type Element.
	 * @return void
	 * @throws EditException When it has none.
	 */
	private function require_text_param( $type ) {
		$params = $this->registry->params( $type );
		if ( ! isset( $params['element_content'] ) || ! in_array( $params['element_content']['type'], array_merge( self::TEXT_TYPES, self::URL_TYPES ), true ) ) {
			throw new EditException( ( $type ? $type : 'This element' ) . ' has no editable text.' );
		}
	}

	/**
	 * The element may be placed in this parent.
	 *
	 * @param string $type   Element.
	 * @param Node   $parent Parent.
	 * @return void
	 * @throws EditException When it may not.
	 */
	private function require_placement( $type, Node $parent ) {
		$parent_type = $parent === $this->root ? '' : $parent->name;
		$allowed     = isset( self::STRUCTURE[ $type ] ) ? self::STRUCTURE[ $type ] : self::CONTENT_PARENTS;
		if ( ! in_array( $parent_type, $allowed, true ) ) {
			throw new EditException( $type . ' cannot be placed in ' . ( '' === $parent_type ? 'the page root' : $parent_type ) . '.' );
		}
	}

	/**
	 * Clean an element's content: text (HTML allowed) for text elements, or
	 * a web address for elements whose content is a file, such as the image
	 * of fusion_imageframe.
	 *
	 * @param string $type Element.
	 * @param string $text Content.
	 * @return string
	 * @throws EditException When a file element gets something other than a web address.
	 */
	private function clean_content( $type, $text ) {
		$param = $this->registry->params( $type )['element_content'];
		if ( ! in_array( $param['type'], self::URL_TYPES, true ) ) {
			return $this->clean_text( $text );
		}
		$url = trim( $text );
		if ( ! preg_match( '#^https?://[^\s\[\]"<>]+$#i', $url ) ) {
			throw new EditException( $type . ' needs a web address (https://...) as its content.' );
		}
		return (string) call_user_func( $this->sanitize_attr, $url, $param );
	}

	/**
	 * Clean element text and stop it from opening shortcodes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function clean_text( $text ) {
		$text = (string) call_user_func( $this->sanitize_text, $text );
		return str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $text );
	}

	/**
	 * The element an operation targets.
	 *
	 * @param array<string,mixed> $op  Operation.
	 * @param array<string,Node>  $ids Path ids.
	 * @return Node
	 * @throws EditException When it is missing.
	 */
	private function target( array $op, array $ids ) {
		return $this->lookup( isset( $op['target'] ) ? (string) $op['target'] : '', $ids );
	}

	/**
	 * An element by path id.
	 *
	 * @param string             $id  Path id.
	 * @param array<string,Node> $ids Path ids.
	 * @return Node
	 * @throws EditException When it is missing or was removed.
	 */
	private function lookup( $id, array $ids ) {
		if ( isset( $this->refs[ $id ] ) ) {
			if ( $this->removed->contains( $this->refs[ $id ] ) ) {
				throw new EditException( 'Element "' . $id . '" was removed by an earlier operation.' );
			}
			return $this->refs[ $id ];
		}
		if ( ! isset( $ids[ $id ] ) ) {
			throw new EditException( 'No element with id "' . $id . '".' );
		}
		if ( $this->removed->contains( $ids[ $id ] ) ) {
			throw new EditException( 'Element "' . $id . '" was removed by an earlier operation.' );
		}
		return $ids[ $id ];
	}

	/**
	 * A required string field.
	 *
	 * @param array<string,mixed> $op         Operation.
	 * @param string              $field      Field.
	 * @param int                 $max_length Maximum length in bytes.
	 * @return string
	 * @throws EditException When it is missing or too long.
	 */
	private function string_field( array $op, $field, $max_length ) {
		if ( ! isset( $op[ $field ] ) || ! is_scalar( $op[ $field ] ) || is_bool( $op[ $field ] ) ) {
			throw new EditException( '"' . $field . '" is required.' );
		}
		$value = (string) $op[ $field ];
		if ( strlen( $value ) > $max_length ) {
			throw new EditException( '"' . $field . '" is longer than ' . $max_length . ' bytes.' );
		}
		return $value;
	}

	/**
	 * A required non-negative integer "index".
	 *
	 * @param array<string,mixed> $op Operation.
	 * @return int
	 * @throws EditException When it is missing or invalid.
	 */
	private function index_field( array $op ) {
		if ( ! isset( $op['index'] ) || ! is_int( $op['index'] ) || $op['index'] < 0 ) {
			throw new EditException( '"index" must be a whole number, 0 or more.' );
		}
		return $op['index'];
	}

	/**
	 * Record path ids and parents, numbered like PageModel.
	 *
	 * @param Node               $node   Node.
	 * @param string             $prefix Its path id.
	 * @param array<string,Node> $ids    Collected ids.
	 * @return void
	 */
	private function index( Node $node, $prefix, array &$ids ) {
		foreach ( $node->children as $child ) {
			if ( $child->is_shortcode() ) {
				$this->parents[ $child ] = $node;
			}
		}
		foreach ( $node->shortcode_children() as $i => $child ) {
			$id         = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$ids[ $id ] = $child;
			$this->index( $child, $id, $ids );
		}
	}

	/**
	 * Mark a node and everything in it as removed.
	 *
	 * @param Node $node Node.
	 * @return void
	 */
	private function mark_removed( Node $node ) {
		$this->removed->attach( $node );
		foreach ( $node->shortcode_children() as $child ) {
			$this->mark_removed( $child );
		}
	}
}
