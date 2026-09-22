#!/usr/bin/env bash
# Installs, as systemd --user units:
#   - the Laravel scheduler, as a timer running every minute (like a cron
#     entry would), replacing a manually-launched `schedule:work` process;
#   - the queue worker, as a permanent service (notifications and
#     verification/reset emails are queued and need it running).
# Re-run safely after editing the unit files in this directory.
set -euo pipefail

UNIT_DIR="$HOME/.config/systemd/user"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$UNIT_DIR"
cp "$SOURCE_DIR/wallet-tracker-scheduler.service" "$UNIT_DIR/"
cp "$SOURCE_DIR/wallet-tracker-scheduler.timer" "$UNIT_DIR/"
cp "$SOURCE_DIR/wallet-tracker-queue.service" "$UNIT_DIR/"

systemctl --user daemon-reload
systemctl --user enable --now wallet-tracker-scheduler.timer
systemctl --user enable --now wallet-tracker-queue.service

echo "Installed. Check status with:"
echo "  systemctl --user status wallet-tracker-scheduler.timer"
echo "  journalctl --user -u wallet-tracker-scheduler.service -f"
echo "  systemctl --user status wallet-tracker-queue.service"
echo "  journalctl --user -u wallet-tracker-queue.service -f"
echo
echo "After deploying new code, reload the worker with:"
echo "  php artisan queue:restart"
echo
echo "To have it survive a reboot without an active login session, also run:"
echo "  loginctl enable-linger \$USER"
