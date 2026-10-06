#!/usr/bin/env python3
"""
AI Language Coach — Dialogue LLM First-Token Latency Benchmark
==============================================================
Self-contained harness measuring Time-To-First-Token (TTFT) and total turn
latency for candidate dialogue models in the real-time voice agent.

Reference:
- Plan ai-language-coach-plan.md §5, §6 (Nov week 1), §7
- Target conversational turnaround budget: STT (~400ms) + TTFT (≤600ms) + TTS (~150ms) ≤ 1200ms (P95)
"""

import argparse
import datetime
import http.client
import json
import math
import os
import re
import sys
import time
import urllib.parse
from typing import Any, Dict, List, Optional, Tuple


# ---------------------------------------------------------------------------
# Default Candidate Configurations & Environment
# ---------------------------------------------------------------------------

DEFAULT_CANDIDATES = [
    "deepseek:deepseek-chat",
    "openai:gpt-4o-mini",
    "groq:llama-3.3-70b-versatile",
    "groq:llama-3.1-8b-instant",
]

DEFAULT_SYSTEM_PROMPT = """You are an expert, empathetic AI Language Coach.
You are conducting a 1-on-1 spoken practice session with a language learner.

Session Details:
- Target Language: en
- Student CEFR Level: A2
- Target Grammar Point: Present Perfect (past experiences vs finished past actions)
- Practice Goal / Scenario: Practice discussing past travel experiences and visits.

Guidelines:
1. Speak exclusively or predominantly in the target language (en), tailored to level A2.
2. Keep your conversational turns concise (1 to 3 short sentences maximum). You are speaking aloud over voice.
3. Be conversational and interactive: ask open-ended questions so the student does most of the talking.
4. When the student makes an error related to 'Present Perfect', provide a gentle rephrase or brief correction naturally in conversation.
5. Keep the atmosphere supportive, friendly, and motivating."""

DEFAULT_LEARNER_UTTERANCE = (
    "Yesterday I have visited my friend in London, but I didn't saw Big Ben yet. Have you ever went there?"
)


