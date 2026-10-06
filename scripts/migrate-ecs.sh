#!/usr/bin/env bash
# ==============================================================================
# scripts/migrate-ecs.sh
# Operator CLI to run one-off database migrations on ECS Fargate.
# Plan §8, Milestone 2.
# ==============================================================================

set -euo pipefail

CLUSTER="${ECS_CLUSTER:-ai-language-coach-cluster}"
TASK_DEF="${TASK_DEF:-ai-language-coach-migration}"
SUBNETS=""
SECURITY_GROUP=""
DRY_RUN=false

usage() {
    cat << 'EOF'
Usage: ./scripts/migrate-ecs.sh [options]

Executes `php artisan migrate --force` followed by `php artisan lighthouse:clear-cache`
as a standalone one-off ECS Fargate task, streams task logs, waits for task
termination, and verifies exit code 0.

The cache clear is not decoration: Lighthouse caches the parsed schema in the
cache store, so a deploy that changes the SDL without clearing it leaves the new
API answering queries with the previous schema — which surfaces as a field being
"unknown" in a browser whose client already asks for it.

Options:
  -c, --cluster <CLUSTER>      ECS cluster name (default: ai-language-coach-cluster)
  -t, --task-def <TASK_DEF>    Task definition family or ARN (default: ai-language-coach-migration)
  -s, --subnets <SUBNET_IDS>   Comma-separated public subnet IDs (e.g. subnet-abc,subnet-def)
  -g, --security-group <SG_ID> Security group ID for migration task
  -n, --dry-run                Preview the aws ecs run-task command without executing
      --help                   Show this help message

Environment variables:
  ECS_CLUSTER                  Default cluster name
  TASK_DEF                     Default task definition name

Examples:
  ./scripts/migrate-ecs.sh --subnets subnet-01,subnet-02 --security-group sg-01
  ./scripts/migrate-ecs.sh --dry-run
EOF
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -c|--cluster)
            CLUSTER="$2"
            shift 2
            ;;
        -t|--task-def)
            TASK_DEF="$2"
            shift 2
            ;;
        -s|--subnets)
            SUBNETS="$2"
            shift 2
            ;;
        -g|--security-group)
            SECURITY_GROUP="$2"
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
            exit 1
            ;;
    esac
done

echo "=== ECS Fargate Database Migration ==="
echo "Cluster:         $CLUSTER"
echo "Task Definition: $TASK_DEF"
echo "Subnets:         ${SUBNETS:-auto-discover}"
echo "Security Group:  ${SECURITY_GROUP:-auto-discover}"
echo "Dry Run:         $DRY_RUN"
echo "======================================="

# Auto-discover network configuration if not explicitly provided
if [[ -z "$SUBNETS" || -z "$SECURITY_GROUP" ]]; then
    if command -v aws &> /dev/null && [[ "$DRY_RUN" == "false" ]]; then
        echo "Discovering network configuration from existing laravel-app service..."
        SVC_JSON=$(aws ecs describe-services --cluster "$CLUSTER" --services laravel-app --query "services[0].networkConfiguration.awsvpcConfiguration" --output json 2>/dev/null || echo "{}")
        if [[ -z "$SUBNETS" ]]; then
            SUBNETS=$(echo "$SVC_JSON" | jq -r '.subnets | join(",")')
        fi
        if [[ -z "$SECURITY_GROUP" ]]; then
            SECURITY_GROUP=$(echo "$SVC_JSON" | jq -r '.securityGroups[0]')
        fi
    fi
fi

# The container is named `migration` in the task definition (infra/terraform/ecs.tf).
# `lighthouse:clear-cache` runs after a successful migration, never before it: a
# failed migration must leave the running deploy's schema cache untouched.
CONTAINER_OVERRIDES='{"containerOverrides":[{"name":"migration","command":["sh","-lc","php artisan migrate --force && php artisan lighthouse:clear-cache"]}]}'

RUN_CMD=(
    aws ecs run-task
    --cluster "$CLUSTER"
    --task-definition "$TASK_DEF"
    --launch-type FARGATE
    --overrides "$CONTAINER_OVERRIDES"
    --network-configuration "awsvpcConfiguration={subnets=[${SUBNETS:-subnet-placeholder}],securityGroups=[${SECURITY_GROUP:-sg-placeholder}],assignPublicIp=ENABLED}"
)

if [[ "$DRY_RUN" == "true" ]]; then
    echo "[DRY RUN] Would execute:"
    echo "  ${RUN_CMD[*]}"
    exit 0
fi

echo "Launching migration task..."
TASK_ARN=$("${RUN_CMD[@]}" --query "tasks[0].taskArn" --output text)
echo "Launched task: $TASK_ARN"

echo "Waiting for migration task to complete..."
aws ecs wait tasks-stopped --cluster "$CLUSTER" --tasks "$TASK_ARN"

DESCRIBE_JSON=$(aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN" --query "tasks[0]" --output json)
EXIT_CODE=$(echo "$DESCRIBE_JSON" | jq -r '.containers[0].exitCode')
STOP_REASON=$(echo "$DESCRIBE_JSON" | jq -r '.stoppedReason // "None"')

if [[ "$EXIT_CODE" == "0" ]]; then
    echo "=== Migrations executed successfully (exit code 0) ==="
    exit 0
else
    echo "Error: Migration failed with exit code $EXIT_CODE (Reason: $STOP_REASON)" >&2
    exit 1
fi
