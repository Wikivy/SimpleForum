<?php

namespace Wikivy\SimpleForum\Specials;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\ILBFactory;

class SpecialForums extends SpecialPage
{
	public function __construct(
		private readonly ILBFactory $lbFactory,
	) {
		parent::__construct( 'Forums' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.SimpleForum.styles' );

		$out->setPageTitle( wfMessage( 'simpleforum-forums-page-title' )->text() );
		$out->addWikiMsg( 'simpleforum-forums-intro' );

		$dbr = $this->lbFactory->getReplicaDatabase();

		$res = $dbr->select(
			'page',
			[ 'page_id', 'page_title' ],
			[ 'page_namespace' => NS_FORUM ],
			__METHOD__,
			[ 'ORDER BY' => 'page_title ASC' ]
		);

		if ( $res->numRows() === 0 ) {
			$out->addHTML(
				'<p>' . wfMessage( 'simpleforum-no-forums' )->escaped() . '</p>'
			);
			return;
		}

		$out->addHTML( '<ul class="simpleforum-forums-list">' );

		$skin = $this->getSkin();

		foreach ( $res as $row ) {
			$forumTitle = Title::makeTitleSafe( NS_FORUM, $row->page_title );
			if ( !$forumTitle ) {
				continue;
			}

			$forumLink = Html::element(
				'a',
				['href' => $forumTitle->getLocalURL()],
				$forumTitle->getText()
			);

			$out->addHTML(
				'<li>' . $forumLink . '</li>'
			);
		}

		$out->addHTML( '</ul>' );
	}
}
