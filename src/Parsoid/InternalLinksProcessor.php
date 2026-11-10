<?php

namespace MWStake\MediaWiki\Component\RichLinks\Parsoid;

use File;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use RepoGroup;
use Wikimedia\Parsoid\Ext\DOMProcessor;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;

/**
 * Parsoid counterpart of `HookHandler\InternalLinks`.
 *
 * Parsoid does not invoke the legacy `HtmlPageLinkRendererEnd`, `LinkerMakeMediaLinkFile` and
 * `ThumbnailBeforeProduceHTML` hooks for links within the content. This processor adds the same
 * data-attributes (and the "media" CSS class) to the Parsoid DOM.
 *
 * Unlike the legacy hook handler it does NOT replace the link text of user page links with the
 * user's real name: Parsoid HTML is also used for editing (e.g. VisualEditor), and altering
 * the link text would be serialized back into the wikitext.
 */
class InternalLinksProcessor extends DOMProcessor {

	/**
	 * @var string[]
	 */
	private $prefixes = [];

	/**
	 * @param TitleFactory $titleFactory
	 * @param UserFactory $userFactory
	 * @param RepoGroup $repoGroup
	 * @param string[] $prefixes
	 */
	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly RepoGroup $repoGroup,
		array $prefixes = [ 'mw' ]
	) {
		$this->prefixes = $prefixes;
	}

	/**
	 * `$root` is left untyped to stay compatible with the different signatures of
	 * `DOMProcessor::wtPostprocess` across Parsoid versions.
	 *
	 * @param ParsoidExtensionAPI $extApi
	 * @param \Wikimedia\Parsoid\DOM\DocumentFragment|\Wikimedia\Parsoid\DOM\Element $root
	 * @param array $options
	 * @return void
	 */
	public function wtPostprocess( ParsoidExtensionAPI $extApi, $root, array $options ): void {
		$linkPrefix = $extApi->getSiteConfig()->relativeLinkPrefix();
		$contextTitle = $this->titleFactory->newFromLinkTarget(
			$extApi->getPageConfig()->getLinkTarget()
		);

		$this->walk( $root, $linkPrefix, $contextTitle );
	}

	/**
	 * Iterative depth-first traversal. Only uses basic DOM API, so it works with all
	 * DOM implementations Parsoid may use.
	 *
	 * @param mixed $root
	 * @param string $linkPrefix
	 * @param Title $contextTitle
	 */
	private function walk( $root, string $linkPrefix, Title $contextTitle ): void {
		$stack = [ $root ];
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( $node->nodeType === XML_ELEMENT_NODE ) {
				$this->processElement( $node, $linkPrefix, $contextTitle );
			}
			for ( $child = $node->lastChild; $child !== null; $child = $child->previousSibling ) {
				$stack[] = $child;
			}
		}
	}

	/**
	 * @param mixed $element
	 * @param string $linkPrefix
	 * @param Title $contextTitle
	 */
	private function processElement( $element, string $linkPrefix, Title $contextTitle ): void {
		$nodeName = strtolower( $element->nodeName );
		if ( $nodeName === 'a' ) {
			$rel = $this->getTokens( $element, 'rel' );
			if ( in_array( 'mw:WikiLink', $rel, true ) ) {
				$this->processWikiLink( $element, $linkPrefix, $contextTitle );
			} elseif ( in_array( 'mw:MediaLink', $rel, true ) ) {
				$this->processMediaLink( $element, $linkPrefix );
			}
			return;
		}

		$typeOf = $this->getTokens( $element, 'typeof' );
		foreach ( $typeOf as $type ) {
			if ( $type === 'mw:File' || str_starts_with( $type, 'mw:File/' ) ) {
				$this->processFileContainer( $element, $linkPrefix );
				return;
			}
		}
	}

	/**
	 * Equivalent of `InternalLinks::onHtmlPageLinkRendererEnd`
	 *
	 * @param mixed $anchor
	 * @param string $linkPrefix
	 * @param Title $contextTitle
	 */
	private function processWikiLink( $anchor, string $linkPrefix, Title $contextTitle ): void {
		$href = $anchor->getAttribute( 'href' ) ?? '';
		$title = $this->titleFromRelativeUrl( $href, $linkPrefix );
		if ( !$title && $anchor->hasAttribute( 'title' ) ) {
			$title = $this->titleFactory->newFromText( $anchor->getAttribute( 'title' ) );
		}
		if ( !$title || $title->isExternal() || $title->getDBkey() === '' ) {
			return;
		}
		if ( str_contains( $href, '#' ) && $title->equals( $contextTitle ) ) {
			// Fragment-only link (`[[#Section]]`). The legacy parser renders those without
			// a target title, so the hook handler skips them as well
			return;
		}

		if ( $title->getNamespace() === NS_FILE ) {
			$this->setDataAttribute( $anchor, 'filename', $title->getText() );
			// In this context we have no way of knowing the timestamp of the linked file.
			// We add an attribute anyways for consistency
			$this->setDataAttribute( $anchor, 'filetimestamp', '' );
		}

		$this->setDataAttribute( $anchor, 'title', $title->getPrefixedDBkey() );

		if ( $title->getNamespace() !== NS_USER || $title->isSubpage() ) {
			return;
		}
		$user = $this->userFactory->newFromName( $title->getText() );
		if ( !$user ) {
			return;
		}
		$this->setDataAttribute( $anchor, 'username', $user->getName() );
	}

	/**
	 * Equivalent of `InternalLinks::onLinkerMakeMediaLinkFile`
	 *
	 * @param mixed $anchor
	 * @param string $linkPrefix
	 */
	private function processMediaLink( $anchor, string $linkPrefix ): void {
		$title = $this->titleFromRelativeUrl( $anchor->getAttribute( 'resource' ) ?? '', $linkPrefix );
		if ( !$title && $anchor->hasAttribute( 'title' ) ) {
			$title = $this->titleFactory->makeTitleSafe( NS_MEDIA, $anchor->getAttribute( 'title' ) );
		}
		if ( !$title ) {
			return;
		}

		$this->addClass( $anchor, 'media' );

		$filename = $title->getDBkey();
		$timestamp = '';
		$file = $this->findFile( $title );
		if ( $file ) {
			// Like the legacy parser: existing files are referenced by their `File:` title,
			// missing ones by the `Media:` title
			$title = $file->getTitle();
			$filename = $file->getName();
			$timestamp = (string)$file->getTimestamp();
		}

		$this->setDataAttribute( $anchor, 'title', $title->getPrefixedDBkey() );
		$this->setDataAttribute( $anchor, 'filename', $filename );
		$this->setDataAttribute( $anchor, 'filetimestamp', $timestamp );
	}

	/**
	 * Equivalent of `InternalLinks::onThumbnailBeforeProduceHTML`
	 *
	 * Parsoid renders embedded files as
	 * `<figure|span typeof="mw:File/..."><a ...><img resource="./File:..."/></a>...</figure>`
	 *
	 * @param mixed $container
	 * @param string $linkPrefix
	 */
	private function processFileContainer( $container, string $linkPrefix ): void {
		$anchor = null;
		$media = null;
		foreach ( $container->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}
			if ( strtolower( $child->nodeName ) === 'a' ) {
				$anchor = $child;
				$media = $this->findResourceElement( $child );
				break;
			}
			if ( $child->hasAttribute( 'resource' ) ) {
				// Unlinked media, e.g. `[[File:X.png|link=]]`
				$media = $child;
				break;
			}
		}
		if ( !$anchor || !$media ) {
			return;
		}

		$title = $this->titleFromRelativeUrl( $media->getAttribute( 'resource' ) ?? '', $linkPrefix );
		if ( !$title ) {
			return;
		}
		$file = $this->findFile( $title );
		if ( !$file ) {
			// The legacy hook only fires when a thumbnail can be rendered
			return;
		}

		$this->setDataAttribute( $anchor, 'title', $file->getTitle()->getPrefixedDBkey() );
		$this->setDataAttribute( $anchor, 'filetimestamp', (string)$file->getTimestamp() );
	}

	/**
	 * @param mixed $element
	 * @return mixed|null
	 */
	private function findResourceElement( $element ) {
		foreach ( $element->childNodes as $child ) {
			if ( $child->nodeType === XML_ELEMENT_NODE && $child->hasAttribute( 'resource' ) ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Parsoid uses URLs relative to the article path, e.g. `./Help:Some_page?action=edit#Section`
	 *
	 * @param string $url
	 * @param string $linkPrefix
	 * @return Title|null
	 */
	private function titleFromRelativeUrl( string $url, string $linkPrefix ): ?Title {
		if ( $url === '' || $linkPrefix === '' || !str_starts_with( $url, $linkPrefix ) ) {
			return null;
		}
		$text = substr( $url, strlen( $linkPrefix ) );
		$text = preg_replace( '/[?#].*$/s', '', $text );
		$text = rawurldecode( $text );
		if ( $text === '' ) {
			return null;
		}
		return $this->titleFactory->newFromText( $text );
	}

	/**
	 * @param Title $title
	 * @return File|null
	 */
	private function findFile( Title $title ): ?File {
		if ( !in_array( $title->getNamespace(), [ NS_FILE, NS_MEDIA ], true ) ) {
			return null;
		}
		$file = $this->repoGroup->findFile( $title );
		return $file instanceof File ? $file : null;
	}

	/**
	 * @param mixed $element
	 * @param string $attribute
	 * @return string[]
	 */
	private function getTokens( $element, string $attribute ): array {
		if ( !$element->hasAttribute( $attribute ) ) {
			return [];
		}
		return preg_split( '/\s+/', trim( $element->getAttribute( $attribute ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * @param mixed $element
	 * @param string $class
	 */
	private function addClass( $element, string $class ): void {
		$classes = $this->getTokens( $element, 'class' );
		if ( in_array( $class, $classes, true ) ) {
			return;
		}
		$classes[] = $class;
		$element->setAttribute( 'class', implode( ' ', $classes ) );
	}

	/**
	 * @param mixed $element
	 * @param string $fieldName
	 * @param string $value
	 */
	private function setDataAttribute( $element, string $fieldName, string $value ): void {
		foreach ( $this->prefixes as $prefix ) {
			$element->setAttribute( "data-$prefix-$fieldName", $value );
		}
	}
}
