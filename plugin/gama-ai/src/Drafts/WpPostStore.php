<?php
/**
 * PostStore backed by the standard WordPress post APIs.
 *
 * @package GamaAI
 */

namespace GamaAI\Drafts;

/**
 * WordPress implementation of PostStore.
 */
class WpPostStore implements PostStore {

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Post ID.
	 */
	public function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return [
			'id'      => (int) $post->ID,
			'type'    => $post->post_type,
			'status'  => $post->post_status,
			'title'   => $post->post_title,
			'content' => $post->post_content,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Post ID.
	 */
	public function get_meta( $id ) {
		$meta = get_post_meta( (int) $id );
		if ( ! is_array( $meta ) ) {
			return [];
		}
		foreach ( $meta as $key => $values ) {
			$meta[ $key ] = array_map( 'maybe_unserialize', $values );
		}
		return $meta;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed>            $post Fields.
	 * @param array<string,array<int,mixed>> $meta Meta.
	 * @throws DraftException When WordPress refuses the insert.
	 */
	public function insert( array $post, array $meta ) {
		$id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => $post['type'],
					'post_status'  => $post['status'],
					'post_title'   => $post['title'],
					'post_content' => $post['content'],
				]
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new DraftException( $id->get_error_message() );
		}
		foreach ( $meta as $key => $values ) {
			$this->set_meta( $id, $key, $values );
		}
		return (int) $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id      Post ID.
	 * @param string $content Content.
	 * @throws DraftException When WordPress refuses the update.
	 */
	public function update_content( $id, $content ) {
		$result = wp_update_post(
			wp_slash(
				[
					'ID'           => (int) $id,
					'post_content' => $content,
				]
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new DraftException( $result->get_error_message() );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int              $id     Post ID.
	 * @param string           $key    Meta key.
	 * @param array<int,mixed> $values Values.
	 */
	public function set_meta( $id, $key, array $values ) {
		delete_post_meta( (int) $id, $key );
		foreach ( $values as $value ) {
			add_post_meta( (int) $id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Post ID.
	 */
	public function delete( $id ) {
		wp_delete_post( (int) $id, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $type  Post type.
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 */
	public function find_by_meta( $type, $key, $value ) {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'        => $type,
					'post_status'      => 'any',
					'meta_key'         => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $value, // phpcs:ignore WordPress.DB.SlowDBQuery
					'orderby'          => 'ID',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'numberposts'      => 50,
					'suppress_filters' => true,
				]
			)
		);
	}
}
