<?php

declare(strict_types=1);

namespace Special;

use Special\Storage\Store;

final class OAuth
{
    public const READ_SCOPE = 'model.read';
    public const WRITE_SCOPE = 'model.write';
    public const LEGACY_SCOPE = 'mcp:tools';
    public const DEFAULT_SCOPE = self::READ_SCOPE . ' ' . self::WRITE_SCOPE;

    /** @var list<string> */
    private const SCOPES = [self::READ_SCOPE, self::WRITE_SCOPE, self::LEGACY_SCOPE];

    public function __construct(
        private Store $store,
        private Security $security,
        private array $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $base = (string) $this->config['app_url'];
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'scopes_supported' => [self::READ_SCOPE, self::WRITE_SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function protectedResourceMetadata(): array
    {
        $base = (string) $this->config['app_url'];
        return [
            'resource' => (string) $this->config['mcp_resource'],
            'authorization_servers' => [$base],
            'scopes_supported' => [self::READ_SCOPE, self::WRITE_SCOPE],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{client_id: string, redirect_uri: string, resource: string, scope: string, scopes: list<string>, code_challenge: string, code_challenge_method: string, state: ?string}
     */
    public function validateAuthorizationRequest(array $input): array
    {
        $clientId = $this->requiredString($input, 'client_id', 2048);
        $client = $this->getClient($clientId);
        if ($client === null) {
            $client = $this->clientFromMetadataDocument($clientId);
        }
        if ($client === null || !(bool) $client['enabled']) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown OAuth client.', 400);
        }

        $redirectUri = $this->requiredString($input, 'redirect_uri', 2000);
        $redirectUris = json_decode((string) $client['redirect_uris'], true);
        if (!is_array($redirectUris) || !self::matchesRegisteredRedirect($redirectUris, $redirectUri)) {
            throw new SpecialException('INVALID_ARGUMENT', 'The redirect URI is not registered.', 400);
        }

        $resource = array_key_exists('resource', $input)
            ? $input['resource']
            : (string) $this->config['mcp_resource'];
        if (!is_string($resource) || $resource !== (string) $this->config['mcp_resource']) {
            throw new SpecialException('INVALID_ARGUMENT', 'The OAuth resource is invalid.', 400);
        }

        if (!is_string($input['response_type'] ?? null) || $input['response_type'] !== 'code') {
            throw new SpecialException('INVALID_ARGUMENT', 'Only the authorization code response type is supported.', 400);
        }
        $codeChallengeMethod = $input['code_challenge_method'] ?? null;
        if (!is_string($codeChallengeMethod) || $codeChallengeMethod !== 'S256') {
            throw new SpecialException('INVALID_ARGUMENT', 'S256 PKCE is required.', 400);
        }
        $codeChallenge = $this->requiredString($input, 'code_challenge', 200);
        if (preg_match('/^[A-Za-z0-9_-]{43,128}$/', $codeChallenge) !== 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'The PKCE challenge is invalid.', 400);
        }

        if (array_key_exists('scope', $input) && !is_string($input['scope'])) {
            throw new SpecialException('INVALID_ARGUMENT', 'The OAuth scope is invalid.', 400);
        }
        $scopeString = array_key_exists('scope', $input) ? trim((string) $input['scope']) : self::DEFAULT_SCOPE;
        $scopes = $this->normalizeScopes($scopeString);
        $state = $input['state'] ?? null;
        if ($state !== null && (!is_string($state) || strlen($state) > 2048)) {
            throw new SpecialException('INVALID_ARGUMENT', 'The OAuth state is invalid.', 400);
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'resource' => $resource,
            'scope' => implode(' ', $scopes),
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ];
    }

    /** @param array{client_id: string, redirect_uri: string, resource: string, scope: string, scopes: list<string>, code_challenge: string, code_challenge_method: string, state: ?string} $request */
    public function issueAuthorizationCode(Principal $principal, array $request, ?string $requestId = null): string
    {
        $code = special_b64url_encode(random_bytes(32));
        $this->store->putAuthCode(
            [
                'code_hash' => $this->security->tokenHash($code),
                'client_id' => $request['client_id'],
                'user_id' => $principal->id,
                'redirect_uri' => $request['redirect_uri'],
                'resource' => $request['resource'],
                'scope' => $request['scope'],
                'code_challenge' => $request['code_challenge'],
                'code_challenge_method' => $request['code_challenge_method'],
                'expires_at' => special_now((int) $this->config['oauth_code_ttl']),
                'consumed_at' => null,
                'created_at' => special_now(),
            ],
            special_audit_entry(
                $principal,
                'oauth.authorization_code_issued',
                'oauth_client',
                null,
                ['client_id' => $request['client_id'], 'scope' => $request['scope']],
                $requestId,
                $request['client_id'],
            ),
        );

        return $code;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function issueToken(array $input, ?string $requestId = null): array
    {
        $grantType = $this->requiredString($input, 'grant_type', 100);
        $clientId = $this->requiredString($input, 'client_id', 2048);
        if (!array_key_exists('resource', $input)
            || !is_string($input['resource'])
            || $input['resource'] !== (string) $this->config['mcp_resource']) {
            throw new SpecialException('INVALID_ARGUMENT', 'The OAuth resource is invalid.', 400);
        }
        $client = $this->getClient($clientId);
        $this->assertClient($client, $input['client_secret'] ?? null);

        return match ($grantType) {
            'authorization_code' => $this->redeemAuthorizationCode($clientId, $input, $requestId),
            'refresh_token' => $this->redeemRefreshToken($clientId, $input, $requestId),
            default => throw new SpecialException('INVALID_ARGUMENT', 'Unsupported OAuth grant type.', 400),
        };
    }

    /** @return array{client_id: string, client_name: string, client_type: string, redirect_uris: list<string>, enabled: bool} */
    public function registerClient(string $clientId, string $clientName, array $redirectUris, string $clientType = 'public', ?string $clientSecret = null): array
    {
        $clientId = trim($clientId);
        $clientName = trim($clientName);
        if ($clientId === '' || strlen($clientId) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $clientId) !== 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'Invalid OAuth client ID.', 422);
        }
        if ($clientName === '' || special_string_length($clientName) === null || special_string_length($clientName) > 200) {
            throw new SpecialException('INVALID_ARGUMENT', 'Invalid OAuth client name.', 422);
        }
        if (!in_array($clientType, ['public', 'confidential'], true)) {
            throw new SpecialException('INVALID_ARGUMENT', 'Invalid OAuth client type.', 422);
        }
        if ($redirectUris === []) {
            throw new SpecialException('INVALID_ARGUMENT', 'At least one redirect URI is required.', 422);
        }
        foreach ($redirectUris as $redirectUri) {
            if (!is_string($redirectUri) || !$this->isAllowedRedirectUri($redirectUri)) {
                throw new SpecialException('INVALID_ARGUMENT', 'Every redirect URI must be a valid HTTPS, loopback HTTP, or native-application URI without a fragment or user-info.', 422);
            }
        }
        if ($clientType === 'confidential' && ($clientSecret === null || strlen($clientSecret) < 32)) {
            throw new SpecialException('INVALID_ARGUMENT', 'A confidential client requires a 32-character secret.', 422);
        }
        if ($clientType === 'public') {
            $clientSecret = null;
        }

        $stored = $this->store->putOAuthClient([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'client_type' => $clientType,
            'client_secret_hash' => $clientSecret === null ? null : password_hash($clientSecret, PASSWORD_DEFAULT),
            'redirect_uris' => json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'enabled' => true,
            'created_at' => special_now(),
        ], false);
        if (!$stored) {
            throw new SpecialException('CONFLICT', 'That OAuth client ID already exists.', 409);
        }

        return [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'client_type' => $clientType,
            'redirect_uris' => array_values($redirectUris),
            'enabled' => true,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function registerDynamicClient(array $input): array
    {
        $redirectUris = $input['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > 10) {
            throw new SpecialException('INVALID_ARGUMENT', 'redirect_uris is required.', 400);
        }
        foreach ($redirectUris as $redirectUri) {
            if (!is_string($redirectUri)) {
                throw new SpecialException('INVALID_ARGUMENT', 'Every redirect_uri must be a string.', 400);
            }
        }
        $redirectUris = array_values(array_unique($redirectUris));
        foreach ($redirectUris as $redirectUri) {
            if (!$this->isAllowedRedirectUri($redirectUri)) {
                throw new SpecialException('INVALID_ARGUMENT', 'Invalid redirect_uri.', 400);
            }
        }
        if (($input['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new SpecialException('INVALID_ARGUMENT', 'Only public OAuth clients are supported.', 400);
        }
        $grantTypes = $input['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!$this->validClientGrantTypes($grantTypes)) {
            throw new SpecialException('INVALID_ARGUMENT', 'grant_types must include authorization_code and contain at most ten bounded values.', 400);
        }
        $responseTypes = $input['response_types'] ?? ['code'];
        if (!is_array($responseTypes)
            || $responseTypes === []
            || array_filter($responseTypes, static fn (mixed $value): bool => !is_string($value)) !== []
            || array_diff($responseTypes, ['code']) !== []) {
            throw new SpecialException('INVALID_ARGUMENT', 'response_types must contain code.', 400);
        }
        $name = $input['client_name'] ?? 'MCP client';
        if (!is_string($name)) {
            throw new SpecialException('INVALID_ARGUMENT', 'client_name must be a string.', 400);
        }
        $name = trim($name);
        if ($name === '') {
            $name = 'MCP client';
        }
        if (special_string_length($name) === null || special_string_length($name) > 200) {
            throw new SpecialException('INVALID_ARGUMENT', 'client_name is too long.', 400);
        }

        $clientId = special_b64url_encode(random_bytes(24));
        $registered = $this->registerClient($clientId, $name, $redirectUris, 'public');
        return array_merge($registered, [
            'grant_types' => array_values(array_intersect(array_unique($grantTypes), ['authorization_code', 'refresh_token'])),
            'response_types' => array_values(array_unique($responseTypes)),
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getClient(string $clientId): ?array
    {
        return $this->store->getOAuthClient($clientId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function redeemAuthorizationCode(string $clientId, array $input, ?string $requestId): array
    {
        $code = $this->requiredString($input, 'code', 512);
        $redirectUri = $this->requiredString($input, 'redirect_uri', 2000);
        $verifier = $this->requiredString($input, 'code_verifier', 128);
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'The PKCE verifier is invalid.', 400);
        }

        $codeHash = $this->security->tokenHash($code);
        $row = $this->store->getAuthCode($codeHash);
        if ($row === null
            || (string) $row['client_id'] !== $clientId
            || (string) $row['redirect_uri'] !== $redirectUri
            || ($row['resource'] !== null && (string) $row['resource'] !== (string) $this->config['mcp_resource'])
            || $row['consumed_at'] !== null
            || !special_timestamp_is_future((string) $row['expires_at'])
        ) {
            throw new SpecialException('INVALID_ARGUMENT', 'The authorization code is invalid or expired.', 400);
        }

        $expectedChallenge = special_b64url_encode(hash('sha256', $verifier, true));
        if (!hash_equals((string) $row['code_challenge'], $expectedChallenge)) {
            throw new SpecialException('INVALID_ARGUMENT', 'The PKCE verifier is invalid.', 400);
        }

        $user = $this->security->loadPrincipal((int) $row['user_id']);
        if ($user === null) {
            throw new SpecialException('UNAUTHENTICATED', 'The OAuth user is no longer active.', 401);
        }
        if (!$this->store->consumeAuthCode($codeHash, special_now())) {
            throw new SpecialException('INVALID_ARGUMENT', 'The authorization code is invalid or expired.', 400);
        }

        [$response, $access, $refresh] = $this->buildTokenPair($user, $clientId, (string) $row['scope'], (string) $this->config['mcp_resource']);
        $this->store->issueTokens(
            $access,
            $refresh,
            special_audit_entry($user, 'oauth.authorization_code_redeemed', 'oauth_client', null, ['client_id' => $clientId], $requestId, $clientId),
        );

        return $response;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function redeemRefreshToken(string $clientId, array $input, ?string $requestId): array
    {
        $refreshToken = $this->requiredString($input, 'refresh_token', 512);
        $oldHash = $this->security->tokenHash($refreshToken);
        $row = $this->store->getRefreshToken($oldHash);
        if ($row === null
            || (string) $row['client_id'] !== $clientId
            || ($row['resource'] !== null && (string) $row['resource'] !== (string) $this->config['mcp_resource'])
        ) {
            throw new SpecialException('INVALID_ARGUMENT', 'The refresh token is invalid or expired.', 400);
        }
        $family = is_string($row['refresh_family']) ? trim($row['refresh_family']) : '';
        if ($row['revoked_at'] !== null) {
            // A rotated-away token was presented again: assume theft and revoke the whole family.
            if ($family !== '') {
                $this->store->revokeFamily($family, special_now());
            }
            throw new SpecialException('INVALID_ARGUMENT', 'The refresh token is invalid or expired.', 400);
        }
        if (!special_timestamp_is_future((string) $row['expires_at'])) {
            throw new SpecialException('INVALID_ARGUMENT', 'The refresh token is invalid or expired.', 400);
        }

        $user = $this->security->loadPrincipal((int) $row['user_id']);
        if ($user === null) {
            throw new SpecialException('UNAUTHENTICATED', 'The OAuth user is no longer active.', 401);
        }
        [$response, $access, $refresh] = $this->buildTokenPair(
            $user,
            $clientId,
            (string) $row['scope'],
            (string) $this->config['mcp_resource'],
            $family !== '' ? $family : null,
        );
        $rotated = $this->store->rotateRefresh(
            $oldHash,
            special_now(),
            $access,
            $refresh,
            special_audit_entry($user, 'oauth.refresh_rotated', 'oauth_client', null, ['client_id' => $clientId], $requestId, $clientId),
        );
        if (!$rotated) {
            // Lost a race against another redemption of the same token: treat as replay.
            $this->store->revokeFamily((string) $access['refresh_family'], special_now());
            throw new SpecialException('INVALID_ARGUMENT', 'The refresh token is invalid or expired.', 400);
        }

        return $response;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} response, access row, refresh row
     */
    private function buildTokenPair(Principal $principal, string $clientId, string $scope, string $resource, ?string $refreshFamily = null): array
    {
        $scopes = $this->normalizeScopes($scope);
        $scope = implode(' ', $scopes);
        $accessToken = special_b64url_encode(random_bytes(32));
        $refreshToken = special_b64url_encode(random_bytes(48));
        $refreshFamily ??= hash('sha256', random_bytes(32));
        $base = [
            'client_id' => $clientId,
            'user_id' => $principal->id,
            'resource' => $resource,
            'refresh_family' => $refreshFamily,
            'scope' => $scope,
            'revoked_at' => null,
            'created_at' => special_now(),
        ];

        return [
            [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => (int) $this->config['oauth_access_ttl'],
                'refresh_token' => $refreshToken,
                'scope' => $scope,
            ],
            $base + ['token_hash' => $this->security->tokenHash($accessToken), 'expires_at' => special_now((int) $this->config['oauth_access_ttl'])],
            $base + [
                'token_hash' => $this->security->tokenHash($refreshToken),
                'expires_at' => special_now((int) $this->config['oauth_refresh_ttl']),
                'replaced_by_hash' => null,
                'last_used_at' => null,
            ],
        ];
    }

    /** @param array<string, mixed>|null $client */
    private function assertClient(?array $client, mixed $secret): void
    {
        if ($client === null || !(bool) $client['enabled']) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown OAuth client.', 400);
        }
        if ((string) $client['client_type'] === 'confidential') {
            if (!is_string($secret) || !password_verify($secret, (string) $client['client_secret_hash'])) {
                throw new SpecialException('INVALID_ARGUMENT', 'OAuth client authentication failed.', 400);
            }
        }
    }

    /** @return list<string> */
    private function normalizeScopes(string $scope): array
    {
        $scopes = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mapped = array_map(static function (string $value): string {
            return match ($value) {
                'spec.read' => 'model.read',
                'spec.write' => 'model.write',
                default => $value,
            };
        }, $scopes);
        if ($mapped === [self::LEGACY_SCOPE]) {
            return [self::LEGACY_SCOPE];
        }
        if (in_array(self::LEGACY_SCOPE, $mapped, true)) {
            $mapped = array_values(array_diff($mapped, [self::LEGACY_SCOPE]));
            $mapped = array_merge($mapped, [self::READ_SCOPE, self::WRITE_SCOPE]);
        }
        $unknown = array_diff($mapped, self::SCOPES);
        if ($unknown !== []) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unsupported OAuth scope.', 400, ['scope' => $unknown[0]]);
        }
        $scopes = array_values(array_unique($mapped));
        if ($scopes === []) {
            $scopes = [self::READ_SCOPE];
        }
        return $scopes;
    }

    private function requiredString(array $input, string $key, int $maxLength): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new SpecialException('INVALID_ARGUMENT', "{$key} is required and bounded.", 400);
        }
        return $value;
    }

    /** @return array<string, mixed>|null */
    private function clientFromMetadataDocument(string $clientId): ?array
    {
        $parts = parse_url($clientId);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $ipHost = trim($host, '[]');
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($ipHost, FILTER_VALIDATE_IP) !== false
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 3,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => false,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($clientId, false, $context, 0, 65_537);
        $status = isset($http_response_header[0]) && is_string($http_response_header[0])
            ? $http_response_header[0]
            : '';
        if ($body === false || strlen($body) > 65_536 || !preg_match('/^HTTP\/\S+ 200(?:\s|$)/', $status)) {
            return null;
        }

        try {
            $metadata = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($metadata)
            || (array_key_exists('client_id', $metadata)
                && (!is_string($metadata['client_id']) || !hash_equals($clientId, $metadata['client_id'])))) {
            return null;
        }

        $redirects = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirects)
            || $redirects === []
            || count($redirects) > 10
            || array_filter(
                $redirects,
                static fn (mixed $value): bool => !is_string($value)
                    || strlen($value) > 2000
                    || !self::validRedirect($value),
            ) !== []) {
            return null;
        }
        if (($metadata['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            return null;
        }

        $grantTypes = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        $responseTypes = $metadata['response_types'] ?? ['code'];
        if (!$this->validClientGrantTypes($grantTypes)) {
            return null;
        }
        if (!is_array($responseTypes)
            || $responseTypes === []
            || array_filter($responseTypes, static fn (mixed $value): bool => !is_string($value)) !== []
            || array_diff($responseTypes, ['code']) !== []) {
            return null;
        }

        $clientName = $metadata['client_name'] ?? 'MCP client';
        if (!is_string($clientName)) {
            return null;
        }
        $clientName = trim($clientName);
        if ($clientName === '') {
            $clientName = 'MCP client';
        }
        if (special_string_length($clientName) === null || special_string_length($clientName) > 200) {
            return null;
        }

        $redirects = array_values(array_unique($redirects));
        $this->store->putOAuthClient([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'client_type' => 'public',
            'client_secret_hash' => null,
            'redirect_uris' => json_encode($redirects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'enabled' => true,
            'created_at' => special_now(),
        ], true);

        return [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'client_type' => 'public',
            'client_secret_hash' => null,
            'redirect_uris' => json_encode($redirects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'enabled' => true,
            'grant_types' => array_values(array_intersect(array_unique($grantTypes), ['authorization_code', 'refresh_token'])),
            'response_types' => array_values(array_unique($responseTypes)),
        ];
    }

    private function validClientGrantTypes(mixed $grantTypes): bool
    {
        return is_array($grantTypes)
            && array_is_list($grantTypes)
            && $grantTypes !== []
            && count($grantTypes) <= 10
            && array_filter(
                $grantTypes,
                static fn (mixed $value): bool => !is_string($value)
                    || $value === ''
                    || strlen($value) > 200,
            ) === []
            && in_array('authorization_code', $grantTypes, true);
    }

    public static function validRedirect(string $uri): bool
    {
        if (strlen($uri) > 2000) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts)
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !isset($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'https') {
            return isset($parts['host']) && (string) $parts['host'] !== '';
        }
        if ($scheme === 'http') {
            return isset($parts['host'])
                && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        }

        $unsafeSchemes = [
            'about', 'blob', 'chrome', 'chrome-extension', 'data', 'edge', 'file',
            'ftp', 'ftps', 'javascript', 'mailto', 'resource', 'sms', 'tel',
            'vbscript', 'view-source', 'ws', 'wss',
        ];
        return preg_match('/^[a-z][a-z0-9+.-]*$/i', $scheme) === 1
            && !in_array($scheme, $unsafeSchemes, true)
            && ((isset($parts['host']) && (string) $parts['host'] !== '')
                || (isset($parts['path']) && (string) $parts['path'] !== ''));
    }

    /**
     * Match a requested OAuth redirect against registered redirect URIs.
     *
     * Loopback HTTP callbacks may use an arbitrary local port. Scheme, host,
     * path, query, and user-info remain exact; HTTPS callbacks remain exact
     * string matches.
     *
     * @param mixed $registered
     */
    public static function matchesRegisteredRedirect(mixed $registered, string $requested): bool
    {
        if (!is_array($registered)) {
            return false;
        }
        foreach ($registered as $candidate) {
            if (!is_string($candidate)
                || !self::validRedirect($candidate)
                || !self::validRedirect($requested)) {
                continue;
            }
            if ($candidate === $requested) {
                return true;
            }

            $registeredParts = self::loopbackRedirectParts($candidate);
            $requestedParts = self::loopbackRedirectParts($requested);
            if ($registeredParts === null || $requestedParts === null) {
                continue;
            }
            unset($registeredParts['port'], $requestedParts['port']);
            if ($registeredParts === $requestedParts) {
                return true;
            }
        }

        return false;
    }

    /** @return array{scheme: string, host: string, path: string, query: ?string, port?: int}|null */
    private static function loopbackRedirectParts(string $uri): ?array
    {
        $parts = parse_url($uri);
        if (!self::validRedirect($uri)
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return null;
        }

        return [
            'scheme' => strtolower((string) $parts['scheme']),
            'host' => strtolower((string) $parts['host']),
            'path' => (string) ($parts['path'] ?? ''),
            'query' => array_key_exists('query', $parts) ? (string) $parts['query'] : null,
            ... (array_key_exists('port', $parts) ? ['port' => (int) $parts['port']] : []),
        ];
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        return self::validRedirect($uri);
    }
}
