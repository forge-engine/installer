<?php

define("BASE_PATH", __DIR__);
const STARTER_REGISTRY_URL = 'https://github.com/forge-engine/starter-registry';
const STARTER_REGISTRY_BRANCH = 'main';
const STARTER_REGISTRY_MANIFEST_PATH = 'starters.json';
const MODULE_REGISTRY_URL = 'https://github.com/forge-engine/modules';
const MODULE_REGISTRY_BRANCH = 'main';
const MODULE_REGISTRY_MANIFEST_PATH = 'modules.json';

$options = parseArgv($argv);

if ($options['help']) {
    displayHelp();
    exit(0);
}

if ($options['list']) {
    $registry = getStarterRegistry();
    if ($registry === null) {
        echo "Error: Failed to fetch starter registry.\n";
        exit(1);
    }
    displayStarterList($registry);
    exit(0);
}

if ($options['starter'] !== null) {
    $registry = getStarterRegistry();
    if ($registry === null) {
        echo "Error: Failed to fetch starter registry.\n";
        exit(1);
    }

    $starter = selectStarter($registry, $options['starter']);
    if ($starter === null) {
        echo "Error: Starter '{$options['starter']}' not found.\n";
        echo "Run with --list to see available starters.\n";
        exit(1);
    }
} else {
    $registry = getStarterRegistry();
    if ($registry === null) {
        echo "Error: Failed to fetch starter registry.\n";
        exit(1);
    }

    $starter = selectStarterInteractive($registry);
    if ($starter === null) {
        echo "\nProject scaffolding cancelled.\n";
        exit(0);
    }
}

// Prompt for project path if not specified
if ($options['path'] === '.' && !$options['yes']) {
    echo "\nProject path:\n";
    echo "  <name>      Create a new directory with that name\n";
    echo "  <path>      Use the exact path (absolute or relative)\n";
    echo "  .           Use the current directory\n";
    $input = prompt("Enter project name or path: ");
    if ($input === '' || $input === null) {
        echo "\nProject scaffolding cancelled.\n";
        exit(0);
    }
    $options['path'] = $input;
}

$projectPath = resolveProjectPath($options['path']);

if ($projectPath === null) {
    echo "\nProject scaffolding cancelled.\n";
    exit(0);
}

$finalStarterKey = $starter['key'];
$starterName = $starter['name'];
$starterData = $starter['data'];
$starterVersion = $starter['version'];
$versionData = $starter['versionData'];

echo "\n";
echo "── Scaffolding: {$starterName} (v{$starterVersion}) ──────────────────\n";
echo "  Project path: {$projectPath}\n\n";

if (!$options['yes']) {
    echo "This will create/overwrite files in: {$projectPath}\n";
    $confirm = prompt("Continue? (Y/n): ");
    if ($confirm !== '' && strtolower($confirm) !== 'y' && strtolower($confirm) !== 'yes') {
        echo "\nProject scaffolding cancelled.\n";
        exit(0);
    }
    echo "\n";
}

if (!is_dir($projectPath)) {
    if (!mkdir($projectPath, 0755, true)) {
        echo "Error: Failed to create project directory.\n";
        exit(1);
    }
    echo "✓ Project directory created\n";
} else {
    echo "✓ Using existing directory\n";
}

// Download starter template
echo "\nDownloading starter template...\n";
$downloadPath = 'starters/' . $versionData['url'] . '/' . $starterVersion . '.zip';
$zipUrl = generateRawGithubUrl(STARTER_REGISTRY_URL, STARTER_REGISTRY_BRANCH, $downloadPath);

$zipPath = $projectPath . '/starter-template.zip';
if (!downloadFile($zipUrl, $zipPath)) {
    echo "Error: Failed to download starter template.\n";
    echo "URL: {$zipUrl}\n";
    echo "Check that the version exists in the starter registry.\n";
    exit(1);
}
echo "✓ Template downloaded\n";

