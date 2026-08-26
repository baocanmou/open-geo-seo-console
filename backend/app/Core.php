<?php

declare(strict_types=1);

namespace OpenGeo;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Config
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Environment file is missing.');
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || isset(self::$values[$key])) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = self::$values[$key] ?? getenv($key);
        if (($value === false || $value === null || $value === '') && $default === null) {
            throw new RuntimeException("Missing configuration: {$key}");
        }
        return (string) (($value === false || $value === null || $value === '') ? $default : $value);
    }

    public static function int(string $key, int $default): int
    {
        return max(0, (int) self::get($key, (string) $default));
    }

    public static function environment(): string
    {
        $environment = self::get('APP_ENV', 'production');
        if (!in_array($environment, ['production', 'development', 'test'], true)) {
            throw new RuntimeException('APP_ENV must be production, development, or test.');
        }
        return $environment;
    }

    public static function assertProductionSecurity(): void
    {
        if (self::environment() !== 'production') {
            return;
        }
        $appUrl = self::get('APP_URL');
        $appKey = self::get('APP_KEY');
        if (!str_starts_with($appUrl, 'https://')) {
            throw new RuntimeException('Production APP_URL must use HTTPS.');
        }
        if (strlen($appKey) < 32 || preg_match('/replace|change-me|example/i', $appKey)) {
            throw new RuntimeException('Production APP_KEY must be a strong unique secret.');
        }
        if (filter_var(self::get('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('Production sessions must use secure cookies.');
        }
    }
}

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_PORT', '3306'),
            Config::get('DB_NAME')
        );
        self::$connection = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $databaseTimeZone = Config::get('DB_TIMEZONE', '+00:00');
        if (!preg_match('/\A[+-](?:0\d|1[0-4]):[0-5]\d\z/', $databaseTimeZone)) {
            throw new RuntimeException('DB_TIMEZONE must be a numeric UTC offset.');
        }
        self::$connection->exec('SET time_zone = ' . self::$connection->quote($databaseTimeZone));
        return self::$connection;
    }
}

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly ?array $details = null)
    {
        parent::__construct($message);
    }
}

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(mixed $data, ?string $csrfToken = null): never
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($csrfToken !== null) {
            $payload['csrf_token'] = $csrfToken;
        }
        self::json($payload);
    }

    public static function error(Throwable $error): never
    {
        if ($error instanceof HttpError) {
            self::json(['ok' => false, 'error' => ['message' => $error->getMessage(), 'details' => $error->details]], $error->status);
        }
        error_log(sprintf('[OPEN-GEO] %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine()));
        self::json(['ok' => false, 'error' => ['message' => '服务器处理请求时发生错误']], 500);
    }
}

final class Security
{
    private const SESSION_NAME = '__Host-OPENGEOSESSID';
    private const BOOTSTRAP_COOKIE = '__Host-OPENGEOCSRF';
    private const JSON_BODY_LIMIT = 65536;

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function hasSessionCookie(): bool
    {
        return isset($_COOKIE[self::SESSION_NAME]) && is_string($_COOKIE[self::SESSION_NAME]) && $_COOKIE[self::SESSION_NAME] !== '';
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!self::hasSessionCookie()) {
            throw new HttpError(401, '请先登录');
        }
        self::configureSession();
        session_start();

        $now = time();
        $idleTimeout = max(300, min(Config::int('SESSION_IDLE_TIMEOUT', 1800), 86400));
        $absoluteTimeout = max($idleTimeout, min(Config::int('SESSION_ABSOLUTE_TIMEOUT', 28800), 604800));
        $rotateInterval = max(300, min(Config::int('SESSION_ROTATE_INTERVAL', 900), $idleTimeout));
        $createdAt = (int) ($_SESSION['created_at'] ?? 0);
        $lastSeen = (int) ($_SESSION['last_seen'] ?? 0);
        $rotatedAt = (int) ($_SESSION['rotated_at'] ?? 0);
        $boundAgent = (string) ($_SESSION['user_agent_hash'] ?? '');
        $expired = (int) ($_SESSION['user_id'] ?? 0) < 1
            || $createdAt < 1
            || $lastSeen < 1
            || $rotatedAt < 1
            || $boundAgent === ''
            || $now - $lastSeen > $idleTimeout
            || $now - $createdAt > $absoluteTimeout
            || !hash_equals($boundAgent, self::userAgentHash());
        if ($expired) {
            self::destroySession();
            throw new HttpError(401, '登录状态已失效');
        }
        if ($now - $rotatedAt >= $rotateInterval) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = $now;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $_SESSION['last_seen'] = $now;
    }

    public static function startAuthenticatedSession(int $userId, int $sessionVersion): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::destroySession();
        }
        self::configureSession();
        session_start();
        session_regenerate_id(true);
        $now = time();
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = $sessionVersion;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['created_at'] = $now;
        $_SESSION['last_seen'] = $now;
        $_SESSION['rotated_at'] = $now;
        $_SESSION['user_agent_hash'] = self::userAgentHash();
    }

    private static function configureSession(): void
    {
        $secure = filter_var(Config::get('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN) === true;
        $idleTimeout = max(300, min(Config::int('SESSION_IDLE_TIMEOUT', 1800), 86400));
        $absoluteTimeout = max($idleTimeout, min(Config::int('SESSION_ABSOLUTE_TIMEOUT', 28800), 604800));
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', (string) $absoluteTimeout);
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function bootstrapCsrfToken(): string
    {
        $existing = (string) ($_COOKIE[self::BOOTSTRAP_COOKIE] ?? '');
        if (self::validBootstrapToken($existing)) {
            return $existing;
        }
        $issuedAt = (string) time();
        $nonce = bin2hex(random_bytes(24));
        $payload = $issuedAt . '.' . $nonce;
        $token = $payload . '.' . hash_hmac('sha256', $payload, Config::get('APP_KEY'));
        setcookie(self::BOOTSTRAP_COOKIE, $token, [
            'expires' => time() + 900,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return $token;
    }

    public static function verifyUnsafeRequest(bool $allowBootstrap = false): void
    {
        $appOrigin = rtrim(Config::get('APP_URL'), '/');
        $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($origin === '' || !hash_equals($appOrigin, $origin)) {
            throw new HttpError(403, '请求来源校验失败');
        }
        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = session_status() === PHP_SESSION_ACTIVE
            ? self::csrfToken()
            : ($allowBootstrap ? (string) ($_COOKIE[self::BOOTSTRAP_COOKIE] ?? '') : '');
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided) || ($allowBootstrap && session_status() !== PHP_SESSION_ACTIVE && !self::validBootstrapToken($expected))) {
            throw new HttpError(419, '安全令牌已失效，请刷新后重试');
        }
    }

    private static function validBootstrapToken(string $token): bool
    {
        if (!preg_match('/\A(\d{10})\.([0-9a-f]{48})\.([0-9a-f]{64})\z/', $token, $matches)) {
            return false;
        }
        $issuedAt = (int) $matches[1];
        if ($issuedAt > time() + 30 || time() - $issuedAt > 900) {
            return false;
        }
        $payload = $matches[1] . '.' . $matches[2];
        return hash_equals(hash_hmac('sha256', $payload, Config::get('APP_KEY')), $matches[3]);
    }

    public static function jsonBody(): array
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::JSON_BODY_LIMIT) {
            throw new HttpError(413, '请求内容过大');
        }
        $raw = file_get_contents('php://input', false, null, 0, self::JSON_BODY_LIMIT + 1);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > self::JSON_BODY_LIMIT) {
            throw new HttpError(413, '请求内容过大');
        }
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new HttpError(400, '请求格式无效');
        }
        return $decoded;
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function identityHash(string $username): string
    {
        return hash_hmac('sha256', strtolower(trim($username)) . '|' . self::clientIp(), Config::get('APP_KEY'));
    }

    public static function accountHash(string $username): string
    {
        return hash_hmac('sha256', 'account|' . strtolower(trim($username)), Config::get('APP_KEY'));
    }

    public static function ipHash(): string
    {
        return hash_hmac('sha256', self::clientIp(), Config::get('APP_KEY'));
    }

    public static function userAgentHash(): string
    {
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        return hash_hmac('sha256', $agent, Config::get('APP_KEY'));
    }

    public static function encrypt(array $value): string
    {
        $key = self::encryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $payload): array
    {
        $binary = base64_decode($payload, true);
        if ($binary === false || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted integration configuration is invalid.');
        }
        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::encryptionKey());
        if ($plain === false) {
            throw new RuntimeException('Encrypted integration configuration cannot be opened.');
        }
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }

    private static function encryptionKey(): string
    {
        $configured = Config::get('APP_KEY');
        $decoded = base64_decode($configured, true);
        $material = $decoded !== false ? $decoded : $configured;
        return hash('sha256', $material, true);
    }
}

