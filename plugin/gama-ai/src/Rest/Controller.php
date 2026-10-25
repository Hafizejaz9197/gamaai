<?php
/**
 * REST routes for reading pages and running the draft workflow.
 *
 * @package GamaAI
 */

namespace GamaAI\Rest;

use GamaAI\Avada\ElementSettings;
use GamaAI\Avada\PageModel;
use GamaAI\Avada\RegistryCollector;
use GamaAI\Avada\ShortcodeParser;
use GamaAI\Compatibility;
use GamaAI\Drafts\DraftException;
use GamaAI\Drafts\DraftManager;
use GamaAI\Edits\AiContext;
use GamaAI\Edits\EditCompiler;
use GamaAI\Edits\EditException;
use GamaAI\Edits\QuickEdits;
use GamaAI\Saas\SaasClient;
use GamaAI\Saas\SaasException;
use GamaAI\Tasks\Pins;
use GamaAI\Tasks\TaskStore;

/**
 * Routes under /wp-json/gama-ai/v1. Every route needs a logged-in user with
 * the matching WordPress capability; nothing is public.
 */
class Controller {

	const NAMESPACE_V1 = 'gama-ai/v1';

	/** Longest image data URL an AI request takes: about 1.5 MB of picture. */
	const MAX_IMAGE_LENGTH = 2000000;

	/** @var DraftManager */
	private $drafts;

	/** @var SaasClient */
	private $saas;

	/** @var TaskStore */
	private $tasks;

