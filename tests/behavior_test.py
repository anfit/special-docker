from __future__ import annotations

import os
import unittest

from contract_test_support import php_binary, run_php


class BehaviorTest(unittest.TestCase):
    """Runs the service-level suite in tests/behavior_test.php against the in-memory store."""

    def test_memory_store(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; behaviour suite unavailable.")
        result = run_php("tests/behavior_test.php", "memory")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("0 failed", result.stdout)

    def test_dynamodb_local(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; behaviour suite unavailable.")
        if not os.environ.get("SPECIAL_DYNAMODB_ENDPOINT"):
            self.skipTest("Set SPECIAL_DYNAMODB_ENDPOINT (DynamoDB Local) and run `composer install` to test the DynamoDB store.")
        result = run_php("tests/behavior_test.php", "dynamodb")
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("0 failed", result.stdout)
