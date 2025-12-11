<?php

namespace Wikivy\SimpleForum\Services;

use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\ILBFactory;

class ThreadService
{
	public function __construct(
		private readonly ILBFactory $lbFactory
	) {

	}

	/**
	 * List threads in a forum with metadata, sticky-first and last-activity ordering.
	 *
	 * @return array[] Each item:
	 *   [
	 *     'id' => int,
	 *     'title' => Title,
	 *     'subject' => string,
	 *     'created' => string|null,
	 *     'creator' => int|null,
	 *     'lastReply' => string|null,
	 *     'lastReplyUser' => int|null,
	 *     'sticky' => bool,
	 * 	   'locked' => bool
	 *   ]
	 */
	public function listThreadsForForum(
		Title $forumTitle,
		int $limit = 20,
		int $offset = 0
	): array {
		$dbr = $this->lbFactory->getReplicaDatabase();

		$forumPageId = $forumTitle->getArticleID();
		if ( !$forumPageId ) {
			return [];
		}

		$useMeta = $dbr->tableExists( 'simpleforum_threads', __METHOD__ );
		$hasProps = $dbr->tableExists( 'simpleforum_thread_props', __METHOD__ );

		if ( !$useMeta ) {
			// Fallback: old-style scan of Thread: pages matching ForumTitle/
			$forumKey = $forumTitle->getDBkey();
			$like = $dbr->buildLike( $forumKey . '/', $dbr->anyString() );

			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace' ],
				[
					'page_namespace' => NS_THREAD,
					"page_title $like"
				],
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC',
					'LIMIT'    => $limit,
					'OFFSET'   => $offset
				]
			);

			$threads = [];
			foreach ( $res as $row ) {
				$tTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
				if ( !$tTitle ) {
					continue;
				}

				$threads[] = [
					'id'            => (int)$row->page_id,
					'title'         => $tTitle,
					'subject'       => $tTitle->getText(),
					'created'       => null,
					'creator'       => null,
					'lastReply'     => null,
					'lastReplyUser' => null,
					'sticky'        => false,
					'locked' 		=> false,
				];
			}

