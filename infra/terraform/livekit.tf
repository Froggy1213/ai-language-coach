# Standalone LiveKit EC2 Node Configuration (Plan §2, §5, §8)
#
# Design Constraints (Non-negotiable):
# - Single EC2 instance, Elastic IP, NO HA, NO ALB.
# - Media & signaling connect directly via Elastic IP and DNS.
# - TURN/TLS uses a Let's Encrypt certificate obtained directly on the instance via certbot
#   (ACM cannot be used because LiveKit requires raw cert_file and key_file paths).
# - Native SIGTERM drain: stop_grace_period set to 15m (fitting a real call length).

# Elastic IP dedicated to the LiveKit server node
resource "aws_eip" "livekit" {
  domain = "vpc"

  tags = {
    Name = "${var.name_prefix}-livekit-eip"
    Role = "livekit-server"
  }
}

# AMI: Ubuntu 24.04 LTS Noble Numbat (arm64 for t4g or x86_64 for t3/c6i)
data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = [startswith(var.livekit_instance_type, "t4g") || startswith(var.livekit_instance_type, "c7g") ? "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-arm64-server-*" : "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

# IAM Role for LiveKit EC2 Node (SSM Session Manager & CloudWatch logs)
resource "aws_iam_role" "livekit_ec2_role" {
  name = "${var.name_prefix}-livekit-ec2-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ec2.amazonaws.com"
        }
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "livekit_ssm" {
  role       = aws_iam_role.livekit_ec2_role.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_instance_profile" "livekit" {
  name = "${var.name_prefix}-livekit-instance-profile"
  role = aws_iam_role.livekit_ec2_role.name
}

locals {
  user_data_template = fileexists("${path.module}/../livekit/user-data.sh") ? "${path.module}/../livekit/user-data.sh" : "${path.module}/user-data.sh"
}

# EC2 Instance for LiveKit Server
resource "aws_instance" "livekit" {
  ami                  = data.aws_ami.ubuntu.id
  instance_type        = var.livekit_instance_type
  subnet_id            = aws_subnet.public[0].id
  iam_instance_profile = aws_iam_instance_profile.livekit.name

  vpc_security_group_ids = [aws_security_group.livekit.id]

  root_block_device {
    volume_size           = 30
    volume_type           = "gp3"
    encrypted             = true
    delete_on_termination = true
  }

  user_data = templatefile(local.user_data_template, {
    LIVEKIT_DOMAIN     = var.livekit_domain
    LIVEKIT_NODE_IP    = aws_eip.livekit.public_ip
    VOICE_WEBHOOK_URL  = "https://${var.app_domain}/api/webhooks/livekit"
    LIVEKIT_API_KEY    = random_string.livekit_api_key.result
    LIVEKIT_API_SECRET = random_password.livekit_api_secret.result
    STOP_GRACE_PERIOD  = var.livekit_stop_grace_period
    ADMIN_EMAIL        = var.budget_alert_emails[0]
  })

  user_data_replace_on_change = true

  tags = {
    Name = "${var.name_prefix}-livekit-server"
    Role = "livekit-server"
  }
}

resource "aws_eip_association" "livekit" {
  instance_id   = aws_instance.livekit.id
  allocation_id = aws_eip.livekit.id
}
