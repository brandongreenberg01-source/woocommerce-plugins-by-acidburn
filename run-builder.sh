#!/bin/bash
# Spawn the Gumroad store builder in background
mkdir -p /opt/data/sandy_ops/gumroad-store/products
export HERMES_HOME="/opt/data/.local/share/hermes"
cd /opt/hermes

# Read the prompt and spawn
PROMPT=$(cat /opt/data/sandy_ops/gumroad-store/startup-prompt.txt)
/opt/hermes/.venv/bin/hermes chat -q "$PROMPT" \
  --model 'deepseek/deepseek-v4-pro:nitro' \
  --provider openrouter \
  -s woocommerce-api \
  --worktree \
  -Q 2>&1

echo "BUILDER EXITED WITH CODE $?" 
