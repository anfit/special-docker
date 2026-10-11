from __future__ import annotations

import http.cookiejar
import os
import re
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

from contract_test_support import ROOT, php_binary, run_php

PORT = 18082
BASE = f"http://127.0.0.1:{PORT}"
ADMIN_PASSWORD = "an-admin-passphrase-123"
USER_PASSWORD = "a-user-passphrase-1234"


class Browser:
    def __init__(self, base: str = BASE) -> None:
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def get(self, path: str) -> tuple[int, str]:
        return self._open(urllib.request.Request(self.base + path))

    def post(self, path: str, fields: dict[str, str]) -> tuple[int, str]:
        data = urllib.parse.urlencode(fields).encode()
        return self._open(urllib.request.Request(self.base + path, data=data, method="POST"))

    def csrf(self, path: str) -> str:
        _, html = self.get(path)
        match = re.search(r'name="csrf" value="([^"]+)"', html)
        assert match, f"no csrf token on {path}"
        return match.group(1)

    def login(self, username: str, password: str) -> tuple[int, str]:
        token = self.csrf("/login")
        return self.post("/login", {"csrf": token, "username": username, "password": password})

    def _open(self, request: urllib.request.Request) -> tuple[int, str]:
        try:
            with self.opener.open(request, timeout=5) as response:
                return response.status, response.read().decode("utf-8")
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8")


class WebFlowTest(unittest.TestCase):
    """Drives the real front controller over HTTP with the file-backed in-memory store."""

    def test_public_and_private_specs_across_two_users(self) -> None:
        binary = php_binary()
        if binary is None:
            self.skipTest("PHP CLI is not installed; web flow unavailable.")

        with tempfile.TemporaryDirectory() as workdir:
            environment = {
                "SPECIAL_ENV": "development",
                "SPECIAL_APP_KEY": "web-flow-test-key-with-at-least-32-characters",
                "SPECIAL_PUBLIC_BASE_URL": BASE,
                "SPECIAL_SESSION_PATH": os.path.join(workdir, "sessions"),
                "SPECIAL_SESSION_SECURE": "0",
                "SPECIAL_BOOTSTRAP_ADMIN_PASSWORD": ADMIN_PASSWORD,
            }
            if os.environ.get("SPECIAL_DYNAMODB_ENDPOINT"):
                # Same scenario against DynamoDB Local (needs vendor/ and a PHP with curl+openssl).
                environment.update({
                    "SPECIAL_STORE": "dynamodb",
                    "SPECIAL_DYNAMODB_TABLE": "special-web-" + os.urandom(3).hex(),
                    "SPECIAL_AWS_REGION": "us-east-1",
                    "AWS_ACCESS_KEY_ID": "local",
                    "AWS_SECRET_ACCESS_KEY": "local",
                })
                created = run_php("scripts/init-table.php", env=environment)
                self.assertEqual(0, created.returncode, created.stdout + created.stderr)
            else:
                environment.update({
                    "SPECIAL_STORE": "memory",
                    "SPECIAL_MEMORY_STORE_FILE": os.path.join(workdir, "store.bin"),
                })
            bootstrap = run_php("scripts/bootstrap-admin.php", "--username", "admin@example.test", env=environment)
            self.assertEqual(0, bootstrap.returncode, bootstrap.stdout + bootstrap.stderr)
            again = run_php("scripts/bootstrap-admin.php", "--username", "other@example.test", env=environment)
            self.assertNotEqual(0, again.returncode, "bootstrap must only succeed once")

            server = subprocess.Popen(
                [str(binary), "-S", f"127.0.0.1:{PORT}", "-t", str(ROOT / "public"), str(ROOT / "public" / "index.php")],
                cwd=ROOT,
                env={**os.environ, **environment},
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
            try:
                self._wait_for_server()
                self._scenario()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=3)

    def _wait_for_server(self) -> None:
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(BASE + "/health", timeout=0.3) as response:
                    if response.status == 200:
                        return
            except (urllib.error.URLError, OSError):
                time.sleep(0.05)
        self.fail("PHP development server did not start in time.")

    def _scenario(self) -> None:
        admin = Browser()

        status, html = admin.get("/specs")
        self.assertIn("Log in", html, "anonymous users are sent to the login page")
        status, html = admin.login("admin@example.test", "wrong password!!")
        self.assertEqual(401, status)
        status, html = admin.login("admin@example.test", ADMIN_PASSWORD)
        self.assertEqual(200, status)
        self.assertIn("<h1>Specs</h1>", html)

        token = admin.csrf("/specs/new")
        status, html = admin.post("/specs", {
            "csrf": token, "title": "Public design", "project_name": "Atlas", "status": "active",
            "visibility": "public", "description": "visible to all", "body": "# Hello",
        })
        self.assertEqual(200, status)
        self.assertIn("Public design", html)
        self.assertIn('class="badge public"', html)

        status, html = admin.post("/specs", {
            "csrf": admin.csrf("/specs/new"), "title": "Secret design", "project_name": "Hidden",
            "status": "draft", "visibility": "private", "description": "", "body": "",
        })
        self.assertIn('class="badge private"', html)
        private_id = int(re.search(r'/specs/(\d+)/edit', html).group(1))

        status, html = admin.post("/admin/users", {
            "csrf": admin.csrf("/admin/users"), "username": "reader@example.test",
            "password": USER_PASSWORD, "role": "editor",
        })
        self.assertEqual(200, status)
        self.assertIn("reader@example.test", html)

        reader = Browser()
        status, html = reader.login("reader@example.test", USER_PASSWORD)
        self.assertEqual(200, status)
        self.assertIn("Public design", html, "public spec is in the other user's main listing")
        self.assertNotIn("Secret design", html, "private spec is not")
        status, html = reader.get("/projects")
        self.assertIn("Atlas", html, "public spec's project is listed")
        self.assertNotIn("Hidden", html, "private-only project is not")
        status, _ = reader.get(f"/specs/{private_id}")
        self.assertEqual(404, status, "private spec behaves as nonexistent for others")

        status, html = reader.get("/specs?project_name=atlas")
        self.assertIn("Public design", html, "project filter is case-insensitive")

        public_id = int(re.search(r'href="/specs/(\d+)"', reader.get("/specs")[1]).group(1))
        status, html = reader.get(f"/specs/{public_id}")
        self.assertEqual(200, status)
        self.assertNotIn(f"/specs/{public_id}/edit", html, "only the author sees the edit link")
        status, html = reader.post(f"/specs/{public_id}", {"csrf": reader.csrf(f"/specs/{public_id}"), "title": "Hijacked"})
        self.assertEqual(403, status, "non-authors cannot edit a public spec")

        status, html = admin.post(f"/specs/{private_id}", {
            "csrf": admin.csrf(f"/specs/{private_id}/edit"), "visibility": "public", "expected_revision": "1",
        })
        self.assertIn("Spec updated.", html)
        self.assertIn("Secret design", reader.get("/specs")[1], "flipping to public shares it")

        request = urllib.request.Request(BASE + "/mcp", data=b'{"jsonrpc":"2.0","id":1,"method":"tools/list"}', method="POST",
                                         headers={"Content-Type": "application/json"})
        with self.assertRaises(urllib.error.HTTPError) as context:
            urllib.request.urlopen(request, timeout=5)
        self.assertEqual(401, context.exception.code)
        self.assertIn("resource_metadata", context.exception.headers.get("WWW-Authenticate", ""))


if __name__ == "__main__":
    unittest.main()
