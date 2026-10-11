<?php

declare(strict_types=1);

namespace Special;

use Special\Storage\Store;

final class RememberMe
{
    public const HTTPS_COOKIE = '__Host-special_remember';
    public const HTTP_COOKIE = 'special_remember';
    public const DEFAULT_DAYS = 60;

    private const SELECTOR_BYTES = 16;
    private const SECRET_BYTES = 32;

    public function __construct(
        private Store $store,
        private array $config,
    ) {
    }

    public static function lifetimeDays(): int
    {
        $raw = getenv('SPECIAL_REMEMBER_DAYS');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_DAYS;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > 3650) {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_REMEMBER_DAYS must be between 1 and 3650.', 500);
        }

        return (int) $raw;
    }

    public function issueForUser(int $userId): void
    {
        [$selector, $secret] = $this->newToken();
        $this->store->putRemember([
            'selector' => $selector,
            'user_id' => $userId,
            'token_hash' => $this->hash($secret),
            'expires_at' => special_now($this->lifetimeSeconds()),
            'created_at' => special_now(),
            'last_used_at' => null,
        ]);

        if (!$this->writeCookie($selector . '.' . $secret)) {
            $this->deleteSelector($selector);
            throw new \RuntimeException('Unable to set persistent login cookie.');
        }
    }

    /** @return array<string, mixed>|null */
    public function restoreSession(): ?array
    {
        if (isset($_SESSION['user_id'])) {
            return null;
        }

        $cookie = $this->readCookie();
        if ($cookie === null) {
            return null;
        }

        $parsed = $this->parse($cookie);
        if ($parsed === null) {
            $this->clearCookies();
            return null;
        }

        [$selector, $secret] = $parsed;
        $replacementSelector = null;
        try {
            $row = $this->store->getRemember($selector);
            $user = $row === null ? null : $this->store->getUser((int) $row['user_id']);

            if (
                $row === null
                || $user === null
                || !(bool) $user['enabled']
                || !special_timestamp_is_future((string) $row['expires_at'])
                || !hash_equals((string) $row['token_hash'], $this->hash($secret))
            ) {
                if ($row !== null) {
                    $this->deleteSelector($selector);
                }
                $this->clearCookies();
                return null;
            }

            [$replacementSelector, $replacementSecret] = $this->newToken();
            $rotated = $this->store->rotateRemember($selector, (string) $row['token_hash'], [
                'selector' => $replacementSelector,
                'user_id' => (int) $row['user_id'],
                'token_hash' => $this->hash($replacementSecret),
                'expires_at' => special_now($this->lifetimeSeconds()),
                'created_at' => (string) $row['created_at'],
                'last_used_at' => special_now(),
            ]);
            if (!$rotated) {
                // Another request used this token first (or it was revoked); treat as logged out.
                $this->clearCookies();
                return null;
            }

            if (!session_regenerate_id(true)) {
                throw new \RuntimeException('Unable to rotate the authenticated session.');
            }
            $_SESSION['user_id'] = (int) $row['user_id'];
            unset($_SESSION['_csrf']);

            if (!$this->writeCookie($replacementSelector . '.' . $replacementSecret)) {
                $this->deleteSelector($replacementSelector);
            }

            return ['user_id' => (int) $row['user_id']];
        } catch (\Throwable) {
            try {
                $this->deleteSelector($selector);
                if ($replacementSelector !== null) {
                    $this->deleteSelector($replacementSelector);
                }
            } catch (\Throwable) {
                // Do not let token cleanup hide the failed restoration.
            }
            $this->clearCookies();
            error_log('Persistent login restoration failed.');
            return null;
        }
    }

    public function clearCurrentBrowserToken(): void
    {
        foreach ([self::HTTPS_COOKIE, self::HTTP_COOKIE] as $name) {
            $parsed = $this->parse((string) ($_COOKIE[$name] ?? ''));
            if ($parsed !== null) {
                $this->deleteSelector($parsed[0]);
            }
        }
        $this->clearCookies();
    }

    /** @return array{0: string, 1: string} */
    private function newToken(): array
    {
        return [
            bin2hex(random_bytes(self::SELECTOR_BYTES)),
            bin2hex(random_bytes(self::SECRET_BYTES)),
        ];
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function lifetimeSeconds(): int
    {
        return ((int) ($this->config['remember_days'] ?? self::DEFAULT_DAYS)) * 86400;
    }

    /** @return array{0: string, 1: string}|null */
    private function parse(string $cookie): ?array
    {
        $parts = explode('.', $cookie);
        if (
            count($parts) !== 2
            || preg_match('/^[0-9a-f]{32}$/D', $parts[0]) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $parts[1]) !== 1
        ) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    private function readCookie(): ?string
    {
        foreach ([self::HTTPS_COOKIE, self::HTTP_COOKIE] as $name) {
            if (isset($_COOKIE[$name])) {
                return (string) $_COOKIE[$name];
            }
        }

        return null;
    }

    private function deleteSelector(string $selector): void
    {
        $this->store->deleteRemember($selector);
    }

    private function secureRequest(): bool
    {
        return (bool) ($this->config['session_secure'] ?? false);
    }

    private function writeCookie(string $value): bool
    {
        $secure = $this->secureRequest();
        return setcookie(
            $secure ? self::HTTPS_COOKIE : self::HTTP_COOKIE,
            $value,
            [
                'expires' => time() + $this->lifetimeSeconds(),
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );
    }

    private function clearCookies(): void
    {
        $secure = $this->secureRequest();
        foreach ([self::HTTPS_COOKIE => true, self::HTTP_COOKIE => $secure] as $name => $cookieSecure) {
            setcookie(
                $name,
                '',
                [
                    'expires' => 1,
                    'path' => '/',
                    'secure' => $cookieSecure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
            );
            unset($_COOKIE[$name]);
        }
    }
}
