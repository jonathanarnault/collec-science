# Claude Code integration

Installs the [Claude Code](https://claude.ai/code) CLI and the
`Anthropic.claude-code` VS Code extension.

## Config sharing

Host credentials and settings are bind-mounted into the container so you stay
logged in across rebuilds:

- `~/.claude.json` → `/home/node/.claude.json`
- `~/.claude` → `/home/node/.claude`

Installed plugins live in a `claude-plugins` named volume (mounted at
`~/.claude/plugins`) so they persist across rebuilds without touching the host.
