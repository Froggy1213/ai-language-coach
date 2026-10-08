# ECS Fargate Cluster, Task Definitions, and Services (Plan §0, §2, §5, §8)
#
# Constraints & Roles (Plan §8, README -> Production images):
# 1. laravel-app: default CMD (nginx + php-fpm on :8080) behind ALB.
# 2. horizon-worker: php artisan horizon (public subnet, no NAT).
# 3. reverb: php artisan reverb:start --host=0.0.0.0 --port=8081 (1 instance, behind ALB).
# 4. voice-agent-worker: Python livekit-agents worker (public subnet, no NAT, autoscaled).
# 5. web: Nuxt 4 Nitro frontend behind ALB.
#
# Configuration:
# Injected via Secrets Manager (valueFrom) rather than plaintext.
# stopTimeout configured to allow graceful drains (Horizon 360s, voice-agent 420s).

resource "aws_ecs_cluster" "main" {
  name = "${var.name_prefix}-cluster"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = {
    Name = "${var.name_prefix}-cluster"
  }
}

resource "aws_ecs_cluster_capacity_providers" "main" {
  cluster_name = aws_ecs_cluster.main.name

  capacity_providers = ["FARGATE", "FARGATE_SPOT"]

  default_capacity_provider_strategy {
    base              = 1
    weight            = 100
    capacity_provider = "FARGATE"
  }
}

# ------------------------------------------------------------------------------
# IAM Roles for ECS Tasks
# ------------------------------------------------------------------------------
resource "aws_iam_role" "ecs_execution_role" {
  name = "${var.name_prefix}-ecs-execution-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_execution_standard" {
  role       = aws_iam_role.ecs_execution_role.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy_attachment" "ecs_execution_secrets" {
  role       = aws_iam_role.ecs_execution_role.name
  policy_arn = aws_iam_policy.ecs_secrets_access.arn
}

resource "aws_iam_role" "ecs_task_role" {
  name = "${var.name_prefix}-ecs-task-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
      }
    ]
  })
}

# Task role policy allowing S3 presigning, uploads, downloads, and deletions (Plan §5)
resource "aws_iam_policy" "ecs_task_s3_policy" {
  name        = "${var.name_prefix}-ecs-task-s3-policy"
  description = "Allows ECS tasks to interact with S3 assessment audio bucket"

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:AbortMultipartUpload"
        ]
        Resource = "${aws_s3_bucket.storage.arn}/*"
      },
      {
        Effect = "Allow"
        Action = [
          "s3:ListBucket"
        ]
        Resource = aws_s3_bucket.storage.arn
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_task_s3" {
  role       = aws_iam_role.ecs_task_role.name
  policy_arn = aws_iam_policy.ecs_task_s3_policy.arn
}

