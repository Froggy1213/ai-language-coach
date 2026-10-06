# AWS Budgets and Cost Monitoring (Plan §7, §8)
#
# ==============================================================================
# DOCUMENTED COST MODEL (Voice Minutes -> Providers + AWS Compute):
# ==============================================================================
#
# Variable cost per 1 active voice dialogue minute:
# 1. STT (Deepgram Nova-2 streaming):
#    $0.0043 per minute.
# 2. TTS (Cartesia Sonic streaming):
#    $0.075 per 1,000 characters. At ~80-100 spoken words/min (~500 chars/min),
#    TTS cost is ~$0.0038 - $0.0050 per minute.
# 3. Dialogue LLM (DeepSeek-V3 or conversational LLM):
#    ~300 tokens input + 100 tokens output per conversational turn (~2-3 turns/min).
#    DeepSeek-V3 rates: $0.14/M input, $0.28/M output -> ~$0.0002 / minute.
# 4. Async Mistake Analysis (DeepSeek-V3 batch at room close):
#    ~1,500 prompt tokens + 400 output tokens per completed session.
#    Cost per session is < $0.0005.
# 5. LiveKit EC2 compute (t4g.medium: ~$0.0336/hr):
#    Amortized across concurrent sessions: ~$0.00056/min of capacity.
# 6. ECS Fargate voice-agent task (1 vCPU, 2GB: ~$0.048/hr):
#    With 3 concurrent sessions per task (var.voice_agent_max_processes):
#    ~$0.00026/min per active student.
# 7. S3 raw audio + transcripts storage & network egress:
#    ~$0.0001/min.
#
# TOTAL VARIABLE COST PER ACTIVE DIALOGUE MINUTE: ~$0.009 - $0.012 / minute.
# (Conservative ceiling with network egress: ~$0.015 / minute).
#
# Fixed monthly AWS infrastructure baseline (V1 without NAT Gateway):
# - RDS MySQL (db.t4g.small, 20GB gp3):             ~$26.00 / month
# - ElastiCache Redis (cache.t4g.micro):           ~$12.50 / month
# - LiveKit EC2 (t4g.medium, 30GB gp3, Elastic IP): ~$27.00 / month
# - Application Load Balancer:                      ~$18.00 / month
# - ECS Fargate baseline (laravel-app x2, workers): ~$35.00 / month
# ------------------------------------------------------------------------------
# Fixed Baseline Total:                            ~$118.50 / month
# Note: Omitting NAT Gateway saves ~$32.40/month baseline + $0.045/GB data charge.
#
# A $100 - $150 budget safely covers baseline infrastructure plus ~2,500 to ~4,000
# student dialogue minutes per month.
# ==============================================================================

resource "aws_budgets_budget" "monthly_spend" {
  name         = "${var.name_prefix}-monthly-budget"
  budget_type  = "COST"
  limit_amount = tostring(var.monthly_budget_usd)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  # Alert 1: Actual spend reaches 80% of monthly budget
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 80
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = var.budget_alert_emails
  }

  # Alert 2: Forecasted spend exceeds 100% of monthly budget
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = var.budget_alert_emails
  }
}
