# RDS MySQL 8.4 Configuration (Plan §3, §8)
#
# Requirements:
# - Engine: MySQL 8.4 with utf8mb4 collation matching the app schema (§3).
# - Instance class: db.t4g.micro or db.t4g.small (Plan §8).
# - Security: In private subnets, encrypted at rest via KMS, no public IP.
# - Automated backups enabled (7-day retention).
# - Data protection: Deletion protection and final snapshots controlled by var.protect_data.

resource "aws_db_subnet_group" "rds" {
  name        = "${var.name_prefix}-rds-subnet-group"
  description = "Subnet group for private RDS MySQL instance"
  subnet_ids  = aws_subnet.private[*].id

  tags = {
    Name = "${var.name_prefix}-rds-subnet-group"
  }
}

# Parameter group enforcing utf8mb4 character set & unicode collation (Plan §3)
resource "aws_db_parameter_group" "mysql84" {
  name        = "${var.name_prefix}-mysql84-params"
  family      = "mysql8.4"
  description = "Custom parameters for MySQL 8.4 enforcing utf8mb4"

  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }

  parameter {
    name  = "character_set_client"
    value = "utf8mb4"
  }

  parameter {
    name  = "character_set_connection"
    value = "utf8mb4"
  }

  parameter {
    name  = "character_set_database"
    value = "utf8mb4"
  }

  parameter {
    name  = "character_set_results"
    value = "utf8mb4"
  }

  parameter {
    name  = "collation_server"
    value = "utf8mb4_unicode_ci"
  }

  parameter {
    name  = "collation_connection"
    value = "utf8mb4_unicode_ci"
  }

  tags = {
    Name = "${var.name_prefix}-mysql84-params"
  }
}

resource "random_password" "db_password" {
  length           = 32
  special          = true
  override_special = "!#$%&*()-_=+[]{}<>:?"
}

resource "aws_db_instance" "main" {
  identifier = "${var.name_prefix}-mysql"

  engine         = "mysql"
  engine_version = "8.4"
  instance_class = var.db_instance_class

  allocated_storage     = var.db_allocated_storage
  max_allocated_storage = var.db_max_allocated_storage
  storage_type          = "gp3"
  storage_encrypted     = true

  db_name  = var.db_name
  username = var.db_username
  password = random_password.db_password.result

  db_subnet_group_name   = aws_db_subnet_group.rds.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  parameter_group_name   = aws_db_parameter_group.mysql84.name

  publicly_accessible = false
  multi_az            = false # Plan §0, §8: Single instance in V1

  backup_retention_period = 7
  backup_window           = "03:00-04:00"
  maintenance_window      = "Sun:04:30-Sun:05:30"

  auto_minor_version_upgrade = true
  deletion_protection        = var.protect_data
  skip_final_snapshot        = !var.protect_data
  # A final snapshot makes a mistaken terraform destroy recoverable.
  final_snapshot_identifier = var.protect_data ? "${var.name_prefix}-mysql-final" : null

  tags = {
    Name = "${var.name_prefix}-mysql"
  }
}
