<?php

/**
 * Setup script for Potomatic CLI tool
 * This runs automatically when the package is installed via Composer
 */

use PublishPress\Translations\Output;

$libDir = dirname(__DIR__);
$potomaticDir = $libDir . '/potomatic';
$nodeModulesDir = $potomaticDir . '/node_modules';

$output = new Output();

$output->separator();
$output->step('Verifying Potomatic setup...');
$output->blankLine();

if (is_dir($nodeModulesDir)) {
    $output->success("Potomatic already set up");
    return;
}

$output->step("Setting up Potomatic CLI tool...");

if (!is_dir($potomaticDir)) {
    $output->error("Potomatic directory not found at {$potomaticDir}");
    exit(2);
}

exec('node --version 2>&1', $commandOutput, $returnCode);
if ($returnCode !== 0) {
    $output->error("Node.js is not installed. Please install Node.js 18+ to use Potomatic.");
    $output->line("Visit: https://nodejs.org/");
    exit(3);
}

$nodeVersion = trim($commandOutput[0] ?? '');
$output->line("Found {$nodeVersion}");

exec('npm --version 2>&1', $commandOutput, $returnCode);
if ($returnCode !== 0) {
    $output->error("npm is not installed. Please install npm to use Potomatic.");
    exit(4);
}

if (!file_exists($potomaticDir . '/package.json')) {
    $output->error("package.json not found in {$potomaticDir}");
    exit(5);
}

$output->step("Installing Potomatic dependencies...");
chdir($potomaticDir);

$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$npmCommand = $isWindows ? 'npm.cmd' : 'npm';

$output->startBoxed();
$descriptorSpec = [
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
];
$process = proc_open("{$npmCommand} install --omit=dev --production", $descriptorSpec, $pipes);
if (is_resource($process)) {
    while ($line = fgets($pipes[1])) {
        $output->boxedLine(rtrim($line, "\r\n"));
    }
    while ($line = fgets($pipes[2])) {
        $output->boxedLine(rtrim($line, "\r\n"));
    }
}
foreach ($pipes as $pipe) {
    fclose($pipe);
}
$returnCode = proc_close($process);
$output->endBoxed();

if ($returnCode === 0) {
    $output->blankLine();
    $output->success("Potomatic setup complete!");
    $output->blankLine();

} else {
    $output->error("Failed to install Potomatic dependencies");
    exit(6);
}
