# AGENTS — library-publishpress-translator

AI guide for Cursor agents. **User-facing replies: caveman full** (`/caveman full`). **Commits, PRs, CHANGELOG: normal English**, Conventional Commits.

README = end-user docs. **This file wins on conflicts** for agent behavior.

## What this repo is

Composer package `publishpress/translations`. CLI `bin/publishpress-translate` → PHP orchestration + **Potomatic** (external Node dep, bundled) + optional **Weblate**.

- **PHP (edit here)**: `src/` (`PublishPress\Translations\`), PSR-4 autoload
- **Potomatic (do not edit)**: `potomatic/` — upstream bundle; PHP only shells out via `Translator::buildCommand()`
- **Config (edit here)**: `config/dictionaries.json` (merged into temp dict for Potomatic)
- **Dev**: Docker `./dev-workspace/run` (same checks as CI)

## Layout / touch map

| Change | Where |
|--------|--------|
| CLI flags, bootstrap | `bin/publishpress-translate` |
| Translate / Weblate / Potomatic shell | `src/Translator.php` (legacy god file — see practices) |
| Weblate HTTP | `src/WeblateClient.php` |
| Overrides env parsing | `src/Support/TranslationOverrides.php` |
| Audit orchestration | `src/Audit/Auditor.php` |
| One audit rule | `src/Audit/Checks/*.php` |
| Check IDs / labels | `src/Audit/CheckId.php` |
| Finding slugs | `src/Audit/IssueSlug.php` |
| Audit options (immutable) | `src/Audit/AuditOptions.php` |
| PO/git/AI helpers | `src/Audit/Support/*` |
| Report output | `src/Audit/Report/*` |
| CLI UX | `src/Output.php` |
| Potomatic CLI args / paths | `src/Translator.php` (`buildCommand`, `getPotomaticPath`) — **not** `potomatic/` |
| Brand dictionary | `config/dictionaries.json` |

## Practices we follow (keep doing)

Detailed rules: `.cursor/rules/practices.mdc` (always on). Summary:

**Architecture**

- **Strategy checks**: one class per audit concern, `AuditCheckInterface`, register in `Auditor`.
- **Stable IDs**: `CheckId` for `--audit-only`; `IssueSlug` for machine-readable findings.
- **Immutable options**: `AuditOptions::defaults()` + `with*()`; validate in `with*`, not at read time.
- **Shared domain logic**: e.g. `TranslationOverrides`, `PoFile` — Translator and audit must not diverge.
- **Factories**: `AuditReportRendererFactory` (PHP audit reports).
- **Context object**: pass `AuditContext` into checks; no global state.
- **Findings model**: `AuditFinding` with `reportSummary` + `reportDetailLines` for files; CLI `message` can be verbose.
- **Thin CLI**: `bin/` parses options → `Translator` / `Auditor`; heavy logic not in bin.

**Safety / ops**

- **Cost caps**: `--audit-max-cost`, Potomatic `--max-cost`; dry-run when no key.
- **CI / non-TTY**: interactive audit → forced `report` (no prompts).
- **Shell**: `escapeshellarg` on Potomatic args; never log API keys.
- **Env**: `.env` only fills unset vars; shell wins.

**Code style (PHP)**

- **PHP ≥ 7.2.5** (match `composer.json`). No 7.3+ syntax without guarded fallback.
- **PSR-12** + `PublishPressStandards` + `VariableAnalysis` (`.phpcs.xml`).
- **`final`** on audit/support value types; interfaces for extension points.
- **Early throw** `InvalidArgumentException` on bad CLI-derived config.
- **PHPDoc** `@package` on files; typed params where 7.2 allows.

**Potomatic**

- **Never edit `potomatic/`** — external dependency; integrate from PHP only.
- Dictionaries/overrides: PHP `config/dictionaries.json` + `TranslationOverrides` → temp files passed to CLI.

## Practices we aspire to (do not regress)

- **No god files**: `Translator.php` (~3k lines) is **known debt**. Do **not** add large features inline. Extract to `src/` classes (Weblate, subprocess runner, PO maintenance) and call from `Translator`.
- **Never patch Potomatic** — behavior gaps → PHP layer or upstream version bump.
- **One responsibility per new class**; mirror audit layout for new PHP subsystems.
- **Sync public surface**: new `CheckId` → `CheckId::all()`, `label()`, `Auditor` list, `bin/` `--help`, README audit table.
- **Run checks before done**: see Verify below. Prefer adding `check:stan` to CI when touching types.
- **No PHP copy-paste across checks** — lift to `Audit\Support`.
- **PHP tests** for audit checks (none yet) — add when behavior is non-trivial.
- **README accuracy**: e.g. `--audit` locale set = `targetLanguages` ∪ `skippedLanguages` in code (not “skipped excluded” unless code changes).

## Audit: add a check

1. `src/Audit/Checks/MyCheck.php` implements `AuditCheckInterface`.
2. `id()` → new `CheckId` const + `all()` + `label()`.
3. New `IssueSlug` consts if needed (not generic fallback).
4. Register in `Auditor.php` `$checks`.
5. Update `bin/publishpress-translate` `--help` if user-facing.
6. `./dev-workspace/run composer check:cs` (+ `check:stan` locally).

## Verify

```bash
./dev-workspace/run composer install
./dev-workspace/run composer check:lint
./dev-workspace/run composer check:cs
./dev-workspace/run composer check:php
./dev-workspace/run composer check:stan   # local; not in root CI yet
```

Do **not** run Potomatic lint/tests as part of normal agent work — `potomatic/` is not maintained in this repo.

## Drift watchlist

| Topic | Agent truth |
|-------|----------------|
| Audit locales | `audit()` passes merged target + skipped langs |
| Check `translation-count` | In `CheckId`; may be missing from README / CLI help |
| PHPStan | `composer check:stan` exists; root `.github/workflows/code-check.yml` omits it |
| Parent `publishpress/CLAUDE.md` | Plugin language-folder research — **not** this library |

## Communication

- Replies to user: **caveman full**.
- Code comments: plain English, only non-obvious logic.
- User docs edits: `README.md` / `CHANGELOG.md` — full sentences.
