<?php
/**
 * The editing bar shown on the site itself.
 *
 * @package GamaAI
 */

namespace GamaAI\Frontend;

use GamaAI\Avada\ShortcodeParser;
use GamaAI\Compatibility;
use GamaAI\Drafts\DraftManager;
use GamaAI\Saas\SaasClient;

/**
 * Adds a "Gama AI" panel to pages for users who can edit them. The editor
 * types a change in plain words; the panel calls the plugin's REST routes,
 * shows what the AI did, and offers preview, publish, discard and undo. It
 * is shown on a live page and on the preview of its Gama AI draft.
 */
class CommandBar {

	const HANDLE = 'gama-ai-command-bar';

	/** @var DraftManager */
	private $drafts;

	/** @var ElementMarks */
	private $marks;

	/**
	 * Constructor.
	 *
	 * @param DraftManager $drafts Draft workflow.
	 */
	public function __construct( DraftManager $drafts ) {
		$this->drafts = $drafts;
		$this->marks  = new ElementMarks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );
		// Shortcodes run at the_content priority 11.
		add_filter( 'the_content', [ $this, 'start_marks' ], 9 );
		add_filter( 'the_content', [ $this, 'stop_marks' ], 12 );
		add_filter( 'pre_do_shortcode_tag', [ $this, 'open_mark' ], PHP_INT_MAX, 2 );
		add_filter( 'do_shortcode_tag', [ $this, 'close_mark' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Start marking elements when the page's own content is rendered for
	 * an editor, and the layout on screen is the one edits change.
	 *
	 * @param string $content Content.
	 * @return string Unchanged.
	 */
	public function start_marks( $content ) {
		$context = $this->context();
		$post    = get_queried_object();
		if ( null !== $context && null !== $context['containers'] && $post instanceof \WP_Post
			&& in_the_loop() && is_main_query() && (int) get_the_ID() === (int) $post->ID ) {
			$this->marks->begin( $post->post_content );
		}
		return $content;
	}

	/**
	 * Stop marking.
	 *
	 * @param string $content Content.
	 * @return string Unchanged.
	 */
	public function stop_marks( $content ) {
		$this->marks->end();
		return $content;
	}

	/**
	 * A shortcode starts rendering.
	 *
	 * @param false|string $value Short-circuit value of other filters.
	 * @param string       $tag   Shortcode name.
	 * @return false|string Unchanged.
	 */
	public function open_mark( $value, $tag ) {
		if ( false === $value ) {
			$this->marks->open( $tag );
		}
		return $value;
	}

	/**
	 * A shortcode finished rendering.
	 *
	 * @param string $output HTML.
	 * @param string $tag    Shortcode name.
	 * @return string
	 */
	public function close_mark( $output, $tag ) {
		return $this->marks->close( $output, $tag );
	}

	/**
	 * Load the bar on pages it can edit.
	 *
	 * @return void
	 */
	public function enqueue() {
		$context = $this->context();
		if ( null === $context ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/command-bar.css', GAMA_AI_DIR . '/gama-ai.php' ), [], GAMA_AI_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/command-bar.js', GAMA_AI_DIR . '/gama-ai.php' ), [], GAMA_AI_VERSION, true );
		wp_add_inline_script( self::HANDLE, 'window.gamaAi = ' . wp_json_encode( $context ) . ';', 'before' );
	}

	/**
	 * A "Gama AI" item in the toolbar that opens the panel.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( null === $this->context() ) {
			return;
		}
		$bar->add_node(
			[
				'id'    => 'gama-ai',
				'title' => 'Gama AI',
				'href'  => '#gama-ai',
			]
		);
	}

	/**
	 * What the bar needs to know about the current page, or null when it
	 * should not be shown.
	 *
	 * @return array<string,mixed>|null
	 */
	private function context() {
		static $cache = false;
		if ( false !== $cache ) {
			return $cache;
		}
		$cache = null;

		// Not inside Avada's own Live editor, the Customizer or the admin.
		if ( is_admin() || is_customize_preview() || isset( $_GET['fb-edit'] ) || isset( $_GET['builder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		if ( ! is_user_logged_in() || ! is_singular( 'page' ) ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$source_id = $this->drafts->source_of( $post->ID );
		$on_draft  = $source_id > 0;
		$page_id   = $on_draft ? $source_id : (int) $post->ID;
		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			return null;
		}
		$draft_id = $on_draft ? (int) $post->ID : $this->drafts->find_draft( $page_id );
		$report   = Compatibility::report();

		$cache = [
			'restUrl'    => esc_url_raw( rest_url( 'gama-ai/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'pageId'     => $page_id,
			'pageUrl'    => get_permalink( $page_id ),
			'draftId'    => $draft_id,
			'previewUrl' => $draft_id ? get_preview_post_link( $draft_id ) : null,
			'onDraft'    => $on_draft,
			'canPublish' => current_user_can( 'publish_pages' ),
			'problem'    => $this->problem( $report ),
			// The picker only works when the page on screen is the layout edits change.
			'containers' => ( $on_draft || ! $draft_id ) ? self::rendered_containers( $post->post_content ) : null,
		];
		return $cache;
	}

	/**
	 * Path ids of the top-level containers Avada renders, in page order, so
	 * the bar can tell which container on screen is which. Containers set to
	 * Draft are not rendered and are skipped. Null when a container is
	 * scheduled (rendering then depends on the date) or the page has content
	 * outside containers, because the order on screen may then differ.
	 *
	 * @param string $content Layout markup.
	 * @return string[]|null
	 */
	public static function rendered_containers( $content ) {
		$ids = [];
		foreach ( ( new ShortcodeParser() )->parse( $content )->shortcode_children() as $i => $node ) {
			if ( 'fusion_builder_container' !== $node->name ) {
				return null;
			}
			$status = isset( $node->attrs['status'] ) ? $node->attrs['status'] : '';
			if ( 'draft' === $status ) {
				continue;
			}
			if ( '' !== $status && 'published' !== $status ) {
				return null;
			}
			$ids[] = (string) $i;
		}
		return $ids;
	}

	/**
	 * Why edits cannot run here, in words for the editor, or null.
	 *
	 * @param array<string,mixed> $report Compatibility report.
	 * @return string|null
	 */
	private function problem( array $report ) {
		if ( ! $report['verified'] ) {
			return implode( ' ', $report['messages'] );
		}
		if ( ! SaasClient::from_options()->is_configured() ) {
			return current_user_can( 'manage_options' )
				? 'Gama AI is not connected yet. Add the service URL and site token under Settings → Gama AI.'
				: 'Gama AI is not connected yet. Ask the site administrator to connect it.';
		}
		return null;
	}
}
