from __future__ import annotations

import subprocess
import unittest

from contract_test_support import ROOT, php_binary


class PhpSyntaxTest(unittest.TestCase):
    def test_all_php_files_parse(self) -> None:
        binary = php_binary()
        if binary is None:
            self.skipTest("PHP CLI is not installed; set SPECIAL_PHP to enable syntax checks.")

        php_files = sorted(path for path in ROOT.rglob("*.php") if "vendor" not in path.relative_to(ROOT).parts)
        self.assertGreater(len(php_files), 0)
        failures: list[str] = []
        for path in php_files:
            result = subprocess.run(
                [str(binary), "-l", str(path)],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )
            if result.returncode != 0:
                failures.append(f"{path}: {result.stdout}{result.stderr}")
        self.assertEqual([], failures, "PHP syntax errors found:\n" + "\n".join(failures))
