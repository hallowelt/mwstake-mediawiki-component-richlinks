# MediaWiki Stakeholders Group - Components
# RichLinks for MediaWiki

This component will alter links, created by the MediaWiki application. It will
- add `data`-attributes that allow client side code to have more context
- add CSS-`class`es to allow better styling
- alter the canonical username to the users real name on various locations (e. g. `action=history`)

**This code is meant to be executed within the MediaWiki application context. No standalone usage is intended.**

## Compatibility
- \>= `1.0.x` -> MediaWiki 1.47

## Use in a MediaWiki extension

Require this component in the `composer.json` of your extension:

```json
{
	"require": {
		"mwstake/mediawiki-component-richlinks": "~1"
	}
}
```

## Data attributes

| Attribute | Set on |
|---|---|
| `data-mw-title` | all internal links, media links and linked embedded files (prefixed DB key) |
| `data-mw-username` | links to user pages (not subpages) |
| `data-mw-filename` | links to `File:` pages and media links |
| `data-mw-filetimestamp` | links to `File:` pages (empty), media links and linked embedded files |

Additional prefixes can be configured with `$GLOBALS['mwsgRichLinksPrefixes']`, e.g. `[ 'bs' ]` to also
emit `data-bs-title` and so on.

## Parsoid

Parsoid does not invoke the `HtmlPageLinkRendererEnd`, `LinkerMakeMediaLinkFile` and
`ThumbnailBeforeProduceHTML` hooks for links within the page content. For Parsoid output the same data
attributes are added by a DOM post-processor (`Parsoid\InternalLinksProcessor`). Since Parsoid HTML is
also used for editing (e.g. VisualEditor), the processor does not replace the link text of user page
links with the user's real name.

Existing parser cache entries do not contain the attributes. Purge affected pages or bump
`$wgCacheEpoch` after installing this version.

## Tests

The unit tests in `tests/phpunit/unit` use MediaWiki's `MediaWikiUnitTestCase` and therefore need to be
run from within a MediaWiki installation that has this component installed, e.g.

```sh
composer phpunit -- vendor/mwstake/mediawiki-component-richlinks/tests/phpunit/unit
```