if (isset($versionData['integrity'])) {
    echo "Verifying integrity...\n";
    if (!verifyFileIntegrity($zipPath, $versionData['integrity'])) {
        echo "Error: Integrity check failed! Downloaded file may be corrupted or tampered.\n";
        exit(1);
    }
    echo "✓ Integrity verified\n";
}

echo "Extracting starter template...\n";
if (!extractZip($zipPath, $projectPath)) {
    echo "Error: Failed to extract starter template.\n";
    exit(1);
}
unlink($zipPath);
echo "✓ Template extracted\n";

// Remove .git directory from engine folder if present
$engineGitDir = $projectPath . '/engine/.git';
if (is_dir($engineGitDir)) {
    recursiveDeleteDirectory($engineGitDir);
}

// Install engine
echo "\nInstalling engine...\n";
$engineVersion = $options['engine'] ?? $versionData['engine'] ?? null;

if (is_dir($projectPath . '/engine')) {
    echo "Removing existing engine folder...\n";
    recursiveDeleteDirectory($projectPath . '/engine');
}

if ($engineVersion !== null && $engineVersion !== 'latest') {
    $exitCode = runCommand('php install.php --version=' . escapeshellarg($engineVersion), $projectPath);
} else {
    $exitCode = runCommand('php install.php', $projectPath);
}
if ($exitCode !== 0) {
    echo "Error: Engine installation failed.\n";
    exit(1);
}
echo "✓ Engine installed\n";

// Check if forge.php exists before trying to run forge commands
$forgePhpPath = $projectPath . '/forge.php';
if (!file_exists($forgePhpPath)) {
    echo "Error: forge.php not found after engine installation.\n";
    exit(1);
}

// Install modules
echo "\nInstalling modules...\n";

// ForgePackageManager must be installed first — it provides the package management commands
$pmResult = downloadForgePackageManager($projectPath);
if ($pmResult === -1) {
    echo "Warning: Failed to download ForgePackageManager. Module installation may be incomplete.\n";
} else {
    echo "✓ ForgePackageManager installed\n";

    // Now run package:install-project which reads forge-lock.json or forge.json
    $exitCode = runCommand('php forge.php package:install-project', $projectPath);
    if ($exitCode !== 0) {
        echo "  Note: package:install-project completed with warnings.\n";
    } else {
        echo "✓ Modules installed\n";
    }
}

// Generate app key
echo "\nGenerating app key...\n";
$exitCode = runCommand('php forge.php key:generate', $projectPath);
if ($exitCode !== 0) {
    echo "Warning: App key generation failed. You can run it manually with: php forge.php key:generate\n";
} else {
    echo "✓ App key generated\n";
}

// Run post-install commands
if (!empty($versionData['post_install'])) {
    echo "\nRunning post-install commands...\n";
    foreach ($versionData['post_install'] as $command) {
        $exitCode = runCommand($command, $projectPath);
        if ($exitCode !== 0) {
            echo "Warning: Post-install command failed: {$command}\n";
        }
    }
    echo "✓ Post-install commands completed\n";
}

// Cache Flush
echo "\nFlushing cache...\n";
$exitCode = runCommand('php forge.php cache:flush', $projectPath);
if ($exitCode !== 0) {
    echo "Warning: Cache flush failed. You can run it manually with: php forge.php cache:flush\n";
} else {
    echo "✓ Cache flushed\n";
}

// Cache Flush
echo "\Warming cache...\n";
$exitCode = runCommand('php forge.php cache:warm', $projectPath);
if ($exitCode !== 0) {
    echo "Warning: Cache warm failed. You can run it manually with: php forge.php cache:warm\n";
} else {
    echo "✓ Cache warmed\n";
}

// Success message
echo "\n";
echo "── Project Ready! ──────────────────────────────────────\n";
echo "  Starter: {$starterName} v{$starterVersion}\n";
echo "  Location: {$projectPath}\n";
echo "\n";
echo "  Next steps:\n";

