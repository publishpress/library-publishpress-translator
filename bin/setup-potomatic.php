<?php
/**
 * Setup script for Potomatic CLI tool
 * This runs automatically when the package is installed via Composer
 */

$libDir = dirname(__DIR__);
$potomaticDir = $libDir . '/potomatic';
$nodeModulesDir = $potomaticDir . '/node_modules';

if (is_dir($nodeModulesDir)) {
    echo "✓ Potomatic already set up\n";
    return;
}

echo "Setting up Potomatic CLI tool...\n";

if (!is_dir($potomaticDir)) {
    echo "Error: Potomatic directory not found at {$potomaticDir}\n";
    exit(1);
}

exec('node --version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Error: Node.js is not installed. Please install Node.js 18+ to use Potomatic.\n";
    echo "Visit: https://nodejs.org/\n";
    exit(1);
}

$nodeVersion = trim($output[0] ?? '');
echo "Found {$nodeVersion}\n";

exec('npm --version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Error: npm is not installed. Please install npm to use Potomatic.\n";
    exit(1);
}

if (!file_exists($potomaticDir . '/package.json')) {
    echo "Error: package.json not found in {$potomaticDir}\n";
    exit(1);
}

echo "Installing Potomatic dependencies...\n";
chdir($potomaticDir);

$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$npmCommand = $isWindows ? 'npm.cmd' : 'npm';

passthru("{$npmCommand} install --production 2>&1", $returnCode);

if ($returnCode === 0) {
    echo "✓ Potomatic setup complete!\n";
} else {
    echo "Error: Failed to install Potomatic dependencies\n";
    exit(1);
}