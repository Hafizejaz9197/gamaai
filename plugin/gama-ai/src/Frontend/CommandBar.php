<?php
/**
 * The editing bar shown on the site itself.
 *
 * @package GamaAI
 */

namespace GamaAI\Frontend;

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

	/**
	 * Constructor.
	 *
	 * @param DraftManager $drafts Draft workflow.
	 */
	public function __construct( DraftManager $drafts ) {
		$this->drafts = $drafts;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );
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
		];
		return $cache;
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
