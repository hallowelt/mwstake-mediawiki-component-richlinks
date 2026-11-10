<?php

namespace MWStake\MediaWiki\Component\RichLinks\Tests\Unit\Parsoid;

use File;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use MWStake\MediaWiki\Component\RichLinks\Parsoid\InternalLinksProcessor;
use RepoGroup;
use Wikimedia\Parsoid\Config\PageConfig;
use Wikimedia\Parsoid\Config\SiteConfig;
use Wikimedia\Parsoid\Core\LinkTarget;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;

/**
 * @covers \MWStake\MediaWiki\Component\RichLinks\Parsoid\InternalLinksProcessor
 */
class InternalLinksProcessorTest extends MediaWikiUnitTestCase {

	private const NAMESPACES = [
		'Media' => NS_MEDIA,
		'Special' => NS_SPECIAL,
		'User' => NS_USER,
		'File' => NS_FILE,
		'Help' => NS_HELP,
	];

	private const CONTEXT_PAGE = 'Testpage';

	private const EXISTING_FILE = 'Existing.png';

	private const EXISTING_FILE_TIMESTAMP = '20261110111336';

	/**
	 * @return array
	 */
	public static function provideWtPostprocess(): array {
		return [
			'Regular link' => [
				'<a id="t" rel="mw:WikiLink" href="./Main_Page" title="Main Page">Main Page</a>',
				[ 'data-mw-title' => 'Main_Page' ]
			],
			'Link with namespace' => [
				'<a id="t" rel="mw:WikiLink" href="./Help:Some_page" title="Help:Some page">x</a>',
				[ 'data-mw-title' => 'Help:Some_page' ]
			],
			'Red link: localized "title" attribute is not used' => [
				'<a id="t" rel="mw:WikiLink" href="./Some_page?action=edit&amp;redlink=1"'
					. ' title="Some page (page does not exist)" class="new">Some page</a>',
				[ 'data-mw-title' => 'Some_page' ]
			],
			'Self link without "title" attribute' => [
				'<a id="t" rel="mw:WikiLink" href="./Testpage" class="mw-selflink selflink">Testpage</a>',
				[ 'data-mw-title' => 'Testpage' ]
			],
			'Fragment-only link is skipped, like in the legacy parser' => [
				'<a id="t" rel="mw:WikiLink" href="./Testpage#Section" class="mw-selflink-fragment">#Section</a>',
				[]
			],
			'Link with fragment to other page' => [
				'<a id="t" rel="mw:WikiLink" href="./Main_Page#Section" title="Main Page">x</a>',
				[ 'data-mw-title' => 'Main_Page' ]
			],
			'URL encoded target' => [
				'<a id="t" rel="mw:WikiLink" href="./%C3%84rger_mit_%22Quotes%22" title="Ärger">x</a>',
				[ 'data-mw-title' => 'Ärger_mit_"Quotes"' ]
			],
			'No fallback to "title" if "href" is not relative' => [
				'<a id="t" rel="mw:WikiLink" href="https://example.com/wiki/Main_Page" title="Main Page">x</a>',
				[]
			],
			'User page link' => [
				'<a id="t" rel="mw:WikiLink" href="./User:Jane_Doe" title="User:Jane Doe">Jane Doe</a>',
				[ 'data-mw-title' => 'User:Jane_Doe', 'data-mw-username' => 'Jane Doe' ]
			],
			'User subpage link has no username' => [
				'<a id="t" rel="mw:WikiLink" href="./User:Jane_Doe/Sub" title="User:Jane Doe/Sub">x</a>',
				[ 'data-mw-title' => 'User:Jane_Doe/Sub' ]
			],
			'Link to file description page' => [
				'<a id="t" rel="mw:WikiLink" href="./File:Existing.png" title="File:Existing.png">x</a>',
				[
					'data-mw-title' => 'File:Existing.png',
					'data-mw-filename' => 'Existing.png',
					'data-mw-filetimestamp' => '',
				]
			],
			'Nested link' => [
				'<ul><li><p>Text <b><a id="t" rel="mw:WikiLink" href="./Main_Page">x</a></b></p></li></ul>',
				[ 'data-mw-title' => 'Main_Page' ]
			],
			'External link is not touched' => [
				'<a id="t" rel="mw:ExtLink nofollow" href="https://example.com" class="external">x</a>',
				[]
			],
			'Interwiki link is not touched' => [
				'<a id="t" rel="mw:WikiLink/Interwiki" href="https://en.wikipedia.org/wiki/Foo" title="wikipedia:Foo">'
					. 'x</a>',
				[]
			],
			'Media link to existing file uses the "File:" title' => [
				'<a id="t" rel="mw:MediaLink" href="//localhost/images/a/ab/Existing.png"'
					. ' resource="./Media:Existing.png" title="Existing.png">x</a>',
				[
					'data-mw-title' => 'File:Existing.png',
					'data-mw-filename' => 'Existing.png',
					'data-mw-filetimestamp' => self::EXISTING_FILE_TIMESTAMP,
				],
				'media'
			],
			'Media link to missing file' => [
				'<a id="t" rel="mw:MediaLink" href="./Special:Upload?wpDestFile=Missing.docx"'
					. ' resource="./Media:Missing.docx" title="Missing.docx" typeof="mw:Error">x</a>',
				[
					'data-mw-title' => 'Media:Missing.docx',
					'data-mw-filename' => 'Missing.docx',
					'data-mw-filetimestamp' => '',
				],
				'media'
			],
			'Media link keeps existing classes' => [
				'<a id="t" rel="mw:MediaLink" href="./Special:Upload" resource="./Media:Missing.docx"'
					. ' class="foo">x</a>',
				[
					'data-mw-title' => 'Media:Missing.docx',
					'data-mw-filename' => 'Missing.docx',
					'data-mw-filetimestamp' => '',
				],
				'foo media'
			],
			'No fallback to "title" for media links without "resource"' => [
				'<a id="t" rel="mw:MediaLink" href="//localhost/images/a/ab/Existing.png" title="Existing.png">x</a>',
				[]
			],
			'Thumbnail' => [
				'<figure typeof="mw:File/Thumb"><a id="t" href="./File:Existing.png" class="mw-file-description">'
					. '<img resource="./File:Existing.png" src="//localhost/images/thumb.png"/></a>'
					. '<figcaption>Caption</figcaption></figure>',
				[
					'data-mw-title' => 'File:Existing.png',
					'data-mw-filetimestamp' => self::EXISTING_FILE_TIMESTAMP,
				]
			],
			'Inline image with custom link target' => [
				'<span typeof="mw:File"><a id="t" href="./Main_Page">'
					. '<img resource="./File:Existing.png" src="//localhost/images/a.png"/></a></span>',
				[
					'data-mw-title' => 'File:Existing.png',
					'data-mw-filetimestamp' => self::EXISTING_FILE_TIMESTAMP,
				]
			],
			'Thumbnail of missing file' => [
				'<span typeof="mw:File mw:Error"><a id="t" href="./Special:Upload?wpDestFile=Missing.png">'
					. '<span resource="./File:Missing.png" class="mw-broken-media">File:Missing.png</span>'
					. '</a></span>',
				[]
			],
		];
	}

