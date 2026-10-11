from __future__ import annotations

import json
import os
import shutil
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def read_text(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def php_binary() -> Path | None:
    configured = os.environ.get("SPECIAL_PHP")
    if configured:
        candidate = Path(configured)
        if candidate.is_file():
            return candidate
    found = shutil.which("php")
    if found:
        return Path(found)
    return None


def php_command() -> list[str]:
    binary = php_binary()
    if binary is None:
        raise RuntimeError("PHP CLI is not installed; set SPECIAL_PHP to run PHP probes.")

    return [str(binary)]


def run_php(
    relative_path: str,
    *arguments: str,
    env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [*php_command(), str(ROOT / relative_path), *arguments],
        cwd=ROOT,
        text=True,
        capture_output=True,
        env={**os.environ, **(env or {})},
        check=False,
    )


def run_php_code(
    code: str,
    *arguments: str,
    env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [*php_command(), "-r", code, *arguments],
        cwd=ROOT,
        text=True,
        capture_output=True,
        env={**os.environ, **(env or {})},
        check=False,
    )


def php_json_probe(relative_path: str, *, env: dict[str, str] | None = None) -> object:
    result = run_php(relative_path, env=env)
    if result.returncode != 0:
        raise AssertionError(f"PHP probe failed:\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}")
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError(f"PHP probe did not emit JSON: {result.stdout!r}\n{result.stderr}") from exc
