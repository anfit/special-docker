from __future__ import annotations

import os
import re
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

from contract_test_support import ROOT, php_binary
from web_flow_test import Browser

PORT = 18083
BASE = f"http://127.0.0.1:{PORT}"
SETUP_TOKEN = "configured-setup-token-for-tests-123"


class SetupFlowTest(unittest.TestCase):
    """First-run web setup and the forced password change after an administrator reset."""

    def test_setup_then_reset_flow(self) -> None:
        binary = php_binary()
        if binary is None:
            self.skipTest("PHP CLI is not installed; setup flow unavailable.")
        with tempfile.TemporaryDirectory() as workdir:
            environment = {
                "SPECIAL_ENV": "development",
                "SPECIAL_APP_KEY": "setup-flow-test-key-with-at-least-32-characters",
                "SPECIAL_PUBLIC_BASE_URL": BASE,
                "SPECIAL_STORE": "memory",
                "SPECIAL_MEMORY_STORE_FILE": os.path.join(workdir, "store.bin"),
                "SPECIAL_SESSION_PATH": os.path.join(workdir, "sessions"),
                "SPECIAL_SESSION_SECURE": "0",
                "SPECIAL_SETUP_TOKEN": SETUP_TOKEN,
                "SPECIAL_VERSION": "9.9.9-test",
            }
            server = subprocess.Popen(
                [str(binary), "-S", f"127.0.0.1:{PORT}", "-t", str(ROOT / "public"), str(ROOT / "public" / "index.php")],
                cwd=ROOT, env={**os.environ, **environment},
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
            try:
                self._wait()
                self._scenario()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=3)

    def _wait(self) -> None:
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(BASE + "/health", timeout=0.3):
                    return
            except (urllib.error.URLError, OSError):
                time.sleep(0.05)
        self.fail("PHP development server did not start in time.")

    def _scenario(self) -> None:
        with urllib.request.urlopen(BASE + "/health", timeout=3) as response:
            health = response.read().decode()
        self.assertIn("9.9.9-test", health, "health reports the build version")

        browser = Browser(BASE)
        status, html = browser.get("/")
        self.assertIn("First-run setup", html, "an empty system sends everyone to /setup")
        status, html = browser.get("/login")
        self.assertIn("First-run setup", html, "the login page defers to setup")
        self.assertIn("Special 9.9.9-test", html, "footer shows the version")

        form = {"username": "owner@example.test", "password": "a-long-owner-passphrase", "password_confirm": "a-long-owner-passphrase"}
        status, html = browser.post("/setup", {"csrf": browser.csrf("/setup"), "token": "nope", **form})
        self.assertEqual(401, status)
        self.assertIn("setup token is not valid", html)
        status, html = browser.post("/setup", {"csrf": browser.csrf("/setup"), "token": SETUP_TOKEN, **form, "password_confirm": "different-passphrase!"})
        self.assertEqual(422, status)
        self.assertIn("do not match", html)
        status, html = browser.post("/setup", {"csrf": browser.csrf("/setup"), "token": SETUP_TOKEN, **form})
        self.assertEqual(200, status)
        self.assertIn("Setup complete", html, "the creator is signed in straight away")
        self.assertIn("<h1>Specs</h1>", html)

        status, _ = Browser(BASE).get("/setup")
        self.assertEqual(404, status, "setup disappears once an administrator exists")
        status, html = Browser(BASE).get("/")
        self.assertIn("Log in", html, "the normal login page returns")

        status, html = browser.post("/admin/users", {
            "csrf": browser.csrf("/admin/users"), "username": "colleague@example.test",
            "password": "initial-passphrase-123", "role": "editor",
        })
        user_id = int(re.search(r'/admin/users/(\d+)/reset-password', html).group(1))
        status, html = browser.post(f"/admin/users/{user_id}/reset-password", {"csrf": browser.csrf("/admin/users")})
        self.assertEqual(200, status)
        temporary = re.search(r'id="temporary-password" readonly value="([^"]+)"', html).group(1)

        colleague = Browser(BASE)
        status, html = colleague.login("colleague@example.test", "initial-passphrase-123")
        self.assertEqual(401, status, "the old password no longer works")
        status, html = colleague.login("colleague@example.test", temporary)
        self.assertIn("reset your password", html, "the user lands on the account page")
        status, html = colleague.get("/specs")
        self.assertIn("reset your password", html, "everything else redirects to the account page")
        status, html = colleague.post("/account/password", {"csrf": colleague.csrf("/account"), "password": "my-own-new-passphrase"})
        self.assertIn("<h1>Specs</h1>", html, "changing the password releases the user")


if __name__ == "__main__":
    unittest.main()
