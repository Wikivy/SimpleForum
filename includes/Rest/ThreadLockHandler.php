<?php

namespace Wikivy\SimpleForum\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;
use Wikivy\SimpleForum\Helpers\ThreadProps;

class ThreadLockHandler extends SimpleHandler
{

	public function __construct(
		private readonly ILBFactory $lbFactory,
	) {

	}

	public function needsWriteAccess() {
		return true;
	}

	public function getParamSettings() {
		return [
			'threadId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'locked' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}

	public function run(): ResponseInterface {
		$params = $this->getValidatedParams();
		$threadId = (int)$params['threadId'];
		$lockedParam = $params['locked'] ?? null;

		$user = $this->getAuthority()->getUser();
		if ( !$user->isAllowed( 'protect' ) ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'permissiondenied' ],
				403
			);
		}

		$dbr = $this->lbFactory->getReplicaDatabase();

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

		$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
		if ( !$title ) {
			return $this->getResponseFactory()->createJson(
				[ 'error' => 'invalidtitle' ],
				500
			);
		}

		$current = ThreadProps::isLocked( $title );
		$newState = $lockedParam !== null ? (bool)$lockedParam : !$current;

		ThreadProps::setLocked( $title, $newState );

		return $this->getResponseFactory()->createJson(
			[
				'id' => (int)$row->page_id,
				'title' => $title->getPrefixedText(),
				'locked' => $newState
			]
		);
	}

}
