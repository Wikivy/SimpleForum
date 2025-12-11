<?php

namespace Wikivy\SimpleForum\Hooks\Handlers;

use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\ILBFactory;
use Wikivy\SimpleForum\Helpers\Permissions;
use Wikivy\SimpleForum\Helpers\ThreadProps;

class OutputHooks implements BeforePageDisplayHook
{
	public function __construct(
		private readonly ILBFactory $lbFactory,
		private readonly UserFactory $userFactory
	) {

	}

	public function onBeforePageDisplay($out, $skin): void
	{
		$title = $skin->getTitle();

		if ( !$title ) {
			return;
		}

		if ( $title->inNamespace( NS_FORUM ) ) {
			$out->addModuleStyles( 'ext.SimpleForum.styles' );
			$this->handleForumPage( $skin, $title );
		} elseif ( $title->inNamespace( NS_THREAD ) ) {
			$out->addModuleStyles( 'ext.SimpleForum.styles' );
			$this->handleThreadPage( $skin, $title );
		}

	}

	private function handleForumPage( SkinTemplate $skin, Title $title ): void
	{
		$out = $skin->getOutput();

		$request = $skin->getRequest();
		$user = $skin->getUser();
		$lang = $out->getLanguage();

		$out->addHTML( '<div class="simpleforum-forum-wrapper">' );
		$out->addHTML( '<h2>' . wfMessage( 'simpleforum-forum-threads-heading' )->escaped() . '</h2>' );

		$forumPageId = $title->getArticleID();
		$forumKey = $title->getDBkey();

		// Pagination
		$perPage = 20;
		$page = max( 1, (int)$request->getInt( 'tp', 1 ) );
		$offset = ( $page - 1 ) * $perPage;

		$dbr = $this->lbFactory->getReplicaDatabase();

		$useMeta = $dbr->tableExists( 'simpleforum_threads', __METHOD__ );
		$hasProps = $dbr->tableExists( 'simpleforum_thread_props', __METHOD__ );

		$threads = [];
		$hasMore = false;

		if ( $useMeta ) {
			// Use metadata table for ordering & extra info
			$tables = [ 'simpleforum_threads', 'page' ];
			$fields = [
				'page.page_id',
				'page.page_title',
				'page.page_namespace',
				'simpleforum_threads.sf_subject',
				'simpleforum_threads.sf_created',
				'simpleforum_threads.sf_creator',
				'simpleforum_threads.sf_last_reply',
				'simpleforum_threads.sf_last_reply_user'
			];
			$conds = [
				'page.page_id = simpleforum_threads.sf_page_id',
				'simpleforum_threads.sf_forum_page_id' => $forumPageId
			];
			$options = [
				'LIMIT'  => $perPage + 1,
				'OFFSET' => $offset
			];
			$joinConds = [];

			if ( $hasProps ) {
				$tables[] = 'simpleforum_thread_props';
				$fields['sf_sticky'] = 'simpleforum_thread_props.sf_sticky';
				$joinConds['simpleforum_thread_props'] = [
					'LEFT JOIN',
					'simpleforum_thread_props.sf_page_id = page.page_id'
				];
				$options['ORDER BY'] =
					'IFNULL(simpleforum_thread_props.sf_sticky,0) DESC, ' .
					'COALESCE(simpleforum_threads.sf_last_reply, simpleforum_threads.sf_created) DESC';
			} else {
				$options['ORDER BY'] =
					'COALESCE(simpleforum_threads.sf_last_reply, simpleforum_threads.sf_created) DESC';
			}

			$res = $dbr->select(
				$tables,
				$fields,
				$conds,
				__METHOD__,
				$options,
				$joinConds
			);
		} else {
			// Fallback: just scan Thread: pages matching ForumTitle/
			$like = $dbr->buildLike( $forumKey . '/', $dbr->anyString() );

			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace' ],
				[
					'page_namespace' => NS_THREAD,
					"page_title $like"
				],
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC',
					'LIMIT'    => $perPage + 1,
					'OFFSET'   => $offset
				]
			);
		}

		if ( $res->numRows() === 0 ) {
			$out->addHTML(
				'<p>' . wfMessage( 'simpleforum-no-threads' )->escaped() . '</p>'
			);
		} else {
			$out->addHTML( '<ul class="simpleforum-thread-list">' );

			foreach ( $res as $row ) {
				if ( count( $threads ) >= $perPage ) {
					// We fetched one extra row to detect "has more"
					$hasMore = true;
					break;
				}

				$threadTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
				if ( !$threadTitle ) {
					continue;
				}

				$meta = [
					'id'       => (int)$row->page_id,
					'title'    => $threadTitle->getPrefixedText(),
					'url'      => $threadTitle->getLocalURL(),
					'subject'  => $useMeta && isset( $row->sf_subject ) ? $row->sf_subject : $threadTitle->getText(),
					'created'  => $useMeta && isset( $row->sf_created ) ? $row->sf_created : null,
					'creator'  => $useMeta && isset( $row->sf_creator ) ? (int)$row->sf_creator : null,
					'lastReply'      => $useMeta && isset( $row->sf_last_reply ) ? $row->sf_last_reply : null,
					'lastReplyUser'  => $useMeta && isset( $row->sf_last_reply_user ) ? (int)$row->sf_last_reply_user : null,
					'sticky'   => $hasProps && isset( $row->sf_sticky ) ? (bool)$row->sf_sticky : false,
				];

				// Resolve display names and timestamps if we have metadata
				if ( $useMeta ) {
					$creatorName = null;
					$lastUserName = null;
					$displayTime = null;

					if ( $meta['creator'] ) {
						$creatorUser = $this->userFactory->newFromId( $meta['creator'] );
						$creatorName = $creatorUser?->getName();
					}

					// Choose last activity timestamp
					$ts = $meta['lastReply'] ?: $meta['created'];
					if ( $ts ) {
						$displayTime = $lang->userTimeAndDate( $ts, $user );
					}

					if ( $meta['lastReplyUser'] ) {
						$lastUser = $this->userFactory->newFromId( $meta['lastReplyUser'] );
						$lastUserName = $lastUser?->getName();
					}

					$meta['creatorName'] = $creatorName;
					$meta['lastUserName'] = $lastUserName;
					$meta['displayTime'] = $displayTime;
				}

				$threads[] = $meta;
			}

			foreach ( $threads as $thread ) {
				$threadTitle = Title::newFromID( $thread['id'] );
				if ( !$threadTitle ) {
					continue;
				}

				$classes = [ 'simpleforum-thread-item' ];
				if ( !empty( $thread['sticky'] ) ) {
					$classes[] = 'simpleforum-thread-sticky';
				}

				$out->addHTML( '<li class="' . htmlspecialchars( implode( ' ', $classes ) ) . '">' );

				$label = htmlspecialchars( $thread['subject'] );
				if ( !empty( $thread['sticky'] ) ) {
					$label = '📌 ' . $label;
				}

				$threadLink = Html::element(
					'a',
					[ 'href' => $threadTitle->getLocalURL() ],
					$label
				);

				// Title line
				$out->addHTML(
					'<div class="simpleforum-thread-title">' .
					$threadLink .
					'</div>'
				);

				// Meta line (only if we have metadata)
				if ( $useMeta ) {
					$metaBits = [];

					if ( !empty( $thread['creatorName'] ) ) {
						$metaBits[] = wfMessage( 'created' )->inContentLanguage()->text() .
							' ' . htmlspecialchars( $thread['creatorName'] );
					}

					if ( !empty( $thread['lastUserName'] ) && !empty( $thread['displayTime'] ) ) {
						$metaBits[] = wfMessage( 'lastmodified' )->inContentLanguage()->text() .
							' ' . htmlspecialchars( $thread['displayTime'] ) .
							' ' . wfMessage( 'by' )->inContentLanguage()->text() .
							' ' . htmlspecialchars( $thread['lastUserName'] );
					} elseif ( !empty( $thread['displayTime'] ) ) {
						$metaBits[] = htmlspecialchars( $thread['displayTime'] );
					}

					if ( $metaBits ) {
						$out->addHTML(
							'<div class="simpleforum-thread-meta">' .
							htmlspecialchars( implode( ' • ', $metaBits ) ) .
							'</div>'
						);
					}
				}

				$out->addHTML( '</li>' );
			}

			$out->addHTML( '</ul>' );
		}

		// Pagination controls
		$pagerHtml = '<div class="simpleforum-pager">';
		if ( $page > 1 ) {
			$prevUrl = $title->getLocalURL( [ 'tp' => $page - 1 ] );
			$pagerHtml .= '<a class="simpleforum-pager-prev" href="' . htmlspecialchars( $prevUrl ) . '">&laquo; ' . intval( $page - 1 ) . '</a>';
		} else {
			$pagerHtml .= '<span class="simpleforum-pager-prev simpleforum-pager-disabled">&laquo;</span>';
		}

		$pagerHtml .= '<span class="simpleforum-pager-current">' . intval( $page ) . '</span>';

		if ( $hasMore ) {
			$nextUrl = $title->getLocalURL( [ 'tp' => $page + 1 ] );
			$pagerHtml .= '<a class="simpleforum-pager-next" href="' . htmlspecialchars( $nextUrl ) . '">' . intval( $page + 1 ) . ' &raquo;</a>';
		} else {
			$pagerHtml .= '<span class="simpleforum-pager-next simpleforum-pager-disabled">&raquo;</span>';
		}

		$pagerHtml .= '</div>';

		$out->addHTML( $pagerHtml );

		// "New thread" link, respecting per-forum permissions
		$user = $skin->getUser();
		if ( Permissions::canCreateThread( $user, $title ) ) {
			$newThreadTitle = Title::newFromText( 'NewThread', NS_SPECIAL );
			$query = [ 'forum' => $title->getPrefixedText() ];

			$newThreadLink = Html::element(
				'a',
				['href' => $newThreadTitle->getLocalURL($query) ],
				wfMessage( 'simpleforum-create-thread-link' )->text()
			);

			$out->addHTML(
				'<p class="simpleforum-newthread-link">' .
				$newThreadLink .
				'</p>'
			);
		}

		$out->addHTML( '</div>' );
	}

	private function handleThreadPage( SkinTemplate $skin, Title $title ): void
	{
		$out = $skin->getOutput();
		$user = $skin->getUser();

		// Derive the parent forum from the thread title:
		// Thread:ForumName/Subject -> Forum:ForumName
		$parts = explode( '/', $title->getText(), 2 );
		if ( !$parts || $parts[0] === '' ) {
			return;
		}

		$forumTitle = Title::makeTitleSafe( NS_FORUM, $parts[0] );
		if ( !$forumTitle ) {
			return;
		}

		$isLocked = ThreadProps::isLocked( $title );
		$isSticky = ThreadProps::isSticky( $title );

		// Show a small lock banner if locked
		if ( $isLocked ) {
			$out->addHTML(
				'<div class="simpleforum-lock-banner">' .
				wfMessage( 'simpleforum-thread-locked-notice' )->escaped() .
				'</div>'
			);
		}

		// Lock/unlock controls for users with protect right
		if ( $user->isAllowed( 'protect' ) ) {
			$lockPage = Title::newFromText( 'LockThread', NS_SPECIAL );
			$token = $skin->getCsrfTokenSet()->getToken( 'lockthread' );

			$action = $isLocked ? 'unlock' : 'lock';
			$labelMsg = $isLocked
				? 'simpleforum-unlockthread-link'
				: 'simpleforum-lockthread-link';

			$html = '<div class="simpleforum-lock-controls">';
			$html .= '<form method="post" action="' .
				htmlspecialchars( $lockPage->getLocalURL() ) . '">';
			$html .= '<input type="hidden" name="thread" value="' .
				htmlspecialchars( $title->getPrefixedText() ) . '" />';
			$html .= '<input type="hidden" name="actionMode" value="' .
				htmlspecialchars( $action ) . '" />';
			$html .= '<input type="hidden" name="token" value="' .
				htmlspecialchars( $token ) . '" />';
			$html .= '<input type="submit" value="' .
				wfMessage( $labelMsg )->escaped() . '" />';
			$html .= '</form>';
			$html .= '</div>';

			// sticky / unsticky
			$stickyAction = $isSticky ? 'unsticky' : 'sticky';
			$stickyLabelMsg = $isSticky
				? 'simpleforum-unstickthread-link'
				: 'simpleforum-stickthread-link';

			$html .= ' <form method="post" action="' .
				htmlspecialchars( $lockPage->getLocalURL() ) . '">';
			$html .= '<input type="hidden" name="thread" value="' .
				htmlspecialchars( $title->getPrefixedText() ) . '" />';
			$html .= '<input type="hidden" name="actionMode" value="' .
				htmlspecialchars( $stickyAction ) . '" />';
			$html .= '<input type="hidden" name="token" value="' .
				htmlspecialchars( $token ) . '" />';
			$html .= '<input type="submit" value="' .
				wfMessage( $stickyLabelMsg )->escaped() . '" />';
			$html .= '</form>';

			$html .= '</div>';

			$out->addHTML( $html );
		}

		// If locked, do NOT show reply form
		if ( $isLocked ) {
			return;
		}

		if ( !Permissions::canReply( $user, $forumTitle ) ) {
			// Don't show a form at all if they can't reply
			return;
		}

		$replyTitle = Title::newFromText( 'ReplyThread', NS_SPECIAL );
		$token = $skin->getCsrfTokenSet()->getToken('replythread');

		$out->addHTML( '<div class="simpleforum-thread-reply-wrapper">' );
		$out->addHTML( '<h2>' . wfMessage( 'simpleforum-thread-replies-heading' )->escaped() . '</h2>' );

		$html = '<form method="post" action="' .
			htmlspecialchars( $replyTitle->getLocalURL() ) .
			'">';

		$html .= '<input type="hidden" name="thread" value="' .
			htmlspecialchars( $title->getPrefixedText() ) . '" />';

		$html .= '<table class="wikitable">';

		$html .= '<tr><th>' .
			wfMessage( 'simpleforum-reply-body' )->escaped() .
			'</th><td>';
		$html .= '<textarea name="wpReplyBody" rows="8" cols="80"></textarea>';
		$html .= '</td></tr>';

		$html .= '</table>';

		$html .= '<input type="hidden" name="token" value="' .
			htmlspecialchars( $token ) . '" />';
		$html .= '<input type="hidden" name="wpReplySubmit" value="1" />';

		$html .= '<p><input type="submit" value="' .
			wfMessage( 'simpleforum-reply-submit' )->escaped() .
			'" /></p>';

		$html .= '</form>';

		$out->addHTML( $html );
		$out->addHTML( '</div>' );
	}
}
