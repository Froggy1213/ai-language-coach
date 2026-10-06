# Milestone 2: AWS Infrastructure Runbook

This directory contains the production Infrastructure-as-Code (Terraform) and deployment automation for **AI Language Coach** on AWS, implementing **Milestone 2** per [`ai-language-coach-plan.md`](../ai-language-coach-plan.md) §8.

---

## 1. Architecture Overview

```
                      Internet / Learners
                    ┌──────────┴──────────┐
      HTTPS / WSS   │                     │ WebRTC (Direct ICE / TURN/TLS)
                    ▼                     ▼
        [ Application Load Balancer ]    [ LiveKit Server — EC2 + Elastic IP ]
              (ACM TLS Termination)       │ (Let's Encrypt TLS on instance, 15m SIGTERM drain)
                    │                     │
       ┌────────────┼────────────┐        │ Twirp Dispatches / Webhooks
       │            │            │        ▼
       ▼            ▼            ▼   [ Voice Agent Fleet — ECS Fargate ]
   [ Nuxt 4 ]  [ Laravel ]   [ Reverb ]  (Python livekit-agents, Public Subnet, no NAT)
   (:3000)      (:8080)       (:8081)     │  num_idle_processes=4, load_threshold=0.7
       │            │                     ├─► Deepgram (STT) / Cartesia (TTS) / LLM
       │            ├─────────────────────┼─► CloudWatch (P95 TURN_LATENCY)
       │            │                     └─► Webhook room_finished ──► Horizon job
       │            ├─► RDS MySQL 8.0 (Private)
       │            └─► ElastiCache Redis 7.0 (Private)
       ▼
   [ S3 Storage Bucket ]
   (Presigned audio uploads + transcript archives)
```

### Architectural Constraints (Plan §0, §8)
- **No NAT Gateway in V1**: Fargate worker services (`horizon-worker`, `reverb`, and `voice-agent-worker`) run in public subnets with public IPs and restrictive security groups. This saves ~$32.40/month in base gateway hourly charges plus $0.045/GB data processing fees.
- **Single LiveKit EC2 Node**: Self-hosted on an EC2 instance with an Elastic IP and native SIGTERM drain (`stop_grace_period: 15m`). No multi-node clustering or HA in V1.
- **ALB Placement**: The ALB and ACM certificate terminate traffic **only** for `web` (Nuxt), `laravel-app` (API), and `reverb` (WebSockets). WebRTC media and TURN/TLS never pass through the ALB.
- **Single Reverb Instance**: 1 replica with Lighthouse subscription state maintained in private Redis.
- **Private Data Layer**: RDS MySQL 8.0 and ElastiCache Redis 7.0 reside in isolated private subnets with no public IPs and ingress restricted to authorized ECS security groups.

---

## 2. Directory Structure

```
infra/
├── README.md                  # This runbook
├── terraform/                 # Pinned Terraform code
│   ├── versions.tf            # Terraform >= 1.9, AWS provider ~> 5.50
│   ├── backend.tf             # Commented S3+DynamoDB remote state instructions
│   ├── variables.tf           # Configurable inputs with documented defaults
│   ├── terraform.tfvars.example # Example variable values
│   ├── outputs.tf             # ALB DNS, ECR URLs, LiveKit IP, S3 bucket name
│   ├── vpc.tf                 # VPC, 2 AZs, public/private subnets, IGW, S3 endpoint
│   ├── security_groups.tf     # Least-privilege SGs decoupled with sg_rules
│   ├── alb.tf                 # ALB, target groups, HTTPS listener, WSS routing
│   ├── ecs.tf                 # Fargate cluster, 5 services, task definitions, autoscaling
│   ├── ecr.tf                 # ECR repos for api, web, and agent with lifecycle policies
│   ├── rds.tf                 # MySQL 8.0 with utf8mb4 parameter group, KMS encryption
│   ├── elasticache.tf         # Redis 7.0 for Horizon queues, cache, and Lighthouse
│   ├── s3.tf                  # Storage bucket, CORS for presigned POST, lifecycle rules
│   ├── secrets.tf             # Secrets Manager entries and IAM policies
│   ├── livekit.tf             # EC2 instance, Elastic IP, and cloud-init rendering
│   ├── cloudwatch.tf          # Log groups, metric filters, P95 alarms, dashboard
│   └── budgets.tf             # AWS Budgets with documented voice minute cost model
└── livekit/                   # LiveKit server host artifacts
    ├── user-data.sh           # Cloud-init script: Docker, certbot, livekit.yaml, drain
    ├── livekit.yaml.tpl       # Reference configuration template
    ├── docker-compose.yml.tpl # Reference compose template with 15m stop_grace_period
    └── update-livekit.sh      # On-node idempotent zero-drop upgrade script
```

