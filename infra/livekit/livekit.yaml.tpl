# Reference template for /opt/livekit/livekit.yaml
# Rendered dynamically on instance boot by user-data.sh.
# Plan §2, §5, §8.

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
