<?php

namespace Wikivy\SimpleForum\Rest;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;

class ForumsHandler extends SimpleHandler
{

	public function __construct(
		private readonly ILBFactory $lbFactory,
		private readonly WikiPageFactory $wikiPageFactory,
	) {

	}

	public function needsWriteAccess() {
		// POST creates a page; GET does not.
		$method = $this->getRequest()->getMethod();
		return $method === 'POST';
	}

	public function getParamSettings() {
		return [
			'title' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}

	public function run(): ResponseInterface {
		$method = $this->getRequest()->getMethod();

		if ( $method === 'GET' ) {
			return $this->handleGet();
		} elseif ( $method === 'POST' ) {
			return $this->handlePost();
		}

		// Shouldn’t happen given RestRoutes, but just in case
		return $this->getResponseFactory()->createJson(
			[ 'error' => 'Method not allowed' ],
			405
		);
	}

	private function handleGet(): ResponseInterface {
		$dbr = $this->lbFactory->getReplicaDatabase();

		// Prefer metadata table if present
		$hasMeta = $dbr->tableExists( 'simpleforum_forums', __METHOD__ );

		$forums = [];

		if ( $hasMeta ) {
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
				[ 'ORDER BY' => 'page.page_title ASC' ]
			);
		} else {
			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace' ],
				[ 'page_namespace' => NS_FORUM ],
				__METHOD__,
				[ 'ORDER BY' => 'page_title ASC' ]
			);
		}

		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( !$title ) {
				continue;
			}

			$forums[] = [
				'id' => (int)$row->page_id,
				'title' => $title->getPrefixedText(),
				'dbKey' => $title->getDBkey(),
				'url' => $title->getFullURL(),
				'created' => $row->sf_created ?? null,
				'creator' => isset( $row->sf_creator ) ? (int)$row->sf_creator : null,
			];
		}

		return $this->getResponseFactory()->createJson( [
			'forums' => $forums
		] );
	}

	private function handlePost(): ResponseInterface {
		$user = $this->getAuthority()->getUser();
		if ( !$user->isRegistered() || !$user->isAllowed( 'edit' ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied' ],
				403
			);
		}

		$params = $this->getValidatedParams();
		$titleText = trim( $params['title'] ?? '' );

		if ( $titleText === '' ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'missingtitle', 'message' => 'Missing forum title' ],
				400
			);
		}

		$forumTitle = Title::newFromText( $titleText, NS_FORUM );
		if ( !$forumTitle ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'invalidtitle' ],
				400
			);
		}

		if ( $forumTitle->exists() ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'alreadexists', 'message' => 'Forum already exists' ],
				409
			);
		}

		$page = $this->wikiPageFactory->newFromTitle( $forumTitle );

		$content = ContentHandler::makeContent(
			"This is the forum page for ''" . $forumTitle->getText() . "''.\n",
			$forumTitle
		);

		$page->doEditContent(
			$content,
			'Created forum via REST API',
			0,
			false,
			$user
		);

		$pageId = $forumTitle->getArticleID();

		// Optional: immediately insert into simpleforum_forums if table exists
		$dbw = $this->lbFactory->getPrimaryDatabase();
		if ( $dbw->tableExists( 'simpleforum_forums', __METHOD__ ) ) {
			$dbw->insert(
				'simpleforum_forums',
				[
					'sf_page_id' => $pageId,
					'sf_title'   => $forumTitle->getDBkey(),
					'sf_created' => wfTimestampNow(),
					'sf_creator' => $user->getId(),
				],
				__METHOD__,
				'IGNORE'
			);
		}

		return $this->getResponseFactory()->createJson(
			[
				'id' => $pageId,
				'title' => $forumTitle->getPrefixedText(),
				'url' => $forumTitle->getFullURL()
			],
			201
		);
	}
}
