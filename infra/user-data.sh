#!/usr/bin/env bash
# cloud-init user-data — installs Docker, prepares /opt/lodenica, and
# fetches a stub docker-compose.prod.yml. The actual deploy is performed
# by the GitHub Actions "Deploy" workflow (or an admin manually).

set -euxo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
apt-get install -y ca-certificates curl gnupg ufw jq

# Docker engine via Docker's official apt repo (keeps `docker compose` plugin).
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release; echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -y
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

usermod -aG docker ubuntu

# Convenient swap so a 1 GB instance survives Postgres + Node + builds.
if [ ! -f /swapfile ]; then
  fallocate -l 1G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# App working dir.
install -d -o ubuntu -g ubuntu -m 0755 /opt/lodenica
cd /opt/lodenica

# Stub compose file so the box can be brought up before the first CI deploy.
cat > /opt/lodenica/docker-compose.prod.yml <<'EOF'
# Replaced by the Deploy workflow / admin on first deploy.
services:
  hello:
    image: nginxdemos/hello
    ports: ["80:80"]
EOF

# Stub env so docker-compose doesn't bark about missing variables.
cat > /opt/lodenica/.env <<EOF
GHCR_OWNER=${github_owner}
IMAGE_TAG=latest
POSTGRES_USER=lodenica
POSTGRES_PASSWORD=changeme
POSTGRES_DB=lodenica
BACKEND_LOG_LEVEL=info
BACKEND_CORS_ORIGINS=http://localhost
EOF
chown ubuntu:ubuntu /opt/lodenica/.env
chmod 600 /opt/lodenica/.env

# Bring up a placeholder so the box answers on :80 immediately.
sudo -u ubuntu docker compose -f /opt/lodenica/docker-compose.prod.yml up -d || true

# Mark cloud-init done so SSH key & docker group take effect on next login.
touch /var/log/lodenica-bootstrap-done