$relativePath = getRelativePath($projectPath);
echo "    cd " . ($relativePath ?: '.') . "\n";
echo "    php forge.php serve\n";
echo "\n";
echo "  Happy coding!\n";

// ─── Argument Parsing ──────────────────────────────────────

function parseArgv(array $argv): array
{
    $options = [
        'help' => false,
        'list' => false,
        'starter' => null,
        'engine' => null,
        'yes' => false,
        'path' => '.',
    ];

    $positionalArgs = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--list' || $arg === '-l') {
            $options['list'] = true;
        } elseif ($arg === '--yes' || $arg === '-y') {
            $options['yes'] = true;
        } elseif (str_starts_with($arg, '--starter=')) {
            $options['starter'] = substr($arg, strlen('--starter='));
        } elseif ($arg === '--starter') {
            if (isset($argv[$i + 1])) {
                $i++;
                $options['starter'] = $argv[$i];
            } else {
                echo "Error: --starter requires a value.\n";
                displayHelp();
                exit(1);
            }
        } elseif (str_starts_with($arg, '--engine=')) {
            $options['engine'] = substr($arg, strlen('--engine='));
        } elseif ($arg === '--engine') {
            if (isset($argv[$i + 1])) {
                $i++;
                $options['engine'] = $argv[$i];
            } else {
                echo "Error: --engine requires a value.\n";
                displayHelp();
                exit(1);
            }
        } else {
            $positionalArgs[] = $arg;
        }
    }

    if (!empty($positionalArgs)) {
        $options['path'] = $positionalArgs[0];
    }

    return $options;
}

// ─── Path Resolution ──────────────────────────────────────

function resolveProjectPath(string $input): ?string
{
    if ($input === '.') {
        return getcwd();
    }

    if (str_starts_with($input, '/')) {
        $path = $input;
    } else {
        $path = getcwd() . '/' . $input;
    }

    // Check if it's a name (alphanumeric + dashes) or a full path
    $basename = basename($path);
    if (!str_contains($input, '/') && $input !== '.' && !str_starts_with($input, '/')) {
        // It's a project name
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $basename)) {
            echo "Error: Invalid project name '{$input}'. Use alphanumeric characters and dashes only.\n";
            return null;
        }
    }

    return $path;
}

function getRelativePath(string $path): string
{
    $cwd = getcwd();
    if (str_starts_with($path, $cwd)) {
        $relative = substr($path, strlen($cwd) + 1);
        return $relative;
    }
    return $path;
}

// ─── Starter Registry ──────────────────────────────────────

function getStarterRegistry(): ?array
{
    echo "Fetching available starters...\n";
    $manifestUrl = generateRawGithubUrl(STARTER_REGISTRY_URL, STARTER_REGISTRY_BRANCH, STARTER_REGISTRY_MANIFEST_PATH);

    $json = @file_get_contents($manifestUrl);
    if ($json === false) {
        echo "Error: Could not fetch starter registry from:\n  {$manifestUrl}\n";
        echo "Check your internet connection.\n";
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['starters'])) {
        echo "Error: Invalid starter registry format.\n";
        return null;
    }

    echo "✓ Found " . count($data['starters']) . " starter(s)\n";
    return $data['starters'];
}

function selectStarter(array $registry, string $preferredName): ?array
{
    $preferredName = strtolower(trim($preferredName));

    foreach ($registry as $key => $starter) {
        if (strtolower($key) === $preferredName) {
            return buildStarterResult($key, $starter);
        }
    }

    return null;
}

