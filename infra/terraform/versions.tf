# Pinned Terraform versions and AWS provider configuration.
# Milestone 2: AWS Infrastructure for AI Language Coach (Plan §8)

terraform {
  required_version = ">= 1.9.0, < 2.0.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.50"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "ai-language-coach"
      Environment = var.environment
      ManagedBy   = "terraform"
    }
  }
}
