from __future__ import annotations

import json
import unittest

from contract_test_support import php_binary, php_json_probe, run_php

PRODUCTION = {
    "SPECIAL_ENV": "prod",
    "SPECIAL_APP_KEY": "x" * 32,
    "SPECIAL_STORE": "dynamodb",
    "SPECIAL_DYNAMODB_TABLE": "special-prod",
    "SPECIAL_AWS_REGION": "eu-central-1",
    "SPECIAL_DYNAMODB_ENDPOINT": "",
    "SPECIAL_PUBLIC_BASE_URL": "https://special.example.test",
    "SPECIAL_SESSION_SECURE": "true",
    "SPECIAL_TRUST_PROXY": "1",
    "SPECIAL_REMEMBER_DAYS": "",
}


class ConfigContractTest(unittest.TestCase):
    def setUp(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; config probe unavailable.")

    def test_production_configuration_is_read_from_the_environment(self) -> None:
        config = php_json_probe("tests/config_contract_probe.php", env=PRODUCTION)
        self.assertEqual("production", config["environment"])
        self.assertEqual("https://special.example.test", config["app_url"])
        self.assertEqual("https://special.example.test/mcp", config["mcp_resource"])
        self.assertEqual("dynamodb", config["store"])
        self.assertEqual("special-prod", config["dynamodb_table"])
        self.assertEqual("eu-central-1", config["aws_region"])
        self.assertTrue(config["session_secure"])
        self.assertTrue(config["trust_proxy"])
        self.assertEqual(60, config["remember_days"])

    def test_production_refuses_unsafe_settings(self) -> None:
        for override in (
            {"SPECIAL_STORE": "memory"},
            {"SPECIAL_AWS_REGION": ""},
            {"AWS_REGION": "", "SPECIAL_AWS_REGION": ""},
            {"SPECIAL_DYNAMODB_ENDPOINT": "http://dynamodb-local:8000"},
            {"SPECIAL_APP_KEY": "short"},
            {"SPECIAL_SESSION_SECURE": "0"},
            {"SPECIAL_PUBLIC_BASE_URL": "http://special.example.test"},
        ):
            with self.subTest(override=override):
                result = run_php("tests/config_contract_probe.php", env={**PRODUCTION, **override})
                self.assertNotEqual(0, result.returncode)

    def test_development_defaults_target_a_local_table(self) -> None:
        config = php_json_probe(
            "tests/config_contract_probe.php",
            env={"SPECIAL_ENV": "dev", "SPECIAL_STORE": "", "SPECIAL_DYNAMODB_TABLE": "", "SPECIAL_AWS_REGION": ""},
        )
        self.assertEqual("development", config["environment"])
        self.assertEqual("dynamodb", config["store"])
        self.assertEqual("special", config["dynamodb_table"])
        self.assertFalse(config["session_secure"])
