<?php

namespace Wikivy\SimpleForum\Models;

use MediaWiki\Title\Title;

class ForumInfo
{
	private int $id;
	private Title $title;

	private string $name;

	private string $url;

	/** @var array ForumInfo[] */
	private array $children;

	private int $postCount;

	private int $threadCount;

	private $lastPost;

	public function __construct(Title $children, array $data)
	{

	}
}
