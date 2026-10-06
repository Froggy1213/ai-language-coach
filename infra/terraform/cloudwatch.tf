# CloudWatch Logs, Metric Filters, Alarms, and Dashboard (Plan §5, §7, §8)
#
# Constraints & Requirements:
# - Log groups with pinned retention for all ECS roles and EC2 LiveKit.
# - Metric filter parsing the EXACT log line emitted by agent/agent.py (decision 32):
#   `TURN_LATENCY {"event": "voice_turn_latency", ...}` (do not alter app code!).
# - CloudWatch Alarms for:
#   1. ALB 5xx errors (Target 5XX count)
#   2. ECS task running count / task health
#   3. Voice turn P95 latency > 2000 ms (Plan §7)
# - CloudWatch Dashboard aggregating P95 latency, stage breakdown, and task metrics.

locals {
  service_names = ["laravel-app", "horizon-worker", "reverb", "voice-agent-worker", "web"]
}

# ------------------------------------------------------------------------------
# CloudWatch Log Groups
# ------------------------------------------------------------------------------
resource "aws_cloudwatch_log_group" "services" {
  for_each          = toset(local.service_names)
  name              = "/ecs/${var.name_prefix}-${each.key}"
  retention_in_days = var.log_retention_days

  tags = {
    Name    = "/ecs/${var.name_prefix}-${each.key}"
    Service = each.key
  }
}

resource "aws_cloudwatch_log_group" "livekit" {
  name              = "/ec2/${var.name_prefix}-livekit"
  retention_in_days = var.log_retention_days

  tags = {
    Name    = "/ec2/${var.name_prefix}-livekit"
    Service = "livekit-server"
  }
}

# ------------------------------------------------------------------------------
# Metric Filter: Voice Turn Latency from stdout logs (Plan §5, §7, decision 32)
#
# Exact log shape emitted by agent/agent.py:
# `YYYY-MM-DD HH:MM:SS,mmm [INFO] ai-language-coach-agent: TURN_LATENCY {"event": "voice_turn_latency", ...}`
# ------------------------------------------------------------------------------
resource "aws_cloudwatch_log_metric_filter" "voice_turnaround_latency" {
  name           = "${var.name_prefix}-turnaround-latency"
  log_group_name = aws_cloudwatch_log_group.services["voice-agent-worker"].name
  pattern        = "[..., marker = \"TURN_LATENCY\", payload]"

  metric_transformation {
    name          = "TurnaroundLatencyMs"
    namespace     = "AiLanguageCoach/Voice"
    value         = "$payload.total_turnaround_ms"
    unit          = "Milliseconds"
    default_value = "0"
  }
}

resource "aws_cloudwatch_log_metric_filter" "voice_stt_latency" {
  name           = "${var.name_prefix}-stt-latency"
  log_group_name = aws_cloudwatch_log_group.services["voice-agent-worker"].name
  pattern        = "[..., marker = \"TURN_LATENCY\", payload]"

  metric_transformation {
    name          = "SttLatencyMs"
    namespace     = "AiLanguageCoach/Voice"
    value         = "$payload.stt_final_ms"
    unit          = "Milliseconds"
    default_value = "0"
  }
}

resource "aws_cloudwatch_log_metric_filter" "voice_llm_ttft" {
  name           = "${var.name_prefix}-llm-ttft"
  log_group_name = aws_cloudwatch_log_group.services["voice-agent-worker"].name
  pattern        = "[..., marker = \"TURN_LATENCY\", payload]"

  metric_transformation {
    name          = "LlmTtftMs"
    namespace     = "AiLanguageCoach/Voice"
    value         = "$payload.llm_first_token_ms"
    unit          = "Milliseconds"
    default_value = "0"
  }
}

resource "aws_cloudwatch_log_metric_filter" "voice_tts_ttfb" {
  name           = "${var.name_prefix}-tts-ttfb"
  log_group_name = aws_cloudwatch_log_group.services["voice-agent-worker"].name
  pattern        = "[..., marker = \"TURN_LATENCY\", payload]"

  metric_transformation {
    name          = "TtsTtfbMs"
    namespace     = "AiLanguageCoach/Voice"
    value         = "$payload.tts_first_chunk_ms"
    unit          = "Milliseconds"
    default_value = "0"
  }
}

# ------------------------------------------------------------------------------
# CloudWatch Alarms (Plan §7, §8)
# ------------------------------------------------------------------------------
# 1. Voice Turn P95 Latency Alarm (> 2000ms over 5 minutes)
resource "aws_cloudwatch_metric_alarm" "turn_p95_latency" {
  alarm_name          = "${var.name_prefix}-voice-turn-p95-latency-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  threshold           = 2000 # 2.0 seconds target upper bound
  alarm_description   = "Voice dialogue P95 turnaround latency exceeded 2000ms threshold (Plan §7)"
  treat_missing_data  = "notBreaching"

  metric_name        = "TurnaroundLatencyMs"
  namespace          = "AiLanguageCoach/Voice"
  period             = 300
  extended_statistic = "p95"

  depends_on = [aws_cloudwatch_log_metric_filter.voice_turnaround_latency]
}

