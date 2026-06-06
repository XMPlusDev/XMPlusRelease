#!/bin/bash

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

log()   { echo -e "${GREEN}[✔]${NC} $1"; }
info()  { echo -e "${BLUE}[i]${NC} $1"; }
error() { echo -e "${RED}[✘]${NC} $1"; }

INSTALL_PATH="/usr/bin/XMPanel"
SYMLINK_PATH="/usr/bin/xmpanel"
SCRIPT_URL="https://raw.githubusercontent.com/XMPlusDev/XMPlusRelease/scripts/XMPanel.sh"

# Check root
if [[ $EUID -ne 0 ]]; then
  error "This script must be run as root."
  exit 1
fi

if systemctl is-active --quiet XMPlusPanel.service 2>/dev/null; then
    systemctl stop XMPlusPanel.service
fi
if systemctl is-enabled --quiet XMPlusPanel.service 2>/dev/null; then
    systemctl disable XMPlusPanel.service
fi
if [ -f "/etc/systemd/system/XMPlusPanel.service" ]; then
    rm -f /etc/systemd/system/XMPlusPanel.service
fi
systemctl daemon-reload

cat > /etc/systemd/system/XMPlusPanel.service <<EOF
[Unit]
Description=XMPlusPanel
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/home/XMPlusPanel
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF

echo -e "${GREEN}==> Enabling and starting XMPlusPanel service...${NC}"
systemctl daemon-reload
systemctl enable XMPlusPanel.service
systemctl start XMPlusPanel.service

# Remove existing XMPanel Script
if [[ -f "$INSTALL_PATH" ]]; then
  info "Removing existing XMPanel Script..."
  rm -rf "$INSTALL_PATH"
fi

if [[ -f "$SYMLINK_PATH" ]]; then
  info "Removing existing XMPanel symlink file..."
  rm -rf "$SYMLINK_PATH"
fi

# Download
info "Downloading XMPanel Script..."
curl -o "$INSTALL_PATH" -Ls "$SCRIPT_URL"
log "Downloaded to $INSTALL_PATH"

# Set permissions & symlink
chmod +x "$INSTALL_PATH"
ln -s "$INSTALL_PATH" "$SYMLINK_PATH"
chmod +x "$SYMLINK_PATH"
log "Permissions set and symlink created at $SYMLINK_PATH"

log "XMPanel Script installed successfully. Run with: xmpanel"