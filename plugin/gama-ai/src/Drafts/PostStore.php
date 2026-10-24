<?php
/**
 * Storage the draft workflow needs, kept behind an interface so it can be
 * tested without WordPress.
 *
 * @package GamaAI
 */

namespace GamaAI\Drafts;

/**
 * Post and post-meta storage.
 *
 * Meta is handled as key => list of values, matching get_post_meta( $id ),
 * so keys with several values survive a copy or a restore.
 */
interface PostStore {

	/**
	 * Get a post as [ 'id', 'type', 'status', 'title', 'content' ], or null.
	 *
	 * @param int $id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get( $id );

	/**
	 * All meta of a post.
	 *
	 * @param int $id Post ID.
	 * @return array<string,array<int,mixed>>
	 */
	public function get_meta( $id );

	/**
	 * Insert a post with meta. Returns the new ID.
	 *
	 * @param array<string,mixed>            $post Fields: type, status, title, content.
	 * @param array<string,array<int,mixed>> $meta Meta.
	 * @return int
	 */
	public function insert( array $post, array $meta );

	/**
	 * Update a post's content.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content New content.
	 * @return void
	 */
	public function update_content( $id, $content );

	/**
	 * Replace all values of one meta key. An empty list deletes the key.
	 *
	 * @param int            $id     Post ID.
	 * @param string         $key    Meta key.
	 * @param array<int,mixed> $values Values.
	 * @return void
	 */
	public function set_meta( $id, $key, array $values );

	/**
	 * Permanently delete a post.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	public function delete( $id );

	/**
	 * IDs of posts of a type whose meta key equals a value, newest first.
	 *
	 * @param string $type  Post type.
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return int[]
	 */
	public function find_by_meta( $type, $key, $value );
}
