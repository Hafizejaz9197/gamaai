<?php
/**
 * Raised when the Gama AI service cannot be used.
 *
 * @package GamaAI
 */

namespace GamaAI\Saas;

/**
 * Service error with the HTTP status to pass on.
 */
class SaasException extends \RuntimeException {

	/** @var int */
	private $status;

	/** @var array<string,mixed> */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string              $message Message for the site owner.
	 * @param int                 $status  HTTP status for the REST response.
	 * @param array<string,mixed> $data    Extra response data, such as usage.
	 */
	public function __construct( $message, $status = 502, array $data = [] ) {
		parent::__construct( $message );
		$this->status = (int) $status;
		$this->data   = $data;
	}

	/**
	 * HTTP status for the REST response.
	 *
	 * @return int
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Extra response data.
	 *
	 * @return array<string,mixed>
	 */
	public function data() {
		return $this->data;
	}
}
