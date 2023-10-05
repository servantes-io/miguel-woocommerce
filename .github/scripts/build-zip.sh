#!/usr/bin/env bash

tmpdir=$(mktemp -d)
result="$(pwd)/miguel.zip"

rm -rf "$result"

mkdir -p $tmpdir/miguel
rsync -a --exclude=.git --exclude=run --exclude=vendor assets includes languages CHANGELOG.md README.md LICENSE miguel.php "${tmpdir}/miguel/"

pushd $tmpdir > /dev/null
    find miguel -name ".DS_Store" -depth -exec rm {} \;

    zip "${result}" -r miguel
popd > /dev/null

rm -rf "$tmpdir"
