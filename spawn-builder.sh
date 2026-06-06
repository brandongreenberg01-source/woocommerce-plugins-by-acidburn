#!/bin/bash
# Builder startup script for WooCommerce Gumroad Store
# Launches a Hermes agent that builds all products and lists them on Gumroad

SKY_HOME="/opt/data/hermes-sky"
export HERMES_HOME="/opt/data/.local/share/hermes"
cd /opt/hermes

# Read Gumroad token
GUMROAD_TOKEN=$(grep -oP 'access_token=\K.*' /opt/data/.env 2>/dev/null || echo "CyGGoEVPli0MT01j_6LY_-KJ_S8tmQEyaAy8zDcsBGo")

# Launch in tmux
tmux new-session -d -s gumroad-builder -x 132 -y 50 "cd /opt/hermes && /opt/hermes/.venv/bin/hermes chat -q \"$(cat /opt/data/sandy_ops/gumroad-store/startup-prompt.txt)\" -s woocommerce-api --model 'deepseek/deepseek-v4-pro:nitro' --provider openrouter 2>&1 | tee /opt/data/sandy_ops/gumroad-store/build-log.txt"