---

## 3. Prerequisites

Before provisioning infrastructure, verify that you have:
1. **AWS Account & IAM Permissions**: Administrative access or equivalent permissions to provision VPC, EC2, ECS, RDS, ElastiCache, S3, Secrets Manager, CloudWatch, and IAM roles.
2. **Domain Names**:
   - Application domain (e.g., `coach.example.com`) pointing to the ALB.
   - LiveKit domain (e.g., `livekit.example.com`) pointing to the LiveKit Elastic IP.
3. **ACM Certificate**:
   - An ACM certificate in your deployment region covering `coach.example.com`. If not pre-created, Terraform will declare an ACM certificate resource that you can validate via DNS.
4. **Third-Party API Keys**:
   - Deepgram API key (STT)
   - DeepSeek API key (Mistake analysis and CEFR assessment)
   - Cartesia API key (TTS, optional fallback)
   - Sentry DSN (Application error tracking)
5. **GitHub OIDC Role**:
   - An IAM role with trust policy allowing `token.actions.githubusercontent.com` to assume the deployment role for GitHub Actions CD.
   - Then arm the workflow: set the repository secret `AWS_ROLE_TO_ASSUME` to that role's ARN and the repository variable `AWS_DEPLOY_ENABLED=true`.
     **Until the variable is set, CD deliberately does nothing**: a push to `main` runs the
     guard job, reports «deployment skipped» in the step summary and exits green. Without
     that guard a repository with no AWS account would show a red build on every commit —
     a failure that says nothing about the commit. `workflow_dispatch` lets an operator
     trigger the same pipeline by hand once it is armed.

---

## 4. Bootstrap Order (Step-by-Step)

Follow this sequence when standing up the environment for the first time:

### Step 1: Bootstrap Remote State Storage (Manual)
Run these commands once in your AWS account to prepare the S3 bucket and DynamoDB lock table:

```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
REGION="us-east-1"

# 1. Create S3 state bucket with versioning and AES256 encryption
aws s3api create-bucket --bucket "ai-language-coach-terraform-state-${ACCOUNT_ID}" --region "${REGION}"
aws s3api put-bucket-versioning --bucket "ai-language-coach-terraform-state-${ACCOUNT_ID}" --versioning-configuration Status=Enabled
aws s3api put-bucket-encryption --bucket "ai-language-coach-terraform-state-${ACCOUNT_ID}" \
  --server-side-encryption-configuration '{"Rules": [{"ApplyServerSideEncryptionByDefault": {"SSEAlgorithm": "AES256"}}]}'
aws s3api put-public-access-block --bucket "ai-language-coach-terraform-state-${ACCOUNT_ID}" \
  --public-access-block-configuration '{"BlockPublicAcls": true, "IgnorePublicAcls": true, "BlockPublicPolicy": true, "RestrictPublicBuckets": true}'

# 2. Create DynamoDB lock table
aws dynamodb create-table \
  --table-name "ai-language-coach-terraform-locks" \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --region "${REGION}"
```

Uncomment the `backend "s3"` block in `infra/terraform/backend.tf` and fill in your account ID.

### Step 2: Configure Variables & Apply Terraform
```bash
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars
# Edit terraform.tfvars with your domains, budget emails, and CIDRs

terraform init
terraform plan -out=tfplan
terraform apply tfplan
```

### Step 3: Populate Secrets in AWS Secrets Manager
Terraform provisions `ai-language-coach/app-secrets` with generated passwords for MySQL, LiveKit, Reverb, and the internal secret. Update external vendor keys:

```bash
aws secretsmanager update-secret \
  --secret-id "ai-language-coach/app-secrets" \
  --secret-string file://my-production-secrets.json
```

