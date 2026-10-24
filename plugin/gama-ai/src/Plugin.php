<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package GamaAI
 */

namespace GamaAI;

use GamaAI\Drafts\DraftManager;
use GamaAI\Drafts\WpPostStore;
use GamaAI\Rest\Controller;
use GamaAI\Saas\SaasClient;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', [ $this, 'register_snapshot_type' ] );
		add_action(
			'rest_api_init',
			function () {
				( new Controller( new DraftManager( new WpPostStore() ), SaasClient::from_options() ) )->register();
			}
		);
		add_action( 'admin_notices', [ $this, 'compatibility_notice' ] );
	}

	/**
	 * Snapshots are stored as a private post type with no admin UI.
	 *
	 * @return void
	 */
	public function register_snapshot_type() {
		register_post_type(
			DraftManager::SNAPSHOT_TYPE,
			[
				'label'               => 'Gama AI snapshots',
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => [ 'title', 'editor' ],
			]
		);
	}

	/**
	 * Tell administrators when Avada is missing or unverified.
	 *
	 * @return void
	 */
	public function compatibility_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = Compatibility::report();
		if ( $report['verified'] ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Gama AI:</strong> %s</p></div>',
			esc_html( implode( ' ', $report['messages'] ) )
		);
	}
}
