#!/usr/bin/env bash

echo "=============================================="
echo "Development Tools Version Information"
echo "=============================================="

echo ""
echo "PHP Version:"
php --version | head -n 1

echo ""
echo "Composer Version:"
composer --version

echo ""
echo "PHPUnit Version:"
vendor/bin/phpunit --version 2>/dev/null || echo "PHPUnit: Not found"

echo ""
echo "PHPCS Version:"
vendor/bin/phpcs --version 2>/dev/null || echo "PHPCS: Not found"

echo ""
echo "PHPStan Version:"
vendor/bin/phpstan --version 2>/dev/null || echo "PHPStan: Not found"

echo ""
echo "PHPLint Version:"
vendor/bin/phplint --version 2>/dev/null || echo "PHPLint: Not found"

echo ""
echo "Node.js Version:"
node --version 2>/dev/null || echo "Node.js: Not found"

echo ""
echo "NPM Version:"
npm --version 2>/dev/null || echo "NPM: Not found"

echo ""
echo "=============================================="