	/**
	 * Constructor.
	 *
	 * @param DraftManager   $drafts Draft workflow.
	 * @param SaasClient     $saas   Gama AI service.
	 * @param TaskStore|null $tasks  Pinned tasks.
	 */
	public function __construct( DraftManager $drafts, SaasClient $saas, $tasks = null ) {
		$this->drafts = $drafts;
		$this->saas   = $saas;
		$this->tasks  = $tasks ? $tasks : new TaskStore();
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

		$edit_request = [
			'instruction' => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2000,
				'required'  => true,
			],
			'target'      => [
				'type'    => 'string',
				'pattern' => '^\\d+(\\.\\d+)*$',
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
			'/pages/(?P<id>\d+)/edits',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'edit' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id + [
					'operations' => [
						'type'     => 'array',
						'items'    => [ 'type' => 'object' ],
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/ai-edit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ai_edit' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id + $edit_request + [
					// A picture that shows what the editor means, shrunk by the command bar. Sent to the AI only; never saved.
					'image' => [
						'type'      => 'string',
						'maxLength' => self::MAX_IMAGE_LENGTH,
						'pattern'   => '^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/]++={0,2}$',
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/ai-context',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'ai_context' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id + $edit_request,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/marks',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'marks' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/tasks',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_tasks' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
				'args'                => [
					'page_id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/tasks',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_task' ],
				'permission_callback' => [ $this, 'can_edit_page' ],
				'args'                => $id + $edit_request,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/tasks/(?P<id>\d+)/fix',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'fix_task' ],
				'permission_callback' => [ $this, 'can_edit_task' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/tasks/(?P<id>\d+)',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_task' ],
					'permission_callback' => [ $this, 'can_edit_task' ],
					'args'                => $id + [
						'status' => [
							'type'     => 'string',
							'enum'     => [ 'open', 'done' ],
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_task' ],
					'permission_callback' => [ $this, 'can_edit_task' ],
					'args'                => $id,
				],
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'saas_url'   => [
							'type'   => 'string',
							'format' => 'uri',
						],
						'site_token' => [
							'type'    => 'string',
							'pattern' => '^gsk_[A-Za-z0-9]{20,100}$',
						],
					],
				],
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
			'/drafts/(?P<id>\d+)/undo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'undo' ],
				'permission_callback' => [ $this, 'can_edit_linked' ],
				'args'                => $id,
			]
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/drafts/(?P<id>\d+)/discard',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'discard' ],
				'permission_callback' => [ $this, 'can_edit_linked' ],
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
	 * Permission: the user manages the site.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
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
	 * Permission: the user can edit the page a draft belongs to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_linked( $request ) {
		$source_id = $this->drafts->source_of( (int) $request['id'] );
		return $source_id > 0 && current_user_can( 'edit_post', $source_id );
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
	 * GET /pages/{id}/model. When the page has an open draft, the model is
	 * the draft's, because that is what edit operations change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function model( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'gama_ai_not_found', 'Page not found.', [ 'status' => 404 ] );
		}
		$draft_id = $this->drafts->find_draft( (int) $post->ID );
		$content  = $draft_id ? get_post( $draft_id )->post_content : $post->post_content;
		$registry = $this->current_registry();
		return rest_ensure_response(
			[
				'id'               => (int) $post->ID,
				'title'            => $post->post_title,
				'draft_id'         => $draft_id,
				'round_trips'      => $this->drafts->round_trips( $content ),
				'defaults_omitted' => null !== $registry,
				'sections'         => ( new PageModel( $registry ) )->build( ( new ShortcodeParser() )->parse( $content ) ),
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
	 * POST /pages/{id}/edits. Applies edit operations to the page's draft,
	 * creating the draft if needed. The live page is not changed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function edit( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		$registry = $this->current_registry();
		if ( null === $registry ) {
			return new \WP_Error( 'gama_ai_no_registry', 'Avada element settings for this version are not known yet. Open any page in Avada Builder once.', [ 'status' => 409 ] );
		}
		$compiler   = new EditCompiler( $registry, 'wp_kses_post', [ $this, 'sanitize_setting' ], [ $this, 'image_url' ], [ $this, 'image_id' ] );
		$operations = $request['operations'];
		try {
			$draft_id = $this->drafts->edit_draft(
				(int) $request['id'],
				function ( $content ) use ( $compiler, $operations ) {
					return $compiler->apply( $content, $operations );
				}
			);
		} catch ( EditException $e ) {
			return new \WP_Error( 'gama_ai_edit', $e->getMessage(), [ 'status' => 400, 'operation' => $e->operation_index() ] );
		} catch ( DraftException $e ) {
			return new \WP_Error( 'gama_ai_draft', $e->getMessage(), [ 'status' => 400 ] );
		}

		$draft = get_post( $draft_id );
		return rest_ensure_response(
			[
				'draft_id'    => $draft_id,
				'preview_url' => get_preview_post_link( $draft_id ),
				'sections'    => ( new PageModel( $registry ) )->build( ( new ShortcodeParser() )->parse( $draft->post_content ) ),
			]
		);
	}

	/**
	 * POST /pages/{id}/ai-edit. Runs the editor's request on the page (its
	 * draft, if open) and writes the resulting operations to the draft. The
	 * live page is not changed.
	 *
	 * Simple commands with a clear target (delete, duplicate, hide, show) are
	 * turned into operations here without the AI (QuickEdits). Everything
	 * else goes to the Gama AI service with only the part of the page and the
	 * settings the request needs (AiContext). The draft write happens once,
	 * after a successful answer; nothing here retries it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ai_edit( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		$page = $this->edit_input( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		$image = isset( $request['image'] ) && '' !== $request['image'] ? (string) $request['image'] : null;
		$result = $this->run_edit( $page, (string) $request['instruction'], $image );
		return $result instanceof \WP_Error ? $result : rest_ensure_response( $result );
	}

	/**
	 * Turn a request into operations (without the AI when it is simple) and
	 * write them to the page's draft. The draft write happens once, after a
	 * successful answer; nothing here retries it.
	 *
	 * @param array       $page        edit_input() result.
	 * @param string      $instruction Request.
	 * @param string|null $image       Attached picture as a data URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_edit( array $page, $instruction, $image = null ) {
		list( $page_id, $root, $registry, $draft_id, $target ) = $page;

		// A request with a picture needs the AI to look at it.
		$quick = null === $image ? ( new QuickEdits( $registry ) )->handle( $instruction, $root, $target ) : null;
		if ( null !== $quick ) {
			$result = $quick + [
				'quick' => true,
				'usage' => null,
			];
		} else {
			$context = ( new AiContext( $registry, [ $this, 'recent_images' ] ) )->build( $instruction, $root, $target );
			try {
				$answer = $this->saas->request_edit( $page_id, $instruction, $context['sections'], $context['elements'], $context['scope'], $context['stats'], $context['media'], $image );
			} catch ( SaasException $e ) {
				return new \WP_Error( 'gama_ai_service', $e->getMessage(), [ 'status' => $e->status() ] + $e->data() + [ 'context' => $context['stats'] ] );
			}
			$result = [
				'summary'    => isset( $answer['summary'] ) ? (string) $answer['summary'] : '',
				'operations' => isset( $answer['operations'] ) && is_array( $answer['operations'] ) ? $answer['operations'] : [],
				'quick'      => false,
				'usage'      => isset( $answer['usage'] ) ? $answer['usage'] : null,
				'context'    => $context['stats'],
			];
		}

		$operations = $result['operations'];
		if ( ! $operations ) {
			return $result + [ 'draft_id' => $draft_id ];
		}

		$compiler = new EditCompiler( $registry, 'wp_kses_post', [ $this, 'sanitize_setting' ], [ $this, 'image_url' ], [ $this, 'image_id' ] );
		try {
			$draft_id = $this->drafts->edit_draft(
				$page_id,
				function ( $content ) use ( $compiler, $operations ) {
					return $compiler->apply( $content, $operations );
				}
			);
		} catch ( EditException $e ) {
			// An AI answer was already paid for, so it is returned with the error.
			return new \WP_Error( 'gama_ai_edit', $e->getMessage(), [ 'status' => 422, 'operation' => $e->operation_index() ] + $result );
		} catch ( DraftException $e ) {
			return new \WP_Error( 'gama_ai_draft', $e->getMessage(), [ 'status' => 400 ] );
		}

		return $result + [
			'draft_id'    => $draft_id,
			'preview_url' => get_preview_post_link( $draft_id ),
			'undo_steps'  => count( $this->drafts->undo_steps( $draft_id ) ),
			'sections'    => ( new PageModel( $registry ) )->build( ( new ShortcodeParser() )->parse( get_post( $draft_id )->post_content ) ),
		];
	}

	/**
	 * GET /pages/{id}/marks. Renders the page's layout (its draft, if open)
	 * the way the editor sees it and reports which elements got click marks,
	 * and where marking stopped. For diagnosis; nothing is saved.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function marks( $request ) {
		$page_id  = (int) $request['id'];
		$draft_id = $this->drafts->find_draft( $page_id );
		$post     = get_post( $draft_id ? $draft_id : $page_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'gama_ai_not_found', 'Page not found.', [ 'status' => 404 ] );
		}
		$marks        = new \GamaAI\Frontend\ElementMarks();
		$marks->trace = [];
		$open         = function ( $value, $tag ) use ( $marks ) {
			if ( false === $value ) {
				$marks->open( $tag );
			}
			return $value;
		};
		$close        = function ( $output, $tag ) use ( $marks ) {
			return $marks->close( $output, $tag );
		};
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		add_filter( 'pre_do_shortcode_tag', $open, PHP_INT_MAX, 2 );
		add_filter( 'do_shortcode_tag', $close, PHP_INT_MAX, 2 );
		$marks->begin( $post->post_content );
		$html = do_shortcode( $post->post_content );
		$marks->end();
		remove_filter( 'pre_do_shortcode_tag', $open, PHP_INT_MAX );
		remove_filter( 'do_shortcode_tag', $close, PHP_INT_MAX );
		wp_reset_postdata();

		preg_match_all( '/data-gama-id="([^"]+)" data-gama-type="([^"]+)"/', $html, $found, PREG_SET_ORDER );
		return rest_ensure_response(
			[
				'post_id' => (int) $post->ID,
				'marked'  => array_map(
					function ( $m ) {
						return $m[1] . ' ' . $m[2];
					},
					$found
				),
				'trace'   => array_slice( $marks->trace, 0, 200 ),
			]
		);
	}

	/**
	 * GET /tasks. Tasks of one page (page_id) or of every page the user can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_tasks( $request ) {
		$page_id = isset( $request['page_id'] ) ? (int) $request['page_id'] : null;
		$tasks   = array_values(
			array_filter(
				$this->tasks->all( $page_id ?: null ),
				function ( $task ) {
					return current_user_can( 'edit_post', $task['page_id'] );
				}
			)
		);
		return rest_ensure_response( [ 'tasks' => $tasks ] );
	}

	/**
	 * POST /pages/{id}/tasks. Pin a request to the page or one element on it.
	 * Nothing is sent to the AI until the task is fixed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_task( $request ) {
		$page = $this->edit_input( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		list( $page_id, $root, , , $target ) = $page;
		$pin = null === $target ? null : Pins::make( $root, $target );
		return rest_ensure_response( [ 'task' => $this->tasks->create( $page_id, (string) $request['instruction'], $pin, get_current_user_id() ) ] );
	}

	/**
	 * POST /tasks/{id}/fix. Run the task's request on its page, into the draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fix_task( $request ) {
		$blocked = $this->write_blocked();
		if ( $blocked ) {
			return $blocked;
		}
		$task = $this->tasks->get( (int) $request['id'] );
		if ( null === $task ) {
			return new \WP_Error( 'gama_ai_not_found', 'Task not found.', [ 'status' => 404 ] );
		}
		$input = new \WP_REST_Request( 'POST' );
		$input->set_param( 'id', $task['page_id'] );
		$page = $this->edit_input( $input );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		if ( null !== $task['pin'] ) {
			$page[4] = Pins::locate( $page[1], $task['pin'] );
			if ( null === $page[4] ) {
				$this->tasks->update( $task['id'], [ 'error' => 'The pinned element changed. Pin it again.' ] );
				return new \WP_Error( 'gama_ai_pin_lost', 'The element this task was pinned to has changed or moved, so it cannot be found for sure. Delete the task and pin it again.', [ 'status' => 409 ] );
			}
		}

		$result = $this->run_edit( $page, $task['instruction'] );
		if ( $result instanceof \WP_Error ) {
			$this->tasks->update( $task['id'], [ 'error' => $result->get_error_message() ] );
			$data         = (array) $result->get_error_data();
			$data['task'] = $this->tasks->get( $task['id'] );
			$result->add_data( $data );
			return $result;
		}
		$changed        = ! empty( $result['operations'] );
		$result['task'] = $this->tasks->update(
			$task['id'],
			[
				'status'  => $changed ? 'review' : 'open',
				'summary' => $result['summary'],
				'error'   => $changed ? '' : 'Nothing was changed.',
			]
		);
		return rest_ensure_response( $result );
	}

	/**
	 * POST /tasks/{id}. Set a task's status by hand (open or done).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_task( $request ) {
		$task = $this->tasks->update( (int) $request['id'], [ 'status' => (string) $request['status'] ] );
		return null === $task ? new \WP_Error( 'gama_ai_not_found', 'Task not found.', [ 'status' => 404 ] ) : rest_ensure_response( [ 'task' => $task ] );
	}

	/**
	 * DELETE /tasks/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_task( $request ) {
		$this->tasks->delete( (int) $request['id'] );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * Permission: the user can edit the page a task belongs to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_task( $request ) {
		$task = $this->tasks->get( (int) $request['id'] );
		return null !== $task && current_user_can( 'edit_post', $task['page_id'] );
	}

	/**
	 * URL of a media library image, or null when the id is not an image.
	 *
	 * @param int $id Attachment id.
	 * @return string|null
	 */
	public function image_url( $id ) {
		if ( ! wp_attachment_is_image( (int) $id ) ) {
			return null;
		}
		$url = wp_get_attachment_url( (int) $id );
		return $url ? (string) $url : null;
	}

	/**
	 * Media library id of an image URL, or null. Also finds resized copies
	 * such as photo-300x200.jpg.
	 *
	 * @param string $url Image URL.
	 * @return int|null
	 */
	public function image_id( $url ) {
		$id = attachment_url_to_postid( (string) $url );
		if ( ! $id ) {
			$id = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', (string) $url ) );
		}
		return $id && wp_attachment_is_image( $id ) ? (int) $id : null;
	}

	/**
	 * The newest images in the media library, for requests that add or
	 * change images.
	 *
	 * @param int $limit How many.
	 * @return array<int,array{id:int,url:string,title:string}>
	 */
	public function recent_images( $limit ) {
		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);
		$out = [];
		foreach ( $posts as $post ) {
			$url = wp_get_attachment_url( $post->ID );
			if ( $url ) {
				$alt   = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
				$out[] = [
					'id'    => (int) $post->ID,
					'url'   => (string) $url,
					'title' => '' !== $alt ? $alt : (string) $post->post_title,
				];
			}
		}
		return $out;
	}

	/**
	 * GET /pages/{id}/ai-context. What an AI request with this instruction
	 * would send, and how big it is, without sending anything. Also says
	 * whether the request would be handled without the AI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ai_context( $request ) {
		$page = $this->edit_input( $request );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}
		list( , $root, $registry, , $target ) = $page;
		$instruction = (string) $request['instruction'];
		$quick       = ( new QuickEdits( $registry ) )->handle( $instruction, $root, $target );
		$builder     = new AiContext( $registry, [ $this, 'recent_images' ] );
		$context     = $builder->build( $instruction, $root, $target );
		return rest_ensure_response(
			[
				'quick'          => $quick,
				'context'        => $context,
				'before_change'  => $builder->full_size( $root ),
			]
		);
	}

	/**
	 * Shared input of the edit routes: the page, its parsed layout (the
	 * draft's, if open), the registry and the picked element.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0:int,1:\GamaAI\Avada\Node,2:\GamaAI\Avada\Registry,3:int|null,4:string|null}|\WP_Error
	 */
	private function edit_input( $request ) {
		$registry = $this->current_registry();
		if ( null === $registry ) {
			return new \WP_Error( 'gama_ai_no_registry', 'Avada element settings for this version are not known yet. Open any page in Avada Builder once.', [ 'status' => 409 ] );
		}
		$page_id = (int) $request['id'];
		$post    = get_post( $page_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'gama_ai_not_found', 'Page not found.', [ 'status' => 404 ] );
		}
		$draft_id = $this->drafts->find_draft( $page_id );
		$root     = ( new ShortcodeParser() )->parse( $draft_id ? get_post( $draft_id )->post_content : $post->post_content );
		$target   = isset( $request['target'] ) && '' !== (string) $request['target'] ? (string) $request['target'] : null;
		if ( null !== $target && null === PageModel::find( $root, $target ) ) {
			return new \WP_Error( 'gama_ai_target', 'The picked part of the page is no longer there. Reload the page and pick it again.', [ 'status' => 409 ] );
		}
		return [ $page_id, $root, $registry, $draft_id, $target ];
	}

	/**
	 * POST /drafts/{id}/undo. Undo the last change made to the draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( $request ) {
		return $this->run(
			function () use ( $request ) {
				$draft_id = (int) $request['id'];
				return [
					'draft_id'    => $draft_id,
					'preview_url' => get_preview_post_link( $draft_id ),
					'undo_steps'  => $this->drafts->undo_draft_change( $draft_id ),
				];
			}
		);
	}

	/**
	 * GET /settings. The token itself is never returned.
	 *
	 * @return \WP_REST_Response
	 */
	public function settings() {
		return rest_ensure_response(
			[
				'saas_url'  => (string) get_option( SaasClient::OPTION_URL, '' ),
				'token_set' => '' !== (string) get_option( SaasClient::OPTION_TOKEN, '' ),
			]
		);
	}

	/**
	 * POST /settings. Saves the service URL and token, then checks in with
	 * the service so a wrong URL or token shows up straight away.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_settings( $request ) {
		if ( isset( $request['saas_url'] ) ) {
			$url = esc_url_raw( (string) $request['saas_url'], [ 'https' ] );
			if ( '' === $url ) {
				return new \WP_Error( 'gama_ai_settings', 'The service URL must start with https://.', [ 'status' => 400 ] );
			}
			update_option( SaasClient::OPTION_URL, $url, false );
		}
		if ( isset( $request['site_token'] ) ) {
			update_option( SaasClient::OPTION_TOKEN, (string) $request['site_token'], false );
		}

		$client = SaasClient::from_options();
		if ( ! $client->is_configured() ) {
			return $this->settings();
		}
		try {
			$check_in = $client->check_in( Compatibility::report() );
		} catch ( SaasException $e ) {
			return new \WP_Error( 'gama_ai_service', 'Saved, but the service check failed: ' . $e->getMessage(), [ 'status' => $e->status() ] );
		}
		return rest_ensure_response( $this->settings()->get_data() + [ 'usage' => isset( $check_in['usage'] ) ? $check_in['usage'] : null ] );
	}

	/**
	 * Clean a free-form setting value by its Avada setting type.
	 *
	 * @param string              $value Value.
	 * @param array<string,mixed> $param Registry param.
	 * @return string
	 */
	public function sanitize_setting( $value, array $param ) {
		switch ( $param['type'] ) {
			case 'link_selector':
			case 'upload':
				return esc_url_raw( $value );
			case 'textarea':
			case 'tinymce':
				return wp_kses_post( $value );
			default:
				return sanitize_text_field( $value );
		}
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
				$source_id   = $this->drafts->source_of( (int) $request['id'] );
				$snapshot_id = $this->drafts->publish( (int) $request['id'], get_current_user_id() );
				$this->tasks->move_all( $source_id, 'review', 'done' );
				return [
					'snapshot_id' => $snapshot_id,
					'page_url'    => get_permalink( $source_id ),
				];
			}
		);
	}

	/**
	 * POST /drafts/{id}/discard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function discard( $request ) {
		return $this->run(
			function () use ( $request ) {
				$source_id = $this->drafts->discard( (int) $request['id'] );
				$this->tasks->move_all( $source_id, 'review', 'open' );
				return [
					'page_id'  => $source_id,
					'page_url' => get_permalink( $source_id ),
				];
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
	 * The stored registry, if it was read from the running Avada Builder.
	 * A registry from another version could hide settings that are no longer
	 * defaults, or allow settings that no longer exist.
	 *
	 * @return \GamaAI\Avada\Registry|null
	 */
	private function current_registry() {
		$registry = RegistryCollector::load();
		$report   = Compatibility::report();
		return $registry && $registry->builder_version() === $report['avada_builder'] ? $registry : null;
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
