#! /bin/bash

# Sets up a MediaWiki installation in ./mediawiki that requires this component
# from the given local checkout, so its unit tests can be run with MediaWiki's PHPUnit setup.
#
# Usage: installMediaWiki.sh <MediaWiki branch> <path to component checkout>

set -euo pipefail

MW_BRANCH=$1
COMPONENT_PATH=$(realpath "$2")

# Cloned rather than fetched as a tarball: phpunit.xml.template/phpunit.xml.dist are
# export-ignore'd in MediaWiki's .gitattributes, and the PHPUnit setup needs them.
git clone --depth 1 --branch "$MW_BRANCH" https://github.com/wikimedia/mediawiki.git mediawiki

cd mediawiki

# The component is no extension, so it is not loaded via `wfLoadExtension`. Instead it is
# required from the local checkout, like any extension would do in its `composer.json`.
cat <<EOT > composer.local.json
{
	"repositories": [
		{
			"type": "path",
			"url": "$COMPONENT_PATH",
			"options": { "symlink": true }
		}
	],
	"require": {
		"mwstake/mediawiki-component-richlinks": "*@dev"
	}
}
EOT

composer update --no-progress --no-interaction

# MediaWiki's PHPUnit bootstrap needs a `LocalSettings.php` to determine the installed extensions
php maintenance/install.php --dbtype sqlite --dbuser root --dbname mw --dbpath "$(pwd)" \
	--pass AdminPassword WikiName AdminUser

cat <<'EOT' >> LocalSettings.php
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$wgShowExceptionDetails = true;
$wgDevelopmentWarnings = true;
EOT
