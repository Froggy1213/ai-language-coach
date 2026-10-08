# Security Groups Configuration (Plan §0, §2, §8)
#
# Least-privilege network isolation with explicit ingress/egress rules.
# Cross-group references use aws_security_group_rule to avoid Terraform dependency cycles.

# ------------------------------------------------------------------------------
# 1. Application Load Balancer Security Group (Plan §2, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "alb" {
  name        = "${var.name_prefix}-alb-sg"
  description = "Security group for internet-facing Application Load Balancer"
  vpc_id      = aws_vpc.main.id

  ingress {
    description = "HTTP from anywhere (redirects to HTTPS)"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS from anywhere"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-alb-sg"
  }
}

# ------------------------------------------------------------------------------
# 2. Laravel API Service Security Group (Plan §2, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "laravel_app" {
  name        = "${var.name_prefix}-laravel-app-sg"
  description = "Security group for laravel-app Fargate tasks behind ALB"
  vpc_id      = aws_vpc.main.id

  egress {
    description = "HTTPS outbound (DeepSeek API, Deepgram, Sentry, AWS S3/Secrets Manager)"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-laravel-app-sg"
  }
}

# ------------------------------------------------------------------------------
# 3. Nuxt Web Frontend Security Group (Plan §2, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "web" {
  name        = "${var.name_prefix}-web-sg"
  description = "Security group for Nuxt 4 Nitro web frontend Fargate tasks"
  vpc_id      = aws_vpc.main.id

  egress {
    description = "HTTPS outbound for external assets/APIs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-web-sg"
  }
}

# ------------------------------------------------------------------------------
# 4. Horizon Worker Security Group (Plan §0, §5, §8)
# ------------------------------------------------------------------------------
# Runs in public subnet with public IP (NO NAT). Zero inbound ports open!
resource "aws_security_group" "horizon_worker" {
  name        = "${var.name_prefix}-horizon-worker-sg"
  description = "Security group for Horizon queue worker tasks (public subnet, no NAT)"
  vpc_id      = aws_vpc.main.id

  # Ingress: None! Queue workers only pull from Redis.

  egress {
    description = "HTTPS outbound (Deepgram batch STT, DeepSeek LLM mistake analysis, Sentry, AWS APIs)"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-horizon-worker-sg"
  }
}

# ------------------------------------------------------------------------------
# 5. Laravel Reverb Security Group (Plan §2, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "reverb" {
  name        = "${var.name_prefix}-reverb-sg"
  description = "Security group for Laravel Reverb WebSocket server (Plan §8: 1 instance)"
  vpc_id      = aws_vpc.main.id

  egress {
    description = "HTTPS outbound for AWS telemetry"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-reverb-sg"
  }
}

# ------------------------------------------------------------------------------
# 6. Voice Agent Worker Security Group (Plan §0, §2, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "voice_agent_worker" {
  name        = "${var.name_prefix}-voice-agent-worker-sg"
  description = "Security group for Python livekit-agents voice fleet (public subnet, no NAT)"
  vpc_id      = aws_vpc.main.id

  ingress {
    description = "Internal health probe on port 8081 from within VPC"
    from_port   = 8081
    to_port     = 8081
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  egress {
    description = "HTTPS outbound (Deepgram Nova-2 streaming STT, Cartesia TTS, DeepSeek/OpenAI LLM, Sentry, CloudWatch, backend API)"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-voice-agent-worker-sg"
  }
}

# ------------------------------------------------------------------------------
# 7. RDS MySQL Security Group (Plan §3, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "rds" {
  name        = "${var.name_prefix}-rds-sg"
  description = "Security group for private RDS MySQL 8.4 cluster"
  vpc_id      = aws_vpc.main.id

  # Egress: None (Database never initiates outbound connections)

  tags = {
    Name = "${var.name_prefix}-rds-sg"
  }
}

