# _Ykan

**Minimal single-file PHP Kanban board with AI superpowers**

A lightweight, self-contained Kanban board that runs from a single PHP file. Perfect for developers who want a simple, fast, and AI-enhanced project management tool without complex setups.

![Version](https://img.shields.io/badge/version-1.8.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.2+-purple)
![License](https://img.shields.io/badge/license-MIT-green)
[![Demo](https://img.shields.io/badge/demo-live-brightgreen)](https://portale3d.it/projects/_Ykan/demo.php)

---

## Features

### Core
- **Single-file deployment** - Just one PHP file, no database required
- **Drag & Drop** - Move cards between columns and swimlanes
- **Swimlanes** - Organize tasks by category (collapsible accordions)
- **Labels & Priorities** - Color-coded organization
- **Due dates** - Track deadlines with overdue alerts
- **Dark/Light theme** - Easy on the eyes
- **Auto-regenerating tasks** - Recurring tasks that recreate when archived

### Custom Themes
- **JSON themes** - Drop a theme file in `themes/` and switch it live from the header
- **Theme editor** - Create, save and delete themes from the UI
- **Full control** - Override colors, layout (density/shape) and surfaces (gradients, glass)
- **Documented template** - `themes/_TEMPLATE.jsonc` explains every field for humans & AIs

### MCP - Remote control from mobile
- **Claude mobile app** - Manage the board and edit project files from your phone via `mcp.php`
- **JSON-RPC 2.0 over HTTPS** - Stateless, runs on plain shared hosting (no VPS)
- **Projects** - Link swimlanes to project folders and browse their files safely
- **`.env` config** - Secrets (`MCP_SECRET`, `MCP_ROOT`) loaded from `.env`, never hardcoded
- See [MCP_SETUP.md](MCP_SETUP.md) for the full setup guide

### AI Integration (Gemini)
- **Project Analysis** - Scan your codebase and get task suggestions
- **Task Verification** - AI checks if tasks are completed by reading associated files
- **Auto-categorization** - AI suggests labels and priority
- **Time Estimation** - AI estimates complexity and duration
- **Daily Standup** - Generate Agile standup reports
- **Multi-language AI** - AI responds in your preferred language (EN, IT, ES, FR, DE, PT)

### GitHub Integration
- **View Issues** - See all repo issues with labels and status
- **View Pull Requests** - Monitor PRs (open/merged/closed)
- **View Commits** - Latest commits with authors
- **Repo Stats** - Stars, forks, watchers at a glance
- **Issue to Card** - Import GitHub issues as Kanban cards

### Productivity
- **Instant Search** - Filter cards in real-time
- **Advanced Filters** - By label, priority, due date
- **Markdown Support** - Format descriptions with markdown
- **Export JSON/CSV** - Backup your board
- **Card Templates** - Bug, Feature, Task, Docs, Refactor
- **TODO Scanner** - Find TODO/FIXME comments in your code
- **Burndown Chart** - Track velocity and progress

---

## Installation

1. **Download** `_Ykan.php` to your project folder
2. **Access** via browser: `http://localhost/your-project/_Ykan.php`
3. **Configure** your Gemini API key in Settings (optional)
4. **Start** creating tasks!

```bash
# Or clone the repo
git clone https://github.com/YuriNuresi/_Ykan.git
cd _Ykan
# Serve with PHP built-in server
php -S localhost:8000
# Open http://localhost:8000/_Ykan.php
```

---

## Configuration

Click on **Settings** (gear icon) to configure:

| Setting | Description |
|---------|-------------|
| **Project Name** | Displayed in header |
| **AI Language** | Language for AI responses (English, Italiano, Español, Français, Deutsch, Português) |
| **Gemini API Key** | For AI features ([Get one here](https://makersuite.google.com/app/apikey)) |
| **GitHub Token** | For GitHub integration ([Generate token](https://github.com/settings/tokens)) |
| **GitHub Repo** | Format: `owner/repo` (e.g., `YuriNuresi/_Ykan`) |
| **Labels** | Customize colors and names |

---

## Requirements

- PHP 8.2+
- cURL extension (for AI and GitHub features)
- Write permissions (for `_Ykan_data.json`)

---

## Data Storage

All data is stored in `_Ykan_data.json` in the same directory. This file contains:
- Board configuration
- Columns and swimlanes
- All cards (active and archived)
- Labels

> **Security Note:** The data file may contain your API keys. Add `_Ykan_data.json` to `.gitignore` and never commit it to public repositories.

---

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Click + Drag` | Move cards |
| `Click on card` | Edit card |
| `Click on swimlane header` | Toggle collapse |

---

## API Endpoints

_Ykan exposes a simple API for integration:

```
GET/POST ?api=get_summary     # Get board summary (for AI tools)
POST ?api=complete_task       # Archive task by title
POST ?api=move_task          # Move task to column
POST ?api=github_issues      # Get GitHub issues
POST ?api=github_prs         # Get GitHub PRs
POST ?api=github_commits     # Get GitHub commits
```

---

## Changelog

### v1.8.0 (July 2026)
- Custom Themes system: JSON themes in `themes/` with a live switcher
- Theme editor (create/save/delete) with color, layout and surface overrides
- Documented theme template (`themes/_TEMPLATE.jsonc`)
- MCP remote control (`mcp.php`): manage the board and edit project files from the Claude mobile app
- Projects: link swimlanes to folders and browse project files safely
- `mcp.php` now reads `MCP_SECRET`/`MCP_ROOT` from `.env` instead of hardcoded constants

### v1.7.0 (December 2025)
- Full English UI translation
- AI Response Language setting (EN, IT, ES, FR, DE, PT)
- AI prompts now respond in user's preferred language

### v1.6.0 (December 2025)
- GitHub API integration (issues, PRs, commits)
- Repo stats display
- Create cards from GitHub issues

### v1.5.0 (December 2025)
- Instant search
- Advanced filters (label, priority, due date)
- Markdown support
- Export JSON/CSV
- Card templates
- AI auto-categorization
- AI time estimation
- Daily Standup AI
- TODO Scanner
- Burndown Chart

### v1.4.0 (December 2025)
- File associations for tasks
- AI task verification
- Changelog panel

### v1.3.0 (December 2025)
- Auto-regenerating tasks
- Regeneration counter

### v1.2.0 (December 2025)
- AI Integration Guide
- Project analysis with AI
- Suggested tasks from codebase scan

### v1.1.0 (December 2025)
- Gemini AI integration
- Board analysis
- Task suggestions

### v1.0.0 (December 2025)
- Initial release
- Columns, swimlanes, cards
- Drag & drop
- Labels and priorities
- Archive system

---

## License

MIT License - see [LICENSE](LICENSE) file.

---

## Author

**Yuri Nuresi** - [@YuriNuresi](https://github.com/YuriNuresi)

---

## Contributing

Contributions are welcome! Feel free to:
- Open issues for bugs or feature requests
- Submit pull requests
- Star the repo if you find it useful!

---

<p align="center">
  Made with coffee and AI assistance
</p>