function selectStarterInteractive(array $registry): ?array
{
    $items = [];
    $default = null;
    $i = 1;

    foreach ($registry as $key => $starter) {
        $label = $starter['name'] ?? $key;
        $description = isset($starter['description']) ? ' - ' . $starter['description'] : '';
        $items[] = [
            'key' => $key,
            'label' => "{$i}) {$label}{$description}",
            'starter' => $starter,
        ];
        if ($default === null) {
            $default = $i;
        }
        $i++;
    }

    echo "\n";
    echo "Available starters:\n";
    foreach ($items as $item) {
        echo "  " . $item['label'] . "\n";
    }
    echo "\n";

    $input = prompt("Select a starter (1-" . count($items) . ", default: {$default}): ");

    if ($input === '' || $input === null) {
        $selection = $default;
    } elseif (ctype_digit($input)) {
        $selection = (int) $input;
    } else {
        // Try to match by name
        $inputLower = strtolower(trim($input));
        foreach ($items as $idx => $item) {
            if (strtolower($item['key']) === $inputLower) {
                return buildStarterResult($item['key'], $item['starter']);
            }
        }
        echo "Invalid selection. Using default ({$default}).\n";
        $selection = $default;
    }

    if ($selection < 1 || $selection > count($items)) {
        echo "Invalid selection. Using default ({$default}).\n";
        $selection = $default;
    }

    $selected = $items[$selection - 1];
    return buildStarterResult($selected['key'], $selected['starter']);
}

function buildStarterResult(string $key, array $starter): ?array
{
    $latest = $starter['latest'] ?? null;
    if ($latest === null || !isset($starter['versions'][$latest])) {
        echo "Error: Starter '{$key}' has no valid version.\n";
        return null;
    }

    return [
        'key' => $key,
        'name' => $starter['name'] ?? $key,
        'data' => $starter,
        'version' => $latest,
        'versionData' => $starter['versions'][$latest],
    ];
}

function displayStarterList(array $registry): void
{
    echo "\nAvailable starters:\n\n";
    foreach ($registry as $key => $starter) {
        $name = $starter['name'] ?? $key;
        $description = $starter['description'] ?? '';
        $latest = $starter['latest'] ?? '?';

        echo "  {$key}";
        if ($name !== $key) {
            echo " ({$name})";
        }
        echo " v{$latest}\n";

        if ($description) {
            echo "    {$description}\n";
        }

        $modules = [];
        $versionData = $starter['versions'][$starter['latest']] ?? null;
        if ($versionData && isset($versionData['modules'])) {
            $modules = array_keys($versionData['modules']);
        }
        if (!empty($modules)) {
            echo "    Modules: " . implode(', ', $modules) . "\n";
        }
        echo "\n";
    }
}

// ─── I/O ──────────────────────────────────────────────────

function prompt(string $message): string
{
    echo $message;
    fflush(STDOUT);
    $input = fgets(STDIN);
    if ($input === false) {
        return '';
    }
    return trim($input);
}

// ─── Helpers (from install.php) ───────────────────────────

function generateRawGithubUrl(string $repoUrl, string $branch, string $filePathInRepo): string
{
    $repoBaseRawUrl = rtrim(str_replace('github.com', 'raw.githubusercontent.com', $repoUrl), '/');
    return $repoBaseRawUrl . '/' . $branch . '/' . $filePathInRepo;
}

function downloadFile(string $url, string $destinationPath): string|bool
{
    $fileContent = @file_get_contents($url);
    if ($fileContent === false) {
        return false;
    }
    if (file_put_contents($destinationPath, $fileContent) !== false) {
        return $destinationPath;
    }
    return false;
}

function verifyFileIntegrity(string $filePath, string $expectedHash): bool
{
    if (!file_exists($filePath)) {
        return false;
    }
    $calculatedHash = hash_file('sha256', $filePath);
    return $calculatedHash === $expectedHash;
}

function extractZip(string $zipPath, string $destinationPath): bool
{
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) === true) {
        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
        $zip->extractTo($destinationPath);
        $zip->close();
        return true;
    }
    return false;
}