final class Auth
{
    public static function user(): ?array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT id, public_id, username, display_name, role, session_version FROM app_users WHERE id = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || (int) ($user['session_version'] ?? 0) !== (int) ($_SESSION['session_version'] ?? -1)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                Security::destroySession();
            }
            return null;
        }
        return $user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new HttpError(401, '请先登录');
        }
        return $user;
    }

    public static function requireRole(array $user, array $allowedRoles): void
    {
        if (!in_array((string) ($user['role'] ?? ''), $allowedRoles, true)) {
            throw new HttpError(403, '当前账号无权执行此操作');
        }
    }

    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        if (!preg_match('/\A[a-zA-Z0-9._-]{3,64}\z/', $username)
            || !mb_check_encoding($password, 'UTF-8')
            || mb_strlen($password, 'UTF-8') < 16
            || strlen($password) > 200) {
            throw new HttpError(422, '账号或密码格式不正确');
        }
        $db = Database::connection();
        $identityHash = Security::identityHash($username);
        $accountHash = Security::accountHash($username);
        $ipHash = Security::ipHash();
        $candidateStmt = $db->prepare("SELECT *, (username = ?) AS username_match FROM app_users WHERE status = 'active' ORDER BY username_match DESC, id LIMIT 1");
        $candidateStmt->execute([$username]);
        $candidate = $candidateStmt->fetch();
        $lockSubject = $candidate && (int) $candidate['username_match'] === 1
            ? 'user:' . (string) $candidate['id']
            : 'unknown-user';
        $lockName = 'opg-login-' . substr(hash_hmac('sha256', $lockSubject, Config::get('APP_KEY')), 0, 40);
        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lockStmt->execute([$lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            throw new HttpError(429, '登录请求过于频繁，请稍后再试');
        }
        try {
            $cleanupLock = $db->query("SELECT GET_LOCK('opg-login-cleanup', 0)");
            if ((int) $cleanupLock->fetchColumn() === 1) {
                try {
                    $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1000");
                } finally {
                    $db->query("SELECT RELEASE_LOCK('opg-login-cleanup')");
                }
            }
            $guard = $db->prepare('SELECT identity_hash, COUNT(*) AS failures FROM login_attempts WHERE identity_hash IN (?, ?, ?) AND succeeded = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) GROUP BY identity_hash');
            $guard->execute([$accountHash, $identityHash, $ipHash]);
            $failures = array_fill_keys([$accountHash, $identityHash, $ipHash], 0);
            foreach ($guard->fetchAll() as $guardRow) {
                $failures[(string) $guardRow['identity_hash']] = (int) $guardRow['failures'];
            }
            if ($failures[$identityHash] >= 6 || $failures[$ipHash] >= 20) {
                throw new HttpError(429, '登录失败次数过多，请 15 分钟后再试');
            }
            $passwordValid = $candidate ? password_verify($password, (string) $candidate['password_hash']) : false;
            $valid = $candidate && (int) $candidate['username_match'] === 1 && $passwordValid;
            $log = $db->prepare('INSERT INTO login_attempts (identity_hash, succeeded, attempted_at) VALUES (?, ?, NOW()), (?, ?, NOW()), (?, ?, NOW())');
            $log->execute([$accountHash, $valid ? 1 : 0, $identityHash, $valid ? 1 : 0, $ipHash, $valid ? 1 : 0]);
            if (!$valid) {
                throw new HttpError(401, '账号或密码错误');
            }
            $row = $candidate;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
        Security::startAuthenticatedSession((int) $row['id'], (int) $row['session_version']);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash((string) $row['password_hash'], $algorithm)) {
            $rehash = password_hash($password, $algorithm);
            $db->prepare('UPDATE app_users SET password_hash = ?, last_login_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$rehash, $row['id']]);
        } else {
            $db->prepare('UPDATE app_users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$row['id']]);
        }
        Activity::record('auth.login', 'user', (string) $row['public_id']);
        return ['id' => $row['public_id'], 'username' => $row['username'], 'display_name' => $row['display_name'], 'role' => $row['role']];
    }
}

final class Activity
{
    public static function record(string $action, ?string $objectType = null, ?string $objectId = null, array $context = []): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO activity_log (user_id, action, object_type, object_id, context_json, ip_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            $action,
            $objectType,
            $objectId,
            $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_SAPI === 'cli' ? null : Security::ipHash(),
        ]);
    }
}
