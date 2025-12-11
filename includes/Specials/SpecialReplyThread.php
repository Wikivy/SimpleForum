<?php

namespace Wikivy\SimpleForum\Specials;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\ILBFactory;
use Wikivy\SimpleForum\Helpers\Permissions;

class SpecialReplyThread extends SpecialPage
{
	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly ILBFactory $lbFactory,
	) {
		parent::__construct( 'ReplyThread' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();

		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.SimpleForum.styles' );

		if ( !$user->isRegistered() || !$user->isAllowed( 'edit' ) ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-reply-permission-denied' )->escaped() .
				'</p>'
			);
			return;
		}

		$threadParam = $request->getVal( 'thread', $subPage );
		if ( !$threadParam ) {
			$out->addHTML( '<p class="error">No thread specified.</p>' );
			return;
		}

		$threadTitle = Title::newFromText( $threadParam );
		if ( !$threadTitle || !$threadTitle->inNamespace( NS_THREAD ) || !$threadTitle->exists() ) {
			$out->addHTML( '<p class="error">Invalid thread.</p>' );
			return;
		}

		// Derive parent forum from thread title
		$parts = explode( '/', $threadTitle->getText(), 2 );
		if ( !$parts || $parts[0] === '' ) {
			$out->addHTML( '<p class="error">Could not determine parent forum.</p>' );
			return;
		}

		$forumTitle = Title::makeTitleSafe( NS_FORUM, $parts[0] );
		if ( !$forumTitle || !$forumTitle->exists() ) {
			$out->addHTML( '<p class="error">Parent forum not found.</p>' );
			return;
		}

		// Per-forum reply permission
		if ( !Permissions::canReply( $user, $forumTitle ) ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-reply-permission-denied' )->escaped() .
				'</p>'
			);
			return;
		}

		if ( $request->wasPosted() && $request->getCheck( 'wpReplySubmit' ) ) {
			$this->doSubmit( $threadTitle, $forumTitle );
			return;
		}

		// If accessed directly (GET), just show the thread and rely on inline form on the thread page
		$out->redirect( $threadTitle->getFullURL() );
	}

	private function doSubmit( Title $threadTitle, Title $forumTitle ) {
		$request = $this->getRequest();
		$user = $this->getUser();
		$out = $this->getOutput();

		$body = $request->getText( 'wpReplyBody' );
		$token = $request->getVal( 'token' );

		if ( !$out->getCsrfTokenSet()->matchToken( $token, 'replythread' ) ) {
			$out->addHTML( '<p class="error">Invalid edit token.</p>' );
			$out->redirect( $threadTitle->getFullURL() );
			return;
		}

		if ( trim( $body ) === '' ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-reply-empty' )->escaped() .
				'</p>'
			);
			$out->redirect( $threadTitle->getFullURL() );
			return;
		}


		$page = $this->wikiPageFactory->newFromTitle( $threadTitle );
		$pageUpdater = $page->newPageUpdater($user);

		$oldContent = $page->getContent();
		$oldText = $oldContent ? $oldContent->getText() : '';

		// Append reply with automatic signature and timestamp
		$replyText =
			"\n\n----\n" .
			"''Reply by ~~~~''\n\n" .
			$body . "\n";

		$newText = $oldText . $replyText;

		$newContent = ContentHandler::makeContent( $newText, $threadTitle );
		$summary = wfMessage( 'simpleforum-reply-added' )->text();

		$pageUpdater->setContent( SlotRecord::MAIN, $newContent );
		$pageUpdater->saveRevision($summary);

		$dbw = $this->lbFactory->getPrimaryDatabase();
		$timestamp = wfTimestampNow();

		if ( $dbw->tableExists( 'simpleforum_threads', __METHOD__ ) ) {
			$dbw->update(
				'simpleforum_threads',
				[
					'sf_last_reply'      => $timestamp,
					'sf_last_reply_user' => $user->getId(),
				],
				[ 'sf_page_id' => $threadTitle->getArticleID() ],
				__METHOD__
			);
		}

		// redirect
		$out->redirect( $threadTitle->getFullURL() . '#lastReply' );
	}
}