function recursiveDeleteDirectory(string $dirPath): bool
{
    if (!is_dir($dirPath)) {
        return false;
    }

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        if ($fileinfo->isDir()) {
            if (!rmdir($fileinfo->getRealPath())) {
                return false;
            }
        } else {
            if (!unlink($fileinfo->getRealPath())) {
                return false;
            }
        }
    }
    return rmdir($dirPath);
}

function runCommand(string $command, string $workingDir): int
{
    $currentDir = getcwd();
    if (!chdir($workingDir)) {
        echo "Error: Could not change to directory: {$workingDir}\n";
        return -1;
    }
    echo "\n> {$command}\n";
    passthru($command, $exitCode);
    chdir($currentDir);
    return $exitCode;
}

// ─── Module Installation ─────────────────────────────────

function installModulesFromLock(string $projectPath): int
{
    $lockPath = $projectPath . '/forge-lock.json';
    if (!file_exists($lockPath)) {
        echo "  No forge-lock.json found, skipping module installation.\n";
        return 0;
    }

    $lockData = json_decode(file_get_contents($lockPath), true);
    if (!is_array($lockData) || !isset($lockData['modules']) || empty($lockData['modules'])) {
        echo "  No modules defined in forge-lock.json.\n";
        return 0;
    }

    $modulesDir = $projectPath . '/modules';
    if (!is_dir($modulesDir)) {
        if (!mkdir($modulesDir, 0755, true)) {
            echo "  Error: Failed to create modules directory.\n";
            return -1;
        }
    }

    $installedCount = 0;
    $hadErrors = false;

    foreach ($lockData['modules'] as $moduleName => $moduleInfo) {
        echo "  Installing module: {$moduleName}...\n";

        $version = $moduleInfo['version'] ?? null;
        $modulePath = $moduleInfo['module_path'] ?? null;
        $expectedHash = $moduleInfo['integrity'] ?? null;
        $sourceUrl = $moduleInfo['source_config']['url'] ?? null;
        $sourceBranch = $moduleInfo['source_config']['branch'] ?? 'main';

        if ($version === null || $modulePath === null || $sourceUrl === null) {
            echo "    Skipping {$moduleName}: missing version, module_path, or source_config.url\n";
            $hadErrors = true;
            continue;
        }

        // Check if module already installed
        $moduleDir = $modulesDir . '/' . $moduleName;
        if (is_dir($moduleDir)) {
            echo "    Already installed, skipping.\n";
            continue;
        }

        // Download module ZIP
        $downloadPath = $modulePath . '/' . $version . '.zip';
        $zipUrl = generateRawGithubUrl($sourceUrl, $sourceBranch, $downloadPath);
        $zipPath = $modulesDir . '/' . $moduleName . '.zip';

        if (!downloadFile($zipUrl, $zipPath)) {
            echo "    Failed to download {$moduleName} from:\n      {$zipUrl}\n";
            $hadErrors = true;
            continue;
        }

        // Verify integrity
        if ($expectedHash !== null) {
            if (!verifyFileIntegrity($zipPath, $expectedHash)) {
                echo "    Integrity check failed for {$moduleName}! Deleting corrupted file.\n";
                unlink($zipPath);
                $hadErrors = true;
                continue;
            }
        }

        // Extract
        if (!extractZip($zipPath, $moduleDir)) {
            echo "    Failed to extract {$moduleName}.\n";
            unlink($zipPath);
            $hadErrors = true;
            continue;
        }
        unlink($zipPath);
        echo "    ✓ {$moduleName} v{$version} installed\n";
        $installedCount++;
    }

    if ($hadErrors) {
        return -1;
    }

    return $installedCount > 0 ? 1 : 0;
}

