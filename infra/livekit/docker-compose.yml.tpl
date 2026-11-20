# Reference template for /opt/livekit/docker-compose.yml
# Rendered dynamically on instance boot by user-data.sh.
# Plan §5, §8.
#
# stop_grace_period is set to 15m (900s) to honour native SIGTERM drain:
# LiveKit stops accepting new rooms, allows existing active student calls to finish,
# and shuts down gracefully without dropping audio mid-conversation.

services:
  livekit:
    image: livekit/livekit-server:v1.13.1
    container_name: livekit-server
    restart: unless-stopped
    network_mode: host
    stop_grace_period: ${STOP_GRACE_PERIOD:-15m}
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
