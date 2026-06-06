#!/bin/bash
# Run by the listing bot - lists one product on Gumroad
export HERMES_HOME="/opt/data/.local/share/hermes"

# Read API key
if [ -f /tmp/_or_key_val.txt ]; then
    OR_KEY=$(cat /tmp/_or_key_val.txt)
    export OPENROUTER_API_KEY="$OR_KEY"
fi

cd /opt/hermes
PROMPT=$(cat /opt/data/sandy_ops/gumroad-store/listing-bot.txt)
/opt/hermes/.venv/bin/hermes chat -q "$PROMPT" --model 'deepseek/deepseek-v4-pro:nitro' --provider openrouter -Q 2>&1 | tee /opt/data/sandy_ops/gumroad-store/listing-output.log
echo "EXIT_CODE: $?"
