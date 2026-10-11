<?php

declare(strict_types=1);

use Special\SpecialException;
use function Special\special_b64url_encode;

require_once __DIR__ . '/Markdown.php';

function special_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name((string) $config['session_name']);
    $sessionPath = (string) $config['session_path'];
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new SpecialException('CONFIGURATION_ERROR', 'The session directory could not be created.', 500);
    }
    if (!is_writable($sessionPath)) {
        throw new SpecialException('CONFIGURATION_ERROR', 'The session directory is not writable.', 500);
    }
    ini_set('session.save_path', $sessionPath);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) $config['session_secure'],
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (!isset($_SESSION['user_id'])) {
        (new \Special\RememberMe(special_store($config), $config))->restoreSession();
    }
}

function special_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function special_markdown(?string $value): string
{
    return \Special\Markdown::render($value);
}

function special_csrf_token(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || strlen($_SESSION['_csrf']) < 32) {
        $_SESSION['_csrf'] = special_b64url_encode(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function special_require_csrf(?string $candidate): void
{
    $expected = $_SESSION['_csrf'] ?? null;
    if (!is_string($candidate) || !is_string($expected) || !hash_equals($expected, $candidate)) {
        throw new SpecialException('INVALID_ARGUMENT', 'The form has expired. Please try again.', 422);
    }
}

function special_flash(string $message, string $type = 'info'): void
{
    $_SESSION['_flash'][] = ['message' => $message, 'type' => $type];
}

/** @return list<array{message: string, type: string}> */
function special_take_flash(): array
{
    $flash = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flash) ? $flash : [];
}

/** @param array<string, mixed> $data */
function special_render(string $view, array $data = [], int $status = 200): void
{
    http_response_code($status);
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/views/' . $view . '.php';
    $content = (string) ob_get_clean();
    require dirname(__DIR__) . '/views/layout.php';
}

function special_redirect(string $location, int $status = 303): never
{
    header('Location: ' . $location, true, $status);
    exit;
}

function special_safe_return_to(?string $value): string
{
    if (!is_string($value)
        || $value === ''
        || !str_starts_with($value, '/')
        || str_starts_with($value, '//')
        || str_contains($value, '\\')
        || str_contains($value, "\r")
        || str_contains($value, "\n")) {
        return '/specs';
    }

    return $value;
}

function special_form_string(string $key, ?string $default = null): ?string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function special_form_int(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    throw new SpecialException('INVALID_ARGUMENT', 'The form value is invalid.', 422);
}
