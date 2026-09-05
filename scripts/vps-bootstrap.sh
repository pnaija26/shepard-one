#!/usr/bin/env bash
# One-time VPS bootstrap for ShepardOne Docker deploys (dev + prod).
# Supports AlmaLinux / RHEL / Rocky (dnf) and Ubuntu / Debian (apt).
# Run as root (or with sudo) on a fresh VPS.
set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-deploy}"
PROD_PATH="${PROD_PATH:-/var/www/prod}"
DEV_PATH="${DEV_PATH:-/var/www/dev}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script as root (sudo -i, then ./vps-bootstrap.sh)" >&2
  exit 1
fi

. /etc/os-release
ID_LIKE="${ID_LIKE:-}"
echo "==> Detected ${PRETTY_NAME:-$ID}"

is_rhel_family() {
  [[ "$ID" == "almalinux" || "$ID" == "rocky" || "$ID" == "rhel" || "$ID" == "centos" ]] \
    || [[ "$ID_LIKE" == *"rhel"* ]] || [[ "$ID_LIKE" == *"fedora"* ]]
}

is_debian_family() {
  [[ "$ID" == "ubuntu" || "$ID" == "debian" ]] || [[ "$ID_LIKE" == *"debian"* ]]
}

install_docker_rhel() {
  echo "==> Installing Docker Engine (AlmaLinux / RHEL family)"
  dnf -y install dnf-plugins-core curl ca-certificates
  if [[ ! -f /etc/yum.repos.d/docker-ce.repo ]]; then
    dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
  fi
  dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
}

install_docker_debian() {
  echo "==> Installing Docker Engine (Ubuntu / Debian)"
  apt-get update -y
  apt-get install -y ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  if [[ ! -f /etc/apt/keyrings/docker.asc ]]; then
    curl -fsSL https://download.docker.com/linux/${ID}/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
  fi
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
}

open_firewall() {
  echo "==> Opening SSH + HTTP/HTTPS"
  if command -v firewall-cmd >/dev/null 2>&1; then
    systemctl enable --now firewalld >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-service=ssh
    firewall-cmd --permanent --add-service=http
    firewall-cmd --permanent --add-service=https
    firewall-cmd --reload
  elif command -v ufw >/dev/null 2>&1; then
    ufw allow OpenSSH
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw --force enable || true
  else
    echo "    No firewalld/ufw found; skip (open 22/80/443 in your provider panel)"
  fi
}

if is_rhel_family; then
  install_docker_rhel
elif is_debian_family; then
  install_docker_debian
else
  echo "Unsupported OS: ${ID}. Install Docker Engine + Compose plugin manually, then re-run." >&2
  exit 1
fi

echo "==> Creating deploy user"
if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
  useradd -m -s /bin/bash "${DEPLOY_USER}"
fi
usermod -aG docker "${DEPLOY_USER}"

echo "==> Preparing ${PROD_PATH} and ${DEV_PATH}"
mkdir -p "${PROD_PATH}/scripts" "${DEV_PATH}/scripts"
# Prefer deploy ownership — do not leave these as root after bootstrap
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" /var/www/prod /var/www/dev
# Ensure parent is traversable
chmod 755 /var/www
chmod 755 "${PROD_PATH}" "${DEV_PATH}"

if [[ -S /var/run/docker.sock ]]; then
  chmod 660 /var/run/docker.sock || true
fi

open_firewall

echo "==> Docker status"
docker --version
docker compose version
systemctl is-active docker

echo "==> Done"
echo
echo "Layout:"
echo "  ${PROD_PATH}  → production  (HTTP_PORT=127.0.0.1:8080, COMPOSE_PROJECT_NAME=shepardone-prod)"
echo "  ${DEV_PATH}   → staging/dev (HTTP_PORT=127.0.0.1:8081, COMPOSE_PROJECT_NAME=shepardone-dev)"
echo
echo "Next:"
echo "  1. As ${DEPLOY_USER}, put docker-compose.yml + scripts/deploy.sh in each dir"
echo "  2. Create ${PROD_PATH}/.env.production and ${DEV_PATH}/.env.dev (different APP_KEY + DB passwords)"
echo "  3. Add SSH key for GitHub Actions to ~${DEPLOY_USER}/.ssh/authorized_keys"
echo "  4. Set secrets: VPS_HOST, VPS_USER=${DEPLOY_USER}, VPS_SSH_KEY,"
echo "     VPS_PROD_PATH=${PROD_PATH}, VPS_DEV_PATH=${DEV_PATH}"
echo "  5. Install host nginx + certbot and proxy both hostnames (see docs/deployment.md)"
echo
echo "Note: if you previously ran chown as root with \$USER, re-run:"
echo "  chown -R ${DEPLOY_USER}:${DEPLOY_USER} ${PROD_PATH} ${DEV_PATH}"
