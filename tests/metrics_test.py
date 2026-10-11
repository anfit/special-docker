from __future__ import annotations

import os
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

from contract_test_support import ROOT, php_binary, run_php
from web_flow_test import Browser

TOKEN = "metrics-token-for-tests-1234567890"


class MetricsTest(unittest.TestCase):
    """The Prometheus endpoint: off by default, token-protected, and fed by real requests."""

    def _serve(self, port: int, extra: dict[str, str]):
        binary = php_binary()
        if binary is None:
            self.skipTest("PHP CLI is not installed; metrics test unavailable.")
        workdir = tempfile.TemporaryDirectory()
        self.addCleanup(workdir.cleanup)
        environment = {
            "SPECIAL_ENV": "development",
            "SPECIAL_APP_KEY": "metrics-test-key-with-at-least-32-characters",
            "SPECIAL_PUBLIC_BASE_URL": f"http://127.0.0.1:{port}",
            "SPECIAL_STORE": "memory",
            "SPECIAL_MEMORY_STORE_FILE": os.path.join(workdir.name, "store.bin"),
            "SPECIAL_SESSION_PATH": os.path.join(workdir.name, "sessions"),
            "SPECIAL_METRICS_FILE": os.path.join(workdir.name, "metrics.txt"),
            "SPECIAL_VERSION": "7.7.7-test",
            "SPECIAL_COMMIT": "abc1234",
            **extra,
        }
        created = run_php("scripts/bootstrap-admin.php", "--username", "admin@example.test",
                          env={**environment, "SPECIAL_BOOTSTRAP_ADMIN_PASSWORD": "an-admin-passphrase-123"})
        self.assertEqual(0, created.returncode, created.stdout + created.stderr)
        server = subprocess.Popen(
            [str(binary), "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public"), str(ROOT / "public" / "index.php")],
            cwd=ROOT, env={**os.environ, **environment},
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )

        def stop() -> None:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)

        self.addCleanup(stop)
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{port}/health", timeout=0.3):
                    return f"http://127.0.0.1:{port}"
            except (urllib.error.URLError, OSError):
                time.sleep(0.05)
        self.fail("PHP development server did not start in time.")

    def _scrape(self, base: str, token: str | None) -> tuple[int, str]:
        headers = {"Authorization": f"Bearer {token}"} if token else {}
        try:
            with urllib.request.urlopen(urllib.request.Request(base + "/metrics", headers=headers), timeout=5) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            error.close()
            return error.code, ""

    def test_disabled_without_a_token(self) -> None:
        base = self._serve(18084, {})
        self.assertEqual(404, self._scrape(base, None)[0])
        self.assertEqual(404, self._scrape(base, TOKEN)[0])

    def test_scrape_reflects_traffic(self) -> None:
        base = self._serve(18085, {"SPECIAL_METRICS_TOKEN": TOKEN})
        self.assertEqual(401, self._scrape(base, None)[0], "no credentials")
        self.assertEqual(401, self._scrape(base, "wrong-token-wrong-token-1234")[0], "wrong credentials")

        browser = Browser(base)
        browser.get("/login")
        browser.get("/login")
        browser.get("/nonexistent")
        browser.post("/login", {"csrf": browser.csrf("/login"), "username": "nobody@example.test", "password": "wrong password!!"})
        urllib.request.urlopen(base + "/health", timeout=3).close()

        status, text = self._scrape(base, TOKEN)
        self.assertEqual(200, status)
        self.assertIn('special_build_info{version="7.7.7-test",commit="abc1234"} 1', text)
        self.assertIn("special_store_up 1", text)
        self.assertIn("# TYPE special_http_requests_total counter", text)
        self.assertIn("# TYPE special_http_request_duration_seconds histogram", text)
        # /login was fetched three times (two GETs plus the csrf fetch) and posted once; /health and /metrics are excluded.
        self.assertRegex(text, r'special_http_requests_total\{method="GET",route="/login",status="200"\} [3-9]')
        self.assertIn('special_http_requests_total{method="POST",route="/login",status="401"} 1', text)
        self.assertIn('route="other",status="404"', text)
        self.assertNotIn('route="/health"', text)
        self.assertNotIn('route="/metrics"', text)
        self.assertIn('special_login_attempts_total{result="failure"} 1', text)
        self.assertRegex(text, r'special_http_request_duration_seconds_bucket\{le="\+Inf",route="/login"\} [0-9]+')
        self.assertRegex(text, r'special_http_request_duration_seconds_count\{route="/login"\} [0-9]+')

        # Cumulative buckets never decrease and +Inf equals the count.
        buckets = [
            (line.split('le="')[1].split('"')[0], float(line.rsplit(" ", 1)[1]))
            for line in text.splitlines()
            if line.startswith("special_http_request_duration_seconds_bucket") and 'route="/login"' in line
        ]
        values = [value for _, value in buckets]
        self.assertEqual(sorted(values), values, "bucket counts are cumulative and ordered by le")
        self.assertEqual("+Inf", buckets[-1][0])
        count_line = next(l for l in text.splitlines() if l.startswith('special_http_request_duration_seconds_count{route="/login"}'))
        self.assertEqual(float(count_line.rsplit(" ", 1)[1]), buckets[-1][1])

        # Scraping again must not reset or double count.
        _, again = self._scrape(base, TOKEN)
        self.assertIn('special_login_attempts_total{result="failure"} 1', again)

    def test_mcp_requests_are_counted(self) -> None:
        base = self._serve(18086, {"SPECIAL_METRICS_TOKEN": TOKEN})
        request = urllib.request.Request(
            base + "/mcp", data=b'{"jsonrpc":"2.0","id":1,"method":"tools/list"}', method="POST",
            headers={"Content-Type": "application/json"},
        )
        with self.assertRaises(urllib.error.HTTPError) as context:
            urllib.request.urlopen(request, timeout=5)
        context.exception.close()
        _, text = self._scrape(base, TOKEN)
        self.assertIn('special_mcp_requests_total{method="tools/list",outcome="unauthorized"} 1', text)
        self.assertIn('special_http_requests_total{method="POST",route="mcp",status="401"} 1', text)


if __name__ == "__main__":
    unittest.main()
