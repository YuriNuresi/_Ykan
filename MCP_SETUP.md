# _Ykan MCP — remote control from the Claude mobile app

`mcp.php` turns your _Ykan install into a **remote MCP server**: from the Claude
Android app (or claude.ai) you can manage the board *and* edit files of the
projects hosted on the same OVH account — e.g.

> "add a task to Cliente A: redo the header"
> "in Cliente A open style.css and make the font red"

It talks JSON-RPC 2.0 over HTTPS (stateless), so it runs fine on **OVH shared
hosting** — no VPS, no persistent process.

---

## 1. Upload

Put both files in the folder served by your subdomain (e.g.
`ykan.portale3d.it/` → hosting folder `ykan/`):

```
ykan/
├── _Ykan.php
├── _Ykan_data.json   (created automatically on first run)
├── mcp.php
└── .env              (you create this — holds the secrets, keep it out of git)
```

## 2. Configure via `.env`

`mcp.php` reads its config from a `.env` file (no more editing constants in the
code). Create a `.env` next to `mcp.php` — or one level up in your hosting root —
with these two keys:

```
MCP_SECRET=a-long-random-string-that-is-the-password
MCP_ROOT=/absolute/path/that/contains/your/project/folders
```

| Key | What to put |
|-----|-------------|
| `MCP_SECRET` | A long random string. **It is the password** — the whole URL is a secret. |
| `MCP_ROOT` | Absolute path to the folder that **contains** your project folders (usually your hosting web root). Ask OVH/your FTP client for the absolute path, e.g. `/homez.NNN/youruser/www`. |

A `.env` next to `mcp.php` takes precedence over one in the hosting root.
Every project folder you link is resolved **relative to `MCP_ROOT`**.

> **Keep `.env` out of git and off public HTTP.** It holds the password.

## 3. Link swimlanes to folders (the "Projects" popup)

Open `_Ykan.php` in the browser → toolbar → **🔗 Projects**.
For each swimlane (= project) set:

- **Folder** — path relative to `MCP_ROOT` (e.g. `clienteA` or `sites/shopX`)
- **URL** — optional public URL

A swimlane with a folder shows a 🔗 badge. **Only linked folders are reachable**
by the file tools — everything else on the hosting is off-limits.

## 4. Add the connector in Claude

claude.ai → **Settings → Connectors → Add custom connector**, paste:

```
https://ykan.portale3d.it/mcp.php?k=YOUR_SECRET
```

It becomes available in the **Android app** automatically. No OAuth needed —
the secret in the URL is the gate (HTTPS only).

## 5. Test from the Android app

Try, in order:
1. *"list my ykan projects"* → `list_projects`
2. *"add a task to Cliente A: test from phone"* → `add_task` (check it appears on the board)
3. *"in Cliente A, read style.css"* → `read_file`
4. *"in Cliente A, change blue to red in style.css"* → `edit_file`

---

## Tools exposed

**Board (Phase 1):** `list_projects`, `board_summary`, `list_tasks`,
`add_task`, `move_task`, `complete_task`

**Files, scoped to a linked folder (Phase 2):** `list_files`, `read_file`,
`edit_file`, `write_file`, `search_files`

## Safety built in

- **Secret-gated** access, HTTPS only. Keep `MCP_SECRET` out of git.
- **Folder confinement**: file tools can only touch a swimlane's linked folder;
  `../` traversal and symlinks pointing outside are rejected.
- **Backup before every write** → `<folder>/.ykan_backups/<file>.<timestamp>`.
- **Audit log** of every write → `_ykan_mcp_audit.log` next to `mcp.php`.
- Read/write size limits.

> Tip: start by linking **one** project folder. Widen once you trust the flow.
> Anyone who obtains the URL gets the same access — rotate `MCP_SECRET` if leaked.
