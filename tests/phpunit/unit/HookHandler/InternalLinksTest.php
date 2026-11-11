<?php

namespace MWStake\MediaWiki\Component\RichLinks\Tests\Unit\HookHandler;

use MediaWiki\FileRepo\File\File;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Media\ThumbnailImage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use MWStake\MediaWiki\Component\RichLinks\HookHandler\InternalLinks;
use Wikimedia\HtmlArmor\HtmlArmor;

/**
 * @covers \MWStake\MediaWiki\Component\RichLinks\HookHandler\InternalLinks
 */
class InternalLinksTest extends MediaWikiUnitTestCase {

	public function testHtmlPageLinkRendererEndRegularLink() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_MAIN, 'Main_Page' ), 'Main Page' );

		$this->assertSame( [ 'data-mw-title' => 'Main_Page' ], $this->getDataAttributes( $attribs ) );
	}

	public function testHtmlPageLinkRendererEndAdditionalPrefixes() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_MAIN, 'Main_Page' ), 'Main Page', [ 'bs' ] );

		$this->assertSame(
			[ 'data-mw-title' => 'Main_Page', 'data-bs-title' => 'Main_Page' ],
			$this->getDataAttributes( $attribs )
		);
	}

	public function testHtmlPageLinkRendererEndFragmentOnly() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_MAIN, '', 'Section' ), '#Section' );

		$this->assertSame( [], $this->getDataAttributes( $attribs ) );
	}

	public function testHtmlPageLinkRendererEndExternal() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_MAIN, 'Foo', '', 'wikipedia' ), 'wikipedia:Foo' );

		$this->assertSame( [], $this->getDataAttributes( $attribs ) );
	}

	public function testHtmlPageLinkRendererEndFilePage() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_FILE, 'Some_file.png' ), 'File:Some file.png' );

		$this->assertSame(
			[
				'data-mw-filename' => 'Some file.png',
				'data-mw-filetimestamp' => '',
				'data-mw-title' => 'File:Some_file.png',
			],
			$this->getDataAttributes( $attribs )
		);
	}

	public function testHtmlPageLinkRendererEndUserPageWithRealName() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_USER, 'Jane_Doe' ), 'Jane Doe', [], 'Jane Real' );

		$this->assertSame(
			[ 'data-mw-title' => 'User:Jane_Doe', 'data-mw-username' => 'Jane Doe' ],
			$this->getDataAttributes( $attribs )
		);
		$this->assertSame( 'Jane Real', $text );
	}

	public function testHtmlPageLinkRendererEndUserPageBdiWrapped() {
		[ , $text ] = $this->renderLink(
			new TitleValue( NS_USER, 'Jane_Doe' ), new HtmlArmor( '<bdi>Jane Doe</bdi>' ), [], 'Jane Real'
		);

		$this->assertInstanceOf( HtmlArmor::class, $text );
		$this->assertSame( '<bdi>Jane Real</bdi>', HtmlArmor::getHtml( $text ) );
	}

	public function testHtmlPageLinkRendererEndUserPageCustomText() {
		[ $attribs, $text ] = $this->renderLink(
			new TitleValue( NS_USER, 'Jane_Doe' ), 'Some custom text', [], 'Jane Real'
		);

		$this->assertSame( 'Some custom text', $text );
		$this->assertSame( 'Jane Doe', $attribs['data-mw-username'] );
	}

	public function testHtmlPageLinkRendererEndUserSubpage() {
		[ $attribs, $text ] = $this->renderLink( new TitleValue( NS_USER, 'Jane_Doe/Sub' ), 'User:Jane Doe/Sub' );

		$this->assertSame( [ 'data-mw-title' => 'User:Jane_Doe/Sub' ], $this->getDataAttributes( $attribs ) );
	}

	public function testLinkerMakeMediaLinkFileExisting() {
		$file = $this->makeFile( 'Some_file.png', '20261110111336' );
		$attribs = [ 'class' => 'internal' ];
		$html = 'Some file';
		$ret = '';

		$handler = $this->makeHandler();
		$handler->onLinkerMakeMediaLinkFile(
			$this->makeTitle( NS_FILE, 'Some_file.png' ), $file, $html, $attribs, $ret
		);

		$this->assertSame( 'internal media', $attribs['class'] );
		$this->assertSame(
			[
				'data-mw-title' => 'File:Some_file.png',
				'data-mw-filename' => 'Some_file.png',
				'data-mw-filetimestamp' => '20261110111336',
			],
			$this->getDataAttributes( $attribs )
		);
	}

	public function testLinkerMakeMediaLinkFileMissing() {
		$attribs = [ 'class' => 'new' ];
		$html = 'Missing.docx';
		$ret = '';

		$handler = $this->makeHandler();
		$handler->onLinkerMakeMediaLinkFile(
			$this->makeTitle( NS_MEDIA, 'Missing.docx' ), false, $html, $attribs, $ret
		);

		$this->assertSame( 'new media', $attribs['class'] );
		$this->assertSame(
			[
				'data-mw-title' => 'Media:Missing.docx',
				'data-mw-filename' => 'Missing.docx',
				'data-mw-filetimestamp' => '',
			],
			$this->getDataAttributes( $attribs )
		);
	}

	public function testThumbnailBeforeProduceHTML() {
		$file = $this->makeFile( 'Some_file.png', '20261110111336' );
		$thumbnail = $this->createMock( ThumbnailImage::class );
		$thumbnail->method( 'getFile' )->willReturn( $file );
		$attribs = [];
		$linkAttribs = [ 'href' => '/wiki/File:Some_file.png' ];

		$handler = $this->makeHandler( [ 'bs' ] );
		$handler->onThumbnailBeforeProduceHTML( $thumbnail, $attribs, $linkAttribs );

		$this->assertSame( [], $attribs );
		$this->assertSame(
			[
				'data-mw-title' => 'File:Some_file.png',
				'data-bs-title' => 'File:Some_file.png',
				'data-mw-filetimestamp' => '20261110111336',
				'data-bs-filetimestamp' => '20261110111336',
			],
			$this->getDataAttributes( $linkAttribs )
		);
	}

	/**
	 * @param TitleValue $target
	 * @param string|HtmlArmor $text
	 * @param string[] $additionalPrefixes
	 * @param string $realName
	 * @return array [ $attribs, $text ]
	 */
	private function renderLink( TitleValue $target, $text, array $additionalPrefixes = [], string $realName = '' ) {
		$handler = $this->makeHandler( $additionalPrefixes, $realName );
		$attribs = [ 'href' => '/wiki/' . $target->getDBkey() ];
		$ret = null;
		$handler->onHtmlPageLinkRendererEnd(
			$this->createMock( LinkRenderer::class ), $target, true, $text, $attribs, $ret
		);
		return [ $attribs, $text ];
	}

	/**
	 * @param string[] $additionalPrefixes
	 * @param string $realName
	 * @return InternalLinks
	 */
	private function makeHandler( array $additionalPrefixes = [], string $realName = '' ): InternalLinks {
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromLinkTarget' )->willReturnCallback( function ( $target ) {
			return $this->makeTitle( $target->getNamespace(), $target->getDBkey() );
		} );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromName' )->willReturnCallback( function ( $name ) use ( $realName ) {
			$user = $this->createMock( User::class );
			$user->method( 'getName' )->willReturn( $name );
			$user->method( 'getRealName' )->willReturn( $realName );
			return $user;
		} );

		return new InternalLinks( $titleFactory, $userFactory, $additionalPrefixes );
	}

	/**
	 * @param int $namespace
	 * @param string $dbKey
	 * @return Title
	 */
	private function makeTitle( int $namespace, string $dbKey ): Title {
		$nsText = [ NS_MAIN => '', NS_USER => 'User', NS_FILE => 'File', NS_MEDIA => 'Media' ][$namespace];
		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( $namespace );
		$title->method( 'getDBkey' )->willReturn( $dbKey );
		$title->method( 'getPrefixedDBkey' )->willReturn( $nsText === '' ? $dbKey : "$nsText:$dbKey" );
		$title->method( 'isSubpage' )->willReturn( str_contains( $dbKey, '/' ) );
		return $title;
	}

	/**
	 * @param string $name
	 * @param string $timestamp
	 * @return File
	 */
	private function makeFile( string $name, string $timestamp ): File {
		$file = $this->createMock( File::class );
		$file->method( 'getName' )->willReturn( $name );
		$file->method( 'getTimestamp' )->willReturn( $timestamp );
		$file->method( 'getTitle' )->willReturn( $this->makeTitle( NS_FILE, $name ) );
		return $file;
	}

	/**
	 * @param array $attribs
	 * @return array
	 */
	private function getDataAttributes( array $attribs ): array {
		return array_filter(
			$attribs,
			static fn ( $key ) => str_starts_with( $key, 'data-' ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
