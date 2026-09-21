#!/usr/bin/env bash
# Installs the Laravel scheduler as a systemd --user timer (runs every
# minute, like a cron entry would), replacing a manually-launched
# `php artisan schedule:work` process. Re-run safely after editing the unit
# files in this directory.
set -euo pipefail

UNIT_DIR="$HOME/.config/systemd/user"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$UNIT_DIR"
cp "$SOURCE_DIR/wallet-tracker-scheduler.service" "$UNIT_DIR/"
cp "$SOURCE_DIR/wallet-tracker-scheduler.timer" "$UNIT_DIR/"

systemctl --user daemon-reload
systemctl --user enable --now wallet-tracker-scheduler.timer

echo "Installed. Check status with:"
echo "  systemctl --user status wallet-tracker-scheduler.timer"
echo "  journalctl --user -u wallet-tracker-scheduler.service -f"
echo
echo "To have it survive a reboot without an active login session, also run:"
echo "  loginctl enable-linger \$USER"
