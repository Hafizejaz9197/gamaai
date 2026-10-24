<?php
/**
 * Tests for draft, publish and rollback.
 *
 * @package GamaAI
 */

namespace GamaAI\Tests\Unit;

use GamaAI\Drafts\DraftException;
use GamaAI\Drafts\DraftManager;
use GamaAI\Tests\Support\InMemoryPostStore;
use PHPUnit\Framework\TestCase;

class DraftManagerTest extends TestCase {

	const LIVE = '[fusion_builder_container][fusion_text]Old[/fusion_text][/fusion_builder_container]';
	const NEW  = '[fusion_builder_container][fusion_text]New[/fusion_text][/fusion_builder_container]';

	/** @var InMemoryPostStore */
	private $store;

	/** @var DraftManager */
	private $drafts;

	/** @var int */
	private $page;

	protected function setUp(): void {
		$this->store  = new InMemoryPostStore();
		$this->drafts = new DraftManager( $this->store );
		$this->page   = $this->store->add(
			[
				'type'    => 'page',
				'status'  => 'publish',
				'title'   => 'Home',
				'content' => self::LIVE,
			],
			[
				'_fusion'    => [ [ 'main_padding' => [ 'top' => '0' ] ] ],
				'_edit_lock' => [ '123:1' ],
			]
		);
	}

	public function test_draft_copies_content_and_meta_but_leaves_live_page_alone() {
		$draft = $this->drafts->create_draft( $this->page );

		$this->assertSame( 'draft', $this->store->get( $draft )['status'] );
		$this->assertSame( self::LIVE, $this->store->get( $draft )['content'] );
		$this->assertSame( [ [ 'main_padding' => [ 'top' => '0' ] ] ], $this->store->get_meta( $draft )['_fusion'] );
		$this->assertArrayNotHasKey( '_edit_lock', $this->store->get_meta( $draft ) );

		$this->drafts->update_draft( $draft, self::NEW );
		$this->assertSame( self::LIVE, $this->store->get( $this->page )['content'] );
	}

	public function test_second_create_returns_the_open_draft() {
		$this->assertSame( $this->drafts->create_draft( $this->page ), $this->drafts->create_draft( $this->page ) );
	}

	public function test_publish_applies_draft_and_removes_it() {
		$draft = $this->drafts->create_draft( $this->page );
		$this->drafts->update_draft( $draft, self::NEW );
		$this->store->set_meta( $draft, '_fusion', [ [ 'main_padding' => [ 'top' => '40px' ] ] ] );

		$this->drafts->publish( $draft, 7 );

		$this->assertSame( self::NEW, $this->store->get( $this->page )['content'] );
		$this->assertSame( [ [ 'main_padding' => [ 'top' => '40px' ] ] ], $this->store->get_meta( $this->page )['_fusion'] );
		$this->assertSame( [ '123:1' ], $this->store->get_meta( $this->page )['_edit_lock'] );
		$this->assertNull( $this->store->get( $draft ) );
		$this->assertNull( $this->drafts->find_draft( $this->page ) );
	}

	public function test_rollback_restores_content_and_meta_and_can_be_undone() {
		$draft = $this->drafts->create_draft( $this->page );
		$this->drafts->update_draft( $draft, self::NEW );
		$this->store->set_meta( $draft, '_fusion', [ [ 'layout' => 'wide' ] ] );
		$this->store->set_meta( $draft, 'added_by_draft', [ 'x' ] );
		$snapshot = $this->drafts->publish( $draft, 7 );

		$undo = $this->drafts->rollback( $snapshot, 7 );

		$this->assertSame( self::LIVE, $this->store->get( $this->page )['content'] );
		$this->assertSame( [ [ 'main_padding' => [ 'top' => '0' ] ] ], $this->store->get_meta( $this->page )['_fusion'] );
		$this->assertArrayNotHasKey( 'added_by_draft', $this->store->get_meta( $this->page ) );

		$this->drafts->rollback( $undo, 7 );
		$this->assertSame( self::NEW, $this->store->get( $this->page )['content'] );
		$this->assertSame( [ 'x' ], $this->store->get_meta( $this->page )['added_by_draft'] );
	}

	public function test_snapshots_are_listed_newest_first() {
		$draft    = $this->drafts->create_draft( $this->page );
		$snapshot = $this->drafts->publish( $draft, 7 );
		$undo     = $this->drafts->rollback( $snapshot, 9 );

		$list = $this->drafts->list_snapshots( $this->page );
		$this->assertSame( [ $undo, $snapshot ], array_column( $list, 'id' ) );
		$this->assertSame( [ 'rollback', 'publish' ], array_column( $list, 'reason' ) );
		$this->assertSame( 9, $list[0]['user_id'] );
	}

	public function test_refuses_other_post_types_and_drafts_as_sources() {
		$post = $this->store->add( [ 'type' => 'post', 'status' => 'publish', 'content' => '' ] );
		try {
			$this->drafts->create_draft( $post );
			$this->fail( 'Posts are not editable in the MVP.' );
		} catch ( DraftException $e ) {
			$this->assertSame( 'Page not found or not editable.', $e->getMessage() );
		}

		$draft = $this->drafts->create_draft( $this->page );
		$this->expectException( DraftException::class );
		$this->drafts->create_draft( $draft );
	}

	public function test_publish_rejects_non_drafts() {
		$this->expectException( DraftException::class );
		$this->drafts->publish( $this->page, 7 );
	}
}
