<?php

namespace Wikivy\SimpleForum\Helpers;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\Title\Title;


class Permissions
{
	/**
	 * Check if a user can create threads in a given forum.
	 */
	public static function canCreateThread( UserIdentity $user, Title $forumTitle ): bool {
		return self::checkForumPermission( $user, $forumTitle, 'create' );
	}

	/**
	 * Check if a user can reply in a given forum.
	 */
	public static function canReply( UserIdentity $user, Title $forumTitle ): bool {
		return self::checkForumPermission( $user, $forumTitle, 'reply' );
	}

	/**
	 * Core per-forum permission logic.
	 * Config format (in extension.json / LocalSettings):
	 *
	 *  $wgSimpleForumPermissions = [
	 *      'default' => [
	 *          'create' => [ 'user' ],
	 *          'reply'  => [ 'user' ]
	 *      ],
	 *      'Forum:Announcements' => [
	 *          'create' => [ 'sysop' ],
	 *          'reply'  => [ 'user' ]
	 *      ]
	 *  ];
	 */
	private static function checkForumPermission( UserIdentity $user, Title $forumTitle, string $action ): bool {
		$config = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'SimpleForumPermissions' );

		$forumKey = $forumTitle->getPrefixedText();
		$rules = null;

		if ( isset( $config[$forumKey][$action] ) ) {
			$rules = $config[$forumKey][$action];
		} elseif ( isset( $config['default'][$action] ) ) {
			$rules = $config['default'][$action];
		} else {
			// Fallback: registered users
			$rules = [ 'user' ];
		}

		// '*' = anyone including anonymous
		if ( in_array( '*', $rules, true ) ) {
			return true;
		}

		// Need to be logged in for anything else
		if ( !$user->isRegistered() ) {
			return false;
		}

		// 'user' = any logged-in user
		if ( in_array( 'user', $rules, true ) ) {
			return true;
		}

		return false;

		// Otherwise match by group
		//$userGroups = $user->getGroups();

		//return (bool) array_intersect( $userGroups, $rules );
	}
}
