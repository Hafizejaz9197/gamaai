<?php
/**
 * Collects Avada's element settings as Avada builds them.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Listens to the filters Avada Builder runs while mapping each element
 * (`fusion_builder_element_params`, `fusion_builder_responsive_params`,
 * `fusion_builder_states_params`, all registered in Avada's
 * class-fusion-builder-element-helper.php) and keeps the final params per
 * shortcode. The result is stored per Avada Builder version, so pages can be
 * read on requests where Avada does not map its elements.
 *
 * The filters are only observed: params are returned unchanged.
 */
class RegistryCollector {

	const OPTION = 'gama_ai_avada_registry';

	/** @var array<string,array<int|string,mixed>> */
	private $captured = [];

	/**
	 * Register the listeners. Must run before Avada maps its elements.
	 *
	 * @return void
	 */
	public function listen() {
		add_filter( 'fusion_builder_element_params', [ $this, 'capture_element_params' ], PHP_INT_MAX, 2 );
		add_filter( 'fusion_builder_responsive_params', [ $this, 'capture_extended_params' ], PHP_INT_MAX, 3 );
		add_filter( 'fusion_builder_states_params', [ $this, 'capture_extended_params' ], PHP_INT_MAX, 3 );
		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Filter callback for fusion_builder_element_params.
	 *
	 * @param mixed  $params    Element params.
	 * @param string $shortcode Shortcode name.
	 * @return mixed Unchanged params.
	 */
	public function capture_element_params( $params, $shortcode = '' ) {
		if ( is_array( $params ) && is_string( $shortcode ) && '' !== $shortcode ) {
			$this->captured[ $shortcode ] = $params;
		}
		return $params;
	}

	/**
	 * Filter callback for the responsive and states filters, which receive
	 * the params as their second argument.
	 *
	 * @param mixed  $atts      Responsive or state attributes.
	 * @param mixed  $params    Element params (the filtered value).
	 * @param string $shortcode Shortcode name.
	 * @return mixed Unchanged params.
	 */
	public function capture_extended_params( $atts, $params = null, $shortcode = '' ) {
		// The filtered value is the first argument; Avada passes params there.
		if ( is_array( $atts ) && is_string( $shortcode ) && '' !== $shortcode && $this->looks_like_params( $atts ) ) {
			$this->captured[ $shortcode ] = $atts;
		}
		return $atts;
	}

	/**
	 * Store what was captured on this request, if anything changed.
	 *
	 * @return void
	 */
	public function save() {
		if ( empty( $this->captured ) || ! defined( 'FUSION_BUILDER_VERSION' ) ) {
			return;
		}
		$fresh  = Registry::from_avada_params( FUSION_BUILDER_VERSION, $this->captured )->to_array();
		$stored = get_option( self::OPTION );

		// Same Avada version: add to what earlier requests collected.
		if ( is_array( $stored ) && isset( $stored['builder_version'], $stored['elements'] ) && $stored['builder_version'] === $fresh['builder_version'] ) {
			$merged = array_merge( $stored['elements'], $fresh['elements'] );
			ksort( $merged );
			if ( $merged === $stored['elements'] ) {
				return;
			}
			$fresh['elements'] = $merged;
		}
		update_option( self::OPTION, $fresh, false );
	}

	/**
	 * The stored registry, or null when none has been collected yet.
	 *
	 * @return Registry|null
	 */
	public static function load() {
		$data = get_option( self::OPTION );
		return is_array( $data ) ? Registry::from_array( $data ) : null;
	}

	/**
	 * Whether an array is a params list (entries with param_name).
	 *
	 * @param array<int|string,mixed> $value Candidate.
	 * @return bool
	 */
	private function looks_like_params( array $value ) {
		foreach ( $value as $entry ) {
			if ( is_array( $entry ) && isset( $entry['param_name'] ) ) {
				return true;
			}
		}
		return false;
	}
}
