<?php
namespace InstaBackup\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An error whose message is safe to show to the administrator (no paths, SQL or credentials).
 * Technical detail goes to the job log through $detail.
 */
class UserError extends \RuntimeException {

	private $error_code;
	private $detail;

	public function __construct( $error_code, $message, $detail = '' ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->detail     = $detail;
	}

	public function error_code() {
		return $this->error_code;
	}

	public function detail() {
		return $this->detail;
	}
}
