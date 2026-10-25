<?php
/**
 * Tasks left on pages.
 *
 * @package GamaAI
 */

namespace GamaAI\Tasks;

/**
 * A task is a request pinned to a page, or to one element on it, kept
 * until the AI is asked to fix it. Stored as a private post type: the page
 * is post_parent, the request is post_content, and the rest is meta.
 *
 * Status: open (waiting for Fix), review (the fix is in the page's draft),
 * done (published, or marked done by hand).
 */
class TaskStore {

	const POST_TYPE = 'gama_ai_task';
	const META      = '_gama_ai_task';
	const STATUSES  = [ 'open', 'review', 'done' ];

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register_type() {
		register_post_type(
			self::POST_TYPE,
			[
				'label'               => 'Gama AI tasks',
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => [ 'title', 'editor', 'author' ],
			]
		);
	}

	/**
	 * Add a task.
	 *
	 * @param int                      $page_id     Page.
	 * @param string                   $instruction Request.
	 * @param array<string,mixed>|null $pin         Pins::make() result, or null for the whole page.
	 * @param int                      $author      User.
	 * @return array<string,mixed> The task.
	 */
	public function create( $page_id, $instruction, $pin, $author ) {
		$id = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_parent'  => (int) $page_id,
				'post_author'  => (int) $author,
				'post_title'   => wp_trim_words( $instruction, 12, '…' ),
				'post_content' => $instruction,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		update_post_meta(
			$id,
			self::META,
			wp_slash(
				[
					'status'  => 'open',
					'pin'     => $pin,
					'summary' => '',
					'error'   => '',
				]
			)
		);
		return $this->get( $id );
	}

	/**
	 * One task.
	 *
	 * @param int $id Task id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return $this->shape( $post );
	}

	/**
	 * Tasks of one page, or of every page, newest first.
	 *
	 * @param int|null $page_id Page, or null for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( $page_id = null ) {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( null !== $page_id ) {
			$args['post_parent'] = (int) $page_id;
		}
		return array_map( [ $this, 'shape' ], get_posts( $args ) );
	}

	/**
	 * Change a task's status, summary or error.
	 *
	 * @param int                 $id     Task id.
	 * @param array<string,mixed> $fields status, summary, error.
	 * @return array<string,mixed>|null
	 */
	public function update( $id, array $fields ) {
		$meta = get_post_meta( (int) $id, self::META, true );
		if ( ! is_array( $meta ) ) {
			return null;
		}
		foreach ( [ 'status', 'summary', 'error' ] as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$meta[ $key ] = (string) $fields[ $key ];
			}
		}
		update_post_meta( (int) $id, self::META, wp_slash( $meta ) );
		return $this->get( $id );
	}

	/**
	 * Move every task of a page from one status to another, such as review
	 * to done when the draft is published.
	 *
	 * @param int    $page_id Page.
	 * @param string $from    Status.
	 * @param string $to      Status.
	 * @return void
	 */
	public function move_all( $page_id, $from, $to ) {
		foreach ( $this->all( $page_id ) as $task ) {
			if ( $task['status'] === $from ) {
				$this->update( $task['id'], [ 'status' => $to ] );
			}
		}
	}

	/**
	 * Delete a task.
	 *
	 * @param int $id Task id.
	 * @return void
	 */
	public function delete( $id ) {
		wp_delete_post( (int) $id, true );
	}

	/**
	 * A task as the REST routes return it.
	 *
	 * @param \WP_Post $post Task post.
	 * @return array<string,mixed>
	 */
	private function shape( \WP_Post $post ) {
		$meta = get_post_meta( $post->ID, self::META, true );
		$meta = is_array( $meta ) ? $meta : [];
		$page = (int) $post->post_parent;
		return [
			'id'          => (int) $post->ID,
			'page_id'     => $page,
			'page_title'  => get_the_title( $page ),
			'page_url'    => get_permalink( $page ),
			'instruction' => (string) $post->post_content,
			'status'      => isset( $meta['status'] ) ? (string) $meta['status'] : 'open',
			'pin'         => isset( $meta['pin'] ) && is_array( $meta['pin'] ) ? $meta['pin'] : null,
			'summary'     => isset( $meta['summary'] ) ? (string) $meta['summary'] : '',
			'error'       => isset( $meta['error'] ) ? (string) $meta['error'] : '',
			'author'      => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'created'     => get_post_time( 'c', true, $post ),
		];
	}
}
