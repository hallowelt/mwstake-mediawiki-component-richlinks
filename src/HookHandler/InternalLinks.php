<?php

namespace MWStake\MediaWiki\Component\RichLinks\HookHandler;

use File;
use Html;
use HtmlArmor;
use MediaWiki\Hook\LinkerMakeMediaLinkFileHook;
use MediaWiki\Hook\ThumbnailBeforeProduceHTMLHook;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererEndHook;
use MediaWiki\User\UserFactory;
use TitleFactory;

class InternalLinks implements
	   HtmlPageLinkRendererEndHook,
	   LinkerMakeMediaLinkFileHook,
	   ThumbnailBeforeProduceHTMLHook
{

	/**
	 * Allow multiple prefixes, for b/c to BlueSpice
	 * @var string[]
	 */
	private $prefixes = [ 'mw' ];

	/**
	 * @var TitleFactory
	 */
	private $titleFactory = null;

	/**
	 * @var UserFactory
	 */
	private $userFactory = null;

	/**
	 * @param TitleFactory $titleFactory
	 * @param UserFactory $userFactory
	 * @param array $additionalPrefixes For b/c to BlueSpice
	 */
	public function __construct( $titleFactory, $userFactory, $additionalPrefixes = [] ) {
		$this->titleFactory = $titleFactory;
		$this->userFactory = $userFactory;
		$this->prefixes += $additionalPrefixes;
	}

	/**
	 * @param array &$attribs
	 * @param string $fieldName
	 * @param string $value
	 * @return void
	 */
	private function setDataAttribute( &$attribs, $fieldName, $value ) {
		foreach ( $this->prefixes  as $prefix ) {
			$attribs["data-$prefix-$fieldName"] = $value;
		}
	}

	/**
	 * Adds data-attributes for "title", "filename", "filetimestamp", "username" and sets the
	 * "real name" as alias in any links to userpdages that do not have an alias set.
	 *
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown,
		&$text, &$attribs, &$ret
	) {
		if ( $this->target->isExternal() ) {
			return true;
		}
		if ( empty( $this->target->getDBkey() ) ) {
			// not a real target (i.e links from the cite extension)
			return true;
		}

		$dbKey = $target->getText();

		if ( $target->getNamespace() === NS_FILE ) {
			$this->setDataAttribute( $attribs, 'filename', $dbKey );
			// In this context we have no way of knowing the timestamp of the linked file.
			// We add an attribute anyways for consistency
			$this->setDataAttribute( $attribs, 'filetimestamp', '' );
		}

		// We add the original title to a link. This may be the same content as
		// "title" attribute, but it doesn't have to. I.e. in red links
		$title = $this->titleFactory->newFromLinkTarget( $target );
		if ( !$title ) {
			return true;
		}

		$prefixedDBKey = $title->getPrefixedDBkey();
		$this->setDataAttribute( $attribs, 'title', $prefixedDBKey );

		if ( $target->getNamespace() !== NS_USER ) {
			return true;
		}

		if ( $title->isSubpage() ) {
			return true;
		}

		$user = $this->userFactory->newFromName( $target->getText() );
		if ( !$user ) {
			// In rare cases `$target->getText()` returns '127.0.0.1' which
			// results in 'false' in `UserFactory::newFromName`
			return true;
		}

		$username = $user->getName();
		$text = HtmlArmor::getHtml( $text );

		// `$text` can be "<bdi>WikiSysop</bdi>" - we must clean it for comparison
		$bdiWrapped = strpos( $text, '<bdi>' ) === 0;
		$strippedText = strip_tags( $text );
		if ( $username === $strippedText ) {
			$realname = $user->getRealName();
			if ( !empty( $realname ) ) {
				$text = $realname;

				if ( $bdiWrapped ) {
					$text = new HtmlArmor(
						Html::element( 'bdi', [], $text )
					);
				}
			}
		}

		$this->setDataAttribute( $attribs, 'username', $username );
	}

	/**
	 * Adds class "media" and data-attributes for "title", "filename" and "filetimestamp"
	 *
	 * @inheritDoc
	 */
	public function onLinkerMakeMediaLinkFile( $title, $file, &$html, &$attribs,
		&$ret
	) {
		$attribs['class'] .= ' media';

		$prefixedDBkey = $title->getPrefixedDBkey();
		$filename = $title->getDBkey();
		$timestamp = '';

		if ( $file instanceof File ) {
			$filename = $file->getName();
			$timestamp = $file->getTimestamp();
		}

		$this->setDataAttribute( $attribs, 'title', $prefixedDBkey );
		$this->setDataAttribute( $attribs, 'filename', $filename );
		$this->setDataAttribute( $attribs, 'filetimestamp', $timestamp );
	}

	/**
	 * Adds data-attributes for "title", "filename" and "filetimestamp"
	 *
	 * @inheritDoc
	 */
	public function onThumbnailBeforeProduceHTML( $thumbnail, &$attribs,
		&$linkAttribs
	) {
		$prefixedDBkey = $thumbnail->getFile()->getTitle()->getPrefixedDBKey();
		$timestamp = $thumbnail->getFile()->getTimestamp();

		$this->setDataAttribute( $linkAttribs, 'title', $prefixedDBkey );
		$this->setDataAttribute( $linkAttribs, 'filetimestamp', $timestamp );
	}
}
