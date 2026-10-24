<?php
/**
 * In-memory PostStore for tests.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Support;

use GamaAI\Drafts\PostStore;

class InMemoryPostStore implements PostStore {

	/** @var array<int,array<string,mixed>> */
	public $posts = [];

	/** @var array<int,array<string,array<int,mixed>>> */
	public $meta = [];

	private $next_id = 1;

	public function add( array $post, array $meta = [] ) {
		return $this->insert( $post, $meta );
	}

	public function get( $id ) {
		return isset( $this->posts[ $id ] ) ? $this->posts[ $id ] : null;
	}

	public function get_meta( $id ) {
		return isset( $this->meta[ $id ] ) ? $this->meta[ $id ] : [];
	}

	public function insert( array $post, array $meta ) {
		$id                 = $this->next_id++;
		$this->posts[ $id ] = array_merge( [ 'title' => '', 'content' => '' ], $post, [ 'id' => $id ] );
		$this->meta[ $id ]  = [];
		foreach ( $meta as $key => $values ) {
			$this->set_meta( $id, $key, $values );
		}
		return $id;
	}

	public function update_content( $id, $content ) {
		$this->posts[ $id ]['content'] = $content;
	}

	public function set_meta( $id, $key, array $values ) {
		if ( [] === $values ) {
			unset( $this->meta[ $id ][ $key ] );
			return;
		}
		$this->meta[ $id ][ $key ] = array_values( $values );
	}

	public function delete( $id ) {
		unset( $this->posts[ $id ], $this->meta[ $id ] );
	}

	public function find_by_meta( $type, $key, $value ) {
		$ids = [];
		foreach ( $this->posts as $id => $post ) {
			if ( $post['type'] === $type && isset( $this->meta[ $id ][ $key ] ) && in_array( $value, $this->meta[ $id ][ $key ], true ) ) {
				$ids[] = $id;
			}
		}
		rsort( $ids );
		return $ids;
	}
}
