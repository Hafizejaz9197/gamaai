<?php
/**
 * Avada element registry: which elements exist and what their settings are.
 *
 * @package GamaAI
 */

namespace GamaAI\Avada;

/**
 * Normalised view of Avada Builder's element map.
 *
 * Avada describes every element's settings in the `params` passed to
 * fusion_builder_map() (see docs/phase0-avada-findings.md). This class keeps
 * the parts Gama AI needs: each setting's type, its default and, for choice
 * settings, the allowed values.
 */
class Registry {

	/** @var string Avada Builder version the registry was read from. */
	private $builder_version;

	/** @var array<string,array<string,array<string,mixed>>> shortcode => param name => param. */
	private $elements;

	/**
	 * Constructor.
	 *
	 * @param string                                          $builder_version Avada Builder version.
	 * @param array<string,array<string,array<string,mixed>>> $elements        Normalised elements.
	 */
	public function __construct( $builder_version, array $elements ) {
		$this->builder_version = (string) $builder_version;
		$this->elements        = $elements;
	}

	/**
	 * Build from raw Avada params, keyed by shortcode.
	 *
	 * @param string                                  $builder_version Avada Builder version.
	 * @param array<string,array<int|string,mixed>> $raw             shortcode => params list as Avada builds it.
	 * @return Registry
	 */
	public static function from_avada_params( $builder_version, array $raw ) {
		$elements = [];
		foreach ( $raw as $shortcode => $params ) {
			if ( ! is_array( $params ) ) {
				continue;
			}
			$elements[ (string) $shortcode ] = [];
			foreach ( $params as $param ) {
				if ( ! is_array( $param ) || empty( $param['param_name'] ) ) {
					continue; // Unexpanded placeholders and malformed entries.
				}
				$elements[ (string) $shortcode ][ (string) $param['param_name'] ] = self::normalise_param( $param );
			}
		}
		ksort( $elements );
		return new self( $builder_version, $elements );
	}

	/**
	 * Load an exported registry.
	 *
	 * @param array<string,mixed> $data Output of to_array().
	 * @return Registry
	 */
	public static function from_array( array $data ) {
		return new self(
			isset( $data['builder_version'] ) ? $data['builder_version'] : '',
			isset( $data['elements'] ) && is_array( $data['elements'] ) ? $data['elements'] : []
		);
	}

	/**
	 * Export for storage or for the SaaS backend.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return [
			'builder_version' => $this->builder_version,
			'elements'        => $this->elements,
		];
	}

	/**
	 * Avada Builder version.
	 *
	 * @return string
	 */
	public function builder_version() {
		return $this->builder_version;
	}

	/**
	 * Whether the element is known.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return bool
	 */
	public function has( $shortcode ) {
		return isset( $this->elements[ $shortcode ] );
	}

	/**
	 * Number of elements.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->elements );
	}

	/**
	 * Settings of an element.
	 *
	 * @param string $shortcode Shortcode name.
	 * @return array<string,array<string,mixed>>
	 */
	public function params( $shortcode ) {
		return isset( $this->elements[ $shortcode ] ) ? $this->elements[ $shortcode ] : [];
	}

	/**
	 * Whether an attribute value equals the setting's known default.
	 * Unknown elements, unknown settings and settings without a known
	 * default are never treated as defaults, so nothing is hidden by guess.
	 *
	 * @param string $shortcode Shortcode name.
	 * @param string $name      Attribute name.
	 * @param string $value     Attribute value.
	 * @return bool
	 */
	public function is_default( $shortcode, $name, $value ) {
		if ( ! isset( $this->elements[ $shortcode ][ $name ] ) ) {
			return false;
		}
		$param = $this->elements[ $shortcode ][ $name ];
		return array_key_exists( 'default', $param ) && (string) $value === $param['default'];
	}

	/**
	 * Keep what Gama AI needs from one Avada param.
	 *
	 * Avada uses `default` for the default value; when it is missing,
	 * a scalar `value` is the default and an array `value` is the list of
	 * choices. Array defaults (such as visibility) are stored the way they
	 * appear in shortcodes: comma separated.
	 *
	 * @param array<string,mixed> $param Avada param.
	 * @return array<string,mixed>
	 */
	private static function normalise_param( array $param ) {
		$out = [ 'type' => isset( $param['type'] ) ? (string) $param['type'] : '' ];

		$value = isset( $param['value'] ) ? $param['value'] : null;
		if ( is_array( $value ) && self::is_choice_type( $out['type'] ) ) {
			$out['options'] = array_map( 'strval', array_keys( $value ) );
		}

		if ( array_key_exists( 'default', $param ) ) {
			$default = $param['default'];
		} elseif ( null !== $value && ! is_array( $value ) ) {
			$default = $value;
		}
		if ( isset( $default ) ) {
			if ( is_array( $default ) ) {
				$default = implode( ',', array_map( 'strval', $default ) );
			}
			if ( is_scalar( $default ) ) {
				$out['default'] = (string) $default;
			}
		}

		if ( ! empty( $param['responsive']['state'] ) ) {
			$out['responsive'] = (string) $param['responsive']['state'];
		}
		return $out;
	}

	/**
	 * Setting types whose array `value` is a list of choices.
	 *
	 * @param string $type Avada param type.
	 * @return bool
	 */
	private static function is_choice_type( $type ) {
		return in_array( $type, [ 'select', 'radio_button_set', 'checkbox_button_set', 'multiple_select' ], true );
	}
}
