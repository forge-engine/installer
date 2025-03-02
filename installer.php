#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

const STARTER_REPO_BASE_URL = "https://github.com/forge-engine/forge-starter/archive/refs/heads/main.zip";
const STARTER_TEMPLATE_NAME = "forge-starter-main";
const STARTER_ZIP_FILENAME = "starter-template.zip";
const GITHUB_ARCHIVE_ROOT_FOLDER = "forge-starter-main";

function scaffoldNewProject(): void
{
    echo "Welcome to Forge Engine Project Scaffolder!\n";
    echo "-------------------------------------------\n\n";

    // 1. Get Project Name
    $projectName = getProjectNameInput();
    if (!$projectName) {
        echo "\nProject scaffolding cancelled.\n";
        return;
    }

    $projectDir = __DIR__ . "/" . $projectName;

    // 2. Create Project Directory
    if (!createProjectDirectory($projectDir)) {
        echo "\nProject scaffolding cancelled.\n";
        return;
    }

    echo "\nProject directory created: {$projectName}\n";

    // 3. Download Starter Template
    $starterZipUrl = STARTER_REPO_BASE_URL;
    $starterZipPath = $projectDir . "/" . STARTER_ZIP_FILENAME;

    echo "Downloading starter template...\n";
    if (!downloadFile($starterZipUrl, $starterZipPath)) {
        echo "Error: Failed to download starter template.\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }
    echo "Starter template downloaded.\n";

    // 4. Extract Starter Template
    echo "Extracting starter template...\n";
    echo "starterZip: $starterZipPath \n";
    echo "projectDir: $projectDir \n";
    if (!extractZip($starterZipPath, $projectDir)) {
        echo "Error: Failed to extract starter template.\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }

    unlink($starterZipPath);

    $extractedRootFolder = $projectDir . "/" . GITHUB_ARCHIVE_ROOT_FOLDER;
    $extractedTemplateFolder =
        $extractedRootFolder . "/" . STARTER_TEMPLATE_NAME;

    if (is_dir($extractedRootFolder)) {
        echo "Extracted to: $extractedRootFolder\n";

        if (!moveExtractedFiles($extractedRootFolder, $projectDir)) {
            echo "Error: Failed to move files from extracted starter template.\n";
            deleteProjectDirectory($projectDir);
            echo "\nProject scaffolding cancelled.\n";
            return;
        }
        echo "Starter template extracted and files moved to project root.\n";
        deleteDirectory($extractedRootFolder);
    } else {
        echo "Error: Extracted folder not found: {$extractedRootFolder}\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }

    echo "Running install.php...\n";
    if (!executeCommand("php install.php", $projectDir)) {
        echo "Error: install.php script failed.\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }
    echo "install.php executed successfully.\n";

    echo "Running php forge.php install:project...\n";
    if (!executeCommand("php forge.php install:project", $projectDir)) {
        echo "Error: php forge.php install:project command failed.\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }
    echo "php forge.php install:project executed successfully.\n";

    echo "Running php forge.php key:generate...\n";
    if (!executeCommand("php forge.php key:generate", $projectDir)) {
        echo "Error: php forge.php key:generate command failed.\n";
        deleteProjectDirectory($projectDir);
        echo "\nProject scaffolding cancelled.\n";
        return;
    }
    echo "php forge.php key:generate executed successfully.\n";

    echo "\n--------------------------------------------\n";
    echo "Forge Engine project '{$projectName}' scaffolded successfully!\n";
    echo "Project directory: {$projectDir}\n";
    echo "\nNext steps:\n";
    echo "1.  cd {$projectName}\n";
    echo "2.  Start developing your awesome Forge Engine application!\n";
    echo "--------------------------------------------\n";
}

function getProjectNameInput(): string|bool
{
    while (true) {
        echo "Enter your project name (alphanumeric and dashes only): ";
        $projectName = readline();
        $projectName = trim($projectName);

        if (empty($projectName)) {
            echo "Project name cannot be empty. Please try again.\n";
        } elseif (!preg_match('/^[a-zA-Z0-9-]+$/', $projectName)) {
            echo "Invalid project name. Use alphanumeric characters and dashes only.\n";
        } elseif (is_dir(__DIR__ . "/" . $projectName)) {
            echo "Directory '{$projectName}' already exists. Please choose a different name or delete the existing directory.\n";
        } else {
            return $projectName;
        }
    }
    return false;
}

function createProjectDirectory(string $projectDir): bool
{
    if (mkdir($projectDir, 0755, true)) {
        return true;
    } else {
        echo "Error: Failed to create project directory '{$projectDir}'.\n";
        return false;
    }
}

function downloadFile(string $url, string $destinationPath): bool
{
    $fileContent = @file_get_contents($url);
    if ($fileContent === false) {
        return false;
    }
    if (file_put_contents($destinationPath, $fileContent) !== false) {
        return true;
    }
    return false;
}

function extractZip(string $zipFilePath, string $destinationPath): bool
{
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath) === true) {
        $zip->extractTo($destinationPath);
        $zip->close();
        return true;
    } else {
        echo "Error: Could not open zip file: {$zipFilePath}\n";
        return false;
    }
}

function executeCommand(string $command, string $workingDir): bool
{
    $command = "cd " . escapeshellarg($workingDir) . " && " . $command;
    $process = proc_open(
        $command,
        [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ],
        $pipes
    );

    if (is_resource($process)) {
        fclose($pipes[0]); // Close stdin

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        echo "Command Output:\n";
        echo $stdout;
        if (!empty($stderr)) {
            echo "\nCommand Error Output:\n";
            echo $stderr;
        }

        if ($returnCode !== 0) {
            echo "Command failed with exit code: {$returnCode}\n";
            return false;
        } else {
            return true;
        }
    } else {
        echo "Error: Failed to execute command: {$command}\n";
        return false;
    }
}

function deleteProjectDirectory(string $projectDir): bool
{
    if (!is_dir($projectDir)) {
        return true;
    }
    echo "Cleaning up project directory: {$projectDir}\n";
    return deleteDirectory($projectDir);
}

function deleteDirectory(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $files = array_diff(scandir($dir), [".", ".."]);
    foreach ($files as $file) {
        is_dir("$dir/$file")
            ? deleteDirectory("$dir/$file")
            : unlink("$dir/$file");
    }
    return rmdir($dir);
}

function moveExtractedFiles(string $sourceDir, string $destinationDir): bool
{
    if (!is_dir($sourceDir)) {
        return false;
    }
    $items = scandir($sourceDir);
    foreach ($items as $item) {
        if ($item !== "." && $item !== "..") {
            if (
                !rename($sourceDir . "/" . $item, $destinationDir . "/" . $item)
            ) {
                echo "Error moving item: " . $item . "\n";
                return false;
            }
        }
    }
    return true;
}

// Run the scaffolder
scaffoldNewProject();