	/**
	 * @param string $html
	 * @param array $expectedDataAttributes
	 * @param string|null $expectedClass
	 * @dataProvider provideWtPostprocess
	 */
	public function testWtPostprocess( string $html, array $expectedDataAttributes, ?string $expectedClass = null ) {
		$element = $this->process( $html, [ 'mw' ] );

		$this->assertDataAttributes( $expectedDataAttributes, $element );
		if ( $expectedClass !== null ) {
			$this->assertSame( $expectedClass, $element->getAttribute( 'class' ) );
		}
	}

	public function testAdditionalPrefixes() {
		$element = $this->process(
			'<a id="t" rel="mw:WikiLink" href="./User:Jane_Doe" title="User:Jane Doe">x</a>',
			[ 'mw', 'bs' ]
		);

		$this->assertDataAttributes(
			[
				'data-mw-title' => 'User:Jane_Doe',
				'data-bs-title' => 'User:Jane_Doe',
				'data-mw-username' => 'Jane Doe',
				'data-bs-username' => 'Jane Doe',
			],
			$element
		);
	}

	public function testLinkTextIsNotAltered() {
		$element = $this->process(
			'<a id="t" rel="mw:WikiLink" href="./User:Jane_Doe" title="User:Jane Doe">Jane Doe</a>',
			[ 'mw' ]
		);

		$this->assertSame( 'Jane Doe', $element->textContent );
	}

	public function testCustomRelativeLinkPrefix() {
		$element = $this->process(
			'<a id="t" rel="mw:WikiLink" href="/wiki/Main_Page" title="Main Page">x</a>',
			[ 'mw' ],
			'/wiki/'
		);

		$this->assertDataAttributes( [ 'data-mw-title' => 'Main_Page' ], $element );
	}

	/**
	 * @param string $html
	 * @param string[] $prefixes
	 * @param string $linkPrefix
	 * @return mixed The element with id "t"
	 */
	private function process( string $html, array $prefixes, string $linkPrefix = './' ) {
		$doc = DOMUtils::parseHTML( "<html><body>$html</body></html>" );
		$body = DOMCompat::getBody( $doc );

		$processor = new InternalLinksProcessor(
			$this->makeTitleFactory(),
			$this->makeUserFactory(),
			$this->makeRepoGroup(),
			$prefixes
		);
		$processor->wtPostprocess( $this->makeExtApi( $linkPrefix ), $body, [] );

		$element = DOMCompat::querySelector( $body, '#t' );
		$this->assertNotNull( $element, 'Test element not found' );

		return $element;
	}