### Step 4: Build & Push Initial Container Images to ECR
```bash
REGISTRY=$(terraform output -json ecr_repository_urls | jq -r .api | cut -d'/' -f1)
aws ecr get-login-password --region us-east-1 | docker login --username AWS --password-stdin "$REGISTRY"

# Build and push API image
docker build -t "$REGISTRY/ai-language-coach-api:latest" ./backend
docker push "$REGISTRY/ai-language-coach-api:latest"

# Build and push Web image
docker build -t "$REGISTRY/ai-language-coach-web:latest" ./frontend
docker push "$REGISTRY/ai-language-coach-web:latest"

# Build and push Agent image
docker build -t "$REGISTRY/ai-language-coach-agent:latest" ./agent
docker push "$REGISTRY/ai-language-coach-agent:latest"
```

### Step 5: Execute Initial Database Migrations
Run the one-off migration task using the companion script:
```bash
./scripts/migrate-ecs.sh \
  --cluster "ai-language-coach-cluster" \
  --task-def "ai-language-coach-migration"
```

### Step 6: Configure DNS Records
Configure DNS records at your registrar or Route 53:
1. `coach.example.com` -> CNAME to ALB DNS name (`terraform output alb_dns_name`).
2. `livekit.example.com` -> A record pointing directly to the Elastic IP (`terraform output livekit_public_ip`).

### Step 7: Issue LiveKit Let's Encrypt Certificate
Once DNS for `livekit.example.com` resolves to the Elastic IP, SSH into the LiveKit node and run certbot:
```bash
ssh -i ~/.ssh/deployer.pem ubuntu@<LIVEKIT_PUBLIC_IP>
sudo certbot certonly --standalone -d livekit.example.com --non-interactive --agree-tos -m ops@example.com
sudo systemctl restart livekit.service
```

### Step 8: Run Post-Deploy Smoke Verification
```bash
./scripts/smoke-test.sh \
  --app-url "https://coach.example.com" \
  --livekit "livekit.example.com"
```

---

## 5. Post-Deployment Verification (Mapped to Plan §7 Checklist)

| Checklist Item | Implementation & Verification Method |
|---|---|
| **Live webhook signature & idempotency** | Send test webhook to `https://coach.example.com/api/webhooks/livekit`. Unsigned request must return HTTP 401 (verified by `smoke-test.sh`). Duplicate signed `room_finished` events return `200 {applied: false}`. |
| **P95 latency in CloudWatch & Dashboard** | Verify `ai-language-coach-operations` dashboard in CloudWatch. The metric filter extracts `TurnaroundLatencyMs` directly from `TURN_LATENCY` stdout lines. Alarm `ai-language-coach-voice-turn-p95-latency-high` is armed. |
| **Sentry error tracking** | Trigger test exception in staging/health route. Confirm event arrives in Sentry for both Laravel (`sentry/sentry-laravel`) and Python agent (`sentry_sdk`). |
| **AWS Budgets alert** | Verify budget `ai-language-coach-monthly-budget` in AWS Billing Console. Confirm notification recipients receive the confirmation email. |
| **Privacy & deletion-by-request** | S3 lifecycle rule `RawAudioCleanup` automatically expires files under `assessments/` after 1 day. `TranscriptsArchiveRetention` tiers transcripts after 30 days and expires after 90 days. User deletion cascades to voice sessions and removes S3 keys. |

### Telemetry Log Shape (Emitted by Agent)
CloudWatch Log Metric Filter `ai-language-coach-turnaround-latency` consumes the following structured log pattern emitted by `agent/agent.py`:
```text
2026-10-06 12:00:00,000 [INFO] ai-language-coach-agent: TURN_LATENCY {"event": "voice_turn_latency", "session_id": 12, "turn_id": "speech_abc", "stt_final_ms": 280.0, "llm_first_token_ms": 320.0, "tts_first_chunk_ms": 210.0, "total_turnaround_ms": 810.0, "transcript": "I have lived here since three years."}
```
**Metric Filter Expression**: `[..., marker = "TURN_LATENCY", payload]` extracts `$payload.total_turnaround_ms` into `AiLanguageCoach/Voice:TurnaroundLatencyMs`.

---

## 6. Rollback Procedures

### ECS Fargate Services Rollback
To roll back an ECS service to the previous deployment:
```bash
aws ecs update-service \
  --cluster ai-language-coach-cluster \
  --service laravel-app \
  --task-definition ai-language-coach-laravel-app:<PREVIOUS_REVISION>
```

### LiveKit Server Rollback
Pin the previous image tag in `/opt/livekit/docker-compose.yml` on the EC2 instance and trigger the drain:
```bash
./scripts/deploy-livekit.sh --host <LIVEKIT_HOST> --key ~/.ssh/deployer.pem
```

