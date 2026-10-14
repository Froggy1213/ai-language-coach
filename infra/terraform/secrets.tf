# Secrets Manager Configuration (Plan §7, §8)
#
# ==============================================================================
# DOCUMENTED SECRETS INVENTORY & HOW ECS CONSUMES THEM:
# ==============================================================================
#
# Secret: "${var.name_prefix}/app-secrets" (JSON object stored in AWS Secrets Manager)
#
# Key                      Purpose                                          Consumed By
# ------------------------ ------------------------------------------------ ------------------------------
# APP_KEY                  Laravel AES-256 session & cookie encryption      laravel-app, horizon, reverb
# DB_PASSWORD              MySQL database user password                     laravel-app, horizon
# REVERB_APP_ID            Reverb application identifier                    laravel-app, reverb, web
# REVERB_APP_KEY           Reverb client authorization key                  laravel-app, reverb, web
# REVERB_APP_SECRET        Reverb broadcast HMAC secret                     laravel-app, reverb
# LIVEKIT_API_KEY          LiveKit Twirp/access token signing key (>=32b)   laravel-app, voice-agent, LiveKit
# LIVEKIT_API_SECRET       LiveKit token signing secret (decision 25, >=32c)laravel-app, voice-agent, LiveKit
# VOICE_INTERNAL_SECRET    Shared secret for /api/internal/sessions/*       laravel-app, voice-agent
# DEEPGRAM_API_KEY         Deepgram Nova-3 streaming & batch STT            horizon-worker, voice-agent
# DEEPSEEK_API_KEY         DeepSeek LLM for CEFR and mistake analysis       horizon-worker, voice-agent
# GROQ_API_KEY             Fast dialogue LLM (chosen by latency benchmark)  voice-agent-worker
# CEREBRAS_API_KEY         Alternative fast dialogue LLM                    voice-agent-worker
# CARTESIA_API_KEY         Cartesia Sonic streaming TTS                     voice-agent-worker
# OPENAI_API_KEY           Fallback conversational LLM and TTS              voice-agent-worker
# SENTRY_DSN               Sentry error telemetry (Laravel & Python)        all services
#
# ECS Consumption Pattern:
# Fargate task definitions inject each secret via `valueFrom: "${secret_arn}:<KEY>::"`.
# The ECS Task Execution Role is granted `secretsmanager:GetSecretValue` on this ARN.
# No secret is ever stored in plaintext inside the task definition or git.
# ==============================================================================

# ==============================================================================
#
# ADDING A KEY TO AN EXISTING ENVIRONMENT:
#
# `aws_secretsmanager_secret_version.app_secrets` has `ignore_changes =
# [secret_string]` so that Terraform never overwrites keys populated by hand
# after bootstrap. The consequence is the reverse of the usual one: adding a key
# to the map below does NOT add it to an environment that already exists, while
# the task definition that references it does change — and ECS fails the task at
# start with a ResourceInitializationError naming the missing key.
#
# So when a key is added here, the same key must be written into the live secret
# before (or with) the apply:
#
#   aws secretsmanager get-secret-value --secret-id <name_prefix>/app-secrets \
#     --query SecretString --output text > /tmp/app-secrets.json
#   # add the new key with a real value, then:
#   aws secretsmanager put-secret-value --secret-id <name_prefix>/app-secrets \
#     --secret-string file:///tmp/app-secrets.json
#
# GROQ_API_KEY and CEREBRAS_API_KEY are the current example: the voice-agent task
# definition references both, so a deployment that has not added them will not
# start.
# ==============================================================================

resource "random_id" "app_key_bytes" {
  byte_length = 32
}

resource "random_string" "reverb_app_id" {
  length  = 8
  numeric = true
  special = false
  upper   = false
}

resource "random_string" "reverb_app_key" {
  length  = 20
  special = false
}

resource "random_password" "reverb_app_secret" {
  length  = 32
  special = false
}

resource "random_string" "livekit_api_key" {
  length  = 16
  special = false
}

# LiveKit secret must be at least 32 characters (Plan §5, decision 25)
resource "random_password" "livekit_api_secret" {
  length  = 48
  special = false
}

# Shared secret for internal API endpoints must be at least 32 characters (Plan §5, decision 24 & 29)
resource "random_password" "voice_internal_secret" {
  length  = 48
  special = false
}

resource "aws_secretsmanager_secret" "app_secrets" {
  name                    = "${var.name_prefix}/app-secrets"
  description             = "Production application credentials for AI Language Coach services"
  recovery_window_in_days = 0 # Immediate deletion on destroy for test teardowns

  tags = {
    Name = "${var.name_prefix}-app-secrets"
  }
}

resource "aws_secretsmanager_secret_version" "app_secrets" {
  secret_id = aws_secretsmanager_secret.app_secrets.id

  secret_string = jsonencode({
    APP_KEY               = "base64:${random_id.app_key_bytes.b64_std}"
    DB_PASSWORD           = random_password.db_password.result
    REVERB_APP_ID         = random_string.reverb_app_id.result
    REVERB_APP_KEY        = random_string.reverb_app_key.result
    REVERB_APP_SECRET     = random_password.reverb_app_secret.result
    LIVEKIT_API_KEY       = random_string.livekit_api_key.result
    LIVEKIT_API_SECRET    = random_password.livekit_api_secret.result
    VOICE_INTERNAL_SECRET = random_password.voice_internal_secret.result
    DEEPGRAM_API_KEY      = "placeholder-replace-with-real-deepgram-key"
    DEEPSEEK_API_KEY      = "placeholder-replace-with-real-deepseek-key"
    CARTESIA_API_KEY      = "placeholder-replace-with-real-cartesia-key"
    OPENAI_API_KEY        = ""
    # The dialogue runs on one of these: the benchmark in benchmarks/README.md
    # disqualifies DeepSeek for speech (1068 ms p95 TTFT against a 600 ms
    # budget), so the fast candidates need somewhere to live. Empty until a real
    # key is pasted into Secrets Manager; agent.py fails at startup rather than
    # quietly falling back to the slow provider.
    GROQ_API_KEY          = ""
    CEREBRAS_API_KEY      = ""
    SENTRY_DSN            = "placeholder-replace-with-real-sentry-dsn"
  })

  lifecycle {
    ignore_changes = [
      # Prevent Terraform from overwriting real API keys populated manually after bootstrap
      secret_string
    ]
  }
}

# ------------------------------------------------------------------------------
# IAM Policy for ECS Task Execution Role to read Secrets Manager
# ------------------------------------------------------------------------------
resource "aws_iam_policy" "ecs_secrets_access" {
  name        = "${var.name_prefix}-ecs-secrets-policy"
  description = "Allows ECS agent to retrieve secret values during container start"

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret"
        ]
        Resource = [
          aws_secretsmanager_secret.app_secrets.arn
        ]
      }
    ]
  })
}
