#!/bin/bash
set -e

STARTER_REPO_BASE_URL="https://github.com/forge-engine/forge-starter/archive/refs/heads/main.zip"
STARTER_TEMPLATE_NAME="starter-blank"
STARTER_ZIP_FILENAME="starter-template.zip"
GITHUB_ARCHIVE_ROOT_FOLDER="forge-starter-main"


function getProjectNameInput() {
    while true; do
        read -p "Enter your project name (alphanumeric and dashes only): " projectName
        projectName=$(echo "$projectName" | tr -d ' ')

        if [ -z "$projectName" ]; then
            echo "Project name cannot be empty. Please try again."
        elif ! [[ "$projectName" =~ ^[a-zA-Z0-9-]+$ ]]; then
            echo "Invalid project name. Use alphanumeric characters and dashes only."
        elif [ -d "$projectName" ]; then
            echo "Directory '$projectName' already exists. Please choose a different name or delete the existing directory."
        else
            echo "$projectName"
            return 0
        fi
    done
}

function createProjectDirectory() {
    local projectDir="$1"
    mkdir -p "$projectDir"
    if [ $? -ne 0 ]; then
        echo "Error: Failed to create project directory '$projectDir'."
        return 1
    fi
    return 0
}

function downloadFile() {
    local url="$1"
    local destinationPath="$2"
    curl -sSL "$url" -o "$destinationPath"
    if [ $? -ne 0 ]; then
        echo "Error: Failed to download file from '$url' to '$destinationPath'."
        return 1
    fi
    return 0
}

function extractZip() {
    local zipFilePath="$1"
    local destinationPath="$2"
    unzip -qq "$zipFilePath" -d "$destinationPath"
    if [ $? -ne 0 ]; then
        echo "Error: Could not open or extract zip file: '$zipFilePath'."
        return 1
    fi
    return 0
}

function executeCommand() {
    local command="$1"
    local workingDir="$2"
    cd "$workingDir" || return 1
    echo "Running: $command in $(pwd)"
    eval "$command"
    CMD_RESULT=$?
    cd - >/dev/null || return 1
    if [ $CMD_RESULT -ne 0 ]; then
        echo "Error: Command '$command' failed with exit code: $CMD_RESULT"
        return 1
    fi
    return 0
}

function deleteProjectDirectory() {
    local projectDir="$1"
    if [ ! -d "$projectDir" ]; then
        return 0
    fi
    echo "Cleaning up project directory: $projectDir"
    rm -rf "$projectDir"
    if [ $? -ne 0 ]; then
        echo "Error: Failed to delete project directory '$projectDir'."
        return 1
    fi
    return 0
}

function deleteDirectory() {
    local dir="$1"
    if [ -d "$dir" ]; then
        rm -rf "$dir"
        if [ $? -ne 0 ]; then
            echo "Error: Failed to delete directory '$dir'."
            return 1
        fi
    fi
    return 0
}

function moveExtractedFiles() {
    local sourceDir="$1"
    local destinationDir="$2"
    if [ ! -d "$sourceDir" ]; then
        return 1
    fi
    shopt -s dotglob 
    mv "$sourceDir"/* "$destinationDir"/
    shopt -u dotglob 
    if [ $? -ne 0 ]; then
        echo "Error moving files from '$sourceDir' to '$destinationDir'."
        return 1
    fi
    return 0
}

# --- Main Scaffolding Function ---
function scaffoldNewProject() {
    echo "Welcome to Forge Engine Project Scaffolder! (Shell Script)\n"
    echo "---------------------------------------------------------\n\n"

    # 1. Get Project Name
    projectName=$(getProjectNameInput)
    if [ -z "$projectName" ]; then
        echo "\nProject scaffolding cancelled."
        return 1
    fi

    projectDir="./$projectName"

    if ! createProjectDirectory "$projectDir"; then
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    echo "📁 Project directory created: $projectName"

    starterZipUrl="$STARTER_REPO_BASE_URL"
    starterZipPath="$projectDir/$STARTER_ZIP_FILENAME"

    echo "📦 Downloading starter template..."
    if ! downloadFile "$starterZipUrl" "$starterZipPath"; then
        echo "Error: Failed to download starter template."
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    echo "Starter template downloaded."

    echo "Extracting starter template..."
    if ! extractZip "$starterZipPath" "$projectDir"; then
        echo "Error: Failed to extract starter template."
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    rm -f "$starterZipPath"

    extractedRootFolder="$projectDir/$GITHUB_ARCHIVE_ROOT_FOLDER"

    if [ -d "$extractedRootFolder" ]; then
        echo "📂 Extracted to: $extractedRootFolder"

        if ! moveExtractedFiles "$extractedRootFolder" "$projectDir"; then 
            echo "Error: Failed to move files from extracted starter template."
            deleteProjectDirectory "$projectDir"
            echo "\nProject scaffolding cancelled."
            return 1
        fi

        echo "Starter template extracted and files moved to project root."
        deleteDirectory "$extractedRootFolder"
    else
        echo "Error: Extracted folder not found: $extractedRootFolder"
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi

    echo "Removing .git directory from engine folder..."
    rm -rf "$projectDir/engine/.git"
    if [ $? -ne 0 ]; then
        echo "Warning: Failed to remove .git directory from engine folder. This is not critical, but it's recommended to remove it manually."
    else
        echo ".git directory removed from engine folder."
    fi

    echo "Running install.php..."
    if ! executeCommand "php install.php" "$projectDir"; then
        echo "Error: install.php script failed."
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    echo "install.php executed successfully."

    echo "Running php forge.php package:install-project..."
    if ! executeCommand "php forge.php package:install-project" "$projectDir"; then
        echo "Error: php forge.php package:install-project command failed."
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    echo "php forge.php package:install-project executed successfully."

    echo "Running php forge.php key:generate..."
    if ! executeCommand "php forge.php key:generate" "$projectDir"; then
        echo "Error: php forge.php key:generate command failed."
        deleteProjectDirectory "$projectDir"
        echo "\nProject scaffolding cancelled."
        return 1
    fi
    echo "php forge.php key:generate executed successfully."

#    echo "Running php forge.php db:migrate..."
#    if ! executeCommand "php forge.php db:migrate --type=all" "$projectDir"; then
#        echo "Error: php forge.php db:migrate  --type=all command failed."
#        deleteProjectDirectory "$projectDir"
#        echo "\nProject scaffolding cancelled."
#        return 1
#    fi
#    echo "php forge.php db:migrate  --type=all executed successfully."

    echo "\n--------------------------------------------\n"
    echo "Forge Engine project '$projectName' scaffolded successfully!"
    echo "Project directory: $projectDir"
    echo "Next steps:"
    echo "1.  cd $projectName"
    echo "2.  Start dev server:"
    echo "    php forge.php serve"
    echo "Happy coding!"

    return 0
}

if ! command -v curl &>/dev/null
then
    echo "Error: curl is not installed. Please install curl to continue."
    exit 1
fi

if ! command -v unzip &>/dev/null
then
    echo "Error: unzip is not installed. Please install unzip to continue."
    exit 1
fi

if ! command -v php &>/dev/null
then
    echo "Error: php is not installed. Please install php-cli to continue."
    exit 1
fi

scaffoldNewProject