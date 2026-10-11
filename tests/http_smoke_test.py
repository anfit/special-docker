from __future__ import annotations

import os
import subprocess
import time
import unittest
import urllib.error
import urllib.request

from contract_test_support import ROOT, php_binary


class HttpSmokeTest(unittest.TestCase):
    def test_mcp_preflight_and_method_guard_do_not_need_storage(self) -> None:
        binary = php_binary()
        if binary is None:
            self.skipTest("PHP CLI is not installed; HTTP smoke test unavailable.")

        port = 18081
        environment = {
            **os.environ,
            "SPECIAL_APP_ENV": "development",
            "SPECIAL_APP_KEY": "smoke-test-key-with-at-least-32-characters",
            "SPECIAL_APP_URL": f"http://127.0.0.1:{port}",
            "SPECIAL_STORE": "memory",
            "SPECIAL_MEMORY_STORE_FILE": str(ROOT / ".smoke-sessions" / "store.bin"),
            "SPECIAL_SESSION_PATH": str(ROOT / ".smoke-sessions"),
        }
        process = subprocess.Popen(
            [
                str(binary),
                "-S",
                f"127.0.0.1:{port}",
                "-t",
                str(ROOT / "public"),
                str(ROOT / "public" / "index.php"),
            ],
            cwd=ROOT,
            env=environment,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
        try:
            self._wait_for_server(process, port)
            preflight = urllib.request.Request(
                f"http://127.0.0.1:{port}/mcp",
                method="OPTIONS",
                headers={
                    "Origin": "https://chatgpt.com",
                    "Access-Control-Request-Headers": "Authorization, Content-Type, MCP-Protocol-Version",
                },
            )
            with urllib.request.urlopen(preflight, timeout=3) as response:
                self.assertEqual(204, response.status)
                self.assertEqual("https://chatgpt.com", response.headers.get("Access-Control-Allow-Origin"))
                self.assertIn("MCP-Protocol-Version", response.headers.get("Access-Control-Allow-Headers", ""))

            method_guard = urllib.request.Request(f"http://127.0.0.1:{port}/mcp", method="GET")
            with self.assertRaises(urllib.error.HTTPError) as context:
                urllib.request.urlopen(method_guard, timeout=3)
            self.assertEqual(405, context.exception.code)
            self.assertIn("method_not_allowed", context.exception.read().decode("utf-8"))

            with urllib.request.urlopen(f"http://127.0.0.1:{port}/spec-copy.js", timeout=3) as response:
                self.assertEqual(200, response.status)
                self.assertIn("navigator.clipboard", response.read().decode("utf-8"))
        finally:
            process.terminate()
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)

    def _wait_for_server(self, process: subprocess.Popen[bytes], port: int) -> None:
        deadline = time.monotonic() + 3
        while time.monotonic() < deadline:
            if process.poll() is not None:
                self.fail("PHP development server exited before the smoke test started.")
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{port}/health", timeout=0.2):
                    return
            except urllib.error.HTTPError:
                return
            except urllib.error.URLError:
                time.sleep(0.05)
        self.fail("PHP development server did not start in time.")
