# ECR Repositories Configuration (Plan §8, README -> Production images)
#
# Three production container images:
# 1. api:   backend/Dockerfile -> covers laravel-app (default), horizon-worker, reverb, migrations
# 2. web:   frontend/Dockerfile -> Nuxt 4 Nitro web application
# 3. agent: agent/Dockerfile    -> Python livekit-agents voice worker

locals {
  repositories = {
    api   = "${var.name_prefix}-api"
    web   = "${var.name_prefix}-web"
    agent = "${var.name_prefix}-agent"
  }

  lifecycle_policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Expire untagged images older than 14 days"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = 14
        }
        action = {
          type = "expire"
        }
      },
      {
        rulePriority = 2
        description  = "Keep last 30 tagged release images"
        selection = {
          tagStatus     = "tagged"
          tagPrefixList = ["v", "sha-", "prod-", "main-"]
          countType     = "imageCountMoreThan"
          countNumber   = 30
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}

resource "aws_ecr_repository" "repos" {
  for_each             = local.repositories
  name                 = each.value
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = {
    Name = each.value
    Role = each.key
  }
}

resource "aws_ecr_lifecycle_policy" "policies" {
  for_each   = aws_ecr_repository.repos
  repository = each.value.name
  policy     = local.lifecycle_policy
}