# ------------------------------------------------------------------------------
# 8. ElastiCache Redis Security Group (Plan §1, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "elasticache" {
  name        = "${var.name_prefix}-elasticache-sg"
  description = "Security group for private ElastiCache Redis"
  vpc_id      = aws_vpc.main.id

  # Egress: None (Redis never initiates outbound connections)

  tags = {
    Name = "${var.name_prefix}-elasticache-sg"
  }
}

# ------------------------------------------------------------------------------
# 9. LiveKit EC2 Security Group (Plan §2, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_security_group" "livekit" {
  name        = "${var.name_prefix}-livekit-sg"
  description = "Security group for standalone LiveKit EC2 node (WebRTC + TURN/TLS + signaling)"
  vpc_id      = aws_vpc.main.id

  # Signaling & API
  # TODO(livekit-tls): 7880 is plaintext — TLS on this node covers TURN (5349)
  # only — so this rule exposes the backend's LiveKit admin bearer to the
  # internet. Narrow `cidr_blocks` to [var.vpc_cidr] until TLS is terminated in
  # front of 7880; see the livekit-tls TODO in ecs.tf.
  ingress {
    description = "LiveKit HTTP/WebSocket signaling from browsers and Twirp from backend"
    from_port   = 7880
    to_port     = 7880
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "LiveKit WebRTC over TCP fallback"
    from_port   = 7881
    to_port     = 7881
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # TURN / STUN
  ingress {
    description = "LiveKit built-in TURN/TLS over TCP (Plan section 5, section 8: Lets Encrypt cert on EC2)"
    from_port   = 5349
    to_port     = 5349
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "LiveKit built-in TURN/TLS over UDP"
    from_port   = 5349
    to_port     = 5349
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "TURN/STUN standard UDP port"
    from_port   = 3478
    to_port     = 3478
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # WebRTC Media UDP range
  ingress {
    description = "WebRTC media UDP port range (Plan section 8: 50000-60000)"
    from_port   = 50000
    to_port     = 60000
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # Let's Encrypt / certbot HTTP-01 challenge
  ingress {
    description = "HTTP for Lets Encrypt certbot certificate issuance and renewal"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # Restricted SSH
  dynamic "ingress" {
    for_each = length(var.livekit_ssh_allowed_cidrs) > 0 ? [1] : []
    content {
      description = "Restricted SSH access from approved CIDRs"
      from_port   = 22
      to_port     = 22
      protocol    = "tcp"
      cidr_blocks = var.livekit_ssh_allowed_cidrs
    }
  }

  # Egress
  egress {
    description = "HTTPS outbound (webhooks to Laravel API, Lets Encrypt OCSP, Docker pulls)"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description = "HTTP outbound (apt updates, Lets Encrypt)"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description = "DNS queries"
    from_port   = 53
    to_port     = 53
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description = "NTP time synchronization"
    from_port   = 123
    to_port     = 123
    protocol    = "udp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.name_prefix}-livekit-sg"
  }
}

# ------------------------------------------------------------------------------
# Cross-Security-Group Rules (Decoupled to break dependency cycles)
# ------------------------------------------------------------------------------

# ALB -> laravel_app (8080)
resource "aws_security_group_rule" "alb_to_laravel" {
  type                     = "egress"
  security_group_id        = aws_security_group.alb.id
  from_port                = 8080
  to_port                  = 8080
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.laravel_app.id
}

resource "aws_security_group_rule" "laravel_from_alb" {
  type                     = "ingress"
  security_group_id        = aws_security_group.laravel_app.id
  from_port                = 8080
  to_port                  = 8080
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.alb.id
}

# ALB -> web (3000)
resource "aws_security_group_rule" "alb_to_web" {
  type                     = "egress"
  security_group_id        = aws_security_group.alb.id
  from_port                = 3000
  to_port                  = 3000
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.web.id
}

resource "aws_security_group_rule" "web_from_alb" {
  type                     = "ingress"
  security_group_id        = aws_security_group.web.id
  from_port                = 3000
  to_port                  = 3000
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.alb.id
}

# ALB -> reverb (8081)
resource "aws_security_group_rule" "alb_to_reverb" {
  type                     = "egress"
  security_group_id        = aws_security_group.alb.id
  from_port                = 8081
  to_port                  = 8081
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.reverb.id
}

resource "aws_security_group_rule" "reverb_from_alb" {
  type                     = "ingress"
  security_group_id        = aws_security_group.reverb.id
  from_port                = 8081
  to_port                  = 8081
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.alb.id
}

# laravel_app -> rds (3306)
resource "aws_security_group_rule" "laravel_to_rds" {
  type                     = "egress"
  security_group_id        = aws_security_group.laravel_app.id
  from_port                = 3306
  to_port                  = 3306
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.rds.id
}

resource "aws_security_group_rule" "rds_from_laravel" {
  type                     = "ingress"
  security_group_id        = aws_security_group.rds.id
  from_port                = 3306
  to_port                  = 3306
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.laravel_app.id
}

# horizon_worker -> rds (3306)
resource "aws_security_group_rule" "horizon_to_rds" {
  type                     = "egress"
  security_group_id        = aws_security_group.horizon_worker.id
  from_port                = 3306
  to_port                  = 3306
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.rds.id
}

resource "aws_security_group_rule" "rds_from_horizon" {
  type                     = "ingress"
  security_group_id        = aws_security_group.rds.id
  from_port                = 3306
  to_port                  = 3306
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.horizon_worker.id
}

# laravel_app -> elasticache (6379)
resource "aws_security_group_rule" "laravel_to_elasticache" {
  type                     = "egress"
  security_group_id        = aws_security_group.laravel_app.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.elasticache.id
}

resource "aws_security_group_rule" "elasticache_from_laravel" {
  type                     = "ingress"
  security_group_id        = aws_security_group.elasticache.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.laravel_app.id
}

# horizon_worker -> elasticache (6379)
resource "aws_security_group_rule" "horizon_to_elasticache" {
  type                     = "egress"
  security_group_id        = aws_security_group.horizon_worker.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.elasticache.id
}

resource "aws_security_group_rule" "elasticache_from_horizon" {
  type                     = "ingress"
  security_group_id        = aws_security_group.elasticache.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.horizon_worker.id
}

# reverb -> elasticache (6379)
resource "aws_security_group_rule" "reverb_to_elasticache" {
  type                     = "egress"
  security_group_id        = aws_security_group.reverb.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.elasticache.id
}

resource "aws_security_group_rule" "elasticache_from_reverb" {
  type                     = "ingress"
  security_group_id        = aws_security_group.elasticache.id
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.reverb.id
}

# laravel_app -> livekit (7880 Twirp HTTP)
resource "aws_security_group_rule" "laravel_to_livekit" {
  type                     = "egress"
  security_group_id        = aws_security_group.laravel_app.id
  from_port                = 7880
  to_port                  = 7880
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.livekit.id
}

# voice_agent_worker -> livekit (7880 Signaling, 7881 TCP fallback, 50000-60000 UDP RTC)
resource "aws_security_group_rule" "voice_agent_to_livekit_signaling" {
  type                     = "egress"
  security_group_id        = aws_security_group.voice_agent_worker.id
  from_port                = 7880
  to_port                  = 7880
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.livekit.id
}

resource "aws_security_group_rule" "voice_agent_to_livekit_tcp" {
  type                     = "egress"
  security_group_id        = aws_security_group.voice_agent_worker.id
  from_port                = 7881
  to_port                  = 7881
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.livekit.id
}

resource "aws_security_group_rule" "voice_agent_to_livekit_rtc" {
  type                     = "egress"
  security_group_id        = aws_security_group.voice_agent_worker.id
  from_port                = 50000
  to_port                  = 60000
  protocol                 = "udp"
  source_security_group_id = aws_security_group.livekit.id
}
