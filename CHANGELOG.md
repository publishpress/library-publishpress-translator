# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

[1.3.0] - 13 May, 2026

- Added: New `translation-count` audit check to count translatable strings in `.pot` and `.po` files and report coverage.
- Fixed: Human-translated languages were incorrectly being skipped during the audit process.

[1.2.0] - 13 May, 2026

- Added: New `source-i18n` audit check to compare PHP/JS/JSX i18n source strings against POT coverage.

[1.1.0] - 01 April, 2026

- Added: New `--audit` command and comprehensive options for translation auditing.
- Changed: Refactored script output to be more standardized and consistent with our dev-workspace.
- Changed: README now documents the translation audit CLI.

[1.0.5] - 16 April, 2026

Starting the changelog. For prior versions, check the releases on GitHub: https://github.com/publishpress/library-publishpress-translator/releases/tag/v1.0.5
