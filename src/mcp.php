<?php

declare(strict_types=1);

namespace Special;

final class McpAdapter
{
    /** @var list<string> */
    private const READ_TOOLS = ['list_specs', 'get_spec', 'list_projects'];

    /** @var list<string> */
    private const WRITE_TOOLS = ['create_spec', 'update_spec', 'delete_spec'];

    private int $httpStatus = 200;

    public function __construct(
        private SpecService $specs,
        private Security $security,
        private array $config,
    ) {
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed>|null */
    public function handle(array $request, ?Principal $principal, ?string $requestId = null): ?array
    {
        $this->httpStatus = 200;
        $id = array_key_exists('id', $request) ? $request['id'] : null;
        try {
            $method = $request['method'] ?? null;
            if (!is_string($method) || $method === '' || strlen($method) > 100) {
                throw new SpecialException('INVALID_ARGUMENT', 'MCP method is invalid.', 400);
            }
            $params = $request['params'] ?? [];
            if (!is_array($params) || ($params !== [] && array_is_list($params))) {
                throw new SpecialException('INVALID_ARGUMENT', 'JSON-RPC params must be an object.', 400);
            }

            if ($method === 'notifications/initialized') {
                $this->security->assertPermission($principal, 'spec.read');
                return null;
            }
            if ($method === 'server/discover') {
                return $this->successResponse($id, [
                    'resultType' => 'complete',
                    'supportedVersions' => [(string) $this->config['mcp_discovery_version']],
                    'capabilities' => ['tools' => (object) [], 'resources' => ['listChanged' => false]],
                    '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'special', 'version' => (string) ($this->config['mcp_server_version'] ?? '1.0.0')]],
                    'instructions' => 'Use the spec tools to collect and manage specification documents. Oversized bodies are available as complete MCP resources through resources/read.',
                ]);
            }
            if ($method === 'initialize') {
                $this->security->assertPermission($principal, 'spec.read');
                return $this->successResponse($id, [
                    'protocolVersion' => (string) $this->config['mcp_protocol_version'],
                    'capabilities' => ['tools' => (object) [], 'resources' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'special', 'version' => (string) ($this->config['mcp_server_version'] ?? '1.0.0')],
                    'instructions' => 'Use the spec tools to collect and manage specification documents.',
                ]);
            }
            if ($method === 'ping') {
                $this->security->assertPermission($principal, 'spec.read');
                return $this->successResponse($id, (object) []);
            }
            if ($method === 'resources/list') {
                $this->security->assertPermission($principal, 'spec.read');
                return $this->successResponse($id, ['resultType' => 'complete', 'resources' => []]);
            }
            if ($method === 'resources/templates/list') {
                $this->security->assertPermission($principal, 'spec.read');
                return $this->successResponse($id, [
                    'resultType' => 'complete',
                    'resourceTemplates' => [[
                        'uriTemplate' => 'special://specs/{spec_id}/body',
                        'name' => 'special-spec-body',
                        'title' => 'Special spec body',
                        'description' => 'Complete Markdown source body for one Special spec.',
                        'mimeType' => 'text/markdown; charset=utf-8',
                    ]],
                ]);
            }
            if ($method === 'resources/read') {
                $this->security->assertPermission($principal, 'spec.read');
                if (!is_string($params['uri'] ?? null)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'resources/read requires a resource URI.', 422);
                }
                return $this->successResponse($id, $this->readResource($principal, $params['uri']));
            }
            if ($method === 'tools/list') {
                $principal = $this->security->assertAuthenticated($principal);
                return $this->successResponse($id, ['resultType' => 'complete', 'tools' => $this->visibleTools($principal)]);
            }
            if ($method === 'tools/call') {
                return $this->handleToolCall($id, $params, $principal, $requestId);
            }

            throw new SpecialException('METHOD_NOT_FOUND', 'Method not found.', 404);
        } catch (SpecialException $exception) {
            $this->httpStatus = $exception->httpStatus();
            return $id === null ? null : $this->errorResponse($id, $exception);
        } catch (\Throwable) {
            $this->httpStatus = 500;
            return $id === null ? null : $this->errorResponse($id, new SpecialException('INTERNAL_ERROR', 'The MCP request could not be completed.', 500));
        }
    }

