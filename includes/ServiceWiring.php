<?php

namespace Wikivy\SimpleForum;


use MediaWiki\MediaWikiServices;
use Wikivy\SimpleForum\Services\ForumService;
use Wikivy\SimpleForum\Services\ThreadService;

return [
	'SimpleForumForumService' => static function (MediaWikiServices $services): ForumService {
		return new ForumService(
			$services->getDBLoadBalancerFactory()
		);
	},
	'SimpleForumThreadService' => static function (MediaWikiServices $services): ThreadService {
		return new ThreadService(
			$services->getDBLoadBalancerFactory()
		);
	}
];
