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
- Node.js 18+ and npm (for Potomatic CLI tool)
- OpenAI API key ([Get one here](https://platform.openai.com/api-keys))
- Weblate account and API token ([Sign up here](https://hosted.weblate.org/))
- Plugin must have a `languages/` directory with `.pot` files

## Installation

**Note:** This setup works the same whether you're working from the plugin root or inside dev-workspace.

### Step 1: Add to `lib/composer.json`

⚠️ **Current setup (until published on Packagist):**

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/publishpress/publishpress-translator.git"
        }
    ],
    "require": {
        "publishpress/translations": "dev-main"
    }
}
```

### Step 2: Add to root `composer.json`

```json
{
    "scripts": {
        "translate": "lib/vendor/bin/publishpress-translate",
        "translate:dry-run": "lib/vendor/bin/publishpress-translate --dry-run",
        "translate:download": "lib/vendor/bin/publishpress-translate --download",
        "translate:upload": "lib/vendor/bin/publishpress-translate --upload",
        "translate:custom": "lib/vendor/bin/publishpress-translate --languages",
        "translate:force": "lib/vendor/bin/publishpress-translate --force",
        "translate:force-custom": "lib/vendor/bin/publishpress-translate --force --languages"
    }
}
```

### Step 3: Install

```bash
composer update
```

**Once on Packagist:** We can change Step 1 to:
```json
{
    "require": {
        "publishpress/translations": "^1.0"
    }
}
```

## Usage

### Set Environment Variables

Before using the translation tools, set your API keys as environment variables:

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

**Get your Weblate API token:**
1. Sign up at [hosted.weblate.org](https://hosted.weblate.org/)
2. Go to your profile: https://hosted.weblate.org/accounts/profile/#api
3. Copy your personal API key

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

After running translate, we then can:
1. Visit https://hosted.weblate.org/projects/YOUR-PLUGIN/
2. Review and improve AI-generated translations
3. Use Weblate's translation memory and suggestions
4. Collaborate with community translators

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
# Custom languages only
lib/vendor/bin/publishpress-translate --languages=de_DE,fr_FR,es_ES

# Force re-translate all strings (ignore existing translations)
lib/vendor/bin/publishpress-translate --force

# Download specific languages
lib/vendor/bin/publishpress-translate --download --languages=de_DE,fr_FR
```

**Note:** The library automatically detects your environment (dev-workspace vs plugin root) and uses the correct vendor path.

### Default Languages

The tool translates into these languages by default:
- German (de_DE)
- Brazilian Portuguese (pt_BR)
- Indonesian (id_ID)
- Filipino (fil)
- Russian (ru_RU)
- Yoruba (yo)
- Finnish (fi)
- Japanese (ja)
- Korean (ko_KR)

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

### "OPENAI_API_KEY not set" Error

Make sure you've set the environment variable before running the translation command.

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