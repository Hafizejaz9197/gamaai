#!/usr/bin/env bash
# Builds an installable plugin zip at build/gama-ai-<version>.zip,
# containing only the files WordPress needs (no tests or dev tooling).
set -euo pipefail

cd "$(dirname "$0")/.."
version="$(sed -n "s/^define( 'GAMA_AI_VERSION', '\(.*\)' );$/\1/p" gama-ai.php)"
out="build/gama-ai-${version}.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/gama-ai" build
cp -R gama-ai.php readme.txt src "$stage/gama-ai/"
rm -f "$out"
(cd "$stage" && zip -qr - gama-ai) > "$out"
echo "$out"
