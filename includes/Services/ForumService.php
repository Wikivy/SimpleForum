<?php

namespace Wikivy\SimpleForum\Services;

use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\ILBFactory;

class ForumService
{
	public function __construct(
		private readonly ILBFactory $lbFactory
	) {

	}

	/**
	 * List forums with basic metadata.
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array[] Each item: [
	 *   'id' => int,
	 *   'title' => Title,
	 *   'created' => string|null,
	 *   'creator' => int|null,
	 * ]
	 */
	public function listForums( int $limit = 50, int $offset = 0 ): array {
		$dbr = $this->lbFactory->getReplicaDatabase();

		$useMeta = $dbr->tableExists( 'simpleforum_forums', __METHOD__ );

		if ( $useMeta ) {
			$res = $dbr->select(
				[ 'simpleforum_forums', 'page' ],
				[
					'page.page_id',
					'page.page_title',
					'page.page_namespace',
					'simpleforum_forums.sf_created',
					'simpleforum_forums.sf_creator',
				],
				[ 'page.page_id = simpleforum_forums.sf_page_id' ],
				__METHOD__,
				[
					'ORDER BY' => 'page.page_title ASC',
					'LIMIT'    => $limit,
					'OFFSET'   => $offset,
				]
			);
		} else {
			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace' ],
				[ 'page_namespace' => NS_FORUM ],
				__METHOD__,
				[
					'ORDER BY' => 'page_title ASC',
					'LIMIT'    => $limit,
					'OFFSET'   => $offset,
				]
			);
		}

		$forums = [];

		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( !$title ) {
				continue;
			}

			$forums[] = [
				'id'      => (int)$row->page_id,
				'title'   => $title,
				'created' => $useMeta && isset( $row->sf_created ) ? $row->sf_created : null,
				'creator' => $useMeta && isset( $row->sf_creator ) ? (int)$row->sf_creator : null,
			];
		}

		return $forums;
	}

	/**
	 * Resolve a forum Title from a page_id or return null if not a Forum: page.
	 */
	public function getForumTitleById( int $pageId ): ?Title {
		$dbr = $this->lbFactory->getReplicaDatabase();

		$row = $dbr->selectRow(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);

		if ( !$row || (int)$row->page_namespace !== NS_FORUM ) {
			return null;
		}

		return Title::makeTitleSafe( $row->page_namespace, $row->page_title );
	}
}
