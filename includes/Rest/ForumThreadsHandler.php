<?php

namespace Wikivy\SimpleForum\Rest;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;
use Wikivy\SimpleForum\Helpers\Permissions;

class ForumThreadsHandler extends SimpleHandler
{
	public function __construct(
		private readonly ILBFactory $lbFactory,
		private readonly WikiPageFactory $wikiPageFactory,
	) {

	}

	public function needsWriteAccess() {
		$method = $this->getRequest()->getMethod();
		return $method === 'POST';
	}

	public function getParamSettings() {
		return [
			'forumId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'subject' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'body' => [
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

		return $this->getResponseFactory()->createJson(
			[ 'error' => 'Method not allowed' ],
			405
		);
	}

	private function getForumTitleFromId( int $forumId ): ?Title {
		$dbr = $this->lbFactory->getReplicaDatabase();

		$row = $dbr->selectRow(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_id' => $forumId ],
			__METHOD__
		);

		if ( !$row || (int)$row->page_namespace !== NS_FORUM ) {
			return null;
		}

		return Title::makeTitleSafe( $row->page_namespace, $row->page_title );
	}

	private function handleGet(): ResponseInterface {
		$params = $this->getValidatedParams();
		$forumId = (int)$params['forumId'];

		$forumTitle = $this->getForumTitleFromId( $forumId );
		if ( !$forumTitle ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'notfound', 'message' => 'Forum not found' ],
				404
			);
		}

		$dbr = $this->lbFactory->getReplicaDatabase();

		$base = $forumTitle->getDBkey(); // e.g. "General_Discussion"
		$like = $dbr->buildLike( $base . '/', $dbr->anyString() );

		// prefer metadata table if present
		$useMeta = $dbr->tableExists( 'simpleforum_threads', __METHOD__ );

		$threads = [];

		if ( $useMeta ) {
			$res = $dbr->select(
				[ 'simpleforum_threads', 'page' ],
				[
					'page.page_id',
					'page.page_title',
					'page.page_namespace',
					'simpleforum_threads.sf_subject',
					'simpleforum_threads.sf_created',
					'simpleforum_threads.sf_creator',
					'simpleforum_threads.sf_last_reply',
					'simpleforum_threads.sf_last_reply_user'
				],
				[
					'page.page_id = simpleforum_threads.sf_page_id',
					'simpleforum_threads.sf_forum_page_id' => $forumId
				],
				__METHOD__,
				[ 'ORDER BY' => 'simpleforum_threads.sf_created DESC' ]
			);
		} else {
			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace' ],
				[
					'page_namespace' => NS_THREAD,
					"page_title $like"
				],
				__METHOD__,
				[ 'ORDER BY' => 'page_id DESC' ]
			);
		}

		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( !$title ) {
				continue;
			}

			$threads[] = [
				'id' => (int)$row->page_id,
				'title' => $title->getPrefixedText(),
				'subject' => $row->sf_subject ?? $title->getText(),
				'url' => $title->getFullURL(),
				'created' => $row->sf_created ?? null,
				'creator' => isset( $row->sf_creator ) ? (int)$row->sf_creator : null,
				'lastReply' => $row->sf_last_reply ?? null,
				'lastReplyUser' => isset( $row->sf_last_reply_user ) ? (int)$row->sf_last_reply_user : null,
			];
		}

		return $this->getResponseFactory()->createJson( [
			'forum' => [
				'id' => $forumId,
				'title' => $forumTitle->getPrefixedText(),
				'url' => $forumTitle->getFullURL()
			],
			'threads' => $threads
		] );
	}

	private function handlePost(): ResponseInterface {
		$params = $this->getValidatedParams();
		$forumId = (int)$params['forumId'];

		$forumTitle = $this->getForumTitleFromId( $forumId );
		if ( !$forumTitle ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'notfound', 'message' => 'Forum not found' ],
				404
			);
		}

		$user = $this->getAuthority()->getUser();
		if ( !$user->isRegistered() || !$user->isAllowed( 'edit' ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied' ],
				403
			);
		}

		// Per-forum permission
		if ( !Permissions::canCreateThread( $user, $forumTitle ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied', 'message' => 'Cannot create thread in this forum' ],
				403
			);
		}

		$subject = trim( $params['subject'] ?? '' );
		$body = (string)( $params['body'] ?? '' );

		if ( $subject === '' ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'emptysubject', 'message' => 'Subject cannot be empty' ],
				400
			);
		}

		// Build thread title: Thread:ForumName/Subject
		$base = $forumTitle->getText();
		$threadName = $base . '/' . $subject;

		$threadTitle = Title::makeTitleSafe( NS_THREAD, $threadName );
		if ( !$threadTitle ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'invalidthreadtitle' ],
				400
			);
		}

		// Ensure uniqueness
		if ( $threadTitle->exists() ) {
			$threadName .= ' (' . wfTimestampNow() . ')';
			$threadTitle = Title::makeTitleSafe( NS_THREAD, $threadName );
		}

		$page = $this->wikiPageFactory->newFromTitle( $threadTitle );
		$pageUpdater = $page->newPageUpdater( $user );

		$wikitext =
			"''Posted in [[" . $forumTitle->getPrefixedText() . "]]''\n\n" .
			"== " . $subject . " ==\n\n" .
			$body . "\n\n" .
			"----\n" .
			"''Thread started by ~~~~''\n";

		$content = ContentHandler::makeContent( $wikitext, $threadTitle );
		$pageUpdater->setContent(SlotRecord::MAIN, $content);

		$pageUpdater->saveRevision('Created thread via REST API');

		$threadPageId = $threadTitle->getArticleID();
		$forumPageId = $forumTitle->getArticleID();

		// Insert into simpleforum_threads if table exists
		$dbw = $this->lbFactory->getPrimaryDatabase();
		if ( $dbw->tableExists( 'simpleforum_threads', __METHOD__ ) ) {
			$dbw->insert(
				'simpleforum_threads',
				[
					'sf_page_id'        => $threadPageId,
					'sf_forum_page_id'  => $forumPageId,
					'sf_subject'        => $subject,
					'sf_created'        => wfTimestampNow(),
					'sf_creator'        => $user->getId(),
				],
				__METHOD__,
				'IGNORE'
			);
		}

		return $this->getResponseFactory()->createJson(
			[
				'id' => $threadPageId,
				'title' => $threadTitle->getPrefixedText(),
				'url' => $threadTitle->getFullURL()
			],
			201
		);
	}

}
