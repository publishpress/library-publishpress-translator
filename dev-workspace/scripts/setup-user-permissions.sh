#!/usr/bin/env bash

# If not in the `dev-workspace` directory, change to it
if [[ ! $(pwd) =~ .*dev-workspace$ ]]; then
  cd dev-workspace
fi

set -a
source ../.env
set +a

echo "Setting up user permissions for $(whoami) (UID: $(id -u), GID: $(id -g))..."

# Set group write permissions on key directories
echo "Setting directory permissions..."
chmod g+w ../src/ ../dist/ 2>/dev/null || true

# Fix ownership of any files that might be owned by root
echo "Fixing file ownership..."
sudo chown -R $(id -u):$(id -g) ../src/include.php ../src/Versions.php 2>/dev/null || true
sudo chown -R $(id -u):$(id -g) ../vendor/squizlabs/php_codesniffer/CodeSniffer.conf 2>/dev/null || true

# Add group write permissions to PHPCS config
chmod g+w ../vendor/squizlabs/php_codesniffer/CodeSniffer.conf 2>/dev/null || true

# Build the terminal container with the current user's UID/GID
echo "Building terminal container..."
bash ./scripts/build-terminal.sh

echo "Setup completed! You can now run './run composer build' without permission issues."
