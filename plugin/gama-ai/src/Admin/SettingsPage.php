<?php
/**
 * Settings → Gama AI: connects the site to the Gama AI service.
 *
 * @package GamaAI
 */

namespace GamaAI\Admin;

use GamaAI\Compatibility;
use GamaAI\Saas\SaasClient;
use GamaAI\Saas\SaasException;

/**
 * A small form for the service URL and site token. The token field is
 * never filled in from the stored value; leaving it empty keeps it.
 */
class SettingsPage {

	const SLUG   = 'gama-ai';
	const ACTION = 'gama_ai_save_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'save' ] );
	}

	/**
	 * Add the page under Settings.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page( 'Gama AI', 'Gama AI', 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	/**
	 * Render the form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url       = (string) get_option( SaasClient::OPTION_URL, '' );
		$token_set = '' !== (string) get_option( SaasClient::OPTION_TOKEN, '' );
		$notice    = get_transient( 'gama_ai_settings_notice_' . get_current_user_id() );
		delete_transient( 'gama_ai_settings_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>Gama AI</h1>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gama-ai-url">Service URL</label></th>
						<td><input name="saas_url" id="gama-ai-url" type="url" class="regular-text" placeholder="https://" value="<?php echo esc_attr( $url ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gama-ai-token">Site token</label></th>
						<td>
							<input name="site_token" id="gama-ai-token" type="password" class="regular-text" autocomplete="off" value="">
							<p class="description"><?php echo $token_set ? 'A token is saved. Leave this empty to keep it.' : 'Paste the token printed by gama:site-create.'; ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save and test connection' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the form, then check in with the service.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::ACTION );

		$url   = esc_url_raw( trim( (string) wp_unslash( isset( $_POST['saas_url'] ) ? $_POST['saas_url'] : '' ) ), [ 'https' ] );
		$token = trim( (string) wp_unslash( isset( $_POST['site_token'] ) ? $_POST['site_token'] : '' ) );

		if ( '' === $url ) {
			$this->finish( 'error', 'The service URL must start with https://.' );
		}
		if ( '' !== $token && ! preg_match( '/^gsk_[A-Za-z0-9]{20,100}$/', $token ) ) {
			$this->finish( 'error', 'That does not look like a Gama AI site token.' );
		}
		update_option( SaasClient::OPTION_URL, $url, false );
		if ( '' !== $token ) {
			update_option( SaasClient::OPTION_TOKEN, $token, false );
		}

		try {
			$check_in = SaasClient::from_options()->check_in( Compatibility::report() );
		} catch ( SaasException $e ) {
			$this->finish( 'error', 'Saved, but the connection test failed: ' . $e->getMessage() );
		}
		$available = isset( $check_in['usage']['available'] ) ? $check_in['usage']['available'] : null;
		$this->finish( 'success', 'Connected.' . ( null === $available ? '' : ' Tokens left this month: ' . number_format_i18n( $available ) . '.' ) );
	}

	/**
	 * Show a notice on the settings page and stop.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 * @return void
	 */
	private function finish( $type, $message ) {
		set_transient(
			'gama_ai_settings_notice_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}
}
