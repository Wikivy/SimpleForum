<?php

namespace Wikivy\SimpleForum\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILBFactory;

class ThreadHandler extends SimpleHandler
{

	public function __construct(
		private readonly ILBFactory $lbFactory,
	) {

	}

	public function needsWriteAccess() {
		return false;
	}

	public function getParamSettings() {
		return [
			'threadId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function run(): ResponseInterface {
		$params = $this->getValidatedParams();
		$threadId = (int)$params['threadId'];

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

		$threadMeta = [
			'id' => (int)$row->page_id,
			'title' => $title->getPrefixedText(),
			'url' => $title->getFullURL(),
			'locked' => ThreadProps::isLocked( $title ),
			'subject' => $title->getText(),
		];

		// Try to enrich with simpleforum_threads if available
		if ( $dbr->tableExists( 'simpleforum_threads', __METHOD__ ) ) {
			$meta = $dbr->selectRow(
				'simpleforum_threads',
				[
					'sf_forum_page_id',
					'sf_subject',
					'sf_created',
					'sf_creator',
					'sf_last_reply',
					'sf_last_reply_user'
				],
				[ 'sf_page_id' => $threadId ],
				__METHOD__
			);

			if ( $meta ) {
				$threadMeta['subject'] = $meta->sf_subject;
				$threadMeta['created'] = $meta->sf_created;
				$threadMeta['creator'] = (int)$meta->sf_creator;
				$threadMeta['lastReply'] = $meta->sf_last_reply;
				$threadMeta['lastReplyUser'] = $meta->sf_last_reply_user !== null
					? (int)$meta->sf_last_reply_user
					: null;

				// get forum title
				$forumPageId = (int)$meta->sf_forum_page_id;
				$forumRow = $dbr->selectRow(
					'page',
					[ 'page_namespace', 'page_title', 'page_id' ],
					[ 'page_id' => $forumPageId ],
					__METHOD__
				);
				if ( $forumRow ) {
					$forumTitle = Title::makeTitleSafe(
						$forumRow->page_namespace,
						$forumRow->page_title
					);
					if ( $forumTitle ) {
						$threadMeta['forum'] = [
							'id' => $forumPageId,
							'title' => $forumTitle->getPrefixedText(),
							'url' => $forumTitle->getFullURL()
						];
					}
				}
			}
		}

		return $this->getResponseFactory()->createJson( $threadMeta );
	}

}
