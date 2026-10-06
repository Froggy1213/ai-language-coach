# Variable definitions for AI Language Coach Infrastructure (Plan §8)

variable "aws_region" {
  type        = string
  description = "AWS region for infrastructure deployment"
  default     = "us-east-1"
}

variable "environment" {
  type        = string
  description = "Deployment environment (production, staging, dev)"
  default     = "production"
}

variable "name_prefix" {
  type        = string
  description = "Prefix applied to all created AWS resources"
  default     = "ai-language-coach"
}

# ------------------------------------------------------------------------------
# Networking (VPC, Subnets) - Plan §0, §8
# ------------------------------------------------------------------------------
variable "vpc_cidr" {
  type        = string
  description = "CIDR block for the VPC"
  default     = "10.0.0.0/16"
}

variable "availability_zones" {
  type        = list(string)
  description = "List of 2 availability zones to use"
  default     = ["us-east-1a", "us-east-1b"]
}

variable "public_subnet_cidrs" {
  type        = list(string)
  description = "CIDR blocks for public subnets (ALB, LiveKit EC2, horizon, reverb, voice-agent)"
  default     = ["10.0.1.0/24", "10.0.2.0/24"]
}

variable "private_subnet_cidrs" {
  type        = list(string)
  description = "CIDR blocks for private subnets (RDS MySQL, ElastiCache Redis)"
  default     = ["10.0.10.0/24", "10.0.11.0/24"]
}

# ------------------------------------------------------------------------------
# Domains & Certificates - Plan §2, §8
# ------------------------------------------------------------------------------
variable "app_domain" {
  type        = string
  description = "Main application domain name (e.g., coach.example.com)"
  default     = "coach.example.com"
}

variable "livekit_domain" {
  type        = string
  description = "LiveKit server domain for WebRTC signaling and TURN/TLS (e.g., livekit.example.com)"
  default     = "livekit.example.com"
}

variable "acm_certificate_arn" {
  type        = string
  description = "Existing ACM certificate ARN for the ALB (covers app_domain). If empty, an ACM cert resource with DNS validation is declared."
  default     = ""
}

# ------------------------------------------------------------------------------
# Database (RDS MySQL 8.0) - Plan §3, §8
# ------------------------------------------------------------------------------
variable "db_instance_class" {
  type        = string
  description = "RDS MySQL instance class (Plan §8: db.t4g.micro or db.t4g.small)"
  default     = "db.t4g.small"
}

variable "db_name" {
  type        = string
  description = "MySQL database name"
  default     = "language_coach"
}

variable "db_username" {
  type        = string
  description = "MySQL master username"
  default     = "coach_user"
}

variable "db_allocated_storage" {
  type        = number
  description = "Initial allocated storage in GB (gp3)"
  default     = 20
}

variable "db_max_allocated_storage" {
  type        = number
  description = "Maximum storage auto-scaling limit in GB"
  default     = 50
}

# ------------------------------------------------------------------------------
# Cache & Queues (ElastiCache Redis 7.0) - Plan §1, §5, §8
# ------------------------------------------------------------------------------
variable "redis_node_type" {
  type        = string
  description = "ElastiCache Redis node type (Plan §8: cache.t4g.micro)"
  default     = "cache.t4g.micro"
}

# ------------------------------------------------------------------------------
# LiveKit Server Node (EC2 + Elastic IP) - Plan §2, §5, §8
# ------------------------------------------------------------------------------
variable "livekit_instance_type" {
  type        = string
  description = "EC2 instance type for the standalone LiveKit server (Plan §8)"
  default     = "t4g.medium"
}

variable "livekit_ssh_allowed_cidrs" {
  type        = list(string)
  description = "CIDR blocks allowed for restricted SSH access to the LiveKit EC2 node"
  default     = []
}

variable "livekit_stop_grace_period" {
  type        = string
  description = "LiveKit SIGTERM drain timeout in docker-compose. Must fit real call length (Plan §5, §8: 10-15m)"
  default     = "900s"
}

# ------------------------------------------------------------------------------
# Voice Agent Capacity & Autoscaling - Plan §5, §7, §8
# ------------------------------------------------------------------------------
# Measured on the local harness (load/dispatch_capacity.py, load/README.md):
# four prewarmed processes answered a dispatch in ~265 ms p50 and stayed flat
# up to four concurrent dispatches, while a process spawned on demand took
# ~538 ms — both far inside the 5 s VOICE_AGENT_JOIN_TIMEOUT. A SIGTERM with a
# call in flight preserved the call, refused new dispatches and exited 0.
#
# Plan §5 names `max_processes`, but the pinned livekit-agents 1.8.5 has no
# such field: the concurrency ceiling is `load_threshold` plus the task's own
# CPU, which is why there is a load_threshold variable here and no
# max_processes one. The EC2 node's real media ceiling still has to be measured
# there — the local run has no WebRTC audio clients at all.
variable "voice_agent_num_idle_processes" {
  type        = number
  description = "Pre-warmed idle worker processes per Fargate task (first line of defence)"
  default     = 4
}

variable "voice_agent_load_threshold" {
  type        = number
  description = "CPU threshold above which the worker stops accepting new jobs (livekit-agents default 0.7)"
  default     = 0.7
}

variable "voice_agent_drain_timeout" {
  type        = number
  description = "Seconds an in-flight call may finish after SIGTERM (measured sprints are 3-5 minutes; the library default is an hour)"
  default     = 360
}

variable "voice_agent_stop_timeout" {
  type        = number
  description = "ECS task stopTimeout in seconds; must not be shorter than voice_agent_drain_timeout (Plan §5)"
  default     = 420
}

variable "voice_agent_min_capacity" {
  type        = number
  description = "Minimum running Fargate tasks for voice-agent-worker"
  default     = 1
}

variable "voice_agent_max_capacity" {
  type        = number
  description = "Maximum running Fargate tasks for voice-agent-worker (second line of defence autoscaling)"
  default     = 5
}

# ------------------------------------------------------------------------------
# Storage & Retention (S3) - Plan §5, §7, §8
# ------------------------------------------------------------------------------
variable "cors_allowed_origins" {
  type        = list(string)
  description = "Allowed origins for browser direct presigned POST audio uploads"
  default     = ["https://coach.example.com", "http://localhost:3000"]
}

variable "audio_retention_days" {
  type        = number
  description = "Safety-net S3 lifecycle expiration for raw assessment recordings (Plan §5, §7). Raw audio is normally deleted by Horizon job immediately after STT."
  default     = 1
}

variable "transcript_retention_days" {
  type        = number
  description = "S3 lifecycle expiration for transcript archive objects (Plan §5, §7)"
  default     = 90
}

variable "log_retention_days" {
  type        = number
  description = "CloudWatch log retention in days"
  default     = 30
}

# ------------------------------------------------------------------------------
# AWS Budgets & Cost Alerts - Plan §7, §8
# ------------------------------------------------------------------------------
# Cost model documented in budgets.tf:
# Variable dialogue cost: ~$0.0233 / minute of active conversation.
# $100 covers ~4,300 minutes of dialogue plus baseline AWS fixed resources.
variable "monthly_budget_usd" {
  type        = number
  description = "Monthly AWS budget limit in USD"
  default     = 100
}

variable "budget_alert_emails" {
  type        = list(string)
  description = "Email addresses for AWS Budget overage and threshold alerts"
  default     = ["ops@example.com"]
}
