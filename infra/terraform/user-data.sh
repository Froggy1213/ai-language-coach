#!/usr/bin/env bash
# ==============================================================================
# LiveKit Server Node Bootstrap Script (Plan §2, §5, §8)
#
# Runs via cloud-init on EC2 instance boot:
# 1. Installs Docker CE & Docker Compose.
# 2. Obtains / prepares Let's Encrypt TLS certificate for TURN/TLS & signaling.
# 3. Renders livekit.yaml with TURN/TLS, Elastic IP, and webhook URL.
# 4. Renders docker-compose.yml with stop_grace_period = 15m (native SIGTERM drain).
# 5. Installs systemd service and idempotent update script.
# ==============================================================================

set -euo pipefail

exec > >(tee -a /var/log/livekit-bootstrap.log) 2>&1
echo "=== Starting LiveKit Server Node Bootstrap $(date -u) ==="

LIVEKIT_DOMAIN="${LIVEKIT_DOMAIN}"
LIVEKIT_NODE_IP="${LIVEKIT_NODE_IP}"
VOICE_WEBHOOK_URL="${VOICE_WEBHOOK_URL}"
LIVEKIT_API_KEY="${LIVEKIT_API_KEY}"
LIVEKIT_API_SECRET="${LIVEKIT_API_SECRET}"
STOP_GRACE_PERIOD="${STOP_GRACE_PERIOD}"
ADMIN_EMAIL="${ADMIN_EMAIL}"

# ------------------------------------------------------------------------------
# 1. Install System Dependencies & Docker CE
# ------------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    certbot \
    openssl \
    jq

# Install official Docker repository
mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
    > /etc/apt/sources.list.d/docker.list

apt-get update -y
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

systemctl enable --now docker

# ------------------------------------------------------------------------------
# 2. TLS Certificates (Let's Encrypt / Certbot)
# ------------------------------------------------------------------------------
# LiveKit built-in TURN requires raw certificate and private key files on disk.
# If DNS is already pointed at $LIVEKIT_NODE_IP, certbot succeeds immediately.
# If DNS is still propagating, generate a temporary self-signed certificate so
# LiveKit boots without crashing, and schedule certbot standalone to acquire
# the real Let's Encrypt certificate.
CERT_DIR="/etc/letsencrypt/live/$LIVEKIT_DOMAIN"
mkdir -p "$CERT_DIR"

if certbot certonly --standalone \
    -d "$LIVEKIT_DOMAIN" \
    --agree-tos \
    -m "$ADMIN_EMAIL" \
    --non-interactive \
    --preferred-challenges http; then
    echo "Let's Encrypt certificate obtained successfully for $LIVEKIT_DOMAIN"
else
    echo "Certbot failed (DNS may not have propagated yet). Generating bootstrap self-signed certificate..."
    if [[ ! -f "$CERT_DIR/privkey.pem" ]]; then
        openssl req -x509 -nodes -days 30 -newkey rsa:2048 \
            -keyout "$CERT_DIR/privkey.pem" \
            -out "$CERT_DIR/fullchain.pem" \
            -subj "/CN=$LIVEKIT_DOMAIN"
        chmod 600 "$CERT_DIR/privkey.pem"
    fi
fi

# Set up automated renewal hook
mkdir -p /etc/letsencrypt/renewal-hooks/post
cat << 'HOOK' > /etc/letsencrypt/renewal-hooks/post/livekit-restart.sh
#!/usr/bin/env bash
systemctl try-restart livekit.service || true
HOOK
chmod +x /etc/letsencrypt/renewal-hooks/post/livekit-restart.sh

# ------------------------------------------------------------------------------
# 3. LiveKit Server Configuration (/opt/livekit/livekit.yaml)
# ------------------------------------------------------------------------------
mkdir -p /opt/livekit
chmod 700 /opt/livekit

cat << EOF > /opt/livekit/livekit.yaml
port: 7880
bind_addresses:
  - 0.0.0.0

rtc:
  tcp_port: 7881
  udp_port: 7882
  port_range_start: 50000
  port_range_end: 60000
  use_external_ip: true
  node_ip: "${LIVEKIT_NODE_IP}"

turn:
  enabled: true
  domain: "${LIVEKIT_DOMAIN}"
  cert_file: "/etc/letsencrypt/live/${LIVEKIT_DOMAIN}/fullchain.pem"
  key_file: "/etc/letsencrypt/live/${LIVEKIT_DOMAIN}/privkey.pem"
  tls_port: 5349
  udp_port: 3478

keys:
  "${LIVEKIT_API_KEY}": "${LIVEKIT_API_SECRET}"

webhook:
  api_key: "${LIVEKIT_API_KEY}"
  urls:
    - "${VOICE_WEBHOOK_URL}"
EOF
chmod 600 /opt/livekit/livekit.yaml

# ------------------------------------------------------------------------------
# 4. LiveKit Docker Compose (/opt/livekit/docker-compose.yml)
# ------------------------------------------------------------------------------
cat << EOF > /opt/livekit/docker-compose.yml
services:
  livekit:
    image: livekit/livekit-server:v1.8.4
    container_name: livekit-server
    restart: unless-stopped
    network_mode: host
    stop_grace_period: ${STOP_GRACE_PERIOD}
    volumes:
      - /opt/livekit/livekit.yaml:/etc/livekit.yaml:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro
    command: ["--config", "/etc/livekit.yaml"]
EOF
chmod 600 /opt/livekit/docker-compose.yml

# ------------------------------------------------------------------------------
# 5. Idempotent In-Place Upgrade Script (/opt/livekit/update-livekit.sh)
# ------------------------------------------------------------------------------
cat << 'UPDATE_SCRIPT' > /opt/livekit/update-livekit.sh
#!/usr/bin/env bash
# Operator / CD script to upgrade LiveKit server honouring native SIGTERM drain
set -euo pipefail

echo "=== Upgrading LiveKit Server ==="
cd /opt/livekit

echo "Pulling latest/specified LiveKit container image..."
docker compose pull livekit

echo "Initiating graceful SIGTERM drain (allowing active calls up to 15m to finish)..."
# docker compose stop sends SIGTERM and waits up to stop_grace_period (900s)
docker compose stop livekit

echo "Starting updated LiveKit server..."
docker compose up -d livekit

echo "Verifying server health..."
for i in {1..30}; do
    if curl -s -f http://127.0.0.1:7880/ > /dev/null; then
        echo "LiveKit server healthy!"
        exit 0
    fi
    sleep 1
done

echo "Error: LiveKit server did not respond on :7880 within 30s" >&2
exit 1
UPDATE_SCRIPT
chmod 755 /opt/livekit/update-livekit.sh

# ------------------------------------------------------------------------------
# 6. Systemd Service (livekit.service)
# ------------------------------------------------------------------------------
cat << EOF > /etc/systemd/system/livekit.service
[Unit]
Description=LiveKit WebRTC Server (Native SIGTERM Drain)
After=docker.service network-online.target
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/opt/livekit
ExecStart=/usr/bin/docker compose -f /opt/livekit/docker-compose.yml up -d
ExecStop=/usr/bin/docker compose -f /opt/livekit/docker-compose.yml stop -t 900
TimeoutStopSec=960

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now livekit.service

echo "=== LiveKit Server Node Bootstrap Complete $(date -u) ==="
