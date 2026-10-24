<?php
/**
 * Detects Avada and whether its version has been verified.
 *
 * @package GamaAI
 */

namespace GamaAI;

/**
 * Avada compatibility check.
 */
class Compatibility {

	/**
	 * Avada Builder major.minor versions whose storage format has been checked
	 * against the real plugin source (docs/phase0-avada-findings.md).
	 */
	const VERIFIED_BUILDER_VERSIONS = [ '3.15' ];

	/**
	 * Report for the running site.
	 *
	 * @return array<string,mixed>
	 */
	public static function report() {
		$theme = wp_get_theme( get_template() );
		return self::evaluate(
			get_template(),
			$theme->exists() ? (string) $theme->get( 'Version' ) : '',
			defined( 'FUSION_BUILDER_VERSION' ) ? (string) FUSION_BUILDER_VERSION : ''
		);
	}

	/**
	 * Evaluate the given environment.
	 *
	 * @param string $template        Active parent theme directory.
	 * @param string $theme_version   Active parent theme version.
	 * @param string $builder_version Avada Builder version, empty if not loaded.
	 * @return array<string,mixed>
	 */
	public static function evaluate( $template, $theme_version, $builder_version ) {
		$messages = [];
		$avada    = 'avada' === strtolower( (string) $template );

		if ( ! $avada ) {
			$messages[] = 'The Avada theme is not the active theme.';
		}
		if ( '' === $builder_version ) {
			$messages[] = 'The Avada Builder plugin is not active.';
		}

		$compatible = $avada && '' !== $builder_version;
		$verified   = $compatible && in_array( self::major_minor( $builder_version ), self::VERIFIED_BUILDER_VERSIONS, true );

		if ( $compatible && ! $verified ) {
			$messages[] = sprintf( 'Avada Builder %s has not been verified with Gama AI yet.', $builder_version );
		}

		return [
			'avada_theme'   => $avada ? $theme_version : null,
			'avada_builder' => '' !== $builder_version ? $builder_version : null,
			'compatible'    => $compatible,
			'verified'      => $verified,
			'messages'      => $messages,
		];
	}

	/**
	 * "3.15.6" -> "3.15".
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function major_minor( $version ) {
		$parts = explode( '.', $version );
		return $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );
	}
}
