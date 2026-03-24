# PublishPress Translations

AI-powered translation automation for PublishPress plugins using Potomatic, OpenAI, and Weblate.

## Features

- **AI-powered translations** using OpenAI GPT models
- **Weblate integration** for translation management and human review
- **Automatic upload/download** to/from Weblate
- **Merges with existing translations** (preserves manual edits)
- **Cost-effective** (~$0.03 per language for 1,744 strings)
- **Supports 10+ languages** by default
- **Dry-run mode** for cost estimation
- **Automatic detection** of `.pot` files

## Requirements

- PHP 7.2.5 or higher
- PHP extensions: `json`, `zip` (usually enabled by default)
- Node.js 18+ and npm (for Potomatic CLI tool)
- OpenAI API key ([Get one here](https://platform.openai.com/api-keys))
- Weblate account and API token ([Sign up here](https://hosted.weblate.org/))
- Plugin must have a `languages/` directory containing one or more `.pot` files

## Installation

**Note:** This setup works the same whether you're working from the plugin root or inside dev-workspace.

**Recommended setup:**

### Step 1: Add to root `composer.json`

```json
{
    "require-dev": {
        "publishpress/translations": "^1.0.0"
    },
    "scripts": {
        "translate": "vendor/bin/publishpress-translate",
        "translate:dry-run": "vendor/bin/publishpress-translate --dry-run",
        "translate:download": "vendor/bin/publishpress-translate --download",
        "translate:upload": "vendor/bin/publishpress-translate --upload",
        "translate:custom": "vendor/bin/publishpress-translate --languages",
        "translate:force": "vendor/bin/publishpress-translate --force",
        "translate:force-custom": "vendor/bin/publishpress-translate --force --languages",
        "translate:repair-plurals": "php vendor/bin/publishpress-translate --repair-plurals",
    }
}
```

### Step 2: Install

```bash
composer update
```

## Usage

### Set Environment Variables

Before using the translation tools, set your API keys as environment variables:
Create a `.env` file in your plugin root with your API keys:

```
OPENAI_API_KEY=sk-proj-your-openai-key
WEBLATE_API_TOKEN=wlu_your-weblate-token
```

The `.env` file is automatically loaded when you run the translation tool. No additional configuration needed.

Values can be quoted or unquoted:
```
OPENAI_API_KEY="sk-proj-your-openai-key"
WEBLATE_API_TOKEN='wlu_your-weblate-token'
```

Alternatively, you can set environment variables directly in your shell:

**Windows (PowerShell):**
```powershell
$env:OPENAI_API_KEY="sk-proj-your-openai-key"
$env:WEBLATE_API_TOKEN="wlu_your-weblate-token"
```

**Windows (CMD):**
```cmd
set OPENAI_API_KEY=sk-proj-your-openai-key
set WEBLATE_API_TOKEN=wlu_your-weblate-token
```

**Mac/Linux:**
```bash
export OPENAI_API_KEY=sk-proj-your-openai-key
export WEBLATE_API_TOKEN=wlu_your-weblate-token
```

Or create a `.env` file in your plugin root (don't commit this!):
```
OPENAI_API_KEY=sk-proj-your-openai-key
WEBLATE_API_TOKEN=wlu_your-weblate-token
```
> **Note:** Shell environment variables take precedence over `.env` file values.

**Get your Weblate API token:**
1. Sign up at [weblate.publishpress.com](https://weblate.publishpress.com/)
2. Go to your profile: https://weblate.publishpress.com/accounts/profile/#api
3. Copy your personal API key

### Additional configuration

The following environment variables control advanced behaviour:

- **`OPENAI_API_KEY`** (required for live translation)
  Used to call the OpenAI API.
  If it is **missing**:
  - In **dry run** mode, the tool prints a warning but continues so you can verify the workflow without incurring cost.
  - In **live** mode, the tool prints a clear warning and exits before making any API calls.

- **`WEBLATE_API_TOKEN`** (optional for AI generation, required for Weblate sync)
  If not set, Weblate integration is disabled:
  - You can still generate local translations.
  - Upload/download with Weblate will be skipped and a warning will be printed.

- **`WEBLATE_API_URL`** (optional, default: `https://hosted.weblate.org/api/`)
  Set this to your Weblate base URL (ending in `/api/`) when using a self-hosted instance.

- **`WEBLATE_SKIP_VCS`** (optional, default: `true`)
  Skip all VCS (repository) operations when interacting with Weblate.
  By default, VCS is skipped to avoid requiring repository configuration. Set to `false` or `0` to enable VCS operations if your project has a configured repository URL in Weblate.

- **`WEBLATE_API_TIMEOUT`** (optional, default: `120` seconds)
  HTTP timeout used for Weblate API requests.
  For large projects or slow connections this may be too short. You can increase it, for example:

  ```bash
  export WEBLATE_API_TIMEOUT=300
  ```

- **`WEBLATE_UPLOAD_DELAY`** (optional, default: `2` seconds)
  Delay between uploading translation files to Weblate.
  Useful to avoid rate limiting or server overload when uploading many languages.

  ```bash
  export WEBLATE_UPLOAD_DELAY=5
  ```

- **`WEBLATE_PROJECT_SLUG`** (optional)
  Override the Weblate project slug. By default, uses the plugin slug from `composer.json`.

- **`WEBLATE_COMPONENT_SLUG`** (optional)
  Override the Weblate component slug. By default, uses the text domain from the `.pot` file.

- **`WEBLATE_GIT_BRANCH`** (optional, default: `development`)
  Specify which Git branch Weblate should use for the component.

- **`WEBLATE_REPO_TYPE`** (optional, default: `https`)
  Repository access type: `https` or `ssh`. Use `ssh` if you have SSH keys configured in Weblate.

- **`WEBLATE_REPO_URL`** (optional)
  Override the repository URL for Weblate. Useful for private repositories or custom Git hosting.
  Examples:
  - HTTPS with credentials: `https://username:token@github.com/owner/repo.git`
  - SSH: `git@github.com:owner/repo.git`

- **`WEBLATE_PUSH_URL`** (optional)
  Override the push URL separately from the repository URL. Only needed if push and pull URLs differ.

- **`WEBLATE_PREFER_BASE_LANGUAGE`** (optional, default: `false`)
  When downloading from Weblate, prefer base language codes (e.g., `de` over `de_DE`) when duplicate locale variants exist.
  Set to `true` or `1` to enable.

  ```bash
  export WEBLATE_PREFER_BASE_LANGUAGE=true
  ```

- **`WEBLATE_CLEAN_EXISTING_TRANSLATIONS`** (optional, default: `false`)
  Delete all existing `.po` files before downloading from Weblate.
  Useful for a clean slate when syncing translations.
  Set to `true` or `1` to enable.

  ```bash
  export WEBLATE_CLEAN_EXISTING_TRANSLATIONS=true
  ```

- **`SKIP_LANGUAGES`** (optional, default: `it_IT,es_ES,fr_FR,pt_BR`)
  Comma-separated list of language codes to skip during translation and upload (downloads are still allowed).
  These languages are typically handled by human translators on Weblate.
  The default skipped languages are merged with any custom ones you specify.

  ```bash
  export SKIP_LANGUAGES=it_IT,es_ES,fr_FR,pt_BR
  ```

### Complete Translation Workflow

#### 1. Run Translation (Full Cycle)

**From dev-workspace:**
```bash
# Enter dev-workspace
./run

# Dry run (preview cost, no API calls)
composer translate:dry-run

# Full translation cycle
composer translate
```

**From plugin root:**
```bash
# Dry run
composer translate:dry-run

# Full translation cycle
composer translate
```

**What happens when you run `composer translate`:**

1. **📥 Download** - Pulls existing translations from Weblate (if project exists)
2. **🤖 AI Translate** - Potomatic adds translations for new/missing strings
3. **📤 Upload** - Pushes updated translations back to Weblate

This ensures:
- Existing translations are preserved
- Only new/missing strings are translated by AI
- Weblate always has the latest translations

#### 2. Review & Improve in Weblate

After running `translate`, you can visit your project in Weblate:
1. Hosted Weblate: https://hosted.weblate.org/projects/YOUR-PROJECT/
2. Self-hosted Weblate: https://YOUR-WEBLATE-DOMAIN/projects/YOUR-PROJECT/
3. Review and improve AI-generated translations
4. Use Weblate's translation memory and suggestions
5. Collaborate with community translators

#### 3. Download Only

If you just want to download the latest translations without running AI translation:

```bash
# Download latest from Weblate (no AI translation)
composer translate:download
```

Use this when:
- Translators made changes in Weblate
- You want to sync before building plugin
- You don't need to add new translations

**Advanced options:**
```bash
# Translate custom languages only
vendor/bin/publishpress-translate --languages=de_DE,fr_FR,es_ES

# Force re-translate all strings (ignore existing translations)
vendor/bin/publishpress-translate --force

# Download specific languages only
vendor/bin/publishpress-translate --download --languages=de_DE,fr_FR

# Upload specific languages only (no AI translation)
vendor/bin/publishpress-translate --upload --languages=de_DE,fr_FR

# Repair malformed plural entries in existing .po files
vendor/bin/publishpress-translate --repair-plurals
```

#### 4. Repair Malformed Plural Entries

If you have existing `.po` files with malformed plural entries (a known issue with older Potomatic versions where `msgstr[0]` contains `"singular|plural"` instead of separate `msgstr[0]`/`msgstr[1]` lines), you can fix them:

```bash
# Scan and repair all .po files in the languages directory
vendor/bin/publishpress-translate --repair-plurals
```

**What this fixes:**
- Detects plural entries where `msgstr[0]` contains pipe-delimited forms
- Splits them into proper separate `msgstr[N]` lines 
- Regenerates corresponding `.mo` files
- Reports which files were repaired

**Note:** New translations are automatically repaired during the translation process, so you only need this for existing files.

**Note:** The library automatically detects your environment (dev-workspace vs plugin root) and uses the correct vendor path.

### Default Languages

The tool translates into these languages by default:
- Arabic (ar)
- Bulgarian (bg_BG)
- Catalan (ca)
- Czech (cs_CZ)
- Danish (da_DK)
- German (de_DE)
- Greek (el)
- Estonian (et_EE)
- Persian (fa_IR)
- Finnish (fi)
- Filipino (fil)
- Hebrew (he_IL)
- Croatian (hr)
- Hungarian (hu_HU)
- Indonesian (id_ID)
- Japanese (ja)
- Korean (ko_KR)
- Lithuanian (lt_LT)
- Norwegian Bokmål (nb_NO)
- Dutch (nl_NL)
- Polish (pl_PL)
- Portuguese (Portugal) (pt_PT)
- Romanian (ro_RO)
- Russian (ru_RU)
- Slovak (sk_SK)
- Slovenian (sl_SI)
- Swedish (sv_SE)
- Thai (th)
- Turkish (tr_TR)
- Ukrainian (uk)
- Vietnamese (vi)
- Yoruba (yor)
- Chinese (China) (zh_CN)
- Chinese (Taiwan) (zh_TW)

### Skipped Languages
 
The following languages should not be translated by Potomatic, they are handled by human translators:
- Italian (it_IT)
- Spanish (es_ES)
- French (fr_FR)
- Brazilian Portuguese (pt_BR)
 
These languages will be skipped during translation and upload processes, even if PO files exist for them.

### Preventing Plugin Name Translation

By default, all strings in your plugin are translated, including the plugin name. To keep your plugin name untranslated, add it to your `composer.json` file:

```json
{
    "extra": {
        "plugin_name": "your plugin name"
    }
}
```

The translation tool will then automatically keep the plugin name untranslated in all PO files, both when:
- Running AI translations with Potomatic
- Downloading translations from Weblate

## How It Works

### Translation Cycle (`composer translate`)

**Step 1: Download from Weblate**
- Pulls existing translations from Weblate
- Preserves human edits and community contributions
- Creates project if it doesn't exist yet

**Step 2: AI Translation with Potomatic**
- Scans your plugin's `languages/` directory for `.pot` files
- Generates AI translations for new/missing strings only
- Merges with existing translations (preserves manual edits)
- Creates/updates `.po` and `.mo` files for each target language

**Step 3: Upload to Weblate**
- Creates project on Weblate (using plugin slug as project slug)
- Creates component for each text domain
- Uploads POT template and all PO translations
- Provides link to view/edit in Weblate

### Download Only (`composer translate:download`)

1. Connects to Weblate using your API token
2. Finds your plugin's project and components
3. Downloads latest `.po` files for all languages
4. Converts to `.mo` files for WordPress
5. Saves to your `languages/` folder

**Use this when:**
- You want to sync translations before building
- Translators made changes in Weblate
- You don't need to run AI translation

### Weblate Integration

- **Automatic sync** - Download → Translate → Upload in one command
- **Preserves human edits** - Existing translations are never overwritten
- **Automatic project creation** - Uses plugin slug as project name
- **Component per text domain** - Each `.pot` file becomes a component
- **Optional** - Works without Weblate if token not set

## One-Time Setup

Set your API keys permanently:

**Windows:**
```powershell
[System.Environment]::SetEnvironmentVariable('OPENAI_API_KEY', 'sk-proj-your-key', 'User')
[System.Environment]::SetEnvironmentVariable('WEBLATE_API_TOKEN', 'wlu_your-token', 'User')
```

**Mac/Linux (add to ~/.bashrc or ~/.zshrc):**
```bash
export OPENAI_API_KEY=sk-proj-your-key
export WEBLATE_API_TOKEN=wlu_your-token
```

## Troubleshooting

### "Potomatic not found" Error

This shouldn't happen if you installed via Composer. If it does, please report it as a bug.

### "OPENAI_API_KEY not set" warning / exit

If `OPENAI_API_KEY` is not configured:

- In **dry run** (`composer translate:dry-run`), the tool prints a warning but continues so you can verify configuration without any API calls.
- In **live mode** (`composer translate`), the tool prints a clear message and exits before attempting any OpenAI requests.

Make sure you've set the environment variable before running live translations.

### "Weblate not configured" Error

This appears when running `--download` without `WEBLATE_API_TOKEN` set. Weblate integration is optional for generation but required for download.

### "No .pot files found" Error

Ensure your plugin has a `languages/` directory with `.pot` translation template files. Generate these using tools like:
- [WP-CLI i18n make-pot](https://developer.wordpress.org/cli/commands/i18n/make-pot/)
- [Poedit](https://poedit.net/)
- [Loco Translate](https://wordpress.org/plugins/loco-translate/)

### Weblate Upload Fails

If Weblate upload fails, the translation process continues (translations are still saved locally). Check:
- API token is correct
- You have permissions on Weblate
- Project/component names are valid (no special characters)

## Development

### Clone the Repository

```bash
git clone https://github.com/publishpress/translations.git
cd translations
composer install
```

### Testing Locally

To test the library before publishing:

1. In your plugin's `composer.json`, add a repository:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../publishpress-translations"
        }
    ],
    "require": {
        "publishpress/translations": "@dev"
    }
}
```

2. Run `composer install`

## License

GPL-3.0-or-later

## Credits

Built with [Potomatic](https://github.com/GravityKit/potomatic) by GravityKit.
