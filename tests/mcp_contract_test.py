from __future__ import annotations

import unittest

from contract_test_support import php_binary, php_json_probe, read_text


class McpContractTest(unittest.TestCase):
    def test_published_tools_are_strict_and_narrow(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; MCP schema probe unavailable.")
        probe = php_json_probe("tests/mcp_contract_probe.php")
        tools = probe["tools"]
        self.assertEqual(
            ["list_specs", "list_projects", "get_spec", "create_spec", "update_spec", "delete_spec"],
            [tool["name"] for tool in tools],
        )
        for tool in tools:
            self.assertEqual(False, tool["inputSchema"]["additionalProperties"])
            self.assertIn("annotations", tool)
            self.assertIn("securitySchemes", tool)
        self.assertEqual("complete", probe["discovery"]["result"]["resultType"])
        self.assertEqual(["2026-07-28"], probe["discovery"]["result"]["supportedVersions"])
        self.assertEqual("2025-06-18", probe["initialize"]["result"]["protocolVersion"])
        self.assertEqual({}, probe["ping"]["result"])
        self.assertEqual(401, probe["unauthenticated_initialize_status"])
        self.assertEqual("UNAUTHENTICATED", probe["unauthenticated_initialize"]["error"]["data"]["code"])
        self.assertIsNone(probe["unauthenticated_notification"])
        self.assertEqual(401, probe["unauthenticated_notification_status"])
        self.assertEqual(403, probe["write_only_initialize_status"])
        self.assertEqual("insufficient_scope", probe["write_only_initialize"]["error"]["data"]["details"]["oauth_error"])
        self.assertIsNone(probe["write_only_notification"])
        self.assertEqual(403, probe["write_only_notification_status"])
        self.assertIn('scope="model.read model.write"', probe["challenge_missing"])
        self.assertIn('error="invalid_token"', probe["challenge_invalid"])
        self.assertIn('error="insufficient_scope"', probe["challenge_insufficient"])
        self.assertIn('scope="model.write"', probe["challenge_insufficient"])
        self.assertEqual(
            ["create_spec", "update_spec", "delete_spec"],
            [tool["name"] for tool in probe["write_tools"]],
        )

    def test_scholion_shaped_discovery_and_auth_boundary_is_present(self) -> None:
        mcp = read_text("src/mcp.php")
        oauth = read_text("src/OAuth.php")
        bootstrap = read_text("src/bootstrap.php")
        public = read_text("public/index.php")
        interop = read_text("spec/oauth-interop.md")
        self.assertIn("server/discover", mcp)
        self.assertIn("2026-07-28", bootstrap)
        self.assertIn("2025-06-18", bootstrap)
        self.assertIn("io.modelcontextprotocol/serverInfo", mcp)
        self.assertIn("resources/templates/list", mcp)
        self.assertIn("special://specs/{spec_id}/body", mcp)
        self.assertIn("text/markdown; charset=utf-8", mcp)
        self.assertIn("resource_metadata=\"' . (string) $config['app_url'] . '/.well-known/oauth-protected-resource/mcp\"", mcp)
        self.assertIn("https://chatgpt.com", mcp)
        self.assertIn("MCP-Protocol-Version, MCP-Session-Id", mcp)
        self.assertIn("model.read", oauth)
        self.assertIn("model.write", oauth)
        self.assertIn("mcp:tools", oauth)
        self.assertIn("registration_endpoint", oauth)
        self.assertIn("S256", oauth)
        self.assertIn("client_id_metadata_document_supported", oauth)
        self.assertIn("clientFromMetadataDocument", oauth)
        self.assertIn("follow_location", oauth)
        self.assertIn("65_537", oauth)
        self.assertIn("matchesRegisteredRedirect", oauth)
        self.assertIn("resource", oauth)
        self.assertIn("'initialize'", mcp)
        self.assertIn("'notifications/initialized'", mcp)
        self.assertIn("special_mcp_oauth_challenge", mcp)
        self.assertIn("error=\"insufficient_scope\"", mcp)
        self.assertIn("REQUEST_TOO_LARGE", mcp)
        self.assertIn("!array_key_exists('id', $request) || $request['id'] === null", mcp)
        self.assertIn("Claude Code", interop)
        self.assertIn("client_id_metadata_document_supported", interop)
        self.assertIn("ephemeral localhost port", interop)
        self.assertIn("if ($path === '/mcp' && $method === 'OPTIONS')", public)
        self.assertIn("special_mcp_handle_preflight", public)
        self.assertIn("special_mcp_handle_method_not_allowed", public)
        self.assertIn("special_mcp_log", mcp)
        self.assertNotIn("HTTP_AUTHORIZATION", mcp[mcp.index("function special_mcp_log"):])

    def test_adapter_does_not_contain_domain_sql(self) -> None:
        self.assertNotIn("SELECT ", read_text("src/mcp.php").upper())
        self.assertNotIn("INSERT ", read_text("src/mcp.php").upper())
        self.assertNotIn("UPDATE ", read_text("src/mcp.php").upper())

    def test_oauth_metadata_advertises_the_mcp_resource_and_pkce(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; OAuth metadata probe unavailable.")
        metadata = php_json_probe("tests/oauth_contract_probe.php")
        authorization_server = metadata["authorization_server"]
        protected_resource = metadata["protected_resource"]
        self.assertEqual("https://special.example.test", authorization_server["issuer"])
        self.assertEqual("https://special.example.test/oauth/register", authorization_server["registration_endpoint"])
        self.assertEqual(["S256"], authorization_server["code_challenge_methods_supported"])
        self.assertEqual(["authorization_code", "refresh_token"], authorization_server["grant_types_supported"])
        self.assertEqual(["model.read", "model.write"], authorization_server["scopes_supported"])
        self.assertTrue(authorization_server["client_id_metadata_document_supported"])
        self.assertEqual("model.read model.write", metadata["default_scope"])
        self.assertEqual(["model.read", "model.write"], metadata["mixed_legacy_scope"])
        self.assertEqual("https://special.example.test/mcp", protected_resource["resource"])
        self.assertEqual(["https://special.example.test"], protected_resource["authorization_servers"])
