from __future__ import annotations

import unittest

from contract_test_support import read_text


class WebContractTest(unittest.TestCase):
    def test_login_csrf_rotation_imports_the_namespaced_encoder(self) -> None:
        source = read_text("public/index.php")
        self.assertIn("use function Special\\special_b64url_encode;", source)
        login_start = source.index("if ($path === '/login')")
        login_end = source.index("if ($path === '/logout')", login_start)
        login_handler = source[login_start:login_end]
        self.assertIn("special_b64url_encode(random_bytes(32))", login_handler)

    def test_spec_panel_exposes_an_accessible_copy_control(self) -> None:
        view = read_text("views/spec-show.php")
        script = read_text("public/spec-copy.js")
        self.assertIn('data-copy-target="spec-body"', view)
        self.assertIn('data-copy-source="spec-body-source"', view)
        self.assertIn('id="spec-body-source"', view)
        self.assertIn("special_markdown(", view)
        self.assertIn('aria-label="Copy spec body to clipboard"', view)
        self.assertIn('aria-live="polite"', view)
        self.assertIn("copyValue(source)", script)
        self.assertIn("HTMLTextAreaElement", script)
        self.assertIn("navigator.clipboard", script)
        self.assertIn("document.execCommand('copy')", script)

    def test_spec_view_exposes_a_separate_signed_share_permalink(self) -> None:
        router = read_text("public/index.php")
        service = read_text("src/SpecService.php")
        view = read_text("views/spec-show.php")
        self.assertIn("$segments[0] === 'share'", router)
        self.assertIn("getSpecByShareToken", router)
        self.assertIn("Cache-Control: private, no-store", router)
        self.assertIn("shareUrlFor", router)
        self.assertIn("hash_hmac('sha256'", service)
        self.assertIn("/share/", service)
        self.assertIn('id="spec-share-link-top"', view)
        self.assertIn('data-copy-target="spec-share-link-top"', view)
        self.assertIn('data-copy-target="spec-share-link"', view)
        self.assertIn('aria-label="Copy share link to clipboard"', view)
        self.assertLess(view.index('id="spec-share-link-top"'), view.index('<section class="spec-panel"'))

    def test_spec_list_exposes_a_copyable_permalink_for_each_row(self) -> None:
        router = read_text("public/index.php")
        view = read_text("views/specs-list.php")
        list_start = router.index("if ($path === '/specs' && $method === 'GET')")
        list_end = router.index("if ($path === '/specs/new')", list_start)
        list_handler = router[list_start:list_end]
        self.assertIn("$specService->shareUrlFor((int) $spec['id'])", list_handler)
        self.assertIn('data-copy-target="spec-share-link-', view)
        self.assertIn('data-copy-status="spec-share-copy-status-', view)
        self.assertIn('aria-label="Copy permalink for', view)
        self.assertIn('<script src="/spec-copy.js" defer></script>', view)

    def test_front_controller_leaves_public_static_files_to_php_server(self) -> None:
        source = read_text("public/index.php")
        guard_start = source.index("$publicRoot = realpath(__DIR__);")
        bootstrap_start = source.index("require_once dirname(__DIR__) . '/src/bootstrap.php';")
        static_guard = source[guard_start:bootstrap_start]
        self.assertIn("realpath($publicRoot . $path)", static_guard)
        self.assertIn("return false;", static_guard)
