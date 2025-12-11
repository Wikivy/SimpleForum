<?php

namespace Wikivy\SimpleForum\Specials;

use MediaWiki\Content\ContentHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\ILBFactory;
use Wikivy\SimpleForum\Helpers\Permissions;

class SpecialNewThread extends SpecialPage
{
	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly ILBFactory $lbFactory,
	) {
		parent::__construct( 'NewThread' );
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
				wfMessage( 'simpleforum-newthread-permission-denied' )->escaped() .
				'</p>'
			);
			return;
		}

		$out->setPageTitle( wfMessage( 'simpleforum-newthread-page-title' )->text() );

		$forumParam = $request->getVal( 'forum', $subPage );
		$forumTitle = null;

		if ( $forumParam ) {
			// Allow fully prefixed names like "Forum:General"
			$forumTitle = Title::newFromText( $forumParam );
			if ( $forumTitle && !$forumTitle->inNamespace( NS_FORUM ) ) {
				// Force namespace if omitted
				$forumTitle = Title::newFromText( $forumParam, NS_FORUM );
			}
		}

		if ( $request->wasPosted() && $request->getCheck( 'wpCreate' ) ) {
			$this->doSubmit( $forumTitle );
			return;
		}

		// Show form
		$this->showForm( $forumTitle );
	}

	private function showForm( ?Title $forumTitle ) {
		$out = $this->getOutput();
		$user = $this->getUser();

		$forumValue = $forumTitle ? $forumTitle->getPrefixedText() : '';

		$token = $out->getCsrfTokenSet()->getToken('newthread');

		$html = '<form method="post" action="' .
			htmlspecialchars( $this->getPageTitle()->getLocalURL() ) .
			'">';

		$html .= '<table class="wikitable">';

		// Forum
		$html .= '<tr><th>' .
			wfMessage( 'simpleforum-newthread-select-forum' )->escaped() .
			'</th><td>';
		$html .= '<input type="text" name="wpForum" size="50" value="' .
			htmlspecialchars( $forumValue ) . '" />';
		$html .= '</td></tr>';

		// Subject
		$html .= '<tr><th>' .
			wfMessage( 'simpleforum-newthread-subject' )->escaped() .
			'</th><td>';
		$html .= '<input type="text" name="wpSubject" size="50" />';
		$html .= '</td></tr>';

		// Body
		$html .= '<tr><th>' .
			wfMessage( 'simpleforum-newthread-body' )->escaped() .
			'</th><td>';
		$html .= '<textarea name="wpBody" rows="10" cols="80"></textarea>';
		$html .= '</td></tr>';

		$html .= '</table>';

		$html .= '<input type="hidden" name="token" value="' .
			htmlspecialchars( $token ) . '" />';
		$html .= '<input type="hidden" name="wpCreate" value="1" />';

		$html .= '<p><input type="submit" value="' .
			wfMessage( 'simpleforum-newthread-submit' )->escaped() .
			'" /></p>';

		$html .= '</form>';

		$out->addHTML( $html );
	}

	private function doSubmit( ?Title $initialForumTitle ) {
		$request = $this->getRequest();
		$user = $this->getUser();
		$out = $this->getOutput();

		$forumInput = $request->getText( 'wpForum' );
		$subject = trim( $request->getText( 'wpSubject' ) );
		$body = $request->getText( 'wpBody' );
		$token = $request->getVal( 'token' );

		if ( !$out->getCsrfTokenSet()->matchToken( $token, 'newthread' ) ) {
			$out->addHTML( '<p class="error">Invalid edit token.</p>' );
			$this->showForm( $initialForumTitle );
			return;
		}

		if ( $forumInput === '' && !$initialForumTitle ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-newthread-missing-forum' )->escaped() .
				'</p>'
			);
			$this->showForm( $initialForumTitle );
			return;
		}

		$forumTitle = $initialForumTitle;
		if ( !$forumTitle ) {
			$forumTitle = Title::newFromText( $forumInput );
			if ( $forumTitle && !$forumTitle->inNamespace( NS_FORUM ) ) {
				$forumTitle = Title::newFromText( $forumInput, NS_FORUM );
			}
		}

		if ( !$forumTitle || !$forumTitle->exists() || !$forumTitle->inNamespace( NS_FORUM ) ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-newthread-invalid-forum' )->escaped() .
				'</p>'
			);
			$this->showForm( $forumTitle );
			return;
		}

		// Per-forum permission check
		if ( !Permissions::canCreateThread( $user, $forumTitle ) ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-newthread-permission-denied' )->escaped() .
				'</p>'
			);
			$this->showForm( $forumTitle );
			return;
		}

		if ( $subject === '' ) {
			$out->addHTML(
				'<p class="error">' .
				wfMessage( 'simpleforum-newthread-empty-subject' )->escaped() .
				'</p>'
			);
			$this->showForm( $forumTitle );
			return;
		}

		// Build thread title: Thread:ForumName/Subject
		$base = $forumTitle->getText(); // no namespace
		$threadName = $base . '/' . $subject;

		$threadTitle = Title::makeTitleSafe( NS_THREAD, $threadName );
		if ( !$threadTitle ) {
			$out->addHTML( '<p class="error">Could not create a valid thread title.</p>' );
			$this->showForm( $forumTitle );
			return;
		}

		// Ensure uniqueness
		if ( $threadTitle->exists() ) {
			$threadName .= ' (' . wfTimestampNow() . ')';
			$threadTitle = Title::makeTitleSafe( NS_THREAD, $threadName );
		}

		// Initial thread content with automatic signature and timestamp
		$wikitext =
			"''Posted in [[" . $forumTitle->getPrefixedText() . "]]''\n\n" .
			"== " . $subject . " ==\n\n" .
			$body . "\n\n" .
			"----\n" .
			"''Thread started by ~~~~''\n";

		$page = $this->wikiPageFactory->newFromTitle( $threadTitle );
		$pageUpdater = $page->newPageUpdater($user);

		$content = ContentHandler::makeContent( $wikitext, $threadTitle );
		$summary = wfMessage( 'simpleforum-newthread-created' )->text();

		$pageUpdater->setContent(SlotRecord::MAIN, $content);
		$pageUpdater->saveRevision( $summary);

		// insert into simpleforum_threads
		$threadPageId = $threadTitle->getArticleID();
		$forumPageId = $forumTitle->getArticleID();

		$dbw = $this->lbFactory->getPrimaryDatabase();
		$dbw->insert(
			'simpleforum_threads',
			[
				'sf_page_id'        => $threadPageId,
				'sf_forum_page_id'  => $forumPageId,
				'sf_subject'        => $subject,
				'sf_created'        => wfTimestampNow(),
				'sf_creator'        => $user->getId(),
				// sf_last_reply_* left NULL for now
			],
			__METHOD__,
			'IGNORE'
		);

		// Redirect to new Thread
		$out->redirect( $threadTitle->getFullURL() );
		return true;
	}
}
