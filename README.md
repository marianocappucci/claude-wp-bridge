# Claude WP Bridge

A WordPress plugin that exposes your site as **MCP tools** accessible from [Claude Code](https://claude.ai/code). Manage pages, theme files, and plugins directly from your terminal — no FTP, no wp-admin, no custom REST endpoints needed.

Designed to be the **single plugin** you need for Claude Code → WordPress integration. It replaces ad-hoc REST endpoint plugins by using the official WordPress Abilities API as the transport layer.

## How it works

```
Claude Code (MCP client)
    ↕  @automattic/mcp-wordpress-remote  (STDIO → HTTP proxy)
WordPress MCP Adapter  (official plugin, already on your site)
    ↕  WordPress Abilities API  (WP 7.0+)
Claude WP Bridge  (this plugin — registers the actual tools)
```

Claude Code sees **3 fixed MCP tools** from the adapter:
- `mcp-adapter-discover-abilities` — lists all available tools
- `mcp-adapter-get-ability-info` — schema for a specific tool
- `mcp-adapter-execute-ability` — runs any tool with parameters

This plugin registers 16 **WordPress Abilities** under the `claude/` namespace, which the adapter exposes automatically.

---

## Requirements

| Requirement | Notes |
|---|---|
| **WordPress 7.0+** | Abilities API (`wp_register_ability`) is a WP 7.0 feature |
| **[wordpress/mcp-adapter](https://wordpress.org/plugins/mcp-adapter/)** | The official plugin that bridges Abilities → MCP. Must be installed and active. |
| **WordPress Application Password** | Go to *Users → Your Profile → Application Passwords* and generate one. Do **not** use your login password. |
| **[Claude Code](https://claude.ai/code)** | The CLI where you'll interact with your site |

---

## Installation

### 1. Install the official MCP Adapter

Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin on your WordPress site. This is the transport layer — Claude WP Bridge won't work without it.

### 2. Install Claude WP Bridge

**First install (bootstrap):** The plugin can't install itself, so the first time must be manual. Two options:

- **wp-admin (recommended):** Download `claude-wp-bridge.zip` from the [latest release](https://github.com/marianocappucci/claude-wp-bridge/releases/latest), then go to *Plugins → Add New → Upload Plugin*, upload it, and activate.
- **cPanel File Manager / FTP:** Create the folder `wp-content/plugins/claude-wp-bridge/`, upload `claude-wp-bridge.php` into it, and activate from *Plugins*.

> **Building your own ZIP?** The archive must contain a `claude-wp-bridge/` folder with `claude-wp-bridge.php` inside, using forward slashes (`/`) in its paths. ZIPs created with Windows tools (*Send to → Compressed folder*, PowerShell 5.1 `Compress-Archive`) may store backslashes (`\`): the server then extracts a single file literally named `claude-wp-bridge\claude-wp-bridge.php`, WordPress fails with *"Plugin file does not exist"*, and FTP clients cannot rename or delete that file (use cPanel File Manager to remove it). Build the ZIP on Linux/macOS, or with `python -m zipfile -c claude-wp-bridge.zip claude-wp-bridge/`.

**Updates:** Once active, Claude can update the plugin itself using `claude/upload-file` to overwrite the PHP file. No deactivation needed — the new code loads on the next request.

### 3. Create an Application Password

1. Go to *Users → Your Profile* in wp-admin
2. Scroll to **Application Passwords**
3. Enter a name (e.g. `Claude Code`) and click **Add New Application Password**
4. Copy the generated password — you won't see it again

### 4. Configure Claude Code

Create or edit `.claude/settings.json` in your project:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["@automattic/mcp-wordpress-remote"],
      "env": {
        "WORDPRESS_USERNAME": "your-wp-username",
        "WORDPRESS_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "WORDPRESS_URL": "https://your-site.com"
      }
    }
  }
}
```

> **Note:** Use the Application Password (spaces included), not your login password.

Install the proxy if needed:
```bash
npm install -g @automattic/mcp-wordpress-remote
```

### 5. Verify

Open Claude Code in your project directory and run:

```
What WordPress abilities are available?
```

Claude will call `mcp-adapter-discover-abilities` and list all registered tools.

---

## Available tools

All tools are under the `claude/` namespace and require admin-level authentication.

| Tool | Type | Description |
|---|---|---|
| `claude/site-map` | read | Site name, URL, WordPress version, active theme, and full page list |
| `claude/list-pages` | read | All pages with ID, title, slug, status, and URL |
| `claude/get-page` | read | Raw HTML content of a page by ID |
| `claude/update-page` | write | Replace a page's HTML content (bypasses kses, preserves raw HTML) |
| `claude/list-theme-files` | read | Files in the active theme, optionally filtered by extension |
| `claude/get-theme-file` | read | Content of a theme file by relative path |
| `claude/update-theme-file` | write | Write to a theme file (creates missing directories) |
| `claude/upload-file` | write | Write any file to `plugins/` or `themes/`, with base64 support |
| `claude/list-plugins` | read | All installed plugins with name, version, status (active/inactive) |
| `claude/manage-plugin` | write | Activate or deactivate a plugin by slug |
| `claude/elementor-list` | read | Every post built with Elementor, including templates (headers, footers, popups) |
| `claude/elementor-get` | read | Elementor structure of a post: outline, full JSON, or one element; plus its backups |
| `claude/elementor-update-element` | write | Merge new settings into one element (a heading, an image, a button link) |
| `claude/elementor-save` | write | Replace the whole elements JSON of a post (add, remove or reorder sections) |
| `claude/elementor-restore` | write | Restore a backup taken before an earlier Elementor write |
| `claude/elementor-flush` | write | Regenerate Elementor CSS and purge LiteSpeed Cache |

### Elementor pages

Elementor renders a page from the JSON stored in the `_elementor_data` meta. `post_content` only holds a plain-HTML copy, so **`claude/update-page` does not change what an Elementor page shows** — and Elementor overwrites that copy the next time the page is saved in its editor. Use the `claude/elementor-*` tools instead:

1. `claude/elementor-list` to find the post (headers and footers are `elementor_library` templates, not pages).
2. `claude/elementor-get` with the post ID to see the outline, then with `element_id` to see one element's settings.
3. `claude/elementor-update-element` to change it, or `claude/elementor-save` for structural changes.

Every write goes through Elementor's own `Document::save()`, which validates the widgets and regenerates the `post_content` copy. Before writing, the current JSON is stored as a backup (the last 10 per post are kept, in the `_claude_elementor_backup` meta), and afterwards the Elementor CSS and LiteSpeed Cache are flushed. Editing a template flushes the whole site, since it appears on every page.

Elementor drops widgets whose type is not registered — for example, widgets from a deactivated plugin — so the write reports how many elements were sent and how many were stored, and adds a `warning` if they differ. Undo any write with `claude/elementor-restore`.

### Calling the tools without MCP

Every ability is also a plain REST endpoint, authenticated with the same Application Password:

```bash
# Read-only abilities: GET, input as query parameters
curl -u "user:app-password" -G "https://your-site.com/wp-json/wp-abilities/v1/abilities/claude/elementor-get/run" \
  --data-urlencode "input[post_id]=34"

# Abilities that write: POST, input as JSON
curl -u "user:app-password" -X POST -H "Content-Type: application/json" \
  "https://your-site.com/wp-json/wp-abilities/v1/abilities/claude/elementor-update-element/run" \
  -d '{"input": {"post_id": 34, "element_id": "a1b2c3d", "settings": {"title": "New heading"}}}'
```

---

## Example Claude Code session

```
You: What pages does my site have?
→ mcp-adapter-execute-ability("claude/list-pages", {"status": "publish"})

You: Show me the HTML of the Home page (ID 34)
→ mcp-adapter-execute-ability("claude/get-page", {"page_id": 34})

You: Add a newsletter section before the footer in the Home page
→ [Claude reads the page, modifies the HTML, calls update-page]
→ mcp-adapter-execute-ability("claude/update-page", {"page_id": 34, "content": "..."})

You: List the CSS files in my theme
→ mcp-adapter-execute-ability("claude/list-theme-files", {"ext": "css"})

You: Update style.css to change the primary color to #e63946
→ [Claude reads the file, edits it, writes it back]
→ mcp-adapter-execute-ability("claude/update-theme-file", {"path": "style.css", "content": "..."})

You: What plugins are installed and which ones are inactive?
→ mcp-adapter-execute-ability("claude/list-plugins", {"status": "any"})

You: Activate the contact-form-7 plugin
→ mcp-adapter-execute-ability("claude/manage-plugin", {"plugin": "contact-form-7/wp-contact-form-7.php", "action": "activate"})

You: Deploy my updated plugin file
→ mcp-adapter-execute-ability("claude/upload-file", {"path": "plugins/my-plugin/my-plugin.php", "content": "..."})
```

---

## Security notes

- All abilities require `manage_options` or `edit_pages` capability — only admins can use them
- The `update-page` ability **bypasses WordPress kses filtering** by design, so raw HTML/CSS/JS is preserved. Only grant Application Passwords to trusted users.
- The `upload-file` ability is restricted to `plugins/` and `themes/` subdirectories — it cannot write outside `wp-content/`
- Path traversal (`../`) is stripped from all file path inputs
- The `claude/elementor-*` abilities check `edit_post` on the target post, and back up its Elementor JSON before every write (last 10 per post, in the `_claude_elementor_backup` meta)

---

## Compatibility

Tested with:
- WordPress 7.0
- MCP Adapter 0.5.0
- PHP 8.3

---

## License

MIT