# 2. ALB 5xx Error Rate Alarm (> 10 errors over 5 minutes)
resource "aws_cloudwatch_metric_alarm" "alb_5xx_errors" {
  alarm_name          = "${var.name_prefix}-alb-5xx-errors-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  metric_name         = "HTTPCode_Target_5XX_Count"
  namespace           = "AWS/ApplicationELB"
  period              = 300
  statistic           = "Sum"
  threshold           = 10
  alarm_description   = "ALB target 5XX error count is elevated (> 10 in 5m)"
  treat_missing_data  = "notBreaching"

  dimensions = {
    LoadBalancer = aws_lb.main.arn_suffix
  }
}

# 3. Voice Agent Fleet Running Task Count Low (< 1 running task)
resource "aws_cloudwatch_metric_alarm" "voice_agent_task_count" {
  alarm_name          = "${var.name_prefix}-voice-agent-task-count-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  metric_name         = "CPUUtilization"
  namespace           = "AWS/ECS"
  period              = 300
  statistic           = "SampleCount"
  threshold           = 1
  alarm_description   = "Voice agent worker Fargate service has 0 reporting tasks"
  treat_missing_data  = "breaching"

  dimensions = {
    ClusterName = aws_ecs_cluster.main.name
    ServiceName = aws_ecs_service.voice_agent_worker.name
  }
}

# ------------------------------------------------------------------------------
# CloudWatch Operational Dashboard (Plan §7, §8)
# ------------------------------------------------------------------------------
resource "aws_cloudwatch_dashboard" "main" {
  dashboard_name = "${var.name_prefix}-operations"

  dashboard_body = jsonencode({
    widgets = [
      {
        type   = "metric"
        x      = 0
        y      = 0
        width  = 12
        height = 6
        properties = {
          title  = "Voice Turnaround Latency (P95 vs Average, ms)"
          region = var.aws_region
          period = 300
          metrics = [
            ["AiLanguageCoach/Voice", "TurnaroundLatencyMs", { stat = "p95", label = "P95 Total Turnaround (ms)", color = "#d62728" }],
            ["AiLanguageCoach/Voice", "TurnaroundLatencyMs", { stat = "Average", label = "Avg Total Turnaround (ms)", color = "#1f77b4" }]
          ]
          yAxis = { left = { min = 0, label = "Milliseconds" } }
        }
      },
      {
        type   = "metric"
        x      = 12
        y      = 0
        width  = 12
        height = 6
        properties = {
          title  = "Voice Stage Breakdown (Average ms: STT -> LLM -> TTS)"
          region = var.aws_region
          period = 300
          metrics = [
            ["AiLanguageCoach/Voice", "SttLatencyMs", { stat = "Average", label = "STT Final (Deepgram, ms)", color = "#ff7f0e" }],
            ["AiLanguageCoach/Voice", "LlmTtftMs", { stat = "Average", label = "LLM First Token (TTFT, ms)", color = "#2ca02c" }],
            ["AiLanguageCoach/Voice", "TtsTtfbMs", { stat = "Average", label = "TTS First Chunk (Cartesia, ms)", color = "#9467bd" }]
          ]
          yAxis = { left = { min = 0, label = "Milliseconds" } }
        }
      },
      {
        type   = "metric"
        x      = 0
        y      = 6
        width  = 12
        height = 6
        properties = {
          title  = "ALB Traffic & Errors"
          region = var.aws_region
          period = 300
          metrics = [
            ["AWS/ApplicationELB", "RequestCount", "LoadBalancer", aws_lb.main.arn_suffix, { stat = "Sum", label = "Requests" }],
            ["AWS/ApplicationELB", "HTTPCode_Target_5XX_Count", "LoadBalancer", aws_lb.main.arn_suffix, { stat = "Sum", label = "Target 5XX", color = "#d62728" }],
            ["AWS/ApplicationELB", "TargetResponseTime", "LoadBalancer", aws_lb.main.arn_suffix, { stat = "p95", label = "P95 Target Response Time (s)" }]
          ]
        }
      },
      {
        type   = "metric"
        x      = 12
        y      = 6
        width  = 12
        height = 6
        properties = {
          title  = "ECS Fargate CPU Utilization (%)"
          region = var.aws_region
          period = 300
          metrics = [
            ["AWS/ECS", "CPUUtilization", "ClusterName", aws_ecs_cluster.main.name, "ServiceName", aws_ecs_service.laravel_app.name, { stat = "Average", label = "laravel-app" }],
            ["AWS/ECS", "CPUUtilization", "ClusterName", aws_ecs_cluster.main.name, "ServiceName", aws_ecs_service.horizon_worker.name, { stat = "Average", label = "horizon-worker" }],
            ["AWS/ECS", "CPUUtilization", "ClusterName", aws_ecs_cluster.main.name, "ServiceName", aws_ecs_service.reverb.name, { stat = "Average", label = "reverb" }],
            ["AWS/ECS", "CPUUtilization", "ClusterName", aws_ecs_cluster.main.name, "ServiceName", aws_ecs_service.voice_agent_worker.name, { stat = "Average", label = "voice-agent-worker" }]
          ]
          yAxis = { left = { min = 0, max = 100, label = "Percent" } }
        }
      }
    ]
  })
}
