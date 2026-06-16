#!/bin/bash
set -e

if ! command -v php &>/dev/null; then
    echo "Error: php is not installed. Please install PHP 8.2 or higher."
    exit 1
fi

if ! command -v curl &>/dev/null; then
    echo "Error: curl is not installed. Please install curl."
    exit 1
fi

SCRIPT_URL="https://raw.githubusercontent.com/forge-engine/installer/main/create-project.php"
SCRIPT_PATH="/tmp/forge-create-project.php"

echo "Downloading Forge Project Scaffolder..."
if ! curl -sSL "$SCRIPT_URL" -o "$SCRIPT_PATH"; then
    echo "Error: Failed to download scaffold script."
    exit 1
fi

php "$SCRIPT_PATH" "$@"
