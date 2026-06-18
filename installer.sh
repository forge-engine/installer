#!/bin/bash
set -e

BASE_URL="https://raw.githubusercontent.com/forge-kernel/installer/main"
SCRIPT_PATH="/tmp/forge-create-project.php"
INTERACTIVE_PATH="/tmp/InteractiveSelect.php"

cleanup() {
    rm -f "$SCRIPT_PATH" "$INTERACTIVE_PATH"
}
trap cleanup EXIT

if ! command -v php &>/dev/null; then
    echo "Error: php is not installed. Please install PHP 8.2 or higher."
    exit 1
fi

if ! command -v curl &>/dev/null; then
    echo "Error: curl is not installed. Please install curl."
    exit 1
fi

echo "Downloading Forge Project Scaffolder..."
if ! curl -sSL "$BASE_URL/create-project.php" -o "$SCRIPT_PATH"; then
    echo "Error: Failed to download scaffold script."
    exit 1
fi

if ! curl -sSL "$BASE_URL/InteractiveSelect.php" -o "$INTERACTIVE_PATH"; then
    echo "Error: Failed to download interactive select component."
    exit 1
fi

php "$SCRIPT_PATH" "$@"
