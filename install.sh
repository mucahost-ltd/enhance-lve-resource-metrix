#!/usr/bin/env bash
# install.sh - installs enhance-usage-dashboard onto an Enhance Application server.
#
# Usage:
#   sudo bash install.sh
#
# Safe to re-run: it overwrites the scripts/service file but never touches
# your existing /var/log/enhance-usage data or your admin password hash in
# dashboard/index.php once you've set it (see step 2 below).

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root: sudo bash install.sh" >&2
  exit 1
fi

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="/opt/enhance-usage"

echo "==> Installing to ${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}/dashboard"
cp "${SRC_DIR}/bin/enhance-usage-logger.sh" "${INSTALL_DIR}/"
cp "${SRC_DIR}/bin/enhance-usage-report.sh" "${INSTALL_DIR}/"
cp "${SRC_DIR}/dashboard/index.php" "${INSTALL_DIR}/dashboard/"
cp "${SRC_DIR}/dashboard/chart.umd.min.js" "${INSTALL_DIR}/dashboard/"
chmod +x "${INSTALL_DIR}/enhance-usage-logger.sh" "${INSTALL_DIR}/enhance-usage-report.sh"

mkdir -p /var/log/enhance-usage /var/lib/enhance-usage

echo "==> Installing systemd service"
cp "${SRC_DIR}/systemd/enhance-usage-dashboard.service" /etc/systemd/system/
systemctl daemon-reload

echo
echo "=================================================================="
echo " Almost done - two manual steps required:"
echo
echo " 1) Set an admin password for the dashboard:"
echo "      php -r \"echo password_hash('YourStrongPassword', PASSWORD_BCRYPT), PHP_EOL;\""
echo "    Paste the resulting hash into:"
echo "      ${INSTALL_DIR}/dashboard/index.php  (\$ADMIN_PASSWORD_HASH)"
echo
echo " 2) Add the logger to cron (every 5 minutes):"
echo "      crontab -e"
echo "      */5 * * * * ${INSTALL_DIR}/enhance-usage-logger.sh >> /var/log/enhance-usage/cron.log 2>&1"
echo
echo " Then start the dashboard:"
echo "      systemctl enable --now enhance-usage-dashboard"
echo
echo " Dashboard will be reachable at: http://<server-ip>:8181/"
echo " SECURITY: restrict port 8181 with a firewall (ufw/iptables) to your"
echo " own IP, or put it behind an nginx HTTPS reverse proxy."
echo "=================================================================="