# ------------------------------------------------------------------------------
# Common Environment & Secrets Helpers
# ------------------------------------------------------------------------------
locals {
  secret_arn = aws_secretsmanager_secret.app_secrets.arn

  laravel_common_env = [
    { name = "APP_ENV", value = var.environment },
    { name = "APP_DEBUG", value = "false" },
    { name = "APP_URL", value = "https://${var.app_domain}" },
    { name = "DB_CONNECTION", value = "mysql" },
    { name = "DB_HOST", value = aws_db_instance.main.address },
    { name = "DB_PORT", value = "3306" },
    { name = "DB_DATABASE", value = var.db_name },
    { name = "DB_USERNAME", value = var.db_username },
    { name = "REDIS_HOST", value = aws_elasticache_cluster.main.cache_nodes[0].address },
    { name = "REDIS_PORT", value = "6379" },
    { name = "SESSION_DRIVER", value = "database" },
    { name = "CACHE_STORE", value = "redis" },
    { name = "QUEUE_CONNECTION", value = "redis" },
    { name = "BROADCAST_CONNECTION", value = "reverb" },
    { name = "LIGHTHOUSE_BROADCASTER", value = "reverb" },
    { name = "LIGHTHOUSE_SUBSCRIPTION_STORAGE", value = "redis" },
    { name = "LIGHTHOUSE_QUERY_CACHE_MODE", value = "opcache" },
    { name = "REVERB_HOST", value = "127.0.0.1" },
    { name = "REVERB_PORT", value = "8081" },
    { name = "REVERB_SCHEME", value = "https" },
    { name = "CORS_ALLOWED_ORIGINS", value = "https://${var.app_domain}" },
    { name = "TRUSTED_PROXIES", value = var.vpc_cidr },
    { name = "SANCTUM_STATEFUL_DOMAINS", value = "${var.app_domain},www.${var.app_domain}" },
    { name = "AWS_DEFAULT_REGION", value = var.aws_region },
    { name = "AWS_BUCKET", value = aws_s3_bucket.storage.bucket },
    { name = "AWS_USE_PATH_STYLE_ENDPOINT", value = "false" },
    { name = "LIVEKIT_URL", value = "ws://${aws_eip.livekit.public_ip}:7880" },
    { name = "LIVEKIT_PUBLIC_URL", value = "wss://${var.livekit_domain}" },
    { name = "VOICE_AGENT_NAME", value = "ai-language-coach" }
  ]

  laravel_secrets = [
    { name = "APP_KEY", valueFrom = "${local.secret_arn}:APP_KEY::" },
    { name = "DB_PASSWORD", valueFrom = "${local.secret_arn}:DB_PASSWORD::" },
    { name = "REVERB_APP_ID", valueFrom = "${local.secret_arn}:REVERB_APP_ID::" },
    { name = "REVERB_APP_KEY", valueFrom = "${local.secret_arn}:REVERB_APP_KEY::" },
    { name = "REVERB_APP_SECRET", valueFrom = "${local.secret_arn}:REVERB_APP_SECRET::" },
    { name = "LIVEKIT_API_KEY", valueFrom = "${local.secret_arn}:LIVEKIT_API_KEY::" },
    { name = "LIVEKIT_API_SECRET", valueFrom = "${local.secret_arn}:LIVEKIT_API_SECRET::" },
    { name = "VOICE_INTERNAL_SECRET", valueFrom = "${local.secret_arn}:VOICE_INTERNAL_SECRET::" },
    { name = "DEEPGRAM_API_KEY", valueFrom = "${local.secret_arn}:DEEPGRAM_API_KEY::" },
    { name = "DEEPSEEK_API_KEY", valueFrom = "${local.secret_arn}:DEEPSEEK_API_KEY::" },
    { name = "SENTRY_DSN", valueFrom = "${local.secret_arn}:SENTRY_DSN::" }
  ]
}

# ------------------------------------------------------------------------------
# 1. Service: laravel-app (Plan §2, §8)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "laravel_app" {
  family                   = "${var.name_prefix}-laravel-app"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name        = "api"
      image       = "${aws_ecr_repository.repos["api"].repository_url}:latest"
      essential   = true
      stopTimeout = 60
      portMappings = [
        {
          containerPort = 8080
          protocol      = "tcp"
        }
      ]
      environment = local.laravel_common_env
      secrets     = local.laravel_secrets
      healthCheck = {
        command     = ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1"]
        interval    = 15
        timeout     = 5
        retries     = 3
        startPeriod = 20
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["laravel-app"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "laravel_app" {
  name            = "laravel-app"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.laravel_app.arn
  desired_count   = 2
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.laravel_app.id]
    assign_public_ip = true # Plan §0, §8: Public subnet, no NAT
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.laravel_app.arn
    container_name   = "api"
    container_port   = 8080
  }

  depends_on = [aws_lb_listener.https]
}