    /** @return array<string, mixed> */
    public function errorForHttp(mixed $id, SpecialException $exception): array
    {
        return $this->errorResponse($id, $exception);
    }

    /** @return array<string, mixed> */
    private function readResource(Principal $principal, string $uri): array
    {
        if (preg_match('#^special://specs/([1-9][0-9]*)/body$#D', $uri, $matches) !== 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown Special resource URI.', 422);
        }
        $spec = $this->specs->getSpec($principal, (int) $matches[1]);
        return [
            'resultType' => 'complete',
            'contents' => [[
                'uri' => $uri,
                'mimeType' => 'text/markdown; charset=utf-8',
                'text' => $spec['body'] === null ? '' : (string) $spec['body'],
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function visibleTools(Principal $principal): array
    {
        $tools = [];
        if ($principal->can('spec.read')) {
            $tools[] = $this->tool('list_specs', 'List compact summaries of every public spec plus your own private specs, with bounded cursor pagination.', $this->listSchema(), true, false, true, 'model.read');
            $tools[] = $this->tool('list_projects', 'List the project names that have specs visible to you, with spec counts.', $this->emptySchema(), true, false, true, 'model.read');
            $tools[] = $this->tool('get_spec', 'Get one complete spec by ID (public specs, or your own private ones); large bodies are exposed as MCP resources.', $this->getSchema(), true, false, true, 'model.read');
        }
        if ($principal->can('spec.write')) {
            $tools[] = $this->tool('create_spec', 'Create a specification document. Specs are public unless visibility is set to private.', $this->createSchema(), false, false, false, 'model.write');
            $tools[] = $this->tool('update_spec', 'Patch one of your specification documents (including its visibility) with optional optimistic concurrency.', $this->updateSchema(), false, true, true, 'model.write');
            $tools[] = $this->tool('delete_spec', 'Soft-delete a specification document with optional optimistic concurrency.', $this->deleteSchema(), false, true, true, 'model.write');
        }
        return $tools;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function handleToolCall(mixed $id, array $params, ?Principal $principal, ?string $requestId): array
    {
        if (!is_string($params['name'] ?? null) || $params['name'] === '') {
            throw new SpecialException('INVALID_ARGUMENT', 'tools/call requires a tool name.', 422);
        }
        $name = $params['name'];
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new SpecialException('INVALID_ARGUMENT', 'Tool arguments must be an object.', 422);
        }

        $permission = match ($name) {
            'list_specs', 'get_spec', 'list_projects' => 'spec.read',
            'create_spec', 'update_spec', 'delete_spec' => 'spec.write',
            default => null,
        };
        if ($permission === null) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown tool.', 422);
        }
        $principal = $this->security->assertPermission($principal, $permission);

        $data = match ($name) {
            'list_specs' => $this->callList($principal, $arguments),
            'list_projects' => $this->callProjects($principal, $arguments),
            'get_spec' => $this->callGet($principal, $arguments),
            'create_spec' => $this->callCreate($principal, $arguments),
            'update_spec' => $this->callUpdate($principal, $arguments),
            'delete_spec' => $this->callDelete($principal, $arguments),
        };
        return $this->successResponse($id, [
            'resultType' => 'complete',
            'structuredContent' => ['data' => $data, 'meta' => $this->resultMeta($name, $data)],
            'content' => [['type' => 'text', 'text' => 'Special response is available in structuredContent.']],
        ]);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callList(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, ['limit', 'cursor', 'query', 'project_name', 'status']);
        $limit = $arguments['limit'] ?? 20;
        if (!is_int($limit)) {
            throw new SpecialException('INVALID_ARGUMENT', 'limit must be an integer.', 422);
        }
        foreach (['cursor', 'query', 'project_name', 'status'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_string($arguments[$field])) {
                throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a string or null.', 422);
            }
        }
        if (isset($arguments['cursor']) && strlen((string) $arguments['cursor']) > 512) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is too long.', 422);
        }
        $result = $this->specs->listSpecs($principal, [
            'query' => $arguments['query'] ?? null,
            'project_name' => $arguments['project_name'] ?? null,
            'status' => $arguments['status'] ?? null,
        ], $limit, $arguments['cursor'] ?? null);
        return $this->budgetList($result);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callProjects(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, []);
        return $this->specs->listProjects($principal);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callGet(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, ['id']);
        $id = $this->positiveInt($arguments, 'id');
        return ['spec' => $this->budgetSpec($this->specs->getSpec($principal, $id))];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callCreate(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, ['title', 'description', 'body', 'project_name', 'status', 'visibility']);
        foreach (['title', 'project_name'] as $field) {
            if (!is_string($arguments[$field] ?? null)) {
                throw new SpecialException('INVALID_ARGUMENT', $field . ' is required.', 422);
            }
        }
        foreach (['description', 'body', 'status', 'visibility'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_string($arguments[$field])) {
                throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a string or null.', 422);
            }
        }
        $spec = $this->specs->createSpec($principal, $arguments);
        return ['spec' => $this->budgetSpec($spec)];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callUpdate(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, ['id', 'title', 'description', 'body', 'project_name', 'status', 'visibility', 'expected_revision']);
        $id = $this->positiveInt($arguments, 'id');
        $expectedRevision = $this->optionalPositiveInt($arguments, 'expected_revision');
        $patch = [];
        foreach (['title', 'description', 'body', 'project_name', 'status', 'visibility'] as $field) {
            if (array_key_exists($field, $arguments)) {
                if ($arguments[$field] !== null && !is_string($arguments[$field])) {
                    throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a string or null.', 422);
                }
                $patch[$field] = $arguments[$field];
            }
        }
        $spec = $this->specs->updateSpec($principal, $id, $patch, $expectedRevision);
        return ['spec' => $this->budgetSpec($spec)];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function callDelete(Principal $principal, array $arguments): array
    {
        $this->validateArguments($arguments, ['id', 'expected_revision']);
        $id = $this->positiveInt($arguments, 'id');
        return ['deleted' => $this->specs->deleteSpec($principal, $id, $this->optionalPositiveInt($arguments, 'expected_revision'))];
    }

    /** @param array<string, mixed> $spec @return array<string, mixed> */
    private function budgetSpec(array $spec): array
    {
        $encoded = json_encode([
            'resultType' => 'complete',
            'structuredContent' => ['data' => ['spec' => $spec], 'meta' => ['count' => 1]],
            'content' => [['type' => 'text', 'text' => 'Special response is available in structuredContent.']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) <= (int) $this->config['mcp_response_bytes']) {
            return $spec;
        }

        $body = $spec['body'] ?? null;
        $withoutBody = $spec;
        unset($withoutBody['body']);
        $withoutBody['body_delivery_status'] = 'resource_required';
        $withoutBody['body_resource_uri'] = 'special://specs/' . (int) $spec['id'] . '/body';
        $withoutBody['body_retrieval_method'] = 'resources/read';
        $withoutBody['body_length'] = $body === null ? 0 : strlen((string) $body);
        $withoutBody['body_sha256'] = hash('sha256', $body === null ? '' : (string) $body);
        $withoutBody['is_complete_body'] = true;
        unset($withoutBody['deleted_at']);
        return $withoutBody;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function budgetList(array $result): array
    {
        $encoded = json_encode([
            'resultType' => 'complete',
            'structuredContent' => ['data' => $result, 'meta' => ['count' => count($result['items'] ?? []), 'next_cursor' => $result['next_cursor'] ?? null]],
            'content' => [['type' => 'text', 'text' => 'Special response is available in structuredContent.']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) <= (int) $this->config['mcp_response_bytes']) {
            return $result;
        }

        $items = [];
        foreach (($result['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            unset($item['description']);
            $items[] = $item;
        }
        $result['items'] = $items;
        $result['summary_fields_omitted'] = ['description'];
        $result['response_delivery_status'] = 'complete_compact_summaries';
        $compactEncoded = json_encode([
            'resultType' => 'complete',
            'structuredContent' => ['data' => $result, 'meta' => ['count' => count($result['items'] ?? []), 'next_cursor' => $result['next_cursor'] ?? null]],
            'content' => [['type' => 'text', 'text' => 'Special response is available in structuredContent.']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($compactEncoded) > (int) $this->config['mcp_response_bytes']) {
            throw new SpecialException(
                'INVALID_ARGUMENT',
                'The requested list page exceeds the response budget; reduce limit.',
                422,
                ['response_bytes' => (int) $this->config['mcp_response_bytes']],
            );
        }
        return $result;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function resultMeta(string $name, array $data): array
    {
        if ($name === 'list_specs') {
            return ['count' => count($data['items'] ?? []), 'next_cursor' => $data['next_cursor'] ?? null];
        }
        if ($name === 'list_projects') {
            return ['count' => count($data['items'] ?? [])];
        }
        return ['count' => 1];
    }

    /** @param array<string, mixed> $arguments @param list<string> $allowed */
    private function validateArguments(array $arguments, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unknown !== []) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown argument: ' . (string) $unknown[0] . '.', 422);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function positiveInt(array $arguments, string $field): int
    {
        if (!array_key_exists($field, $arguments) || !is_int($arguments[$field]) || $arguments[$field] < 1) {
            throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a positive integer.', 422);
        }
        return $arguments[$field];
    }

    /** @param array<string, mixed> $arguments */
    private function optionalPositiveInt(array $arguments, string $field): ?int
    {
        if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
            return null;
        }
        return $this->positiveInt($arguments, $field);
    }

    /** @return array<string, mixed> */
    private function successResponse(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function errorResponse(mixed $id, SpecialException $exception): array
    {
        $data = ['code' => $exception->errorCode()];
        if ($exception->details() !== []) {
            $data['details'] = $exception->details();
        }
        $rpcCode = match ($exception->errorCode()) {
            'INVALID_ARGUMENT' => -32602,
            'METHOD_NOT_FOUND' => -32601,
            'UNAUTHENTICATED' => -32001,
            'FORBIDDEN' => -32003,
            'NOT_FOUND' => -32004,
            'CONFLICT' => -32009,
            default => -32000,
        };
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $rpcCode, 'message' => $exception->getMessage(), 'data' => $data],
        ];
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    private function tool(string $name, string $description, array $schema, bool $readOnly, bool $destructive, bool $idempotent, string $scope): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $destructive,
                'idempotentHint' => $idempotent,
                'openWorldHint' => false,
            ],
            'securitySchemes' => [['type' => 'oauth2', 'scopes' => [$scope]]],
        ];
    }

    /** @return array<string, mixed> */
    private function listSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) $this->config['mcp_max_page_size']],
                'cursor' => ['type' => ['string', 'null'], 'maxLength' => 512],
                'query' => ['type' => ['string', 'null'], 'maxLength' => 200],
                'project_name' => ['type' => ['string', 'null'], 'maxLength' => 200],
                'status' => ['type' => ['string', 'null'], 'enum' => ['draft', 'active', 'archived', null]],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    /** @return array<string, mixed> */
    private function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function createSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'project_name'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'body' => ['type' => ['string', 'null'], 'maxLength' => 100000],
                'project_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'private'], 'default' => 'public'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function updateSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'body' => ['type' => ['string', 'null'], 'maxLength' => 100000],
                'project_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'private']],
                'expected_revision' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function deleteSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'expected_revision' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ],
            'additionalProperties' => false,
        ];
    }
}

/** @param array<string, mixed> $config */
function special_mcp_handle_http(Security $security, SpecService $specs, array $config, string $requestId): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    special_mcp_cors_headers();
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['error' => 'method_not_allowed'], JSON_THROW_ON_ERROR);
        exit;
    }

    header('MCP-Protocol-Version: ' . (string) $config['mcp_protocol_version']);
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) && ctype_digit((string) $_SERVER['CONTENT_LENGTH'])
        ? (int) $_SERVER['CONTENT_LENGTH']
        : 0;
    if ($contentLength > (int) $config['mcp_request_bytes']) {
        special_mcp_log($requestId, 'unparsed', 'error', 'REQUEST_TOO_LARGE');
        http_response_code(413);
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'The MCP request is too large.',
                'data' => ['code' => 'REQUEST_TOO_LARGE', 'max_bytes' => (int) $config['mcp_request_bytes']],
            ],
        ], JSON_THROW_ON_ERROR);
        exit;
    }
    $raw = file_get_contents('php://input');
    $request = null;
    try {
        if ($raw === false || strlen($raw) > (int) $config['mcp_request_bytes']) {
            special_mcp_log($requestId, 'unparsed', 'error', 'REQUEST_TOO_LARGE');
            http_response_code(413);
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32000,
                    'message' => 'The MCP request is too large.',
                    'data' => ['code' => 'REQUEST_TOO_LARGE', 'max_bytes' => (int) $config['mcp_request_bytes']],
                ],
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request) || array_is_list($request) || ($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)) {
            throw new SpecialException('INVALID_ARGUMENT', 'Invalid JSON-RPC request.', 400);
        }
        if (array_key_exists('id', $request) && !is_int($request['id']) && !is_string($request['id']) && $request['id'] !== null) {
            throw new SpecialException('INVALID_ARGUMENT', 'JSON-RPC id must be a string, integer, or null.', 400);
        }
    } catch (\JsonException) {
        special_mcp_log($requestId, 'unparsed', 'error', 'INVALID_ARGUMENT');
        http_response_code(400);
        echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error', 'data' => ['code' => 'INVALID_ARGUMENT']]], JSON_THROW_ON_ERROR);
        exit;
    } catch (SpecialException $exception) {
        special_mcp_log($requestId, is_array($request) && is_string($request['method'] ?? null) ? $request['method'] : 'unparsed', 'error', $exception->errorCode());
        http_response_code(400);
        echo json_encode(['jsonrpc' => '2.0', 'id' => is_array($request) ? ($request['id'] ?? null) : null, 'error' => ['code' => -32600, 'message' => $exception->getMessage(), 'data' => ['code' => $exception->errorCode()]]], JSON_THROW_ON_ERROR);
        exit;
    }

    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $token = special_mcp_bearer_token($authorization);
    $principal = $token === null ? null : $security->principalFromBearer($token);
    $protectedMethods = [
        'initialize',
        'ping',
        'notifications/initialized',
        'tools/list',
        'tools/call',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];
    if ($principal === null && ($authorization !== '' || in_array($request['method'], $protectedMethods, true))) {
        special_mcp_log($requestId, (string) $request['method'], 'unauthorized', 'AUTHENTICATION_REQUIRED');
        http_response_code(401);
        $details = $authorization === '' ? [] : ['oauth_error' => 'invalid_token'];
        header('WWW-Authenticate: ' . special_mcp_oauth_challenge($config, $details, 401));
        if (!array_key_exists('id', $request) || $request['id'] === null) {
            exit;
        }
        $errorData = ['code' => 'AUTHENTICATION_REQUIRED'];
        if ($details !== []) {
            $errorData['details'] = $details;
        }
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => ['code' => -32001, 'message' => 'Authentication required.', 'data' => $errorData],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $adapter = new McpAdapter($specs, $security, $config);
    $response = $adapter->handle($request, $principal, $requestId);
    $status = $adapter->httpStatus();
    $outcome = is_array($response) && isset($response['error']) ? 'error' : 'success';
    $code = is_array($response) && is_array($response['error']['data'] ?? null) && is_string($response['error']['data']['code'] ?? null)
        ? $response['error']['data']['code']
        : null;
    special_mcp_log($requestId, (string) $request['method'], $outcome, $code);
    http_response_code($status);
    if ($status === 401 || $status === 403) {
        $data = is_array($response) && is_array($response['error']['data'] ?? null) ? $response['error']['data'] : [];
        $details = is_array($data['details'] ?? null) ? $data['details'] : $data;
        header('WWW-Authenticate: ' . special_mcp_oauth_challenge($config, $details, $status));
        http_response_code($status);
    }
    if ($response === null) {
        if ($status === 200) {
            http_response_code(202);
        }
        exit;
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string, mixed> $config @param array<string, mixed> $details */
function special_mcp_oauth_challenge(array $config, array $details = [], int $status = 401): string
{
    $challenge = 'Bearer resource_metadata="' . (string) $config['app_url'] . '/.well-known/oauth-protected-resource/mcp"';
    $oauthError = $details['oauth_error'] ?? null;
    $requiredScope = $details['required_scope'] ?? OAuth::DEFAULT_SCOPE;
    if (!is_string($requiredScope) || preg_match('/^[A-Za-z0-9:._-]+(?:\s+[A-Za-z0-9:._-]+)*$/', $requiredScope) !== 1) {
        $requiredScope = OAuth::DEFAULT_SCOPE;
    }
    if ($oauthError === 'invalid_token') {
        $challenge .= ', error="invalid_token", error_description="The bearer token is invalid or expired."';
    }
    if ($status === 401 || $oauthError === 'insufficient_scope') {
        $scope = $oauthError === 'insufficient_scope' ? $requiredScope : OAuth::DEFAULT_SCOPE;
        $challenge .= ', scope="' . $scope . '"';
    }
    if ($oauthError === 'insufficient_scope') {
        $challenge = str_replace(', scope="' . $requiredScope . '"', ', error="insufficient_scope", scope="' . $requiredScope . '"', $challenge);
    }

    return $challenge;
}

function special_mcp_handle_preflight(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    special_mcp_cors_headers();
    http_response_code(204);
    exit;
}

function special_mcp_handle_method_not_allowed(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    special_mcp_cors_headers();
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'method_not_allowed'], JSON_THROW_ON_ERROR);
    exit;
}

