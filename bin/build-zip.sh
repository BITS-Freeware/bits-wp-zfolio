#!/usr/bin/env bash
# Builds dist/bristlecone-zfolio.zip from the committed tree.
# Files marked export-ignore in .gitattributes are left out.
set -euo pipefail

cd "$(dirname "$0")/.."

slug=bristlecone-zfolio
version=$(sed -n 's/^ \* Version: *//p' "$slug.php")
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)

if [ "$version" != "$stable" ]; then
	echo "Version $version in $slug.php does not match Stable tag $stable in readme.txt." >&2
	exit 1
fi

mkdir -p dist
rm -f "dist/$slug.zip"
git archive --format=zip --prefix="$slug/" -o "dist/$slug.zip" HEAD
echo "dist/$slug.zip ($version)"
