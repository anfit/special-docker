<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = realpath(__DIR__);
$staticPath = $publicRoot === false ? false : realpath($publicRoot . $path);
if (
    $publicRoot !== false
    && $staticPath !== false
    && is_file($staticPath)
    && ($staticPath === $publicRoot || str_starts_with($staticPath, $publicRoot . DIRECTORY_SEPARATOR))
) {
    return false;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Web.php';

use Special\Principal;
use Special\OAuth;
use Special\RememberMe;
use Special\Security;
use Special\SpecService;
use Special\SpecialException;
use function Special\special_mcp_handle_http;
use function Special\special_mcp_handle_method_not_allowed;
use function Special\special_mcp_handle_preflight;
use function Special\special_mcp_cors_headers;
use function Special\special_b64url_encode;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$emitUnavailable = static function (int $status, bool $json): never {
    http_response_code($status);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32603,
                'message' => 'The service is temporarily unavailable.',
                'data' => ['code' => 'INTERNAL_ERROR'],
            ],
        ], JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The service is temporarily unavailable.';
    }
    exit;
};

$crossOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$isOAuthRoute = str_starts_with($path, '/oauth/') || str_starts_with($path, '/.well-known/');
if ($isOAuthRoute && in_array($crossOrigin, ['https://chatgpt.com', 'https://chat.openai.com'], true)) {
    header('Access-Control-Allow-Origin: ' . $crossOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
if ($isOAuthRoute && $method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($path === '/mcp' && $method === 'OPTIONS') {
    special_mcp_handle_preflight();
}
if ($path === '/mcp') {
    special_mcp_cors_headers();
    if ($method !== 'POST') {
        special_mcp_handle_method_not_allowed();
    }
}

try {
    $config = special_config();
} catch (Throwable) {
    $emitUnavailable($path === '/health' ? 503 : 500, $path === '/mcp');
}

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        special_store($config)->ping();
        http_response_code(200);
        $health = ['status' => 'ok', 'version' => $config['version'], 'commit' => $config['commit']];
        if ($config['insecure_http'] === true) {
            $health['warnings'] = ['insecure_http'];
        }
        echo json_encode($health, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode(['status' => 'unavailable'], JSON_THROW_ON_ERROR);
    }
    exit;
}

try {
    $store = special_store($config);
    $security = new Security($store, $config);
    $requestId = special_request_id();
    $specService = new SpecService($store, $security, $config, $requestId);
    $oauth = new OAuth($store, $security, $config);
} catch (Throwable) {
    $emitUnavailable(503, $path === '/mcp');
}

if ($path === '/mcp') {
    try {
        special_mcp_handle_http($security, $specService, $config, $requestId);
    } catch (Throwable) {
        $emitUnavailable(503, true);
    }
}

$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
$isSharedRoute = count($segments) === 2 && $segments[0] === 'share';
$principal = null;
$flash = [];
if (!$isSharedRoute) {
    try {
        special_start_session($config);
        $principal = $security->principalFromSession($_SESSION);
    } catch (Throwable) {
        $emitUnavailable(500, false);
    }
    if ($principal === null && isset($_SESSION['user_id'])) {
        unset($_SESSION['user_id']);
    }
    $flash = special_take_flash();
}

$idFromSegments = static function (int $index) use ($segments): int {
    $value = $segments[$index] ?? '';
    if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
        throw new SpecialException('NOT_FOUND', 'Not found.', 404);
    }
    return (int) $value;
};

// A user whose password was reset by an administrator may only reach the account page until they change it.
if ($principal !== null && $principal->mustChangePassword && !in_array($path, ['/account', '/account/password', '/logout'], true)) {
    special_redirect('/account');
}

$render = static function (string $view, array $data = [], int $status = 200) use ($principal, $flash): void {
    special_render($view, array_merge(['principal' => $principal, 'flash' => $flash], $data), $status);
};

$requirePrincipal = static function () use ($security, $principal): Principal {
    return $security->assertAuthenticated($principal);
};

$appendQuery = static function (string $uri, array $params): string {
    $separator = str_contains($uri, '?') ? '&' : '?';
    return $uri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};

try {
    if ($path === '/' && $method === 'GET') {
        special_redirect($principal === null ? ($security->needsSetup() ? '/setup' : '/login') : '/specs');
    }

    if ($path === '/setup') {
        if ($principal !== null || !$security->needsSetup()) {
            throw new SpecialException('NOT_FOUND', 'Not found.', 404);
        }
        if ($method === 'GET') {
            $security->ensureSetupToken();
            $render('setup', ['title' => 'First-run setup', 'error' => null, 'username' => '', 'tokenFromEnvironment' => (string) $config['setup_token'] !== '']);
            exit;
        }
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        special_require_csrf(special_form_string('csrf'));
        $setupUsername = special_form_string('username', '') ?? '';
        try {
            $password = special_form_string('password', '') ?? '';
            if (!hash_equals($password, special_form_string('password_confirm', '') ?? '')) {
                throw new SpecialException('INVALID_ARGUMENT', 'The two passwords do not match.', 422);
            }
            $created = $security->completeSetup($setupUsername, $password, special_form_string('token', '') ?? '', special_client_ip($config), $requestId);
        } catch (SpecialException $exception) {
            if ($exception->errorCode() === 'NOT_FOUND') {
                throw $exception;
            }
            $render('setup', ['title' => 'First-run setup', 'error' => $exception->getMessage(), 'username' => $setupUsername, 'tokenFromEnvironment' => (string) $config['setup_token'] !== ''], $exception->httpStatus() >= 500 ? 500 : $exception->httpStatus());
            exit;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $created->id;
        $_SESSION['_csrf'] = special_b64url_encode(random_bytes(32));
        special_flash('Setup complete. You are signed in as the administrator.', 'info');
        special_redirect('/specs');
    }

    if ($path === '/.well-known/oauth-protected-resource' || $path === '/.well-known/oauth-protected-resource/mcp') {
        if ($method !== 'GET') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo json_encode($oauth->protectedResourceMetadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($path === '/.well-known/oauth-authorization-server') {
        if ($method !== 'GET') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo json_encode($oauth->metadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($path === '/oauth/register') {
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            $input = str_contains($contentType, 'application/json')
                ? json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR)
                : $_POST;
            if (!is_array($input)) {
                throw new SpecialException('INVALID_ARGUMENT', 'OAuth client metadata must be an object.', 400);
            }
            http_response_code(201);
            echo json_encode($oauth->registerDynamicClient($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (SpecialException $exception) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_client_metadata', 'error_description' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_client_metadata', 'error_description' => 'OAuth client metadata is invalid.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            http_response_code(503);
            echo json_encode(['error' => 'temporarily_unavailable'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    if ($path === '/oauth/token') {
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            $input = str_contains($contentType, 'application/json')
                ? json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR)
                : $_POST;
            if (!is_array($input)) {
                throw new SpecialException('INVALID_ARGUMENT', 'OAuth token input must be an object.', 400);
            }
            echo json_encode($oauth->issueToken($input, $requestId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (SpecialException $exception) {
            http_response_code(400);
            $oauthError = $exception->errorCode() === 'UNAUTHENTICATED' ? 'invalid_grant' : 'invalid_request';
            echo json_encode(['error' => $oauthError, 'error_description' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'OAuth token input is invalid.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            http_response_code(503);
            echo json_encode(['error' => 'temporarily_unavailable'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    if ($path === '/oauth/authorize') {
        if ($method === 'GET') {
            $authorizeRequest = $oauth->validateAuthorizationRequest($_GET);
            if ($principal === null) {
                $returnTo = '/oauth/authorize?' . http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
                special_redirect('/login?return_to=' . rawurlencode($returnTo));
            }
            $client = $oauth->getClient($authorizeRequest['client_id']);
            $render('oauth-consent', [
                'title' => 'Authorize MCP access',
                'oauthRequest' => $authorizeRequest,
                'clientName' => is_array($client) ? (string) $client['client_name'] : $authorizeRequest['client_id'],
            ]);
            exit;
        }
        if ($method === 'POST') {
            special_require_csrf(special_form_string('csrf'));
            $authorizeRequest = $oauth->validateAuthorizationRequest($_POST);
            $actor = $requirePrincipal();
            if (special_form_string('approve') !== '1') {
                $denied = ['error' => 'access_denied'];
                if ($authorizeRequest['state'] !== null) {
                    $denied['state'] = $authorizeRequest['state'];
                }
                special_redirect($appendQuery($authorizeRequest['redirect_uri'], $denied));
            }
            $code = $oauth->issueAuthorizationCode($actor, $authorizeRequest, $requestId);
            $approved = ['code' => $code];
            if ($authorizeRequest['state'] !== null) {
                $approved['state'] = $authorizeRequest['state'];
            }
            special_redirect($appendQuery($authorizeRequest['redirect_uri'], $approved));
        }
        throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
    }

    if ($path === '/login') {
        if ($principal !== null) {
            special_redirect('/specs');
        }
        if ($security->needsSetup()) {
            special_redirect('/setup');
        }
        $returnTo = special_safe_return_to($_GET['return_to'] ?? $_POST['return_to'] ?? null);
        if ($method === 'GET') {
            $render('login', ['title' => 'Log in', 'returnTo' => $returnTo, 'error' => null]);
            exit;
        }
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        special_require_csrf(special_form_string('csrf'));
        try {
            $loggedIn = $security->authenticate(
                special_form_string('username', '') ?? '',
                special_form_string('password', '') ?? '',
                special_client_ip($config),
                $requestId,
            );
        } catch (SpecialException $exception) {
            $render('login', ['title' => 'Log in', 'returnTo' => $returnTo, 'error' => $exception->errorCode() === 'UNAUTHENTICATED' ? 'Invalid username or password.' : $exception->getMessage()], 401);
            exit;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $loggedIn->id;
        $_SESSION['_csrf'] = special_b64url_encode(random_bytes(32));
        $rememberMe = new RememberMe($store, $config);
        $rememberMe->clearCurrentBrowserToken();
        if (special_form_string('remember_me') === '1') {
            try {
                $rememberMe->issueForUser($loggedIn->id);
            } catch (\Throwable) {
                error_log('Persistent login could not be created.');
            }
        }
        special_redirect($returnTo);
    }

    if ($path === '/logout') {
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        special_require_csrf(special_form_string('csrf'));
        (new RememberMe($store, $config))->clearCurrentBrowserToken();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        special_redirect('/login');
    }

    if ($path === '/account') {
        $actor = $requirePrincipal();
        if ($method !== 'GET') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        $render('account', ['title' => 'Account']);
        exit;
    }

    if ($path === '/account/password') {
        $actor = $requirePrincipal();
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        special_require_csrf(special_form_string('csrf'));
        $security->changePassword($actor, $actor->id, special_form_string('password', '') ?? '', $requestId);
        special_flash('Your password was changed.', 'info');
        special_redirect('/specs');
    }

    if ($isSharedRoute) {
        if ($method !== 'GET') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        $spec = $specService->getSpecByShareToken($segments[1]);
        $render('spec-show', [
            'title' => $spec['title'],
            'spec' => $spec,
            'isShared' => true,
            'shareUrl' => null,
        ]);
        exit;
    }

    if ($path === '/specs' && $method === 'GET') {
        $actor = $requirePrincipal();
        $query = is_string($_GET['query'] ?? null) ? $_GET['query'] : null;
        $projectName = is_string($_GET['project_name'] ?? null) ? $_GET['project_name'] : null;
        $status = is_string($_GET['status'] ?? null) && $_GET['status'] !== '' ? $_GET['status'] : null;
        $cursor = is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : null;
        $result = $specService->listSpecs($actor, ['query' => $query, 'project_name' => $projectName, 'status' => $status], 20, $cursor);
        $items = [];
        foreach ($result['items'] as $spec) {
            $spec['share_url'] = $specService->shareUrlFor((int) $spec['id']);
            $items[] = $spec;
        }
        $render('specs-list', [
            'title' => 'Specs',
            'items' => $items,
            'nextCursor' => $result['next_cursor'],
            'query' => $query,
            'projectName' => $projectName,
            'status' => $status,
        ]);
        exit;
    }

    if ($path === '/projects' && $method === 'GET') {
        $actor = $requirePrincipal();
        $render('projects-list', ['title' => 'Projects', 'projects' => $specService->listProjects($actor)['items']]);
        exit;
    }

    if ($path === '/specs/new') {
        $actor = $requirePrincipal();
        $security->assertPermission($actor, 'spec.write');
        if ($method !== 'GET') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        $render('spec-form', ['title' => 'New spec', 'isEdit' => false, 'spec' => null, 'action' => '/specs', 'error' => null]);
        exit;
    }

    if ($path === '/specs' && $method === 'POST') {
        $actor = $requirePrincipal();
        $security->assertPermission($actor, 'spec.write');
        special_require_csrf(special_form_string('csrf'));
        $spec = $specService->createSpec($actor, [
            'title' => special_form_string('title'),
            'description' => special_form_string('description'),
            'body' => special_form_string('body'),
            'project_name' => special_form_string('project_name'),
            'status' => special_form_string('status'),
            'visibility' => special_form_string('visibility'),
        ]);
        special_flash('Spec created.', 'info');
        special_redirect('/specs/' . (int) $spec['id']);
    }

    if (count($segments) >= 2 && $segments[0] === 'specs') {
        $specId = $idFromSegments(1);
        if (($segments[2] ?? null) === 'edit') {
            $actor = $requirePrincipal();
            $security->assertPermission($actor, 'spec.write');
            if ($method !== 'GET') {
                throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
            }
            $spec = $specService->getSpec($actor, $specId);
            $render('spec-form', ['title' => 'Edit spec', 'isEdit' => true, 'spec' => $spec, 'action' => '/specs/' . $specId, 'error' => null]);
            exit;
        }
        if (($segments[2] ?? null) === 'delete') {
            $actor = $requirePrincipal();
            $security->assertPermission($actor, 'spec.write');
            if ($method !== 'POST') {
                throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
            }
            special_require_csrf(special_form_string('csrf'));
            $specService->deleteSpec($actor, $specId, special_form_int('expected_revision'));
            special_flash('Spec deleted.', 'info');
            special_redirect('/specs');
        }
        $actor = $requirePrincipal();
        if ($method === 'GET') {
            $spec = $specService->getSpec($actor, $specId);
            $render('spec-show', [
                'title' => $spec['title'],
                'spec' => $spec,
                'isShared' => false,
                'shareUrl' => $specService->shareUrlFor($specId),
            ]);
            exit;
        }
        if ($method === 'POST') {
            $security->assertPermission($actor, 'spec.write');
            special_require_csrf(special_form_string('csrf'));
            $patch = [];
            foreach (['title', 'description', 'body', 'project_name', 'status', 'visibility'] as $field) {
                if (array_key_exists($field, $_POST)) {
                    $patch[$field] = special_form_string($field);
                }
            }
            $spec = $specService->updateSpec($actor, $specId, $patch, special_form_int('expected_revision'));
            special_flash('Spec updated.', 'info');
            special_redirect('/specs/' . $specId);
        }
        throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
    }

    if ($path === '/admin/users') {
        $actor = $requirePrincipal();
        $security->assertPermission($actor, 'users.manage');
        if ($method === 'GET') {
            $render('admin-users', ['title' => 'User administration', 'users' => $security->listUsers($actor), 'error' => null]);
            exit;
        }
        if ($method === 'POST') {
            special_require_csrf(special_form_string('csrf'));
            $security->createUser($actor, special_form_string('username', '') ?? '', special_form_string('password', '') ?? '', special_form_string('role', 'viewer') ?? 'viewer', $requestId);
            special_flash('User created.', 'info');
            special_redirect('/admin/users');
        }
        throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
    }

    if (count($segments) === 4 && $segments[0] === 'admin' && $segments[1] === 'users') {
        $actor = $requirePrincipal();
        $security->assertPermission($actor, 'users.manage');
        $userId = $idFromSegments(2);
        if ($method !== 'POST') {
            throw new SpecialException('INVALID_ARGUMENT', 'Method not allowed.', 405);
        }
        special_require_csrf(special_form_string('csrf'));
        if ($segments[3] === 'role') {
            $security->changeRole($actor, $userId, special_form_string('role', '') ?? '', $requestId);
            special_flash('Role updated.', 'info');
            special_redirect('/admin/users');
        }
        if ($segments[3] === 'reset-password') {
            $reset = $security->resetPassword($actor, $userId, $requestId);
            header('Cache-Control: no-store');
            $render('admin-password-reset', ['title' => 'Temporary password', 'resetUser' => $reset['user'], 'temporaryPassword' => $reset['temporary_password']]);
            exit;
        }
        if ($segments[3] === 'enabled') {
            $security->setEnabled($actor, $userId, special_form_string('enabled') === '1', $requestId);
            special_flash('User status updated.', 'info');
            special_redirect('/admin/users');
        }
    }

    throw new SpecialException('NOT_FOUND', 'Not found.', 404);
} catch (SpecialException $exception) {
    $status = $exception->httpStatus();
    if ($status === 401 && $principal === null) {
        special_redirect('/login?return_to=' . rawurlencode($path));
    }
    $message = $status >= 500 ? 'An internal error occurred.' : $exception->getMessage();
    $render('error', ['title' => 'Error', 'status' => $status, 'error' => $message], $status);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Special request failure request_id=%s path=%s class=%s message=%s',
        $requestId ?? 'unavailable',
        $path,
        get_class($exception),
        $exception->getMessage(),
    ));
    $render('error', ['title' => 'Error', 'status' => 500, 'error' => 'An internal error occurred.'], 500);
}
