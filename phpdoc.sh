#!/usr/bin/env bash
set -euo pipefail

git config --local user.email "action@github.com"
git config --local user.name "GitHub Action"
git fetch origin gh-pages

tmpdir="$(mktemp -d)"
cp -R docs/. "$tmpdir/"

git branch --no-track gh-pages origin/gh-pages 2> /dev/null || true
git switch -f gh-pages

find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +

cp -R "$tmpdir"/. .
rm -rf "$tmpdir"
git add -A

if git diff --cached --quiet; then
	echo "No documentation changes to deploy."
	exit 0
fi

git commit -m "Update PHPDoc ($1)" --no-verify
git push -f origin gh-pages
