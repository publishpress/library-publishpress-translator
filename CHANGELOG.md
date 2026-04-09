# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

[UNRELEASED]

### Changed
- Switched PO processing from text regex to AST-based handling (`gettext/gettext`).
- Updated upload, download, and translation cleanup to use `PoCatalogProcessor`.
- Removed `.mo` generation from this library (PO-only workflow).

### Added
- Added PHPUnit fixture tests for plural/header normalization and PO cleanup behavior.
