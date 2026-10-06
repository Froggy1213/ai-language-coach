# S3 Bucket for Audio Recordings & Transcripts (Plan §5, §7, §8)
#
# Requirements:
# - Strict public access block.
# - AES256 server-side encryption.
# - CORS configuration for direct browser presigned POST uploads (decisions 13 & 31).
# - Lifecycle policy 1: Clean up raw audio under assessments/ (Plan §5, §7).
#   Raw audio is normally deleted by Horizon immediately after STT processing;
#   this rule ensures orphaned/failed recordings do not linger beyond STT needs.
# - Lifecycle policy 2: Transcript archive retention and tiering under transcripts/ (Plan §5, §7).

resource "random_id" "bucket_suffix" {
  byte_length = 4
}

resource "aws_s3_bucket" "storage" {
  bucket        = "${var.name_prefix}-storage-${random_id.bucket_suffix.hex}"
  force_destroy = false

  tags = {
    Name    = "${var.name_prefix}-storage"
    Purpose = "audio-assessments-and-transcripts"
  }
}

resource "aws_s3_bucket_public_access_block" "storage" {
  bucket = aws_s3_bucket.storage.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "storage" {
  bucket = aws_s3_bucket.storage.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_cors_configuration" "storage" {
  bucket = aws_s3_bucket.storage.id

  cors_rule {
    id              = "BrowserPresignedPostUploads"
    allowed_methods = ["POST", "GET", "HEAD"]
    allowed_origins = var.cors_allowed_origins
    allowed_headers = ["*"]
    expose_headers  = ["ETag"]
    max_age_seconds = 3600
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "storage" {
  bucket = aws_s3_bucket.storage.id

  # Rule 1: Raw assessment audio cleanup (Plan §5, §7)
  # Raw audio must not be retained longer than required for STT batch processing.
  rule {
    id     = "RawAudioCleanup"
    status = "Enabled"

    filter {
      prefix = "assessments/"
    }

    expiration {
      days = var.audio_retention_days
    }
  }

  # Rule 2: Transcript archive retention (Plan §5, §7)
  rule {
    id     = "TranscriptsArchiveRetention"
    status = "Enabled"

    filter {
      prefix = "transcripts/"
    }

    transition {
      days          = 30
      storage_class = "STANDARD_IA"
    }

    expiration {
      days = var.transcript_retention_days
    }
  }
}
