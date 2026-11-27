# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

[Unreleased]

- Changed: Potomatic is installed with npm from [publishpress/library-potomatic](https://github.com/publishpress/library-potomatic) as npm `@publishpress/potomatic` (not the unrelated `potomatic` package on npmjs.com). Composer runs `npm install` at this package root. The bundled `potomatic/` directory is deprecated.

[1.8.2] - 20 Nov, 2026

- Added: `VERBOSE_LEVEL` sets the Potomatic log level. The default stays `2`. `0` is errors, `1` is normal, `2` is verbose, and `3` is debug.

[1.8.1] - 20 Nov, 2026

- Added: A translate run prints one OpenAI total at the end. The total is the Potomatic cost plus the placeholder-repair cost.

[1.8.0] - 19 Nov, 2026

- Changed: Bundled Potomatic is publishpress/library-potomatic 1.4.1. The executable is `potomatic`. `potomatic.js` is still accepted. The default model is `gpt-6-luna` with reasoning effort `low`. Set `REASONING_EFFORT` to override the effort. A run that leaves strings untranslated still repairs the written PO files, then exits with an error.
- Changed: Placeholder repair uses the same model and reasoning effort. The repair cost counter uses $0.10 per 1M input tokens and $0.50 per 1M output tokens.

[1.7.0] - 19 Nov, 2026

- Added: `--repair-placeholders` and the `format` audit check. A printf placeholder mismatch is sent to OpenAI. The reply is saved only when it is in the target language and its placeholders match the source. The repair stops at the same $5 OpenAI cap as Potomatic. Extra backslashes before quotes in translations are removed. A bad placeholder can fatal on PHP 8.

[1.6.3] - 18 Nov, 2026

- Fixed: A Weblate download stops when Cloudflare Access returns a login page. The login page is not saved as a PO file.
- Fixed: Potomatic `npm install` runs in the potomatic directory on PHP 8.3.

[1.6.2] - 9 Nov, 2026

- Added: Optional Cloudflare Access service token headers (`CF_ACCESS_CLIENT_ID`, `CF_ACCESS_CLIENT_SECRET`) so Weblate CLI sync still works when Access is in front of weblate.publishpress.com.

[1.6.1] - 14 Oct, 2026

- Improve: Translate more languages at once (8 parallel jobs, 40 strings per request) so a full run spends less time waiting on the API. Override with `AI_JOBS` (1–10) and `AI_BATCH_SIZE` (1–100).

[1.6.0] - 15 Sept, 2026

- Improve: Improve translations audition to catch "translated" strings that are the same as the original string, #132
- Added: Add new GitHub workflow for Dependabot triage, #134
- Added: Add parser-based audit detection for translations identical to their source, #143
- Update: Don't skip es_ES, fr_FR and it_IT, #142
- Fixed: Exclude en_US from Weblate translation sync, #141

[1.5.0] - 19 May, 2026

- Added: New `--verbose` flag to enable debug/verbose output. Details such as POT upload failures are now hidden by default and only shown when `--verbose` is passed.
- Fixed: `componentExists` now falls back to scanning the project's component list when the direct slug lookup returns 404. This prevents duplicate-creation errors when Weblate has a component in a soft-deleted or pending-removal state.

[1.4.0] - 14 May, 2026

- Added: New `upload-pot` command to upload only the POT file to Weblate's source language, without waiting for all languages to finish uploading (Issue #124).
- Fixed: POT synchronization with Weblate now uses the `replace` method for updates, ensuring the plugin's file is always the source of truth. This completely replaces the POT file on Weblate and automatically syncs it with other languages (Issue #123).
- Removed: POT upload capability for VCS-based projects, where the repository now remains the sole source of truth (Issue #123).

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
