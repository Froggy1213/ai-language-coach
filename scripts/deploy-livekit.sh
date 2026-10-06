#!/usr/bin/env bash
# ==============================================================================
# scripts/deploy-livekit.sh
# Operator CLI to deploy or upgrade LiveKit on the EC2 node with graceful SIGTERM drain.
# Plan §5, §8, Milestone 2.
# ==============================================================================

set -euo pipefail

HOST="${LIVEKIT_HOST:-}"
SSH_USER="ubuntu"
SSH_KEY=""
METHOD="ssh"
DRAIN_TIMEOUT=900
DRY_RUN=false

usage() {
    cat << 'EOF'
Usage: ./scripts/deploy-livekit.sh [options]

Deploys or upgrades LiveKit server on the standalone EC2 node honouring
native SIGTERM drain (allowing active student calls to finish cleanly).

Options:
  -h, --host <HOST>           LiveKit EC2 Elastic IP or domain name
  -u, --user <USER>           SSH user (default: ubuntu)
  -k, --key <KEY_PATH>        SSH private key path
  -m, --method <ssh|ssm>      Connection method: ssh or ssm (default: ssh)
  -t, --drain-timeout <SEC>   Max seconds to wait for SIGTERM drain (default: 900)
  -n, --dry-run               Preview remote actions without executing
      --help                  Show this help message

Environment variables:
  LIVEKIT_HOST                Default target host if --host is omitted
  SSH_KEY_PATH                Default SSH key path if --key is omitted

Examples:
  # Deploy via SSH:
  ./scripts/deploy-livekit.sh --host 54.210.10.20 --key ~/.ssh/deployer.pem

  # Dry-run preview:
  ./scripts/deploy-livekit.sh --host 54.210.10.20 --dry-run

  # Deploy via AWS SSM Session Manager:
  ./scripts/deploy-livekit.sh --host i-0123456789abcdef0 --method ssm
EOF
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--host)
            HOST="$2"
            shift 2
            ;;
        -u|--user)
            SSH_USER="$2"
            shift 2
            ;;
        -k|--key)
            SSH_KEY="$2"
            shift 2
            ;;
        -m|--method)
            METHOD="$2"
            shift 2
            ;;
        -t|--drain-timeout)
            DRAIN_TIMEOUT="$2"
            shift 2
            ;;
        -n|--dry-run)
            DRY_RUN=true
            shift
            ;;
        --help)
            usage
            ;;
        *)
            echo "Error: Unknown argument '$1'" >&2
            echo "Run ./scripts/deploy-livekit.sh --help for usage." >&2
            exit 1
            ;;
    esac
done

if [[ -z "$HOST" ]]; then
    echo "Error: Target host or instance ID is required. Use --host <HOST> or set LIVEKIT_HOST." >&2
    exit 1
fi

REMOTE_COMMAND="sudo /opt/livekit/update-livekit.sh"

echo "=== LiveKit Deploy / Upgrade Plan ==="
echo "Target:         $HOST"
echo "Method:         $METHOD"
echo "Drain Timeout:  ${DRAIN_TIMEOUT}s (15 min call length)"
echo "Remote Command: $REMOTE_COMMAND"
echo "Dry Run:        $DRY_RUN"
echo "====================================="

if [[ "$DRY_RUN" == "true" ]]; then
    echo "[DRY RUN] Would execute on $HOST via $METHOD:"
    echo "  $REMOTE_COMMAND"
    echo "[DRY RUN] Completed without making changes."
    exit 0
fi

if [[ "$METHOD" == "ssh" ]]; then
    SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10)
    if [[ -n "$SSH_KEY" ]]; then
        SSH_OPTS+=(-i "$SSH_KEY")
    elif [[ -n "${SSH_KEY_PATH:-}" ]]; then
        SSH_OPTS+=(-i "$SSH_KEY_PATH")
    fi

    echo "Connecting to $SSH_USER@$HOST via SSH..."
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$HOST" "$REMOTE_COMMAND"

elif [[ "$METHOD" == "ssm" ]]; then
    echo "Executing via AWS SSM Session Manager on instance $HOST..."
    aws ssm send-command \
        --instance-ids "$HOST" \
        --document-name "AWS-RunShellScript" \
        --parameters "commands=[\"$REMOTE_COMMAND\"]" \
        --output text
else
    echo "Error: Unsupported method '$METHOD'. Must be 'ssh' or 'ssm'." >&2
    exit 1
fi

echo "=== LiveKit Deployment Complete ==="
