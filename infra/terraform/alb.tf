# Application Load Balancer Configuration (Plan §2, §8)
#
# Constraints (Plan §8, Non-negotiable):
# - ALB + ACM only in front of laravel-app, Nuxt, and Reverb.
# - NEVER placed in front of LiveKit (LiveKit WebRTC requires direct connection via Elastic IP).
# - Port 80 redirects to Port 443 HTTPS.
# - Port 443 routes:
#   - Default: Nuxt 4 web frontend (port 3000)
#   - Path /app/*, /apps/*: Reverb WebSocket server (port 8081, WSS upgrade)
#   - Path /graphql*, /api/*, /sanctum/*, /up: Laravel API (port 8080)

resource "aws_lb" "main" {
  name               = "${var.name_prefix}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id

  enable_deletion_protection = var.protect_data

  tags = {
    Name = "${var.name_prefix}-alb"
  }
}

# ------------------------------------------------------------------------------
# Target Groups
# ------------------------------------------------------------------------------
# 1. Nuxt 4 Web Frontend Target Group
resource "aws_lb_target_group" "web" {
  name        = "${var.name_prefix}-web-tg"
  port        = 3000
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    enabled             = true
    path                = "/"
    port                = "3000"
    protocol            = "HTTP"
    matcher             = "200"
    interval            = 30
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "${var.name_prefix}-web-tg"
  }
}

# 2. Laravel API Target Group (Plan §8, README -> Production images)
resource "aws_lb_target_group" "laravel_app" {
  name        = "${var.name_prefix}-laravel-tg"
  port        = 8080
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    enabled             = true
    path                = "/up" # Native Laravel health check route
    port                = "8080"
    protocol            = "HTTP"
    matcher             = "200"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "${var.name_prefix}-laravel-tg"
  }
}

# 3. Laravel Reverb Target Group (Plan §2, §5, §8)
resource "aws_lb_target_group" "reverb" {
  name        = "${var.name_prefix}-reverb-tg"
  port        = 8081
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    enabled             = true
    path                = "/up"
    port                = "8081"
    protocol            = "HTTP"
    matcher             = "200,404" # Reverb root returns 404 on GET, /up or status
    interval            = 30
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "${var.name_prefix}-reverb-tg"
  }
}

# ------------------------------------------------------------------------------
# ACM Certificate (Provisioned if variable is unset)
# ------------------------------------------------------------------------------
resource "aws_acm_certificate" "cert" {
  count             = var.acm_certificate_arn == "" ? 1 : 0
  domain_name       = var.app_domain
  validation_method = "DNS"

  subject_alternative_names = [
    "*.${var.app_domain}"
  ]

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "${var.name_prefix}-cert"
  }
}

locals {
  certificate_arn = var.acm_certificate_arn != "" ? var.acm_certificate_arn : aws_acm_certificate.cert[0].arn
}

# ------------------------------------------------------------------------------
# ALB Listeners & Rules
# ------------------------------------------------------------------------------
# HTTP (80) -> Redirect to HTTPS (443)
resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

# HTTPS (443) -> SSL Termination with ACM Certificate
resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = local.certificate_arn

  # Default action: route to Nuxt web frontend
  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }
}

# Rule 1: Reverb WebSockets (Pusher protocol paths /app/*, /apps/*)
resource "aws_lb_listener_rule" "reverb" {
  listener_arn = aws_lb_listener.https.arn
  priority     = 10

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.reverb.arn
  }

  condition {
    path_pattern {
      values = ["/app/*", "/apps/*"]
    }
  }
}

# Rule 2: Laravel GraphQL & API endpoints (/graphql*, /api/*, /sanctum/*, /up)
resource "aws_lb_listener_rule" "laravel_api" {
  listener_arn = aws_lb_listener.https.arn
  priority     = 20

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.laravel_app.arn
  }

  condition {
    path_pattern {
      values = ["/graphql*", "/api/*", "/sanctum/*", "/up"]
    }
  }
}
