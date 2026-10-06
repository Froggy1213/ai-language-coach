"""The worker pool the deployment configured (plan §5, §7).

livekit-agents 1.8.5 does not read these settings from the environment itself,
so `build_worker_options()` is the only thing standing between a Terraform
variable and the worker's real capacity. A silent regression here would look
exactly like a healthy fleet that ignores its own sizing.
"""

import os
import unittest
from unittest.mock import patch

from agent import WorkerOptions, build_worker_options


@unittest.skipIf(
    WorkerOptions is None,
    "livekit-agents is not installed on this host; run this suite inside the voice-agent image",
)
class TestWorkerOptionsFromEnvironment(unittest.TestCase):
    def test_it_uses_the_measured_defaults_when_nothing_is_configured(self):
        with patch.dict(os.environ, {}, clear=True):
            options = build_worker_options()

        self.assertEqual(4, options.num_idle_processes)
        self.assertAlmostEqual(0.7, options.load_threshold)
        self.assertAlmostEqual(360.0, options.drain_timeout)

    def test_it_reads_the_deployment_settings(self):
        with patch.dict(
            os.environ,
            {
                "NUM_IDLE_PROCESSES": "6",
                "LOAD_THRESHOLD": "0.55",
                "DRAIN_TIMEOUT_SECONDS": "240",
                "VOICE_AGENT_NAME": "custom-agent",
            },
            clear=True,
        ):
            options = build_worker_options()

        self.assertEqual(6, options.num_idle_processes)
        self.assertAlmostEqual(0.55, options.load_threshold)
        self.assertAlmostEqual(240.0, options.drain_timeout)
        self.assertEqual("custom-agent", options.agent_name)

    def test_a_typo_falls_back_to_the_default_instead_of_crashing_the_worker(self):
        with patch.dict(
            os.environ,
            {"NUM_IDLE_PROCESSES": "four", "LOAD_THRESHOLD": "high", "DRAIN_TIMEOUT_SECONDS": ""},
            clear=True,
        ):
            options = build_worker_options()

        self.assertEqual(4, options.num_idle_processes)
        self.assertAlmostEqual(0.7, options.load_threshold)
        self.assertAlmostEqual(360.0, options.drain_timeout)


if __name__ == "__main__":
    unittest.main()
