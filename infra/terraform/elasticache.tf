# ElastiCache Redis 7.0 Configuration (Plan §1, §5, §8)
#
# Roles in architecture:
# 1. Horizon queue broker (all background jobs: STT, LLM mistake analysis, SM-2 scheduling).
# 2. Application cache (Sanctum sessions, OPcache/Redis cache).
# 3. Lighthouse subscription storage (LIGHTHOUSE_SUBSCRIPTION_STORAGE=redis, decision 19 & 34).
#
# Constraints:
# - Single-node cluster (cache.t4g.micro) in private subnets.
# - Ingress strictly limited to laravel-app, horizon-worker, and reverb.

resource "aws_elasticache_subnet_group" "redis" {
  name        = "${var.name_prefix}-redis-subnet-group"
  description = "Subnet group for private ElastiCache Redis"
  subnet_ids  = aws_subnet.private[*].id

  tags = {
    Name = "${var.name_prefix}-redis-subnet-group"
  }
}

resource "aws_elasticache_parameter_group" "redis7" {
  name        = "${var.name_prefix}-redis7-params"
  family      = "redis7"
  description = "Custom parameter group for Redis 7.0"

  parameter {
    name  = "maxmemory-policy"
    value = "noeviction" # Important: Horizon queues must not drop unhandled jobs
  }

  tags = {
    Name = "${var.name_prefix}-redis7-params"
  }
}

# AWS ElastiCache requires a replication group (aws_elasticache_replication_group)
# to enable in-transit (TLS) and at-rest encryption; standalone clusters do not support them.
resource "aws_elasticache_replication_group" "main" {
  replication_group_id = "${var.name_prefix}-redis"
  description          = "Redis replication group for Horizon queues, cache, and Lighthouse"
  engine               = "redis"
  engine_version       = "7.0"
  node_type            = var.redis_node_type
  num_cache_clusters   = 1
  parameter_group_name = aws_elasticache_parameter_group.redis7.name
  subnet_group_name    = aws_elasticache_subnet_group.redis.name
  security_group_ids   = [aws_security_group.elasticache.id]
  port                 = 6379

  transit_encryption_enabled = true
  at_rest_encryption_enabled = true

  maintenance_window = "Sun:06:00-Sun:07:00"

  tags = {
    Name = "${var.name_prefix}-redis"
  }
}
