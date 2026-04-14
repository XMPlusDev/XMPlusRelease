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

# Remove existing XMPanel Script
if [[ -f "$INSTALL_PATH" ]]; then
  info "Removing existing XMPanel Script..."
  rm -rf "$INSTALL_PATH" "$SYMLINK_PATH"
  log "Old files removed."
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