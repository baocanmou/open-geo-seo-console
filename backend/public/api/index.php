<?php

declare(strict_types=1);

use OpenGeo\Api;
use OpenGeo\Database;
use OpenGeo\HttpError;
use OpenGeo\Response;

$deploymentRoot = dirname(__DIR__, 2);
$bootstrap = is_file($deploymentRoot . '/backend/app/bootstrap.php')
    ? $deploymentRoot . '/backend/app/bootstrap.php'
    : $deploymentRoot . '/app/bootstrap.php';
require $bootstrap;

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
        throw new HttpError(405, '请求方法不受支持');
    }
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/health'), PHP_URL_PATH) ?: '/api/health';
    if (!str_starts_with($requestPath, '/api/')) {
        throw new HttpError(404, '接口不存在');
    }
    $path = substr($requestPath, 4) ?: '/';
    (new Api(Database::connection()))->dispatch($method, $path);
} catch (JsonException) {
    Response::error(new HttpError(400, '请求 JSON 格式无效'));
} catch (Throwable $error) {
    Response::error($error);
}