def load_env_files() -> None:
    """
    Loads environment variables from .env files without requiring third-party libraries.
    Checks repo root .env and agent/.env.
    """
    cwd = os.getcwd()
    candidates = [
        os.path.join(cwd, ".env"),
        os.path.join(cwd, "agent", ".env"),
        os.path.join(os.path.dirname(__file__), "..", ".env"),
        os.path.join(os.path.dirname(__file__), "..", "agent", ".env"),
    ]

    for env_path in candidates:
        norm_path = os.path.abspath(env_path)
        if os.path.isfile(norm_path):
            try:
                with open(norm_path, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        key, val = line.split("=", 1)
                        key = key.strip()
                        val = val.strip().strip("'\"")
                        if key and key not in os.environ:
                            os.environ[key] = val
            except Exception:
                pass


# ---------------------------------------------------------------------------
# Statistical Helpers
# ---------------------------------------------------------------------------

def calculate_percentile(sorted_data: List[float], p: float) -> float:
    """
    Computes p-th percentile using linear interpolation (C=1).
    """
    if not sorted_data:
        return 0.0
    if len(sorted_data) == 1:
        return sorted_data[0]

    k = (len(sorted_data) - 1) * (p / 100.0)
    f = math.floor(k)
    c = math.ceil(k)
    if f == c:
        return sorted_data[int(k)]
    d0 = sorted_data[int(f)] * (c - k)
    d1 = sorted_data[int(c)] * (k - f)
    return d0 + d1


def compute_statistics(values: List[float]) -> Dict[str, Any]:
    """
    Computes summary statistics for a numeric sample set:
    count, mean, min, max, p50, p90, p95, and stddev.
    """
    if not values:
        return {
            "count": 0,
            "mean": None,
            "min": None,
            "max": None,
            "p50": None,
            "p90": None,
            "p95": None,
            "stddev": None,
        }

    sorted_vals = sorted(values)
    n = len(sorted_vals)
    mean_val = sum(sorted_vals) / n
    variance = sum((x - mean_val) ** 2 for x in sorted_vals) / n if n > 1 else 0.0
    stddev = math.sqrt(variance)

    return {
        "count": n,
        "mean": round(mean_val, 2),
        "min": round(sorted_vals[0], 2),
        "max": round(sorted_vals[-1], 2),
        "p50": round(calculate_percentile(sorted_vals, 50.0), 2),
        "p90": round(calculate_percentile(sorted_vals, 90.0), 2),
        "p95": round(calculate_percentile(sorted_vals, 95.0), 2),
        "stddev": round(stddev, 2),
    }


# ---------------------------------------------------------------------------
# Provider & Endpoint Resolution
# ---------------------------------------------------------------------------

class CandidateModel:
    def __init__(self, raw_spec: str):
        self.raw_spec = raw_spec.strip()
        parts = self.raw_spec.split(":", 2)
        if len(parts) == 1:
            self.provider = "custom"
            self.model_id = parts[0]
            self.custom_base_url: Optional[str] = None
        elif len(parts) == 2:
            self.provider = parts[0].strip().lower()
            self.model_id = parts[1].strip()
            self.custom_base_url = None
        else:
            self.provider = parts[0].strip().lower()
            self.model_id = parts[1].strip()
            self.custom_base_url = parts[2].strip()

        self.api_key: Optional[str] = None
        self.base_url: str = ""
        self.host: str = ""
        self.port: int = 443
        self.endpoint_path: str = ""
        self.is_https: bool = True
        self.skip_reason: Optional[str] = None

    def resolve(self) -> bool:
        """
        Resolves API key, base URL, and HTTP endpoint parameters from environment.
        Returns True if candidate is runnable, False if skipped due to missing credentials.
        """
        universal_key = (os.getenv("LLM_API_KEY") or "").strip() or None

        if self.provider == "deepseek":
            self.api_key = universal_key or (os.getenv("DEEPSEEK_API_KEY") or "").strip() or None
            default_base = "https://api.deepseek.com"
            missing_var = "DEEPSEEK_API_KEY"
        elif self.provider == "openai":
            self.api_key = universal_key or (os.getenv("OPENAI_API_KEY") or "").strip() or None
            default_base = "https://api.openai.com/v1"
            missing_var = "OPENAI_API_KEY"
        elif self.provider == "groq":
            self.api_key = universal_key or (os.getenv("GROQ_API_KEY") or "").strip() or None
            default_base = "https://api.groq.com/openai/v1"
            missing_var = "GROQ_API_KEY"
        else:
            provider_var = f"{self.provider.upper()}_API_KEY"
            self.api_key = universal_key or (os.getenv(provider_var) or "").strip() or None
            default_base = os.getenv("LLM_BASE_URL", "https://api.openai.com/v1")
            missing_var = f"{provider_var} or LLM_API_KEY"

        if not self.api_key:
            self.skip_reason = f"Missing environment variable {missing_var}"
            return False

        chosen_base = self.custom_base_url or os.getenv("LLM_BASE_URL") or default_base
        self.base_url = chosen_base

        parsed = urllib.parse.urlsplit(chosen_base)
        self.is_https = parsed.scheme.lower() != "http"
        self.port = parsed.port or (443 if self.is_https else 80)
        self.host = parsed.hostname or "localhost"

        base_path = parsed.path.rstrip("/")
        if base_path.endswith("/chat/completions"):
            self.endpoint_path = base_path
        elif base_path:
            self.endpoint_path = f"{base_path}/chat/completions"
        else:
            self.endpoint_path = "/chat/completions"

        return True


# ---------------------------------------------------------------------------
# Streaming Chat Completion Execution
# ---------------------------------------------------------------------------

def execute_streaming_request(
    candidate: CandidateModel,
    messages: List[Dict[str, str]],
    timeout: float,
    max_tokens: int = 150,
    temperature: float = 0.7,
    existing_conn: Optional[http.client.HTTPConnection] = None,
) -> Tuple[Optional[float], Optional[float], int, str, Optional[http.client.HTTPConnection]]:
    """
    Performs an OpenAI-compatible streaming chat completion over HTTP/HTTPS.
    Measures:
    - TTFT: elapsed time from request dispatch to first non-empty text token parsed from SSE stream.
    - Total turnaround: elapsed time until stream completion ([DONE] or EOF).

    Returns:
    (ttft_ms, total_ms, token_count, text_content, connection)
    """
    conn = existing_conn
    if conn is None:
        if candidate.is_https:
            conn = http.client.HTTPSConnection(candidate.host, candidate.port, timeout=timeout)
        else:
            conn = http.client.HTTPConnection(candidate.host, candidate.port, timeout=timeout)

    payload = {
        "model": candidate.model_id,
        "messages": messages,
        "stream": True,
        "max_tokens": max_tokens,
        "temperature": temperature,
    }
    body = json.dumps(payload).encode("utf-8")
    headers = {
        "Authorization": f"Bearer {candidate.api_key}",
        "Content-Type": "application/json",
        "Accept": "text/event-stream",
        "User-Agent": "ai-language-coach-benchmark/1.0",
        "Connection": "keep-alive",
    }

    t_start = time.perf_counter()

    try:
        conn.request("POST", candidate.endpoint_path, body=body, headers=headers)
        resp = conn.getresponse()
    except (http.client.HTTPException, OSError):
        # Socket was closed by remote server or unconsumed state; reconnect cleanly
        try:
            conn.close()
        except Exception:
            pass
        if candidate.is_https:
            conn = http.client.HTTPSConnection(candidate.host, candidate.port, timeout=timeout)
        else:
            conn = http.client.HTTPConnection(candidate.host, candidate.port, timeout=timeout)
        t_start = time.perf_counter()
        conn.request("POST", candidate.endpoint_path, body=body, headers=headers)
        resp = conn.getresponse()

    if resp.status != 200:
        err_msg = resp.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {resp.status}: {err_msg[:200]}")

    ttft_ms: Optional[float] = None
    tokens: List[str] = []

    # Read SSE stream chunk by chunk
    try:
        for raw_line in resp:
            line = raw_line.decode("utf-8", errors="replace").strip()
            if not line:
                continue
            if line.startswith("data: "):
                data_content = line[6:].strip()
                if data_content == "[DONE]":
                    break
                try:
                    chunk = json.loads(data_content)
                    choices = chunk.get("choices") or []
                    if choices:
                        delta = choices[0].get("delta") or {}
                        content = delta.get("content") or ""
                        # Reasoning models may stream thinking tokens under reasoning_content
                        if not content and "reasoning_content" in delta:
                            content = delta.get("reasoning_content") or ""
                        if content:
                            if ttft_ms is None:
                                ttft_ms = (time.perf_counter() - t_start) * 1000.0
                            tokens.append(content)
                except json.JSONDecodeError:
                    continue
    finally:
        # Drain remaining EOF so the keep-alive socket is left clean for subsequent requests
        try:
            resp.read()
        except Exception:
            pass

    t_end = time.perf_counter()
    total_ms = (t_end - t_start) * 1000.0
    text_content = "".join(tokens)

    return ttft_ms, total_ms, len(tokens), text_content, conn


# ---------------------------------------------------------------------------
# Benchmark Runner
# ---------------------------------------------------------------------------

def run_benchmark(
    candidates_raw: List[str],
    repetitions: int,
    timeout: float,
    system_prompt: str,
    learner_utterance: str,
    warm_up: bool = True,
    max_tokens: int = 150,
    temperature: float = 0.7,
) -> Dict[str, Any]:
    """
    Executes the latency benchmark across configured candidates.
    Captures warm-up, raw samples, summary statistics, and skipped providers.
    """
    messages = [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": learner_utterance},
    ]

    timestamp_utc = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    benchmark_data: Dict[str, Any] = {
        "timestamp_utc": timestamp_utc,
        "config": {
            "repetitions": repetitions,
            "timeout_seconds": timeout,
            "max_tokens": max_tokens,
            "temperature": temperature,
            "warm_up_included_in_stats": False,
            "turn_budget_p95_ms": 600.0,
        },
        "system_prompt": system_prompt,
        "learner_utterance": learner_utterance,
        "candidates": [],
        "skipped_candidates": [],
        "results": {},
    }

    candidates = [CandidateModel(raw) for raw in candidates_raw]

    print("=" * 80)
    print("AI Language Coach — Dialogue LLM First-Token Latency Benchmark")
    print(f"Timestamp (UTC): {timestamp_utc}")
    print(f"Repetitions per candidate: {repetitions} (warm-up run excluded from statistics)")
    print(f"Per-request timeout: {timeout}s")
    print("=" * 80)

    # 1. Inspect and classify candidates
    active_candidates: List[CandidateModel] = []
    for c in candidates:
        if c.resolve():
            active_candidates.append(c)
            benchmark_data["candidates"].append({
                "provider": c.provider,
                "model_id": c.model_id,
                "base_url": c.base_url,
                "status": "ready",
            })
        else:
            benchmark_data["skipped_candidates"].append({
                "provider": c.provider,
                "model_id": c.model_id,
                "status": "skipped",
                "reason": c.skip_reason,
            })
            print(f"[-] SKIPPED: {c.provider}:{c.model_id} -> {c.skip_reason}")

    if not active_candidates:
        print("\n[!] No candidates have valid API keys configured. Exiting.")
        return benchmark_data

    # 2. Benchmark each active candidate
    for c in active_candidates:
        cand_key = f"{c.provider}:{c.model_id}"
        print(f"\n[+] Testing candidate: {cand_key} ({c.base_url})")

        conn: Optional[http.client.HTTPConnection] = None
        warmup_result: Dict[str, Any] = {"status": "none"}

        # Warm-up run (excluded from stats)
        if warm_up:
            print("    -> Warm-up run (primes TCP/TLS connection & DNS)...", end="", flush=True)
            try:
                w_ttft, w_total, w_tokens, _, conn = execute_streaming_request(
                    candidate=c,
                    messages=messages,
                    timeout=timeout,
                    max_tokens=max_tokens,
                    temperature=temperature,
                    existing_conn=conn,
                )
                warmup_result = {
                    "status": "success",
                    "ttft_ms": round(w_ttft, 2) if w_ttft else None,
                    "total_ms": round(w_total, 2) if w_total else None,
                    "tokens": w_tokens,
                }
                print(f" done (TTFT: {w_ttft:.1f}ms, Total: {w_total:.1f}ms)")
            except Exception as exc:
                warmup_result = {"status": "failed", "error": str(exc)}
                print(f" failed ({exc})")
                if conn:
                    try:
                        conn.close()
                    except Exception:
                        pass
                    conn = None

        # Measured repetitions
        raw_samples: List[Dict[str, Any]] = []
        ttft_samples: List[float] = []
        total_samples: List[float] = []
        text_samples: List[str] = []

        for rep in range(1, repetitions + 1):
            print(f"    -> Sample {rep}/{repetitions}...", end="", flush=True)
            sample_record: Dict[str, Any] = {
                "iteration": rep,
                "success": False,
                "ttft_ms": None,
                "total_ms": None,
                "token_count": 0,
                "error": None,
            }

            try:
                s_ttft, s_total, s_tokens, s_text, conn = execute_streaming_request(
                    candidate=c,
                    messages=messages,
                    timeout=timeout,
                    max_tokens=max_tokens,
                    temperature=temperature,
                    existing_conn=conn,
                )
                sample_record["success"] = True
                sample_record["ttft_ms"] = round(s_ttft, 2) if s_ttft else None
                sample_record["total_ms"] = round(s_total, 2) if s_total else None
                sample_record["token_count"] = s_tokens
                if s_text and rep == 1:
                    text_samples.append(s_text.strip())

                if s_ttft is not None:
                    ttft_samples.append(s_ttft)
                if s_total is not None:
                    total_samples.append(s_total)

                print(f" TTFT: {s_ttft:.1f}ms | Total: {s_total:.1f}ms ({s_tokens} tokens)")
            except Exception as exc:
                sample_record["error"] = str(exc)
                print(f" FAILED: {exc}")
                if conn:
                    try:
                        conn.close()
                    except Exception:
                        pass
                    conn = None

            raw_samples.append(sample_record)
            # Brief pause to avoid aggressive rate limits
            time.sleep(0.2)

        if conn:
            try:
                conn.close()
            except Exception:
                pass

        ttft_stats = compute_statistics(ttft_samples)
        total_stats = compute_statistics(total_samples)

        benchmark_data["results"][cand_key] = {
            "provider": c.provider,
            "model_id": c.model_id,
            "base_url": c.base_url,
            "warm_up": warmup_result,
            "successful_runs": len(ttft_samples),
            "total_runs": repetitions,
            "ttft_ms": ttft_stats,
            "total_ms": total_stats,
            "sample_response_text": text_samples[0] if text_samples else "",
            "raw_samples": raw_samples,
        }

    return benchmark_data


# ---------------------------------------------------------------------------
# Output Formatters
# ---------------------------------------------------------------------------

def print_results_table(benchmark_data: Dict[str, Any]) -> None:
    """
    Prints a formatted summary table of benchmark results to stdout.
    """
    results = benchmark_data.get("results", {})
    skipped = benchmark_data.get("skipped_candidates", [])

    print("\n" + "=" * 105)
    print("BENCHMARK RESULTS SUMMARY (TTFT & Total Latency in milliseconds)")
    print("=" * 105)
    header = (
        f"{'Candidate':<32} | {'Runs':<7} | {'TTFT P50':<9} | {'TTFT P90':<9} | "
        f"{'TTFT P95':<9} | {'TTFT Mean':<10} | {'Total P50':<10} | {'Status'}"
    )
    print(header)
    print("-" * 105)

    for cand_key, r in results.items():
        ttft = r.get("ttft_ms", {})
        total = r.get("total_ms", {})
        n = f"{r.get('successful_runs')}/{r.get('total_runs')}"

        p50 = f"{ttft['p50']:.1f}" if ttft.get("p50") is not None else "-"
        p90 = f"{ttft['p90']:.1f}" if ttft.get("p90") is not None else "-"
        p95 = f"{ttft['p95']:.1f}" if ttft.get("p95") is not None else "-"
        mean = f"{ttft['mean']:.1f}" if ttft.get("mean") is not None else "-"
        tot_p50 = f"{total['p50']:.1f}" if total.get("p50") is not None else "-"

        # Turn budget verdict
        if ttft.get("p95") is not None:
            if ttft["p95"] <= 600.0:
                verdict = "PASS (<=600ms)"
            elif ttft["p95"] <= 800.0:
                verdict = "BORDERLINE (<=800ms)"
            else:
                verdict = "FAIL (>800ms budget)"
        else:
            verdict = "ERROR"

        row = (
            f"{cand_key:<32} | {n:<7} | {p50:<9} | {p90:<9} | "
            f"{p95:<9} | {mean:<10} | {tot_p50:<10} | {verdict}"
        )
        print(row)

    for s in skipped:
        cand_key = f"{s['provider']}:{s['model_id']}"
        row = (
            f"{cand_key:<32} | {'0/0':<7} | {'-':<9} | {'-':<9} | "
            f"{'-':<9} | {'-':<10} | {'-':<10} | SKIPPED (no key)"
        )
        print(row)

    print("=" * 105)


def save_results_json(benchmark_data: Dict[str, Any], output_dir: str) -> str:
    """
    Saves sanitized results to benchmarks/results/<utc-timestamp>.json.
    Guarantees no secret keys are present in output.
    """
    os.makedirs(output_dir, exist_ok=True)
    ts = benchmark_data["timestamp_utc"].replace(":", "-")
    filename = f"{ts}.json"
    filepath = os.path.join(output_dir, filename)

    with open(filepath, "w", encoding="utf-8") as f:
        json.dump(benchmark_data, f, indent=2, ensure_ascii=False)

    return filepath


# ---------------------------------------------------------------------------
# CLI Entrypoint
# ---------------------------------------------------------------------------

def main() -> int:
    load_env_files()

    parser = argparse.ArgumentParser(
        description="Benchmark first-token latency (TTFT) for candidate dialogue LLMs."
    )
    parser.add_argument(
        "--models",
        "-m",
        type=str,
        default=os.getenv("BENCHMARK_MODELS"),
        help="Comma-separated candidate models (e.g. 'deepseek:deepseek-chat,openai:gpt-4o-mini')",
    )
    parser.add_argument(
        "--repetitions",
        "-n",
        type=int,
        default=int(os.getenv("BENCHMARK_REPETITIONS", "10")),
        help="Number of repetitions per candidate (default: 10)",
    )
    parser.add_argument(
        "--timeout",
        "-t",
        type=float,
        default=float(os.getenv("BENCHMARK_TIMEOUT", "20.0")),
        help="HTTP request timeout in seconds (default: 20.0)",
    )
    parser.add_argument(
        "--output-dir",
        "-o",
        type=str,
        default=os.path.join(os.path.dirname(__file__), "results"),
        help="Directory to save JSON benchmark result",
    )
    parser.add_argument(
        "--no-warmup",
        action="store_true",
        help="Skip unmeasured warm-up request prior to repetitions",
    )
    parser.add_argument(
        "--json-only",
        action="store_true",
        help="Suppress human table and print JSON only",
    )

    args = parser.parse_args()

    if args.models:
        candidates_raw = [m.strip() for m in args.models.split(",") if m.strip()]
    else:
        candidates_raw = DEFAULT_CANDIDATES

    data = run_benchmark(
        candidates_raw=candidates_raw,
        repetitions=max(1, args.repetitions),
        timeout=args.timeout,
        system_prompt=DEFAULT_SYSTEM_PROMPT,
        learner_utterance=DEFAULT_LEARNER_UTTERANCE,
        warm_up=not args.no_warmup,
    )

    filepath = save_results_json(data, args.output_dir)

    if args.json_only:
        print(json.dumps(data, indent=2))
    else:
        print_results_table(data)
        print(f"\n[+] Results successfully saved to: {filepath}\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
