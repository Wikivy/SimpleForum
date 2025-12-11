<?php

namespace Wikivy\SimpleForum\Helpers;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class ThreadProps
{
	/**
	 * Return true if the given thread title is locked.
	 */
	public static function isLocked( Title $threadTitle ): bool {
		if (!$threadTitle->inNamespace(NS_THREAD))
		{
			return false;
		}

		$pageId = $threadTitle->getArticleID();
		if (!$pageId) {
			return false;
		}

		$dbr = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase();

		$row = $dbr->selectRow(
			'simpleforum_thread_props',
			[ 'sf_locked' ],
			[ 'sf_page_id' => $pageId ],
			__METHOD__
		);

		if (!$row) {
			return false;
		}

		return (bool) $row->sf_locked;
	}

	/**
	 * Set locked/unlocked status for a thread.
	 */
	public static function setLocked( Title $threadTitle, bool $locked ): void {
		if ( !$threadTitle->inNamespace( NS_THREAD ) ) {
			return;
		}

		$pageId = $threadTitle->getArticleID();
		if ( !$pageId ) {
			return;
		}

		$dbw = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();

		$dbw->upsert(
			'simpleforum_thread_props',
			[
				'sf_page_id' => $pageId,
				'sf_locked'  => $locked ? 1 : 0,
			],
			[ 'sf_page_id' ],
			[
				'sf_locked'  => $locked ? 1 : 0,
			],
			__METHOD__
		);
	}

	public static function isSticky( Title $threadTitle ): bool {
		if ( !$threadTitle->inNamespace( NS_THREAD ) ) {
			return false;
		}

		$pageId = $threadTitle->getArticleID();
		if ( !$pageId ) {
			return false;
		}

		$dbr = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase();

		$row = $dbr->selectRow(
			'simpleforum_thread_props',
			[ 'sf_sticky' ],
			[ 'sf_page_id' => $pageId ],
			__METHOD__
		);

		if ( !$row ) {
			return false;
		}

		return (bool)$row->sf_sticky;
	}

	/**
	 * Set sticky / unsticky.
	 */
	public static function setSticky( Title $threadTitle, bool $sticky ): void {
		if ( !$threadTitle->inNamespace( NS_THREAD ) ) {
			return;
		}

		$pageId = $threadTitle->getArticleID();
		if ( !$pageId ) {
			return;
		}

		$dbw = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();

		$dbw->upsert(
			'simpleforum_thread_props',
			[
				'sf_page_id' => $pageId,
				'sf_sticky'  => $sticky ? 1 : 0,
			],
			[ 'sf_page_id' ],
			[
				'sf_sticky'  => $sticky ? 1 : 0,
			],
			__METHOD__
		);
	}
}
