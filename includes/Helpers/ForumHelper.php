<?php

namespace Wikivy\SimpleForum\Helpers;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class ForumHelper
{
	public static $forumNamespaces = [NS_FORUM, NS_THREAD];

	private static bool|null $isForumCached = null;

	public static function isForum(): bool {
		global $wgTitle;

		// called to early?
		if (!$wgTitle instanceof Title) {
			return false;
		}

		// this method can be called 30+ times on each page, cache the result as $wgTitle is not likely to change
		if (self::$isForumCached === null) {
			self::$isForumCached = self::isTitleForum($wgTitle);
		}

		return self::$isForumCached;
	}

	private static function isTitleForum(Title $title): bool {
		if (in_array($title->getNamespace(), self::$forumNamespaces)) {
			return true;
		}

		return false;
	}


}
