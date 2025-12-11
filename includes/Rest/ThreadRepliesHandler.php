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
use Wikivy\SimpleForum\Helpers\ThreadProps;

class ThreadRepliesHandler extends SimpleHandler
{
	public function __construct(
		private readonly ILBFactory $lbFactory,
		private readonly WikiPageFactory $wikiPageFactory,
	) {

	}

	public function needsWriteAccess() {
		return true; // creates a reply (edit)
	}

	public function getParamSettings() {
		return [
			'threadId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'body' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function run(): ResponseInterface {
		$params = $this->getValidatedParams();
		$threadId = (int)$params['threadId'];
		$body = (string)$params['body'];

		$authority = $this->getAuthority();
		$user = $authority->getUser();

		if ( !$user->isRegistered() || !$user->isAllowed( 'edit' ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied' ],
				403
			);
		}

		if ( trim( $body ) === '' ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'emptybody', 'message' => 'Reply body cannot be empty' ],
				400
			);
		}

		$dbr = $this->lbFactory->getReplicaDatabase();

		// Locate the thread page
		$row = $dbr->selectRow(
			'page',
			[ 'page_namespace', 'page_title', 'page_id' ],
			[ 'page_id' => $threadId ],
			__METHOD__
		);

		if ( !$row || (int)$row->page_namespace !== NS_THREAD ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'notfound', 'message' => 'Thread not found' ],
				404
			);
		}

		$threadTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
		if ( !$threadTitle ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'invalidtitle' ],
				500
			);
		}

		// Derive parent forum: Thread:ForumName/Subject → Forum:ForumName
		$parts = explode( '/', $threadTitle->getText(), 2 );
		if ( !$parts || $parts[0] === '' ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'forumresolve', 'message' => 'Could not determine parent forum' ],
				500
			);
		}

		$forumTitle = Title::makeTitleSafe( NS_FORUM, $parts[0] );
		if ( !$forumTitle || !$forumTitle->exists() ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'forumnotfound', 'message' => 'Parent forum not found' ],
				404
			);
		}

		// Per-forum permission
		if ( !Permissions::canReply( $user, $forumTitle ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied', 'message' => 'Cannot reply in this forum' ],
				403
			);
		}

		// Lock check
		if ( ThreadProps::isLocked( $threadTitle ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'locked', 'message' => 'Thread is locked and cannot be replied to' ],
				409
			);
		}

		// Append reply
		$page = $this->wikiPageFactory->newFromTitle( $threadTitle );
		$pageUpdater = $page->newPageUpdater($user);

		$oldContent = $page->getContent();
		$oldText = $oldContent ?: '';

		$replyText =
			"\n\n----\n" .
			"''Reply by ~~~~''\n\n" .
			$body . "\n";

		$newText = $oldText . $replyText;

		$newContent = ContentHandler::makeContent( $newText, $threadTitle );
		$summary = 'Posted reply via REST API';

		$pageUpdater->setContent(SlotRecord::MAIN, $newContent);
		$pageUpdater->saveRevision($summary);

		$timestamp = wfTimestampNow();

		// Update last_reply fields if metadata table exists
		$dbw = $this->lbFactory->getPrimaryDatabase();
		if ( $dbw->tableExists( 'simpleforum_threads', __METHOD__ ) ) {
			$dbw->update(
				'simpleforum_threads',
				[
					'sf_last_reply'      => $timestamp,
					'sf_last_reply_user' => $user->getId(),
				],
				[ 'sf_page_id' => $threadId ],
				__METHOD__
			);
		}

		return $this->getResponseFactory()->createJson(
			[
				'threadId' => $threadId,
				'title' => $threadTitle->getPrefixedText(),
				'url' => $threadTitle->getFullURL(),
				'locked' => false,
				'createdReply' => true,
				'timestamp' => $timestamp
			],
			201
		);
	}
}
