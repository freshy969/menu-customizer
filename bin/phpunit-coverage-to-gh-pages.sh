#!/bin/bash

set -e

cd "$(dirname "$0")/.."
current_branch=$(git rev-parse --abbrev-ref HEAD)

if [ -e /tmp/menu-customizer-coverage ]; then
	rm -r /tmp/menu-customizer-coverage
fi
mkdir /tmp/menu-customizer-coverage

phpunit --coverage-html=/tmp/menu-customizer-coverage

git checkout gh-pages
mkdir -p phpunit-coverage
rsync -avz --delete /tmp/menu-customizer-coverage/ ./phpunit-coverage/
git add -A ./phpunit-coverage
git commit -m "Add phpunit test coverage from $current_branch branch"
git push
git checkout -
