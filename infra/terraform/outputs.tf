# Infrastructure Outputs (Plan §8, Milestone 2)

output "alb_dns_name" {
  description = "DNS name of the Application Load Balancer"
  value       = aws_lb.main.dns_name
}

output "app_url" {
  description = "Production URL for the web application"
  value       = "https://${var.app_domain}"
}

output "livekit_public_ip" {
  description = "Elastic IP allocated to the standalone LiveKit EC2 node"
  value       = aws_eip.livekit.public_ip
}

output "livekit_url" {
  description = "Public WebRTC signaling URL for LiveKit"
  value       = "wss://${var.livekit_domain}"
}

output "ecr_repository_urls" {
  description = "ECR repository URLs for container images"
  value = {
    api   = aws_ecr_repository.repos["api"].repository_url
    web   = aws_ecr_repository.repos["web"].repository_url
    agent = aws_ecr_repository.repos["agent"].repository_url
  }
}

output "s3_bucket_name" {
  description = "S3 bucket for audio assessment uploads and transcript archive"
  value       = aws_s3_bucket.storage.bucket
}

output "ecs_cluster_name" {
  description = "Name of the ECS Fargate cluster"
  value       = aws_ecs_cluster.main.name
}

output "secrets_manager_secret_arn" {
  description = "ARN of the Secrets Manager application secret"
  value       = aws_secretsmanager_secret.app_secrets.arn
}

output "rds_endpoint" {
  description = "Private endpoint for RDS MySQL"
  value       = aws_db_instance.main.endpoint
}

output "elasticache_endpoint" {
  description = "Private configuration endpoint for ElastiCache Redis"
  value       = "${aws_elasticache_cluster.main.cache_nodes[0].address}:6379"
}

output "cloudwatch_dashboard_name" {
  description = "Name of the operations CloudWatch dashboard"
  value       = aws_cloudwatch_dashboard.main.dashboard_name
}
