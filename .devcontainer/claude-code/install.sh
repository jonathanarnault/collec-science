#!/usr/bin/env zsh

set -e

CLAUDE_PATH="/home/${_CONTAINER_USER}/.claude"
CLAUDE_PROJECTS_PATH="${CLAUDE_PATH}/projects"
CLAUDE_PLUGINS_PATH="${CLAUDE_PATH}/plugins"

mkdir -p "$CLAUDE_PROJECTS_PATH"
mkdir -p "$CLAUDE_PLUGINS_PATH"

chown -R $_CONTAINER_USER:$_CONTAINER_USER "$CLAUDE_PATH"

su - "$_CONTAINER_USER" <<'EOL'
cd /tmp
curl -fsSL https://claude.ai/install.sh -o claude-install.sh
chmod +x claude-install.sh
./claude-install.sh
rm -f claude-install.sh
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc

EOL

