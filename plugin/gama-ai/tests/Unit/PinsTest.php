<?php
/**
 * Tests for task pins.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Avada\ShortcodeParser;
use GamaAI\Tasks\Pins;
use PHPUnit\Framework\TestCase;

class PinsTest extends TestCase {

	const PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"]'
		. '[fusion_title]Welcome[/fusion_title][fusion_text]About us[/fusion_text][fusion_text]Contact[/fusion_text]'
		. '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private function parse( $content ) {
		return ( new ShortcodeParser() )->parse( $content );
	}

	public function test_a_pin_keeps_type_text_and_label() {
		$pin = Pins::make( $this->parse( self::PAGE ), '0.0.0.2' );

		$this->assertSame(
			[
				'target'  => '0.0.0.2',
				'type'    => 'fusion_text',
				'snippet' => 'Contact',
				'label'   => 'the 2nd text',
			],
			$pin
		);
		$this->assertNull( Pins::make( $this->parse( self::PAGE ), '0.9' ) );
	}

	public function test_a_pin_follows_its_element_when_ids_move() {
		$pin   = Pins::make( $this->parse( self::PAGE ), '0.0.0.2' );
		$moved = str_replace( '[fusion_title]Welcome[/fusion_title]', '', self::PAGE );

		$this->assertSame( '0.0.0.2', Pins::locate( $this->parse( self::PAGE ), $pin ) );
		$this->assertSame( '0.0.0.1', Pins::locate( $this->parse( $moved ), $pin ) );
	}

	public function test_a_pin_is_lost_rather_than_guessed() {
		$pin = Pins::make( $this->parse( self::PAGE ), '0.0.0.2' );

		$this->assertNull( Pins::locate( $this->parse( str_replace( 'Contact', 'Write to us', self::PAGE ) ), $pin ) );
		$this->assertNull( Pins::locate( $this->parse( str_replace( 'About us', 'Contact', str_replace( '[fusion_title]Welcome[/fusion_title]', '', self::PAGE ) ) ), $pin ) );
	}
}
