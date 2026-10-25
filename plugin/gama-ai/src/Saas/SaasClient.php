<?php
/**
 * Talks to the Gama AI service.
 *
 * @package GamaAI
 */

namespace GamaAI\Saas;

/**
 * Calls the service's site API with this site's token.
 *
 * The service URL and token are stored in options. The token is never
 * returned by any REST route.
 */
class SaasClient {

	const OPTION_URL   = 'gama_ai_saas_url';
	const OPTION_TOKEN = 'gama_ai_site_token';

	/** AI edits can take a while; WordPress' default of 5 seconds is too short. */
	const TIMEOUT = 120;

	/** @var string */
	private $url;

	/** @var string */
	private $token;

	/** @var callable(string,array<string,mixed>):array{code:int,body:string} */
	private $post;

	/**
	 * Constructor.
	 *
	 * @param string        $url   Service base URL.
	 * @param string        $token Site token.
	 * @param callable|null $post  HTTP POST; defaults to wp_remote_post.
	 */
	public function __construct( $url, $token, $post = null ) {
		$this->url   = rtrim( (string) $url, '/' );
		$this->token = (string) $token;
		$this->post  = $post ? $post : [ self::class, 'wp_post' ];
	}

	/**
	 * Client from the stored settings.
	 *
	 * @return SaasClient
	 */
	public static function from_options() {
		return new self( get_option( self::OPTION_URL, '' ), get_option( self::OPTION_TOKEN, '' ) );
	}

	/**
	 * Whether a service URL and token are set.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->url && '' !== $this->token;
	}

	/**
	 * Ask the service for edit operations.
	 *
	 * @param int                                 $page_id     Page ID.
	 * @param string                              $instruction The site owner's request.
	 * @param array<int,array<string,mixed>>      $sections    Page model.
	 * @param array<string,array<string,mixed>>   $elements    Element settings.
	 * @param string|null                         $scope       Which part of the page $sections is, when not all of it.
	 * @param array<string,mixed>                 $stats       Context sizes, logged by the service.
	 * @return array<string,mixed> summary, operations, usage.
	 * @throws SaasException When the service refuses or cannot be reached.
	 */
	public function request_edit( $page_id, $instruction, array $sections, array $elements, $scope = null, array $stats = [] ) {
		$body = [
			'page_id'     => (int) $page_id,
			'instruction' => (string) $instruction,
			'sections'    => $sections,
			'elements'    => (object) $elements,
		];
		if ( null !== $scope ) {
			$body['scope'] = (string) $scope;
		}
		if ( $stats ) {
			$body['context_stats'] = $stats;
		}
		return $this->post_json( '/api/v1/site/edit-requests', $body );
	}

	/**
	 * Report this site's versions to the service.
	 *
	 * @param array<string,mixed> $report Compatibility report.
	 * @return array<string,mixed> Site and usage.
	 * @throws SaasException When the service refuses or cannot be reached.
	 */
	public function check_in( array $report ) {
		return $this->post_json(
			'/api/v1/site/check-in',
			[
				'plugin_version'        => defined( 'GAMA_AI_VERSION' ) ? GAMA_AI_VERSION : '',
				'avada_builder_version' => isset( $report['avada_builder'] ) ? $report['avada_builder'] : null,
				'avada_verified'        => ! empty( $report['verified'] ),
			]
		);
	}

	/**
	 * POST JSON and decode the answer.
	 *
	 * @param string              $path Path.
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 * @throws SaasException On any error.
	 */
	private function post_json( $path, array $body ) {
		if ( ! $this->is_configured() ) {
			throw new SaasException( 'Gama AI is not connected to its service yet.', 409 );
		}

		$response = call_user_func(
			$this->post,
			$this->url . $path,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'    => json_encode( $body ),
			]
		);

		$data = json_decode( $response['body'], true );
		$code = (int) $response['code'];
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		if ( 0 === $code ) {
			throw new SaasException( 'The Gama AI service could not be reached.', 502 );
		}

		$message = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'The Gama AI service returned an error.';
		$extra   = [];
		foreach ( [ 'usage', 'retry_after' ] as $key ) {
			if ( is_array( $data ) && isset( $data[ $key ] ) ) {
				$extra[ $key ] = $data[ $key ];
			}
		}
		if ( 401 === $code ) {
			$status = 409; // The site token is wrong: a setup problem on this site, not the caller's.
		} elseif ( in_array( $code, [ 402, 413, 422, 429 ], true ) ) {
			$status = $code;
		} else {
			$status = 502;
		}
		throw new SaasException( $message, $status, $extra );
	}

	/**
	 * Default transport.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args wp_remote_post arguments.
	 * @return array{code:int,body:string}
	 */
	public static function wp_post( $url, array $args ) {
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [
				'code' => 0,
				'body' => '',
			];
		}
		return [
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		];
	}
}
