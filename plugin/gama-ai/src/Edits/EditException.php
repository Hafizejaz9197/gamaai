<?php
/**
 * Raised when an edit operation is invalid.
 *
 * @package GamaAI
 */

namespace GamaAI\Edits;

/**
 * Edit operation error. Nothing is written when one is thrown.
 */
class EditException extends \RuntimeException {

	/** @var int|null Index of the failing operation in the request. */
	private $operation_index;

	/**
	 * Constructor.
	 *
	 * @param string   $message         Message.
	 * @param int|null $operation_index Failing operation, if any.
	 */
	public function __construct( $message, $operation_index = null ) {
		parent::__construct( $message );
		$this->operation_index = $operation_index;
	}

	/**
	 * Index of the failing operation in the request.
	 *
	 * @return int|null
	 */
	public function operation_index() {
		return $this->operation_index;
	}
}
