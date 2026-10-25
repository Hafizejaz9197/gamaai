<?php
/**
 * Plugin Name:       Gama AI
 * Description:       AI-assisted editing for Avada websites. Edit Avada pages by describing the change: the AI's edits are checked, saved to a draft, and published or undone from a bar on the page.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gama AI
 * License:           GPL-2.0-or-later
 * Text Domain:       gama-ai
 *
 * @package GamaAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMA_AI_VERSION', '0.7.0' );
define( 'GAMA_AI_DIR', __DIR__ );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'GamaAI\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = GAMA_AI_DIR . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Listen before Avada Builder maps its elements, which can happen early.
( new GamaAI\Avada\RegistryCollector() )->listen();

add_action(
	'plugins_loaded',
	function () {
		( new GamaAI\Plugin() )->boot();
	}
);