	/**
	 * Asserts that the element has exactly the expected `data-mw-*`/`data-bs-*` attributes,
	 * regardless of their order
	 *
	 * @param array $expected
	 * @param mixed $element
	 */
	private function assertDataAttributes( array $expected, $element ): void {
		$actual = [];
		foreach ( $element->attributes as $attribute ) {
			if ( preg_match( '/^data-(mw|bs)-/', $attribute->name ) ) {
				$actual[$attribute->name] = $attribute->value;
			}
		}
		ksort( $expected );
		ksort( $actual );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Very simplified title parsing, sufficient for the test data
	 *
	 * @param string $text
	 * @return Title|null
	 */
	private function makeTitle( string $text ): ?Title {
		$text = str_replace( ' ', '_', trim( $text ) );
		if ( $text === '' || preg_match( '/[<>\[\]{}|#]/', $text ) ) {
			return null;
		}
		$namespace = NS_MAIN;
		$nsText = '';
		$dbKey = $text;
		$parts = explode( ':', $text, 2 );
		if ( count( $parts ) === 2 && isset( self::NAMESPACES[$parts[0]] ) ) {
			$nsText = $parts[0];
			$namespace = self::NAMESPACES[$nsText];
			$dbKey = $parts[1];
		}
		$dbKey = ucfirst( $dbKey );
		$prefixedDBkey = $nsText === '' ? $dbKey : "$nsText:$dbKey";

		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( $namespace );
		$title->method( 'getDBkey' )->willReturn( $dbKey );
		$title->method( 'getText' )->willReturn( str_replace( '_', ' ', $dbKey ) );
		$title->method( 'getPrefixedDBkey' )->willReturn( $prefixedDBkey );
		$title->method( 'isExternal' )->willReturn( false );
		$title->method( 'isSubpage' )->willReturn( $namespace === NS_USER && str_contains( $dbKey, '/' ) );
		$title->method( 'equals' )->willReturnCallback(
			static function ( $other ) use ( $prefixedDBkey ) {
				return $other->getPrefixedDBkey() === $prefixedDBkey;
			}
		);

		return $title;
	}

	/**
	 * @return TitleFactory
	 */
	private function makeTitleFactory(): TitleFactory {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturnCallback( function ( $text ) {
			return $this->makeTitle( (string)$text );
		} );
		$titleFactory->method( 'makeTitleSafe' )->willReturnCallback( function ( $ns, $text ) {
			$nsText = array_search( $ns, self::NAMESPACES, true );
			return $this->makeTitle( $nsText === false ? $text : "$nsText:$text" );
		} );
		$titleFactory->method( 'newFromLinkTarget' )->willReturnCallback( function ( $linkTarget ) {
			return $this->makeTitle( $linkTarget->getDBkey() );
		} );
		return $titleFactory;
	}

	/**
	 * @return UserFactory
	 */
	private function makeUserFactory(): UserFactory {
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturnCallback( function ( $name ) {
			$user = $this->createMock( User::class );
			$user->method( 'getName' )->willReturn( $name );
			return $user;
		} );
		return $userFactory;
	}

	/**
	 * @return RepoGroup
	 */
	private function makeRepoGroup(): RepoGroup {
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturnCallback( function ( $title ) {
			if ( $title->getDBkey() !== self::EXISTING_FILE ) {
				return false;
			}
			$file = $this->createMock( File::class );
			$file->method( 'getName' )->willReturn( self::EXISTING_FILE );
			$file->method( 'getTimestamp' )->willReturn( self::EXISTING_FILE_TIMESTAMP );
			$file->method( 'getTitle' )->willReturn( $this->makeTitle( 'File:' . self::EXISTING_FILE ) );
			return $file;
		} );
		return $repoGroup;
	}

	/**
	 * @param string $linkPrefix
	 * @return ParsoidExtensionAPI
	 */
	private function makeExtApi( string $linkPrefix ): ParsoidExtensionAPI {
		$siteConfig = $this->createMock( SiteConfig::class );
		$siteConfig->method( 'relativeLinkPrefix' )->willReturn( $linkPrefix );

		$linkTarget = $this->createMock( LinkTarget::class );
		$linkTarget->method( 'getDBkey' )->willReturn( self::CONTEXT_PAGE );
		$pageConfig = $this->createMock( PageConfig::class );
		$pageConfig->method( 'getLinkTarget' )->willReturn( $linkTarget );

		$extApi = $this->createMock( ParsoidExtensionAPI::class );
		$extApi->method( 'getSiteConfig' )->willReturn( $siteConfig );
		$extApi->method( 'getPageConfig' )->willReturn( $pageConfig );
		return $extApi;
	}
}
