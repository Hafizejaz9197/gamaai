<?php
/**
 * Draft, publish and rollback for Avada pages.
 *
 * @package GamaAI
 */

namespace GamaAI\Drafts;

use GamaAI\Avada\Serializer;
use GamaAI\Avada\ShortcodeParser;

/**
 * Keeps the live page untouched until an edit is approved.
 *
 * A draft is a separate post of the same type holding a copy of the page's
 * content and meta, which is how Avada's own "Clone" action copies a page
 * (AWB_Clone_Posts). Publishing copies the draft back onto the live page
 * after saving a snapshot of what it replaces; rollback restores a snapshot.
 * Writes go through WordPress' normal save path, which also fires the
 * save_post hook Avada uses to reset the page's compiled CSS.
 */
class DraftManager {

	const META_SOURCE   = '_gama_ai_source_id';
	const META_SNAPSHOT = '_gama_ai_snapshot';
	const META_UNDO     = '_gama_ai_undo';

	/** Draft changes that can be undone one by one. */
	const UNDO_STEPS = 10;
	const SNAPSHOT_TYPE = 'gama_ai_snapshot';

	/**
	 * Meta never copied between a page and its draft. The first five match
	 * the keys Avada's own clone action skips.
	 */
	const IGNORED_META = [
		'_edit_last',
		'_edit_lock',
		'avada_post_views_count',
		'avada_today_post_views_count',
		'avada_post_views_count_today_date',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		self::META_SOURCE,
		self::META_SNAPSHOT,
		self::META_UNDO,
	];

	/** @var PostStore */
	private $store;

	/** @var string[] */
	private $allowed_types;

	/**
	 * Constructor.
	 *
	 * @param PostStore $store         Storage.
	 * @param string[]  $allowed_types Post types that can be edited.
	 */
	public function __construct( PostStore $store, array $allowed_types = [ 'page' ] ) {
		$this->store         = $store;
		$this->allowed_types = $allowed_types;
	}

