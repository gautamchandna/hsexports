#!/usr/bin/env bash
#
# Run the HelpScout exporter via Docker, because PHP/Composer are not installed
# on this machine. Vendor deps are already committed under vendor/.
#
# Usage:
#   ./run.sh "<date-range>" [mailbox]      # run hsexports.php
#   ./run.sh --php accesstoken.php         # run a different script (e.g. token)
#
# Examples:
#   ./run.sh "2026" "POP Support"
#   ./run.sh "2024 - 2026" all
#   ./run.sh --php accesstoken.php
#
set -euo pipefail
cd "$(dirname "$0")"

IMAGE=php:8.3-cli
SCRIPT=hsexports.php
if [ "${1:-}" = "--php" ]; then
    SCRIPT="$2"
    shift 2
fi

# Docker Desktop on WSL registers a credential helper (docker-credential-desktop.exe)
# that isn't on PATH for non-interactive shells, which breaks even anonymous Hub
# pulls. Point DOCKER_CONFIG at a throwaway empty config to bypass it.
DOCKER_CONFIG="$(mktemp -d)"
export DOCKER_CONFIG
echo '{}' > "$DOCKER_CONFIG/config.json"
trap 'rm -rf "$DOCKER_CONFIG"' EXIT

# -i keeps stdin open so the interactive mailbox picker still works when no
# mailbox argument is passed.
docker run --rm -i -v "$PWD":/app -w /app "$IMAGE" php "$SCRIPT" "$@"

# The container writes output as root; hand it back to the host user.
docker run --rm -v "$PWD":/app -w /app "$IMAGE" \
    chown -R "$(id -u):$(id -g)" conversations export-*.csv 2>/dev/null || true
