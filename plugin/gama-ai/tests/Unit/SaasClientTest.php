<?php
/**
 * Tests for the Gama AI service client.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Saas\SaasClient;
use GamaAI\Saas\SaasException;
use PHPUnit\Framework\TestCase;

class SaasClientTest extends TestCase {

	/** @var array<int,array{0:string,1:array}> */
	private $requests = [];

	private function client( $code, $body, $url = 'https://saas.example.com/', $token = 'gsk_abc' ) {
		return new SaasClient(
			$url,
			$token,
			function ( $url, $args ) use ( $code, $body ) {
				$this->requests[] = [ $url, $args ];
				return [
					'code' => $code,
					'body' => is_string( $body ) ? $body : json_encode( $body ),
				];
			}
		);
	}

	private function expect_error( SaasClient $client, $status, $message ) {
		try {
			$client->request_edit( 11, 'x', [], [] );
			$this->fail( 'Expected a service error.' );
		} catch ( SaasException $e ) {
			$this->assertSame( $status, $e->status() );
			$this->assertStringContainsString( $message, $e->getMessage() );
			return $e;
		}
	}

	public function test_request_edit_sends_the_page_with_the_token() {
		$answer = $this->client( 200, [ 'summary' => 'Done', 'operations' => [] ] )
			->request_edit( 11, 'Make the title shorter', [ [ 'id' => '0' ] ], [] );

		$this->assertSame( 'Done', $answer['summary'] );
		list( $url, $args ) = $this->requests[0];
		$this->assertSame( 'https://saas.example.com/api/v1/site/edit-requests', $url );
		$this->assertSame( 'Bearer gsk_abc', $args['headers']['Authorization'] );
		$this->assertSame( 120, $args['timeout'] );
		$this->assertSame(
			'{"page_id":11,"instruction":"Make the title shorter","sections":[{"id":"0"}],"elements":{}}',
			$args['body']
		);
	}

	public function test_errors_keep_the_service_message() {
		$e = $this->expect_error( $this->client( 402, [ 'message' => 'No tokens left.', 'usage' => [ 'available' => 0 ] ] ), 402, 'No tokens left.' );
		$this->assertSame( [ 'usage' => [ 'available' => 0 ] ], $e->data() );

		$this->expect_error( $this->client( 401, [ 'message' => 'Invalid site token.' ] ), 409, 'Invalid site token.' );
		$this->expect_error( $this->client( 500, '<html>' ), 502, 'returned an error' );
		$this->expect_error( $this->client( 0, '' ), 502, 'could not be reached' );
	}

	public function test_needs_url_and_token() {
		$this->assertFalse( $this->client( 200, [], '', 'gsk_abc' )->is_configured() );
		$this->expect_error( $this->client( 200, [], 'https://saas.example.com', '' ), 409, 'not connected' );
		$this->assertSame( [], $this->requests );
	}
}