			return $threads;
		}

		// Normal path: use metadata + props
		$tables = [ 'simpleforum_threads', 'page' ];

		$fields = [
			'page.page_id',
			'page.page_title',
			'page.page_namespace',
			'simpleforum_threads.sf_subject',
			'simpleforum_threads.sf_created',
			'simpleforum_threads.sf_creator',
			'simpleforum_threads.sf_last_reply',
			'simpleforum_threads.sf_last_reply_user'
		];

		$conds = [
			'page.page_id = simpleforum_threads.sf_page_id',
			'simpleforum_threads.sf_forum_page_id' => $forumPageId
		];

		$options = [
			'LIMIT'  => $limit,
			'OFFSET' => $offset
		];

		$joinConds = [];

		if ( $hasProps ) {
			$tables[] = 'simpleforum_thread_props';
			$fields['sf_sticky'] = 'simpleforum_thread_props.sf_sticky';
			$fields['sf_locked'] = 'simpleforum_thread_props.sf_locked';
			$joinConds['simpleforum_thread_props'] = [
				'LEFT JOIN',
				'simpleforum_thread_props.sf_page_id = page.page_id'
			];
			$options['ORDER BY'] =
				'IFNULL(simpleforum_thread_props.sf_sticky,0) DESC, ' .
				'COALESCE(simpleforum_threads.sf_last_reply, simpleforum_threads.sf_created) DESC';
		} else {
			$options['ORDER BY'] =
				'COALESCE(simpleforum_threads.sf_last_reply, simpleforum_threads.sf_created) DESC';
		}

		$res = $dbr->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$joinConds
		);

		$threads = [];

		foreach ( $res as $row ) {
			$tTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( !$tTitle ) {
				continue;
			}

			$threads[] = [
				'id'            => (int)$row->page_id,
				'title'         => $tTitle,
				'subject'       => $row->sf_subject ?? $tTitle->getText(),
				'created'       => $row->sf_created ?? null,
				'creator'       => isset( $row->sf_creator ) ? (int)$row->sf_creator : null,
				'lastReply'     => $row->sf_last_reply ?? null,
				'lastReplyUser' => isset( $row->sf_last_reply_user ) ? (int)$row->sf_last_reply_user : null,
				'sticky'        => $hasProps && isset( $row->sf_sticky ) ? (bool)$row->sf_sticky : false,
				'locked'        => $hasProps && isset( $row->sf_locked ) ? (bool)$row->sf_locked : false,
			];
		}

		return $threads;
	}

	/**
	 * Get metadata for a single thread by page_id.
	 *
	 * @return array|null (same shape as listThreadsForForum elements, but 'forum' => [Title] also)
	 */
	public function getThreadMetaById( int $threadId ): ?array {
		$dbr = $this->lbFactory->getReplicaDatabase();

		$useMeta = $dbr->tableExists( 'simpleforum_threads', __METHOD__ );
		$hasProps = $dbr->tableExists( 'simpleforum_thread_props', __METHOD__ );

		// Base page lookup
		$pageRow = $dbr->selectRow(
			'page',
			[ 'page_namespace', 'page_title', 'page_id' ],
			[ 'page_id' => $threadId ],
			__METHOD__
		);

		if ( !$pageRow || (int)$pageRow->page_namespace !== NS_THREAD ) {
			return null;
		}

		$title = Title::makeTitleSafe( $pageRow->page_namespace, $pageRow->page_title );
		if ( !$title ) {
			return null;
		}

		$meta = [
			'id'            => (int)$pageRow->page_id,
			'title'         => $title,
			'subject'       => $title->getText(),
			'created'       => null,
			'creator'       => null,
			'lastReply'     => null,
			'lastReplyUser' => null,
			'sticky'        => false,
			'locked'        => false,
			'forum'         => null,
		];

		if ( !$useMeta ) {
			return $meta;
		}

		$tables = [ 'simpleforum_threads' ];
		$fields = [
			'simpleforum_threads.sf_subject',
			'simpleforum_threads.sf_created',
			'simpleforum_threads.sf_creator',
			'simpleforum_threads.sf_last_reply',
			'simpleforum_threads.sf_last_reply_user',
			'simpleforum_threads.sf_forum_page_id'
		];
		$conds = [ 'simpleforum_threads.sf_page_id' => $threadId ];
		$joinConds = [];

		if ( $hasProps ) {
			$tables[] = 'simpleforum_thread_props';
			$fields['sf_sticky'] = 'simpleforum_thread_props.sf_sticky';
			$fields['sf_locked'] = 'simpleforum_thread_props.sf_locked';
			$joinConds['simpleforum_thread_props'] = [
				'LEFT JOIN',
				'simpleforum_thread_props.sf_page_id = simpleforum_threads.sf_page_id'
			];
		}

		$row = $dbr->selectRow(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			[],
			$joinConds
		);

		if ( !$row ) {
			return $meta;
		}

		$meta['subject']       = $row->sf_subject ?? $meta['subject'];
		$meta['created']       = $row->sf_created ?? null;
		$meta['creator']       = isset( $row->sf_creator ) ? (int)$row->sf_creator : null;
		$meta['lastReply']     = $row->sf_last_reply ?? null;
		$meta['lastReplyUser'] = isset( $row->sf_last_reply_user ) ? (int)$row->sf_last_reply_user : null;
		$meta['sticky']        = $hasProps && isset( $row->sf_sticky ) ? (bool)$row->sf_sticky : false;
		$meta['locked']        = $hasProps && isset( $row->sf_locked ) ? (bool)$row->sf_locked : false;

		// Resolve forum title
		if ( isset( $row->sf_forum_page_id ) ) {
			$forumRow = $dbr->selectRow(
				'page',
				[ 'page_namespace', 'page_title', 'page_id' ],
				[ 'page_id' => (int)$row->sf_forum_page_id ],
				__METHOD__
			);
			if ( $forumRow ) {
				$forumTitle = Title::makeTitleSafe(
					$forumRow->page_namespace,
					$forumRow->page_title
				);
				if ( $forumTitle ) {
					$meta['forum'] = $forumTitle;
				}
			}
		}

		return $meta;
	}

	/**
	 * Update last-reply metadata for a thread (used by reply handlers).
	 */
	public function touchLastReply( Title $threadTitle, User $user, ?string $timestamp = null ): void {
		$dbw = $this->lbFactory->getPrimaryDatabase();

		if ( !$dbw->tableExists( 'simpleforum_threads', __METHOD__ ) ) {
			return;
		}

		$pageId = $threadTitle->getArticleID();
		if ( !$pageId ) {
			return;
		}

		$ts = $timestamp ?? wfTimestampNow();

		$dbw->update(
			'simpleforum_threads',
			[
				'sf_last_reply'      => $ts,
				'sf_last_reply_user' => $user->getId(),
			],
			[ 'sf_page_id' => $pageId ],
			__METHOD__
		);
	}
}
