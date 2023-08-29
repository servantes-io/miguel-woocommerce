#!/usr/bin/env bash

zipfile="$(pwd)/miguel-${CI_COMMIT_TAG:=dev}.zip"

set -ex

# Pack the plugin
pushd src > /dev/null
    zip -r "${zipfile}" *
popd > /dev/null;

# Add the plugin meta files
zip "${zipfile}" composer.json README.md CHANGELOG.md
