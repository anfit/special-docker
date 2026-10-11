from __future__ import annotations

import unittest

from contract_test_support import php_binary, php_json_probe, read_text


class MarkdownContractTest(unittest.TestCase):
    def test_spec_markdown_is_rendered_as_safe_html(self) -> None:
        if php_binary() is None:
            self.skipTest("PHP CLI is not installed; Markdown probe unavailable.")

        html = php_json_probe("tests/markdown_contract_probe.php")["html"]
        self.assertIn("<h1>A spec</h1>", html)
        self.assertIn("<strong>Important</strong>", html)
        self.assertIn('<a href="https://example.test/spec"', html)
        self.assertIn("<table>", html)
        self.assertIn('<th style="text-align: left;">Field</th>', html)
        self.assertIn('<th style="text-align: center;">Required</th>', html)
        self.assertIn("<ul>", html)
        self.assertIn("nested note", html)
        self.assertNotIn('href="javascript:', html)
        self.assertIn("<blockquote>", html)
        self.assertIn("A useful note", html)
        self.assertIn("class=\"language-php\"", html)
        self.assertIn("&lt;script&gt;alert('xss')&lt;/script&gt;", html)
        self.assertNotIn("<script>", html)

    def test_renderer_uses_the_pinned_dependency_and_safety_adapter(self) -> None:
        adapter = read_text("src/Markdown.php")
        parsedown = read_text("src/ThirdParty/Parsedown.php")
        license_text = read_text("src/ThirdParty/Parsedown.LICENSE.txt")
        self.assertIn("ThirdParty/Parsedown.php", adapter)
        self.assertIn("setSafeMode(true)", adapter)
        self.assertIn("safeLinksWhitelist", adapter)
        self.assertIn("const version = '1.8.0'", parsedown)
        self.assertIn("The MIT License", license_text)

    def test_spec_body_contract_names_markdown_source_and_raw_copy(self) -> None:
        form = read_text("views/spec-form.php")
        view = read_text("views/spec-show.php")
        mcp = read_text("src/mcp.php")
        self.assertIn("Body (Markdown)", form)
        self.assertIn("Markdown is stored as source text", form)
        self.assertIn('data-copy-source="spec-body-source"', view)
        self.assertIn("special_escape($spec['body'] ?? null)", view)
        self.assertIn("text/markdown; charset=utf-8", mcp)
