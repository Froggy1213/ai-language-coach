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

# Assessment recordings are transient PII that PRIVACY.md requires to be
# permanently deleted immediately after transcription or account deletion.
# Suspending versioning prevents S3 from retaining deleted recordings as
# noncurrent versions (and cleanly transitions any pre-existing bucket).
resource "aws_s3_bucket_versioning" "storage" {
  bucket = aws_s3_bucket.storage.id

  versioning_configuration {
    status = "Suspended"
  }
}

resource "aws_s3_bucket_policy" "storage_enforce_tls" {
  bucket = aws_s3_bucket.storage.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "EnforceTLSRequestsOnly"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource = [
          aws_s3_bucket.storage.arn,
          "${aws_s3_bucket.storage.arn}/*"
        ]
        Condition = {
          Bool = {
            "aws:SecureTransport" = "false"
          }
        }
      }
    ]
  })
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

  depends_on = [aws_s3_bucket_versioning.storage]

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

  # Rule 3: Purge any historical noncurrent versions and orphaned delete markers
  # left behind by suspended versioning so deleted recordings are never retained.
  rule {
    id     = "NoncurrentAndDeleteMarkerCleanup"
    status = "Enabled"

    filter {}

    expiration {
      expired_object_delete_marker = true
    }

    noncurrent_version_expiration {
      noncurrent_days = 1
    }
  }

  # Rule 4: Abort abandoned browser/multipart uploads after 1 day.
  rule {
    id     = "AbortIncompleteMultipartUploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}