function special_mcp_cors_headers(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if (!in_array($origin, ['https://chatgpt.com', 'https://chat.openai.com'], true)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, MCP-Session-Id');
    header('Access-Control-Expose-Headers: WWW-Authenticate, MCP-Protocol-Version, MCP-Session-Id');
    header('Access-Control-Max-Age: 600');
}

function special_mcp_log(string $requestId, string $method, string $outcome, ?string $code = null): void
{
    $safeMethod = preg_replace('/[^A-Za-z0-9._:\/-]/', '_', substr($method, 0, 100)) ?: 'unknown';
    $knownMethods = ['initialize', 'ping', 'notifications/initialized', 'server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/templates/list', 'resources/read'];
    Metrics::inc('special_mcp_requests_total', ['method' => in_array($safeMethod, $knownMethods, true) ? $safeMethod : 'other', 'outcome' => $outcome]);
    $message = 'MCP request_id=' . $requestId . ' method=' . $safeMethod . ' outcome=' . $outcome;
    if ($code !== null) {
        $message .= ' code=' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', substr($code, 0, 100));
    }
    error_log($message);
}

function special_mcp_bearer_token(string $authorization): ?string
{
    if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorization), $matches) !== 1) {
        return null;
    }
    return strlen($matches[1]) > 512 ? null : $matches[1];
}