---

## 7. Cost Model & Estimates

### Variable Dialogue Cost (per Active Voice Minute)
| Component | Provider / Resource | Unit Cost | Cost per Minute |
|---|---|---|---|
| **STT** | Deepgram Nova-2 streaming | $0.0043 / min | $0.00430 |
| **TTS** | Cartesia Sonic streaming | $0.075 / 1k chars (~500 chars/min) | $0.00375 |
| **Dialogue LLM** | DeepSeek-V3 conversational | ~$0.20 / 1M tokens (~400 tok/min) | $0.00008 |
| **Async Analysis** | DeepSeek-V3 post-room batch | ~$0.0005 / session (~10 min call) | $0.00005 |
| **LiveKit EC2** | `t4g.medium` amortized capacity | $0.0336 / hour / concurrent slots | $0.00056 |
| **Voice Agent Fargate** | 1 vCPU, 2GB task (3 calls/task) | $0.048 / hour / 3 slots | $0.00026 |
| **Egress & Storage** | AWS S3 + Data Transfer | ~$0.09 / GB media data | $0.00010 |
| **Total Variable** | | | **~$0.0091 / active min** |

### Fixed Monthly Baseline (AWS Infrastructure)
| Resource | Specification | Estimated Monthly Cost |
|---|---|---|
| **RDS MySQL 8.0** | `db.t4g.small`, 20GB gp3 storage | ~$26.00 |
| **ElastiCache Redis** | `cache.t4g.micro`, 1 node | ~$12.50 |
| **LiveKit EC2** | `t4g.medium`, 30GB gp3, Elastic IP | ~$27.00 |
| **Application Load Balancer** | 1 ALB, ~1 LCU baseline | ~$18.00 |
| **ECS Fargate Baseline** | 2x laravel-app, 1x horizon, 1x reverb, 1x agent | ~$35.00 |
| **Total Fixed Baseline** | | **~$118.50 / month** |

*Note: Omitting the NAT Gateway in V1 saves ~$32.40/month baseline plus $0.045/GB data processing fees.*

---

## 8. Manual vs Automated Matrix

| Task | Automation Tool | Manual Action Required |
|---|---|---|
| State bucket & DynamoDB creation | One-time AWS CLI commands | Execute once per AWS account before `terraform init` |
| VPC, Subnets, Security Groups | `terraform apply` | None |
| RDS MySQL & ElastiCache Redis | `terraform apply` | None |
| ECR Repositories | `terraform apply` | None |
| Secrets Manager structure | `terraform apply` | Update vendor API keys (Deepgram, DeepSeek, Cartesia) |
| Container Image Building | GitHub Actions CD (`cd.yml`) | None (runs on merge to `main`) |
| ECS Database Migrations | GitHub Actions CD (`scripts/migrate-ecs.sh`) | None |
| ECS Service Rolling Deployment | GitHub Actions CD (`aws ecs update-service`) | Approval in GitHub Environment |
| LiveKit Server Setup | Cloud-init (`user-data.sh`) | Ensure DNS resolves before final Let's Encrypt renewal |
| LiveKit Server Upgrade | GitHub Actions CD (`scripts/deploy-livekit.sh`) | Trigger via CD workflow or manual CLI |
| DNS Record Configuration | Registrar / Route 53 | Create CNAME for ALB and A record for LiveKit EIP |
| Post-deploy Verification | Automated script (`scripts/smoke-test.sh`) | Review CloudWatch operational dashboard |

---

## 9. Honest V1 Limitations & Technical Debt

1. **No NAT Gateway**: Fargate tasks requiring external connectivity run in public subnets with public IPs. Security relies entirely on security group isolation. In V2 with enterprise compliance requirements, migrate tasks to private subnets behind a NAT Gateway or VPC Endpoints.
2. **Single LiveKit EC2 Node**: There is no high availability or auto-scaling for WebRTC media. If the EC2 instance terminates, ongoing voice sessions drop and voice sessions are unavailable until the instance recovers. Web and GraphQL functionality continue uninterrupted.
3. **Single Reverb Instance**: Reverb runs as a single instance. Scaled clustering via Redis multi-server broadcasting is supported by Reverb out of the box but is not deployed in V1.
4. **Single RDS MySQL Instance**: No read replicas or Multi-AZ failover in V1 to keep monthly fixed costs under $120. Automated daily snapshots provide recovery.
