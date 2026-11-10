<?php

use MWStake\MediaWiki\Component\RichLinks\HookHandler\InternalLinks;
use MWStake\MediaWiki\Component\RichLinks\Parsoid\InternalLinksProcessor;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_RICHLINKS_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_RICHLINKS_VERSION', '1.1.0' );

MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
->register( 'richlinks', static function () {
	$GLOBALS['mwsgRichLinksPrefixes'] = $GLOBALS['mwsgRichLinksPrefixes'] ?? [];

	$GLOBALS['wgHooks']['MediaWikiServices'][]
		= static function ( MediaWiki\MediaWikiServices $services ) {
			// The `HookContainer` has already read `$wgHooks` at this point, so the handlers
			// must be registered with it directly. The handler object is created lazily
			// and shared between the hooks
			$hookContainer = $services->getHookContainer();
			$handlerSpec = [
				'handler' => [
					'name' => 'MWStakeRichLinksInternalLinks',
					'class' => InternalLinks::class,
					'services' => [ 'TitleFactory', 'UserFactory' ],
					'args' => [ $GLOBALS['mwsgRichLinksPrefixes'] ]
				]
			];
			$hooks = [ 'HtmlPageLinkRendererEnd', 'LinkerMakeMediaLinkFile', 'ThumbnailBeforeProduceHTML' ];
			foreach ( $hooks as $hook ) {
				$hookContainer->register( $hook, $handlerSpec );
			}

			// Parsoid does not run the hooks above for links within the content.
			// Components can not use the `ParsoidModules` attribute of `extension.json`,
			// therefore the module gets registered directly at the Parsoid site config
			$services->addServiceManipulator(
				'ParsoidSiteConfig',
				static function ( $siteConfig ) {
					$siteConfig->registerExtensionModule( [
						'name' => 'MWStakeRichLinks',
						'domProcessors' => [
							[
								'class' => InternalLinksProcessor::class,
								'services' => [ 'TitleFactory', 'UserFactory', 'RepoGroup' ],
								'args' => [
									array_values( array_unique( array_merge(
										[ 'mw' ],
										$GLOBALS['mwsgRichLinksPrefixes']
									) ) )
								]
							]
						]
					] );
				}
			);
		};
} );
