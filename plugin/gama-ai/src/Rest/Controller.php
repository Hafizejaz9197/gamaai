<?php
/**
 * REST routes for reading pages and running the draft workflow.
 *
 * @package GamaAI
 */

namespace GamaAI\Rest;

use GamaAI\Avada\PageModel;
use GamaAI\Avada\RegistryCollector;
use GamaAI\Avada\ShortcodeParser;
use GamaAI\Compatibility;
use GamaAI\Drafts\DraftException;
use GamaAI\Drafts\DraftManager;

/**
 * Routes under /wp-json/gama-ai/v1. Every route needs a logged-in user with
 * the matching WordPress capability; nothing is public.
 */
class Controller {

	const NAMESPACE_V1 = 'gama-ai/v1';

	/** @var DraftManager */
	private $drafts;

	/**
	 * Constructor.
	 *
	 * @param DraftManager $drafts Draft workflow.
	 */
	public function __construct( DraftManager $drafts ) {
		$this->drafts = $drafts;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register() {
		$id = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/registry',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'registry' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'full' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/model',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'model' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/draft',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_draft' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/snapshots',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'snapshots' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/drafts/(?P<id>\d+)/publish',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'publish' ],
				'permission_callback' => [ $this, 'can_publish_linked' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshots/(?P<id>\d+)/rollback',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => [ $this, 'can_publish_linked' ],
				'args'                => $id,
			]
		);
	}

	/**
	 * Permission: the user can edit this page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_page( $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * Permission: the user can edit and publish the page a draft or snapshot belongs to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_publish_linked( $request ) {
		$source_id = $this->drafts->source_of( (int) $request['id'] );
		return $source_id > 0
			&& current_user_can( 'edit_post', $source_id )
			&& current_user_can( 'publish_pages' );
	}

	/**
	 * GET /status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response( Compatibility::report() );
	}

	/**
	 * GET /registry. A summary by default; ?full=1 returns every element.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function registry( $request ) {
		$registry = RegistryCollector::load();
		$report   = Compatibility::report();
		if ( null === $registry ) {
			return rest_ensure_response(
				[
					'available' => false,
					'message'   => 'Avada has not mapped its elements since Gama AI was activated. Open any page in Avada Builder once.',
				]
			);
		}
		$data = [
			'available'       => true,
			'builder_version' => $registry->builder_version(),
			'current'         => $registry->builder_version() === $report['avada_builder'],
			'elements'        => $registry->count(),
		];
		if ( $request['full'] ) {
			$data['registry'] = $registry->to_array();
		}
		return rest_ensure_response( $data );
	}

	/**
	 * GET /pages/{id}/model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function model( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'gama_ai_not_found', 'Page not found.', [ 'status' => 404 ] );
		}
		$root     = ( new ShortcodeParser() )->parse( $post->post_content );
		$registry = RegistryCollector::load();
		$report   = Compatibility::report();
		// A registry from another Avada version could hide settings that are not defaults any more.
		if ( $registry && $registry->builder_version() !== $report['avada_builder'] ) {
			$registry = null;
		}
		return rest_ensure_response(
			[
				'id'               => (int) $post->ID,
				'title'            => $post->post_title,
				'round_trips'      => $this->drafts->round_trips( $post->post_content ),
				'defaults_omitted' => null !== $registry,
				'sections'         => ( new PageModel( $registry ) )->build( $root ),
			]
		);
	}

	/**
	 * POST /pages/{id}/draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_draft( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		return $this->run(
			function () use ( $request ) {
				$draft_id = $this->drafts->create_draft( (int) $request['id'] );
				return [
					'draft_id'    => $draft_id,
					'preview_url' => get_preview_post_link( $draft_id ),
				];
			}
		);
	}

	/**
	 * GET /pages/{id}/snapshots.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function snapshots( $request ) {
		return rest_ensure_response( $this->drafts->list_snapshots( (int) $request['id'] ) );
	}

	/**
	 * POST /drafts/{id}/publish.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function publish( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		return $this->run(
			function () use ( $request ) {
				return [ 'snapshot_id' => $this->drafts->publish( (int) $request['id'], get_current_user_id() ) ];
			}
		);
	}

	/**
	 * POST /snapshots/{id}/rollback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		return $this->run(
			function () use ( $request ) {
				return [ 'snapshot_id' => $this->drafts->rollback( (int) $request['id'], get_current_user_id() ) ];
			}
		);
	}

	/**
	 * Writes are only allowed on verified Avada versions.
	 *
	 * @return \WP_Error|null
	 */
	private function write_blocked() {
		$report = Compatibility::report();
		if ( $report['verified'] ) {
			return null;
		}
		return new \WP_Error( 'gama_ai_unsupported', implode( ' ', $report['messages'] ), [ 'status' => 409 ] );
	}

	/**
	 * Run a workflow step and turn DraftException into a 400 response.
	 *
	 * @param callable $step Step.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function run( callable $step ) {
		try {
			return rest_ensure_response( $step() );
		} catch ( DraftException $e ) {
			return new \WP_Error( 'gama_ai_draft', $e->getMessage(), [ 'status' => 400 ] );
		}
	}
}
