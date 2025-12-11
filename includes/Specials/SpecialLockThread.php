<?php

namespace Wikivy\SimpleForum\Specials;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikivy\SimpleForum\Helpers\ThreadProps;

class SpecialLockThread extends SpecialPage
{
	public function __construct() {
		parent::__construct( 'LockThread' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();

		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.SimpleForum.styles' );

		if ( !$user->isAllowed( 'protect' ) ) {
			$out->addHTML(
				'<p class="simpleforum-error">' .
				wfMessage( 'simpleforum-lockthread-permission-denied' )->escaped() .
				'</p>'
			);
			return;
		}

		$threadParam = $request->getVal( 'thread', $subPage );
		if ( !$threadParam ) {
			$out->addHTML( '<p class="simpleforum-error">No thread specified.</p>' );
			return;
		}

		$threadTitle = Title::newFromText( $threadParam );
		if ( !$threadTitle || !$threadTitle->inNamespace( NS_THREAD ) || !$threadTitle->exists() ) {
			$out->addHTML( '<p class="simpleforum-error">Invalid thread.</p>' );
			return;
		}

		$token = $request->getVal( 'token' );
		if ( !$out->getCsrfTokenSet()->matchToken( $token, 'lockthread' ) ) {
			$out->addHTML( '<p class="simpleforum-error">Invalid token.</p>' );
			return;
		}

		$mode = $request->getVal( 'actionMode', 'lock' );
		$lock = null;
		$sticky = null;

		if ( $mode === 'lock' ) {
			$lock = true;
		} elseif ( $mode === 'unlock' ) {
			$lock = false;
		} elseif ( $mode === 'sticky' ) {
			$sticky = true;
		} elseif ( $mode === 'unsticky' ) {
			$sticky = false;
		} else {
			$out->addHTML( '<p class="simpleforum-error">Invalid action.</p>' );
			return;
		}

		if ( $lock !== null ) {
			ThreadProps::setLocked( $threadTitle, $lock );
		}

		if ( $sticky !== null ) {
			ThreadProps::setSticky( $threadTitle, $sticky );
		}

		if ( $lock === true ) {
			$msgKey = 'simpleforum-lockthread-locked';
		} elseif ( $lock === false ) {
			$msgKey = 'simpleforum-lockthread-unlocked';
		} elseif ( $sticky === true ) {
			$msgKey = 'simpleforum-stickthread-sticky';
		} else {
			$msgKey = 'simpleforum-stickthread-unsticky';
		}

		$out->addHTML(
			'<p>' . wfMessage( $msgKey )->escaped() . '</p>'
		);

		$out->redirect( $threadTitle->getFullURL() );
	}
}