	/**
	 * Create a draft copy of a page, or return the one that already exists.
	 *
	 * @param int $source_id Live page ID.
	 * @return int Draft ID.
	 * @throws DraftException When the page cannot be drafted.
	 */
	public function create_draft( $source_id ) {
		$source = $this->require_source( $source_id );

		$existing = $this->find_draft( $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		if ( ! $this->round_trips( $source['content'] ) ) {
			throw new DraftException( 'This page contains layout markup Gama AI cannot read back unchanged, so it will not be edited.' );
		}

		$meta                       = $this->copyable_meta( $this->store->get_meta( $source_id ) );
		$meta[ self::META_SOURCE ] = [ (string) $source_id ];

		return $this->store->insert(
			[
				'type'    => $source['type'],
				'status'  => 'draft',
				'title'   => $source['title'] . ' (Gama AI draft)',
				'content' => $source['content'],
			],
			$meta
		);
	}

	/**
	 * The open draft for a page, if any.
	 *
	 * @param int $source_id Live page ID.
	 * @return int|null
	 */
	public function find_draft( $source_id ) {
		$source = $this->store->get( $source_id );
		if ( null === $source ) {
			return null;
		}
		foreach ( $this->store->find_by_meta( $source['type'], self::META_SOURCE, (string) $source_id ) as $id ) {
			$post = $this->store->get( $id );
			if ( $post && 'draft' === $post['status'] ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Replace a draft's layout markup.
	 *
	 * @param int    $draft_id Draft ID.
	 * @param string $content  New content.
	 * @return void
	 * @throws DraftException When the post is not a Gama AI draft.
	 */
	public function update_draft( $draft_id, $content ) {
		$this->require_draft( $draft_id );
		$this->store->update_content( $draft_id, $content );
	}

	/**
	 * Change a page's draft, creating the draft first if there is none.
	 *
	 * @param int                     $source_id Live page ID.
	 * @param callable(string):string $edit      Gets the draft's markup and returns the new markup.
	 * @return int Draft ID.
	 * @throws DraftException When the page cannot be drafted.
	 */
	public function edit_draft( $source_id, callable $edit ) {
		$draft_id = $this->create_draft( $source_id );
		$draft    = $this->require_draft( $draft_id );
		$content  = $edit( $draft['content'] );
		if ( $content === $draft['content'] ) {
			return $draft_id;
		}

		// Keep what this change replaces, so it can be undone on its own.
		$steps   = $this->undo_steps( $draft_id );
		$steps[] = $draft['content'];
		$this->store->set_meta( $draft_id, self::META_UNDO, [ array_slice( $steps, -self::UNDO_STEPS ) ] );
		$this->store->update_content( $draft_id, $content );
		return $draft_id;
	}

	/**
	 * Undo the last change made to a draft. The live page is not touched.
	 *
	 * @param int $draft_id Draft ID.
	 * @return int Changes that can still be undone.
	 * @throws DraftException When there is nothing to undo.
	 */
	public function undo_draft_change( $draft_id ) {
		$this->require_draft( $draft_id );
		$steps = $this->undo_steps( $draft_id );
		if ( ! $steps ) {
			throw new DraftException( 'There is no change to undo in this draft.' );
		}
		$previous = array_pop( $steps );
		$this->store->set_meta( $draft_id, self::META_UNDO, $steps ? [ $steps ] : [] );
		$this->store->update_content( $draft_id, $previous );
		return count( $steps );
	}

	/**
	 * Saved draft contents, oldest first.
	 *
	 * @param int $draft_id Draft ID.
	 * @return string[]
	 */
	public function undo_steps( $draft_id ) {
		$meta = $this->store->get_meta( $draft_id );
		return isset( $meta[ self::META_UNDO ][0] ) && is_array( $meta[ self::META_UNDO ][0] )
			? array_values( array_filter( $meta[ self::META_UNDO ][0], 'is_string' ) )
			: [];
	}

	/**
	 * Throw a draft away. The live page is not touched.
	 *
	 * @param int $draft_id Draft ID.
	 * @return int The live page ID the draft belonged to.
	 * @throws DraftException When the post is not a Gama AI draft.
	 */
	public function discard( $draft_id ) {
		$this->require_draft( $draft_id );
		$source_id = $this->source_of( $draft_id );
		$this->store->delete( $draft_id );
		return $source_id;
	}

	/**
	 * Copy an approved draft onto the live page.
	 *
	 * @param int $draft_id Draft ID.
	 * @param int $user_id  User approving the change.
	 * @return int Snapshot ID that undoes this publish.
	 * @throws DraftException When the draft or its page is missing.
	 */
	public function publish( $draft_id, $user_id ) {
		$draft     = $this->require_draft( $draft_id );
		$source_id = $this->source_of( $draft_id );
		$this->require_source( $source_id );

		$draft_meta = $this->copyable_meta( $this->store->get_meta( $draft_id ) );
		$snapshot   = $this->snapshot( $source_id, array_keys( $draft_meta ), $user_id, 'publish' );

		$this->store->update_content( $source_id, $draft['content'] );
		foreach ( $draft_meta as $key => $values ) {
			$this->store->set_meta( $source_id, $key, $values );
		}
		$this->store->delete( $draft_id );

		return $snapshot;
	}

	/**
	 * Restore a page to a snapshot. The current state is snapshotted first,
	 * so a rollback can itself be undone.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @param int $user_id     User asking for the rollback.
	 * @return int Snapshot ID that undoes this rollback.
	 * @throws DraftException When the snapshot or its page is missing.
	 */
	public function rollback( $snapshot_id, $user_id ) {
		$snapshot = $this->store->get( $snapshot_id );
		if ( null === $snapshot || self::SNAPSHOT_TYPE !== $snapshot['type'] ) {
			throw new DraftException( 'Snapshot not found.' );
		}
		$source_id = $this->source_of( $snapshot_id );
		$this->require_source( $source_id );

		$data = $this->snapshot_data( $snapshot_id );
		$undo = $this->snapshot( $source_id, array_keys( $data['meta'] ), $user_id, 'rollback' );

		$this->store->update_content( $source_id, $snapshot['content'] );
		foreach ( $data['meta'] as $key => $values ) {
			$this->store->set_meta( $source_id, $key, $values );
		}
		return $undo;
	}

	/**
	 * Snapshots of a page, newest first.
	 *
	 * @param int $source_id Live page ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_snapshots( $source_id ) {
		$out = [];
		foreach ( $this->store->find_by_meta( self::SNAPSHOT_TYPE, self::META_SOURCE, (string) $source_id ) as $id ) {
			$data  = $this->snapshot_data( $id );
			$out[] = [
				'id'         => $id,
				'reason'     => $data['reason'],
				'user_id'    => $data['user_id'],
				'created_at' => $data['created_at'],
			];
		}
		return $out;
	}

	/**
	 * Whether markup survives a parse and serialize unchanged.
	 *
	 * @param string $content Markup.
	 * @return bool
	 */
	public function round_trips( $content ) {
		$serializer = new Serializer();
		return $serializer->serialize( ( new ShortcodeParser() )->parse( $content ) ) === $content;
	}

	/**
	 * Save the current content and the given meta keys of a page.
	 *
	 * @param int      $source_id Live page ID.
	 * @param string[] $keys      Meta keys about to change.
	 * @param int      $user_id   Acting user.
	 * @param string   $reason    Why the snapshot was taken.
	 * @return int Snapshot ID.
	 */
	private function snapshot( $source_id, array $keys, $user_id, $reason ) {
		$source  = $this->store->get( $source_id );
		$current = $this->store->get_meta( $source_id );
		$saved   = [];
		foreach ( $keys as $key ) {
			// An empty list means "the key did not exist" and deletes it on restore.
			$saved[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : [];
		}

		return $this->store->insert(
			[
				'type'    => self::SNAPSHOT_TYPE,
				'status'  => 'private',
				'title'   => 'Snapshot of #' . $source_id,
				'content' => $source['content'],
			],
			[
				self::META_SOURCE   => [ (string) $source_id ],
				self::META_SNAPSHOT => [
					[
						'meta'       => $saved,
						'reason'     => $reason,
						'user_id'    => (int) $user_id,
						'created_at' => gmdate( 'c' ),
					],
				],
			]
		);
	}

	/**
	 * Stored snapshot data.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array<string,mixed>
	 */
	private function snapshot_data( $snapshot_id ) {
		$meta = $this->store->get_meta( $snapshot_id );
		$data = isset( $meta[ self::META_SNAPSHOT ][0] ) && is_array( $meta[ self::META_SNAPSHOT ][0] ) ? $meta[ self::META_SNAPSHOT ][0] : [];
		return array_merge(
			[
				'meta'       => [],
				'reason'     => '',
				'user_id'    => 0,
				'created_at' => '',
			],
			$data
		);
	}

	/**
	 * Meta minus keys that must not be copied.
	 *
	 * @param array<string,array<int,mixed>> $meta Meta.
	 * @return array<string,array<int,mixed>>
	 */
	private function copyable_meta( array $meta ) {
		foreach ( self::IGNORED_META as $key ) {
			unset( $meta[ $key ] );
		}
		return $meta;
	}

	/**
	 * The live page ID a draft or snapshot belongs to.
	 *
	 * @param int $id Draft or snapshot ID.
	 * @return int
	 */
	public function source_of( $id ) {
		$meta = $this->store->get_meta( $id );
		return isset( $meta[ self::META_SOURCE ][0] ) ? (int) $meta[ self::META_SOURCE ][0] : 0;
	}

	/**
	 * Load a live page or fail.
	 *
	 * @param int $source_id Page ID.
	 * @return array<string,mixed>
	 * @throws DraftException When it is missing or not editable.
	 */
	private function require_source( $source_id ) {
		$source = $source_id ? $this->store->get( $source_id ) : null;
		if ( null === $source || ! in_array( $source['type'], $this->allowed_types, true ) ) {
			throw new DraftException( 'Page not found or not editable.' );
		}
		if ( $this->source_of( $source_id ) > 0 ) {
			throw new DraftException( 'This is a Gama AI draft, not a live page.' );
		}
		return $source;
	}

	/**
	 * Load a Gama AI draft or fail.
	 *
	 * @param int $draft_id Draft ID.
	 * @return array<string,mixed>
	 * @throws DraftException When it is not a Gama AI draft.
	 */
	private function require_draft( $draft_id ) {
		$draft = $this->store->get( $draft_id );
		if ( null === $draft || 'draft' !== $draft['status'] || $this->source_of( $draft_id ) <= 0 ) {
			throw new DraftException( 'Draft not found.' );
		}
		return $draft;
	}
}
