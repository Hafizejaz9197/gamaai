<?php
/**
 * Tests for Avada detection.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Compatibility;
use PHPUnit\Framework\TestCase;

class CompatibilityTest extends TestCase {

	public function test_verified_avada() {
		$report = Compatibility::evaluate( 'Avada', '7.15.6', '3.15.6' );
		$this->assertTrue( $report['compatible'] );
		$this->assertTrue( $report['verified'] );
		$this->assertSame( [], $report['messages'] );
	}

	public function test_unverified_builder_version_is_compatible_but_flagged() {
		$report = Compatibility::evaluate( 'Avada', '7.16.0', '3.16.0' );
		$this->assertTrue( $report['compatible'] );
		$this->assertFalse( $report['verified'] );
	}

	public function test_missing_avada() {
		$report = Compatibility::evaluate( 'twentytwentyfive', '1.0', '' );
		$this->assertFalse( $report['compatible'] );
		$this->assertNull( $report['avada_theme'] );
		$this->assertCount( 2, $report['messages'] );
	}
}
