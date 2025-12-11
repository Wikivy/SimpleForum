<?php

namespace Wikivy\SimpleForum\Hooks\Handlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
class Installer implements LoadExtensionSchemaUpdatesHook
{

	public function onLoadExtensionSchemaUpdates($updater): void
	{
		wfDebugLog('SimpleForum Installer', __METHOD__);

		/** @var DatabaseUpdater $updater */
		$base = dirname( __DIR__ );
		$dir = "$base/../../sql";

		$type = $updater->getDB()->getType();

		$updater->addExtensionTable(
			'simpleforum_thread_props',
			"$dir/$type/simpleforum_thread_props.sql"
		);

		$updater->addExtensionTable(
			'simpleforum_forums',
			"$dir/$type/simpleforum_forums.sql"
		);

		$updater->addExtensionTable(
			'simpleforum_threads',
			"$dir/$type/simpleforum_threads.sql"
		);

		$updater->addExtensionField(
			'simpleforum_thread_props',
			'sf_sticky',
			"$dir/$type/patch-add-sticky.sql"
		);
	}
}
