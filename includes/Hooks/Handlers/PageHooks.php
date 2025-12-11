<?php

namespace Wikivy\SimpleForum\Hooks\Handlers;

use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\ILBFactory;

class PageHooks implements PageSaveCompleteHook
{
	public function __construct(
		private readonly ILBFactory $lbFactory,
	) {

	}

	/**
	 * Hook: PageSaveComplete
	 * Whenever a Forum: page is created or edited, ensure it exists in simpleforum_forums.
	 */
	public function onPageSaveComplete($wikiPage, $user, $summary, $flags, $revisionRecord, $editResult): bool
	{
		$title = $wikiPage->getTitle();
		if (!$title->inNamespace(NS_FORUM)) {
			return true;
		}

		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return true;
		}

		$dbw = $this->lbFactory->getPrimaryDatabase();

		$dbw->upsert(
			'simpleforum_forums',
			[
				'sf_page_id' => $pageId,
				'sf_title'   => $title->getDBkey(),
				'sf_created' => wfTimestampNow(),
				'sf_creator' => $user->getId(),
			],
			[ 'sf_page_id' ],
			[
				'sf_title'   => $title->getDBkey(),
			],
			__METHOD__
		);

		return true;
	}
}