function downloadForgePackageManager(string $projectPath): int
{
    $forgeJsonPath = $projectPath . '/forge.json';
    if (!file_exists($forgeJsonPath)) {
        echo "  forge.json not found, cannot determine ForgePackageManager version.\n";
        return -1;
    }

    $forgeData = json_decode(file_get_contents($forgeJsonPath), true);
    $constraint = $forgeData['modules']['forge-package-manager'] ?? null;

    // Fetch modules registry manifest
    $manifestUrl = generateRawGithubUrl(MODULE_REGISTRY_URL, MODULE_REGISTRY_BRANCH, MODULE_REGISTRY_MANIFEST_PATH);
    $manifestJson = @file_get_contents($manifestUrl);
    if ($manifestJson === false) {
        echo "  Failed to fetch module registry from:\n    {$manifestUrl}\n";
        return -1;
    }

    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || !isset($manifest['forge-package-manager'])) {
        echo "  forge-package-manager not found in module registry.\n";
        return -1;
    }

    $entry = $manifest['forge-package-manager'];
    $latest = $entry['latest'] ?? null;
    $version = $constraint;
    if ($version === null || $version === '*' || $version === 'latest') {
        $version = $latest;
    }
    if ($version === null) {
        echo "  Could not resolve version for forge-package-manager.\n";
        return -1;
    }

    $versionInfo = $entry['versions'][$version] ?? null;
    if ($versionInfo === null) {
        echo "  forge-package-manager version {$version} not found in registry.\n";
        return -1;
    }

    echo "  Downloading forge-package-manager (v{$version}) from registry...\n";

    $modulesDir = $projectPath . '/modules';
    if (!is_dir($modulesDir)) {
        if (!mkdir($modulesDir, 0755, true)) {
            echo "  Error: Failed to create modules/ directory.\n";
            return -1;
        }
    }

    $downloadPath = 'modules/' . $versionInfo['url'] . '/' . $version . '.zip';
    $zipUrl = generateRawGithubUrl(MODULE_REGISTRY_URL, MODULE_REGISTRY_BRANCH, $downloadPath);
    $zipPath = $modulesDir . '/ForgePackageManager.zip';

    if (!downloadFile($zipUrl, $zipPath)) {
        echo "    Failed to download forge-package-manager from:\n      {$zipUrl}\n";
        return -1;
    }

    $expectedHash = $versionInfo['integrity'] ?? null;
    if ($expectedHash !== null) {
        if (!verifyFileIntegrity($zipPath, $expectedHash)) {
            echo "    Integrity check failed! Deleting corrupted file.\n";
            unlink($zipPath);
            return -1;
        }
    }

    $moduleDir = $modulesDir . '/ForgePackageManager';
    if (!extractZip($zipPath, $moduleDir)) {
        echo "    Failed to extract forge-package-manager.\n";
        unlink($zipPath);
        return -1;
    }
    unlink($zipPath);

    echo "    ✓ forge-package-manager v{$version} installed\n";
    return 1;
}

function displayHelp(): void
{
    echo "Forge Project Scaffolder (create-project.php)\n\n";
    echo "Usage: php create-project.php [options] [path]\n\n";
    echo "Arguments:\n";
    echo "  .                        Scaffold in the current directory\n";
    echo "  <name>                   Create a new directory with the given name\n";
    echo "  <path>                   Use the exact path (absolute or relative)\n\n";
    echo "Options:\n";
    echo "  --starter=<name>         Use the specified starter kit (skip interactive picker)\n";
    echo "  --engine=<version>       Specify the engine version to install (e.g., 5.0.2)\n";
    echo "  --yes, -y                Auto-confirm all prompts (for CI/automation)\n";
    echo "  --list, -l               List available starter kits and exit\n";
    echo "  --help, -h               Display this help message\n\n";
    echo "Examples:\n";
    echo "  php create-project.php                     # Interactive mode\n";
    echo "  php create-project.php .                   # Scaffold in current directory\n";
    echo "  php create-project.php my-app              # Create ./my-app/\n";
    echo "  php create-project.php my-app --starter=blank --yes\n";
    echo "  php create-project.php --list\n";
}
