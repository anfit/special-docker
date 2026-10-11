from __future__ import annotations

import unittest

from contract_test_support import php_binary, php_json_probe


class OAuthRedirectContractTest(unittest.TestCase):
    def test_loopback_ports_are_agnostic_but_other_redirect_parts_are_exact(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; OAuth redirect probe unavailable.")
        result = php_json_probe("tests/oauth_redirect_contract_probe.php")
        self.assertTrue(result["localhost_ephemeral_port"])
        self.assertTrue(result["ipv6_ephemeral_port"])
        self.assertFalse(result["loopback_path_mismatch"])
        self.assertFalse(result["loopback_query_mismatch"])
        self.assertFalse(result["https_port_mismatch"])
        self.assertTrue(result["https_exact"])
        self.assertFalse(result["userinfo_rejected"])
