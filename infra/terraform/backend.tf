# Remote State Configuration (Plan §8, Milestone 2)
#
# S3 + DynamoDB remote state backend configuration with state locking.
#
# ==============================================================================
# MANUAL BOOTSTRAP STEPS (run ONCE before enabling remote state):
# ==============================================================================
#
# 1. Create a dedicated S3 bucket with versioning and AES256 encryption:
#    aws s3api create-bucket \
#      --bucket "ai-language-coach-terraform-state-<ACCOUNT_ID>" \
#      --region us-east-1
#
#    aws s3api put-bucket-versioning \
#      --bucket "ai-language-coach-terraform-state-<ACCOUNT_ID>" \
#      --versioning-configuration Status=Enabled
#
#    aws s3api put-bucket-encryption \
#      --bucket "ai-language-coach-terraform-state-<ACCOUNT_ID>" \
#      --server-side-encryption-configuration '{
#        "Rules": [{"ApplyServerSideEncryptionByDefault": {"SSEAlgorithm": "AES256"}}]
#      }'
#
#    aws s3api put-public-access-block \
#      --bucket "ai-language-coach-terraform-state-<ACCOUNT_ID>" \
#      --public-access-block-configuration '{
#        "BlockPublicAcls": true,
#        "IgnorePublicAcls": true,
#        "BlockPublicPolicy": true,
#        "RestrictPublicBuckets": true
#      }'
#
# 2. Create the DynamoDB table for distributed state locking:
#    aws dynamodb create-table \
#      --table-name "ai-language-coach-terraform-locks" \
#      --attribute-definitions AttributeName=LockID,AttributeType=S \
#      --key-schema AttributeName=LockID,KeyType=HASH \
#      --billing-mode PAY_PER_REQUEST \
#      --region us-east-1
#
# 3. Uncomment the block below, replace <ACCOUNT_ID>, and run:
#    terraform init -migrate-state
# ==============================================================================

# terraform {
#   backend "s3" {
#     bucket         = "ai-language-coach-terraform-state-<ACCOUNT_ID>"
#     key            = "production/terraform.tfstate"
#     region         = "us-east-1"
#     dynamodb_table = "ai-language-coach-terraform-locks"
#     encrypt        = true
#   }
# }
