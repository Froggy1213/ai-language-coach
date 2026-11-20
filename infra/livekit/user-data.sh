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
# 2. TLS Certificates & Renewal Hook (Let's Encrypt / Certbot)
# ------------------------------------------------------------------------------
# Both the Caddy HTTPS/WSS signaling proxy (:443 -> 127.0.0.1:7880) and LiveKit's
# built-in TURN/TLS server (:5349) read raw certificate files from
# /opt/livekit/certs/{fullchain,privkey}.pem (mounted at /etc/livekit-certs).
#
# A bootstrap self-signed certificate is created in /opt/livekit/certs (never in
# /etc/letsencrypt/live/$LIVEKIT_DOMAIN, which belongs to certbot) so both
# containers boot immediately even before DNS propagates. Once Caddy is serving
# /.well-known/acme-challenge/* on :80 from /var/www/certbot, certbot acquires
# the real certificate via --webroot with zero port-80 conflict during issuance
# or automated renewals.
ACTIVE_CERT_DIR="/opt/livekit/certs"
mkdir -p /opt/livekit "$ACTIVE_CERT_DIR" /var/www/certbot /opt/livekit/caddy_data /opt/livekit/caddy_config
chmod 700 /opt/livekit "$ACTIVE_CERT_DIR"

if [[ ! -f "$ACTIVE_CERT_DIR/privkey.pem" ]]; then
    openssl req -x509 -nodes -days 30 -newkey rsa:2048 \
        -keyout "$ACTIVE_CERT_DIR/privkey.pem" \
        -out "$ACTIVE_CERT_DIR/fullchain.pem" \
        -subj "/CN=$LIVEKIT_DOMAIN"
    chmod 600 "$ACTIVE_CERT_DIR/privkey.pem"
    chmod 644 "$ACTIVE_CERT_DIR/fullchain.pem"
fi

# Automated deploy hook: copies renewed certbot files into /opt/livekit/certs,
# reloads Caddy on :443 without dropping connections, and restarts LiveKit TURN.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat << EOF > /etc/letsencrypt/renewal-hooks/deploy/livekit-sync-certs.sh
#!/usr/bin/env bash
set -euo pipefail
if [[ -f "/etc/letsencrypt/live/$LIVEKIT_DOMAIN/fullchain.pem" ]]; then
    cp -L "/etc/letsencrypt/live/$LIVEKIT_DOMAIN/fullchain.pem" /opt/livekit/certs/fullchain.pem
    cp -L "/etc/letsencrypt/live/$LIVEKIT_DOMAIN/privkey.pem" /opt/livekit/certs/privkey.pem
    chmod 644 /opt/livekit/certs/fullchain.pem
    chmod 600 /opt/livekit/certs/privkey.pem
    docker exec livekit-tls-proxy caddy reload --config /etc/caddy/Caddyfile || true
    systemctl try-restart livekit.service || true
fi
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/livekit-sync-certs.sh

# ------------------------------------------------------------------------------
# 3. LiveKit Server & Caddy TLS Proxy Configuration (/opt/livekit/*)
# ------------------------------------------------------------------------------
cat << EOF > /opt/livekit/Caddyfile
{
	auto_https off
}

:80 {
	handle /.well-known/acme-challenge/* {
		root * /var/www/certbot
		file_server
	}
	handle {
		redir https://{host}{uri} permanent
	}
}

:443 {
	tls /etc/livekit-certs/fullchain.pem /etc/livekit-certs/privkey.pem
	reverse_proxy 127.0.0.1:7880
}
EOF
chmod 644 /opt/livekit/Caddyfile

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
  cert_file: "/etc/livekit-certs/fullchain.pem"
  key_file: "/etc/livekit-certs/privkey.pem"
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
# 4. LiveKit + Caddy TLS Proxy Docker Compose (/opt/livekit/docker-compose.yml)
# ------------------------------------------------------------------------------
cat << EOF > /opt/livekit/docker-compose.yml
services:
  livekit:
    image: livekit/livekit-server:v1.13.1
    container_name: livekit-server
    restart: unless-stopped
    network_mode: host
    stop_grace_period: ${STOP_GRACE_PERIOD}
    volumes:
      - /opt/livekit/livekit.yaml:/etc/livekit.yaml:ro
      - /opt/livekit/certs:/etc/livekit-certs:ro
    command: ["--config", "/etc/livekit.yaml"]

  caddy:
    image: caddy:2.10-alpine
    container_name: livekit-tls-proxy
    restart: unless-stopped
    network_mode: host
    volumes:
      - /opt/livekit/Caddyfile:/etc/caddy/Caddyfile:ro
      - /opt/livekit/certs:/etc/livekit-certs:ro
      - /var/www/certbot:/var/www/certbot:ro
      - /opt/livekit/caddy_data:/data
      - /opt/livekit/caddy_config:/config
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

# Obtain real Let's Encrypt certificate via Caddy's HTTP-01 webroot on :80 if DNS is ready
if certbot certonly --webroot \
    -w /var/www/certbot \
    -d "$LIVEKIT_DOMAIN" \
    --agree-tos \
    -m "$ADMIN_EMAIL" \
    --non-interactive \
    --deploy-hook /etc/letsencrypt/renewal-hooks/deploy/livekit-sync-certs.sh; then
    echo "Let's Encrypt certificate obtained and active for $LIVEKIT_DOMAIN"
    /etc/letsencrypt/renewal-hooks/deploy/livekit-sync-certs.sh
else
    echo "Certbot webroot challenge skipped/failed (DNS for $LIVEKIT_DOMAIN may not point to $LIVEKIT_NODE_IP yet)."
    echo "Running on bootstrap self-signed certificate until certbot is run."
fi

echo "=== LiveKit Server Node Bootstrap Complete $(date -u) ==="