# ------------------------------------------------------------------------------
# 2. Service: horizon-worker (Plan §0, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "horizon_worker" {
  family                   = "${var.name_prefix}-horizon-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name        = "horizon"
      image       = "${aws_ecr_repository.repos["api"].repository_url}:latest"
      essential   = true
      command     = ["php", "artisan", "horizon"]
      stopTimeout = 360 # Plan §5, decision 15: Must exceed 300s job timeout to drain active jobs
      environment = local.laravel_common_env
      secrets     = local.laravel_secrets
      healthCheck = {
        command     = ["CMD-SHELL", "php artisan horizon:status || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 20
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["horizon-worker"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "horizon_worker" {
  name            = "horizon-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.horizon_worker.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.horizon_worker.id]
    assign_public_ip = true # Plan §0, §8: Public subnet, no NAT
  }
}

# ------------------------------------------------------------------------------
# 3. Service: reverb (Plan §2, §5, §8)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "reverb" {
  family                   = "${var.name_prefix}-reverb"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name        = "reverb"
      image       = "${aws_ecr_repository.repos["api"].repository_url}:latest"
      essential   = true
      command     = ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8081"]
      stopTimeout = 60
      portMappings = [
        {
          containerPort = 8081
          protocol      = "tcp"
        }
      ]
      environment = local.laravel_common_env
      secrets     = local.laravel_secrets
      healthCheck = {
        # Decision 34: Probe socket on port 8081 (wget / fails because root is 404)
        command     = ["CMD-SHELL", "php -r 'exit(@fsockopen(\"127.0.0.1\", 8081) ? 0 : 1);'"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 10
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["reverb"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "reverb" {
  name            = "reverb"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.reverb.arn
  desired_count   = 1 # Plan §0, §8: Exactly 1 instance in V1
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.reverb.id]
    assign_public_ip = true # Plan §0, §8: Public subnet, no NAT
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.reverb.arn
    container_name   = "reverb"
    container_port   = 8081
  }

  depends_on = [aws_lb_listener.https]
}

# ------------------------------------------------------------------------------
# 4. Service: voice-agent-worker (Plan §0, §2, §5, §7, §8)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "voice_agent_worker" {
  family                   = "${var.name_prefix}-voice-agent-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "1024"
  memory                   = "2048"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name        = "voice-agent"
      image       = "${aws_ecr_repository.repos["agent"].repository_url}:latest"
      essential   = true
      command     = ["python", "agent.py", "start"]
      stopTimeout = var.voice_agent_stop_timeout # Plan §5: not shorter than voice_agent_drain_timeout
      portMappings = [
        {
          containerPort = 8081
          protocol      = "tcp"
        }
      ]
      environment = [
        { name = "LIVEKIT_URL", value = "ws://${aws_eip.livekit.public_ip}:7880" },
        { name = "VOICE_AGENT_NAME", value = "ai-language-coach" },
        { name = "BACKEND_INTERNAL_URL", value = "https://${var.app_domain}" },
        # Plan §5, §7: the worker pool, as measured by load/dispatch_capacity.py.
        # livekit-agents 1.8.5 has no max_processes field — the ceiling is
        # load_threshold plus this task's CPU — so there is no MAX_PROCESSES here.
        { name = "NUM_IDLE_PROCESSES", value = tostring(var.voice_agent_num_idle_processes) },
        { name = "LOAD_THRESHOLD", value = tostring(var.voice_agent_load_threshold) },
        { name = "DRAIN_TIMEOUT_SECONDS", value = tostring(var.voice_agent_drain_timeout) }
      ]
      secrets = [
        { name = "LIVEKIT_API_KEY", valueFrom = "${local.secret_arn}:LIVEKIT_API_KEY::" },
        { name = "LIVEKIT_API_SECRET", valueFrom = "${local.secret_arn}:LIVEKIT_API_SECRET::" },
        { name = "VOICE_INTERNAL_SECRET", valueFrom = "${local.secret_arn}:VOICE_INTERNAL_SECRET::" },
        { name = "DEEPGRAM_API_KEY", valueFrom = "${local.secret_arn}:DEEPGRAM_API_KEY::" },
        { name = "DEEPSEEK_API_KEY", valueFrom = "${local.secret_arn}:DEEPSEEK_API_KEY::" },
        { name = "CARTESIA_API_KEY", valueFrom = "${local.secret_arn}:CARTESIA_API_KEY::" },
        { name = "OPENAI_API_KEY", valueFrom = "${local.secret_arn}:OPENAI_API_KEY::" },
        { name = "SENTRY_DSN", valueFrom = "${local.secret_arn}:SENTRY_DSN::" }
      ]
      healthCheck = {
        # Agent worker exposes health on :8081 once registered with LiveKit
        command     = ["CMD-SHELL", "curl -fsS -o /dev/null http://127.0.0.1:8081/ || exit 1"]
        interval    = 15
        timeout     = 5
        retries     = 5
        startPeriod = 25
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["voice-agent-worker"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "voice_agent_worker" {
  name            = "voice-agent-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.voice_agent_worker.arn
  desired_count   = var.voice_agent_min_capacity
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.voice_agent_worker.id]
    assign_public_ip = true # Plan §0, §8: Public subnet, no NAT
  }
}

# ------------------------------------------------------------------------------
# Voice Agent Autoscaling (Second line of defence — Plan §5, §7)
# ------------------------------------------------------------------------------
resource "aws_appautoscaling_target" "voice_agent" {
  max_capacity       = var.voice_agent_max_capacity
  min_capacity       = var.voice_agent_min_capacity
  resource_id        = "service/${aws_ecs_cluster.main.name}/${aws_ecs_service.voice_agent_worker.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "voice_agent_cpu" {
  name               = "${var.name_prefix}-voice-agent-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.voice_agent.resource_id
  scalable_dimension = aws_appautoscaling_target.voice_agent.scalable_dimension
  service_namespace  = aws_appautoscaling_target.voice_agent.service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value       = 70.0
    scale_in_cooldown  = 300
    scale_out_cooldown = 60
  }
}

# ------------------------------------------------------------------------------
# 5. Service: web (Nuxt 4 Nitro Frontend — Plan §2, §8)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "web" {
  family                   = "${var.name_prefix}-web"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name      = "web"
      image     = "${aws_ecr_repository.repos["web"].repository_url}:latest"
      essential = true
      portMappings = [
        {
          containerPort = 3000
          protocol      = "tcp"
        }
      ]
      environment = [
        { name = "NODE_ENV", value = "production" },
        { name = "NITRO_PORT", value = "3000" },
        { name = "NITRO_HOST", value = "0.0.0.0" },
        { name = "NUXT_PUBLIC_BACKEND_URL", value = "https://${var.app_domain}" },
        { name = "NUXT_PUBLIC_REVERB_HOST", value = var.app_domain },
        { name = "NUXT_PUBLIC_REVERB_PORT", value = "443" },
        { name = "NUXT_PUBLIC_REVERB_SCHEME", value = "https" }
      ]
      secrets = [
        { name = "NUXT_PUBLIC_REVERB_APP_KEY", valueFrom = "${local.secret_arn}:REVERB_APP_KEY::" }
      ]
      healthCheck = {
        command     = ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:3000/ || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 15
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["web"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "ecs"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "web" {
  name            = "web"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.web.arn
  desired_count   = 2
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.public[*].id
    security_groups  = [aws_security_group.web.id]
    assign_public_ip = true
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.web.arn
    container_name   = "web"
    container_port   = 3000
  }

  depends_on = [aws_lb_listener.https]
}

# ------------------------------------------------------------------------------
# 6. One-off Task Definition: Database Migrations (Plan §8, CD Workflow)
# ------------------------------------------------------------------------------
resource "aws_ecs_task_definition" "migration" {
  family                   = "${var.name_prefix}-migration"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.ecs_execution_role.arn
  task_role_arn            = aws_iam_role.ecs_task_role.arn

  container_definitions = jsonencode([
    {
      name        = "migration"
      image       = "${aws_ecr_repository.repos["api"].repository_url}:latest"
      essential   = true
      command     = ["php", "artisan", "migrate", "--force"]
      environment = local.laravel_common_env
      secrets     = local.laravel_secrets
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.services["laravel-app"].name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "migration"
        }
      }
    }
  ])
}
