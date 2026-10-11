from __future__ import annotations

import unittest

from contract_test_support import php_binary, php_json_probe, read_text


class SecurityContractTest(unittest.TestCase):
    def test_role_and_scope_intersection_is_enforced_by_principal(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; policy probe unavailable.")
        permissions = php_json_probe("tests/security_contract_probe.php")
        self.assertEqual(
            {
                "spec.read": True,
                "spec.write": False,
                "users.manage": False,
            },
            permissions["viewer_web"],
        )
        self.assertEqual(
            {
                "spec.read": True,
                "spec.write": True,
                "users.manage": False,
            },
            permissions["editor_web"],
        )
        self.assertEqual(
            {
                "spec.read": True,
                "spec.write": True,
                "users.manage": True,
            },
            permissions["admin_web"],
        )
        self.assertEqual(
            {
                "spec.read": True,
                "spec.write": False,
                "users.manage": False,
            },
            permissions["editor_read_token"],
        )
        self.assertEqual(
            {
                "spec.read": False,
                "spec.write": True,
                "users.manage": False,
            },
            permissions["editor_write_token"],
        )
        self.assertEqual(
            {
                "spec.read": True,
                "spec.write": False,
                "users.manage": False,
            },
            permissions["viewer_legacy_token"],
        )
