<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_RICHLINKS_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_RICHLINKS_VERSION', '1.0.1' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'richlinks', static function () {
	$GLOBALS['mwsgRichLinksPrefixes'] = $GLOBALS['mwsgRichLinksPrefixes'] || [];

	$GLOBALS['wgHooks']['MediaWikiServices'][]
		= static function ( MediaWiki\MediaWikiServices $services ) {
			$handler = new MWStake\MediaWiki\Component\RichLinks\HookHandler\InternalLinks(
				$services->getTitleFactory(),
				$services->getUserFactory(),
				$GLOBALS['mwsgRichLinksPrefixes']
			);

			$GLOBALS['wgHooks']['HtmlPageLinkRendererEnd'][]
				= [ $handler, 'onHtmlPageLinkRendererEnd' ];
			$GLOBALS['wgHooks']['LinkerMakeMediaLinkFile'][]
				= [ $handler, 'onLinkerMakeMediaLinkFile' ];
			$GLOBALS['wgHooks']['ThumbnailBeforeProduceHTML'][]
				= [ $handler, 'onThumbnailBeforeProduceHTML' ];
		};
} );
