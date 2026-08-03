<?php
declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

error_reporting(0);
ini_set('display_errors', '0');

define('ROOT', __DIR__);
define('JWT_SECRET', 'dns_system_jwt_secret_key_2026');

if (!file_exists(ROOT . '/db_config.php')) {
    sendJson(500, ['error' => '缺少数据库配置文件 db_config.php']);
}
$dbConfig = require ROOT . '/db_config.php';

function sendJson(int $status, $data = null): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    if ($data !== null) {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function genId(string $prefix): string {
    return $prefix . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
}

function cleanInput($value): string {
    return trim(strip_tags((string)$value));
}

function fullRecordHost(PDO $pdo, string $domainId, string $host): string {
    $stmt = $pdo->prepare("SELECT domain FROM domains WHERE id = ?");
    $stmt->execute([$domainId]);
    $domain = trim((string)($stmt->fetchColumn() ?: ''));
    return $domain !== '' ? strtolower(trim($host) . '.' . $domain) : strtolower(trim($host));
}

function requireFields(array $body, array $fields): void {
    foreach ($fields as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            sendJson(400, ['error' => "缺少字段：{$field}"]);
        }
    }
}

function db(): PDO {
    static $pdo = null;
    global $dbConfig;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_PERSISTENT => true
    ]);
    return $pdo;
}

function isDbAvailable(): bool {
    static $available = null;
    if ($available !== null) return $available;
    try {
        db();
        $available = true;
        return true;
    } catch (Throwable $e) {
        $available = false;
        return false;
    }
}

function dashboardStats(PDO $pdo): array {
    $stats = [
        'revenueToday' => 0.0,
        'revenue7Days' => 0.0,
        'revenue30Days' => 0.0,
        'revenueTotal' => 0.0
    ];
    try {
        $row = $pdo->query("
            SELECT
              COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) >= CURDATE() THEN amount ELSE 0 END), 0) AS revenue_today,
              COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN amount ELSE 0 END), 0) AS revenue_7_days,
              COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN amount ELSE 0 END), 0) AS revenue_30_days,
              COALESCE(SUM(amount), 0) AS revenue_total
            FROM orders
            WHERE status = 'paid'
        ")->fetch();
        if ($row) {
            $stats['revenueToday'] = (float)$row['revenue_today'];
            $stats['revenue7Days'] = (float)$row['revenue_7_days'];
            $stats['revenue30Days'] = (float)$row['revenue_30_days'];
            $stats['revenueTotal'] = (float)$row['revenue_total'];
        }
    } catch (Throwable $e) {
    }
    return $stats;
}

function getClientIP(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return '0.0.0.0';
}

function headersArray(): array {
    if (function_exists('getallheaders')) return getallheaders();
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function getAuthToken(): string {
    foreach (headersArray() as $key => $value) {
        if (strtolower($key) === 'authorization' && stripos($value, 'bearer ') === 0) {
            return substr($value, 7);
        }
        if (strtolower($key) === 'x-token') return (string)$value;
    }
    return (string)($_GET['token'] ?? '');
}

function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwtEncode(array $payload): string {
    $payload['exp'] = time() + 86400 * 7;
    $header = b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $body = b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = b64url(hash_hmac('sha256', "{$header}.{$body}", JWT_SECRET, true));
    return "{$header}.{$body}.{$sig}";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = b64url(hash_hmac('sha256', "{$header}.{$body}", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!$payload || !empty($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

function verifyMd5Password(string $password, string $salt, string $storedHash): bool {
    return hash_equals($storedHash, md5($salt . $password));
}

function settingCachePath(): string {
    return ROOT . '/data/settings_cache.json';
}

function readSettingsCache(): array {
    $path = settingCachePath();
    if (!is_file($path)) {
        return [
            'systemName' => 'DNS二级域名分发系统',
            'adminLogoText' => 'DNS',
            'adminBrandName' => '分发出租',
            'defaultAvatarUrl' => '',
            'epay' => [
                'pid' => '1001',
                'key' => 'your_epay_key_here',
                'gateway' => 'https://pay.example.com/submit.php',
                'notifyIpWhitelist' => '127.0.0.1,::1'
            ]
        ];
    }
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function writeSettingsCache(array $settings): void {
    $dir = dirname(settingCachePath());
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents(settingCachePath(), json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function pendingRechargeDir(): string {
    return ROOT . '/data/recharge_orders';
}

function pendingRechargePath(string $tradeNo): string {
    return pendingRechargeDir() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $tradeNo) . '.json';
}

function savePendingRecharge(array $order): bool {
    $tradeNo = (string)($order['trade_no'] ?? '');
    if ($tradeNo === '') return false;
    if (!is_dir(pendingRechargeDir())) @mkdir(pendingRechargeDir(), 0775, true);
    return @file_put_contents(pendingRechargePath($tradeNo), json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function readPendingRecharge(string $tradeNo): ?array {
    $path = pendingRechargePath($tradeNo);
    if (!is_file($path)) return null;
    $order = json_decode((string)file_get_contents($path), true);
    return is_array($order) ? $order : null;
}

function loadSetting(string $key, $default = null) {
    $cache = readSettingsCache();
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!isDbAvailable()) return $default;
    try {
        $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) return $default;
        $decoded = json_decode((string)$value, true);
        $setting = $decoded === null ? $value : $decoded;
        $cache[$key] = $setting;
        writeSettingsCache($cache);
        return $setting;
    } catch (Throwable $e) {
        return $cache[$key] ?? $default;
    }
}

function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $proto = $https ? 'https' : 'http';
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function md5Sign(array $params, string $key): string {
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}

function ensureTenantCreditTables(): bool {
    if (!isDbAvailable()) return false;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS tenant_credits (
            username VARCHAR(80) NOT NULL PRIMARY KEY,
            credits DECIMAL(12,2) NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS tenant_credit_logs (
            id VARCHAR(30) NOT NULL PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            trade_no VARCHAR(50) NOT NULL UNIQUE,
            type VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            remark VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureTenantUserTables(): bool {
    if (!isDbAvailable()) return false;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_users (
            id VARCHAR(40) NOT NULL PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password VARCHAR(32) DEFAULT NULL,
            name VARCHAR(100) DEFAULT NULL,
            contact VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            email VARCHAR(120) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'enabled',
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            KEY idx_status (status),
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            "ALTER TABLE tenant_users ADD COLUMN password VARCHAR(32) DEFAULT NULL AFTER username",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}


function tenantPasswordHash(string $password): string {
    return md5($password);
}

function upsertTenantUser(array $body, bool $allowStatus = false): array {
    $username = strtolower(trim((string)($body['username'] ?? '')));
    if ($username === '') throw new Exception('缺少用户账号');
    if (!ensureTenantUserTables()) throw new Exception('用户表初始化失败，请联系管理员');
    ensureTenantCreditTables();

    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') $id = genId('tu');
    $status = $allowStatus ? (string)($body['status'] ?? 'enabled') : 'enabled';
    if (!in_array($status, ['enabled', 'disabled', 'deleted'], true)) $status = 'enabled';

    $stmt = db()->prepare("SELECT * FROM tenant_users WHERE username = ?");
    $stmt->execute([$username]);
    $exists = $stmt->fetch();
    $name = cleanInput($body['name'] ?? ($exists['name'] ?? ''));
    $contact = cleanInput($body['contact'] ?? ($exists['contact'] ?? ''));
    $phone = cleanInput($body['phone'] ?? ($exists['phone'] ?? ''));
    $email = cleanInput($body['email'] ?? ($exists['email'] ?? ''));

    if ($exists) {
        $updates = ['name = ?', 'contact = ?', 'phone = ?', 'email = ?', 'updated_at = ?'];
        $params = [$name, $contact, $phone, $email, now()];
        if ($allowStatus) {
            $updates[] = 'status = ?';
            $params[] = $status;
        }
        $newPassword = (string)($body['password'] ?? $body['newPassword'] ?? '');
        $canUpdatePassword = $allowStatus || empty($exists['password']);
        if ($newPassword !== '' && $canUpdatePassword) {
            if (strlen($newPassword) < 6) throw new Exception('用户密码至少 6 位');
            $updates[] = 'password = ?';
            $params[] = tenantPasswordHash($newPassword);
        }
        $params[] = $username;
        db()->prepare("UPDATE tenant_users SET " . implode(', ', $updates) . " WHERE username = ?")->execute($params);
    } else {
        $password = (string)($body['password'] ?? '');
        $passwordHash = $password !== '' ? tenantPasswordHash($password) : null;
        db()->prepare("INSERT INTO tenant_users (id, username, password, name, contact, phone, email, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$id, $username, $passwordHash, $name, $contact, $phone, $email, $status, now(), now()]);
    }

    db()->prepare("INSERT INTO tenant_credits (username, credits, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at")
        ->execute([$username, now()]);
    return getTenantUser($username) ?: ['username' => $username, 'status' => $status, 'credits' => 0];
}
function getTenantUser(string $username): ?array {
    $username = strtolower(trim($username));
    if ($username === '' || !ensureTenantUserTables()) return null;
    ensureTenantCreditTables();
    $stmt = db()->prepare("SELECT tu.id, tu.username, tu.name, tu.contact, tu.phone, tu.email, tu.status, tu.created_at, tu.updated_at, COALESCE(tc.credits, 0) AS credits FROM tenant_users tu LEFT JOIN tenant_credits tc ON tc.username = tu.username WHERE tu.username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt = db()->prepare("SELECT username, credits, updated_at FROM tenant_credits WHERE username = ?");
        $stmt->execute([$username]);
        $credit = $stmt->fetch();
        if (!$credit) return null;
        return [
            'id' => '',
            'username' => $credit['username'],
            'name' => '',
            'contact' => '',
            'phone' => '',
            'email' => '',
            'status' => 'enabled',
            'credits' => (float)$credit['credits'],
            'created_at' => null,
            'updated_at' => $credit['updated_at'] ?? null
        ];
    }
    $row['credits'] = (float)($row['credits'] ?? 0);
    return $row;
}
function listTenantUsers(): array {
    if (!isDbAvailable()) return [];
    ensureTenantUserTables();
    ensureTenantCreditTables();
    $users = [];
    $deleted = [];
    foreach (db()->query("SELECT tu.id, tu.username, tu.name, tu.contact, tu.phone, tu.email, tu.status, tu.created_at, tu.updated_at, COALESCE(tc.credits, 0) AS credits FROM tenant_users tu LEFT JOIN tenant_credits tc ON tc.username = tu.username ORDER BY tu.created_at DESC") as $row) {
        if (($row['status'] ?? '') === 'deleted') {
            $deleted[$row['username']] = true;
            continue;
        }
        $row['credits'] = (float)($row['credits'] ?? 0);
        $users[$row['username']] = $row;
    }
    foreach (db()->query("SELECT username, credits, updated_at FROM tenant_credits ORDER BY updated_at DESC") as $row) {
        $username = (string)$row['username'];
        if (isset($users[$username]) || isset($deleted[$username])) continue;
        $users[$username] = [
            'id' => '',
            'username' => $username,
            'name' => '',
            'contact' => '',
            'phone' => '',
            'email' => '',
            'status' => 'enabled',
            'credits' => (float)($row['credits'] ?? 0),
            'created_at' => null,
            'updated_at' => $row['updated_at'] ?? null
        ];
    }
    return array_values($users);
}
function getTenantCredits(string $username): float {
    $username = trim($username);
    if ($username === '' || !ensureTenantCreditTables()) return 0.0;
    $stmt = db()->prepare("SELECT credits FROM tenant_credits WHERE username = ?");
    $stmt->execute([$username]);
    $credits = $stmt->fetchColumn();
    return $credits === false ? 0.0 : (float)$credits;
}

function setTenantCreditsByAdmin(string $username, float $credits): array {
    $username = trim($username);
    if ($username === '') throw new Exception('缺少用户账号');
    if ($credits < 0) throw new Exception('积分数量不能小于 0');
    if (!ensureTenantCreditTables()) throw new Exception('积分表初始化失败，请联系管理员');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tenant_credits (username, credits, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at")
            ->execute([$username, now()]);
        $stmt = $pdo->prepare("SELECT credits FROM tenant_credits WHERE username = ? FOR UPDATE");
        $stmt->execute([$username]);
        $current = (float)($stmt->fetchColumn() ?: 0);
        $delta = $credits - $current;
        if (abs($delta) >= 0.01) {
            $tradeNo = 'A' . time() . mt_rand(100, 999);
            $pdo->prepare("INSERT INTO tenant_credit_logs (id, username, trade_no, type, amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([genId('cl'), $username, $tradeNo, 'admin_adjust', $delta, '管理员调整积分', now()]);
        }
        $pdo->prepare("UPDATE tenant_credits SET credits = ?, updated_at = ? WHERE username = ?")
            ->execute([$credits, now(), $username]);
        $pdo->commit();
        return ['credits' => $credits, 'delta' => $delta];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function applyTenantRecharge(string $username, string $tradeNo, float $amount): array {
    $username = trim($username);
    if ($username === '') throw new Exception('充值用户不能为空');
    if ($tradeNo === '') throw new Exception('充值订单号不能为空');
    if ($amount <= 0) throw new Exception('充值积分数量必须大于 0');
    if (!ensureTenantCreditTables()) return ['credits' => 0.0, 'applied' => false];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM tenant_credit_logs WHERE trade_no = ?");
        $stmt->execute([$tradeNo]);
        $exists = $stmt->fetch();
        if (!$exists) {
            $pdo->prepare("INSERT INTO tenant_credit_logs (id, username, trade_no, type, amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([genId('cl'), $username, $tradeNo, 'recharge', $amount, '积分充值', now()]);
            $pdo->prepare("INSERT INTO tenant_credits (username, credits, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE credits = credits + VALUES(credits), updated_at = VALUES(updated_at)")
                ->execute([$username, $amount, now()]);
            $applied = true;
        } else {
            $applied = false;
        }
        $stmt = $pdo->prepare("SELECT credits FROM tenant_credits WHERE username = ?");
        $stmt->execute([$username]);
        $credits = (float)($stmt->fetchColumn() ?: 0);
        $pdo->commit();
        return ['credits' => $credits, 'applied' => $applied];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function applyTenantSpend(string $username, string $tradeNo, float $amount, string $remark = '积分消费', ?PDO $pdo = null): array {
    $username = trim($username);
    if ($username === '') throw new Exception('消费用户不能为空');
    if ($tradeNo === '') throw new Exception('消费订单号不能为空');
    if ($amount <= 0) throw new Exception('扣除积分数量必须大于 0');

    $ownTransaction = !$pdo;
    if ($ownTransaction && !ensureTenantCreditTables()) return ['credits' => 0.0, 'applied' => false];
    $pdo = $pdo ?: db();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM tenant_credit_logs WHERE trade_no = ?");
        $stmt->execute([$tradeNo]);
        $exists = $stmt->fetch();
        if (!$exists) {
            $pdo->prepare("INSERT INTO tenant_credits (username, credits, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at")
                ->execute([$username, now()]);
            $stmt = $pdo->prepare("SELECT credits FROM tenant_credits WHERE username = ? FOR UPDATE");
            $stmt->execute([$username]);
            $current = (float)($stmt->fetchColumn() ?: 0);
            if ($current < $amount) {
                throw new Exception('积分不足，请先充值积分');
            }
            $pdo->prepare("INSERT INTO tenant_credit_logs (id, username, trade_no, type, amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([genId('cl'), $username, $tradeNo, 'spend', -$amount, $remark, now()]);
            $pdo->prepare("UPDATE tenant_credits SET credits = credits - ?, updated_at = ? WHERE username = ?")
                ->execute([$amount, now(), $username]);
            $applied = true;
        } else {
            $applied = false;
        }
        $stmt = $pdo->prepare("SELECT credits FROM tenant_credits WHERE username = ?");
        $stmt->execute([$username]);
        $credits = (float)($stmt->fetchColumn() ?: 0);
        if ($ownTransaction) $pdo->commit();
        return ['credits' => $credits, 'applied' => $applied];
    } catch (Throwable $e) {
        if ($ownTransaction) $pdo->rollBack();
        throw $e;
    }
}

function settlePaymentOrder(string $tradeNo): ?array {
    $tradeNo = trim($tradeNo);
    if ($tradeNo === '' || !isDbAvailable()) return null;
    $stmt = db()->prepare("SELECT * FROM orders WHERE trade_no = ?");
    $stmt->execute([$tradeNo]);
    $order = $stmt->fetch();
    if (!$order) {
        $pending = readPendingRecharge($tradeNo);
        if (!$pending) return null;
        $orderId = $pending['id'] ?? genId('o');
        $stmt = db()->prepare("INSERT INTO orders (id, trade_no, subject, amount, status, customer, contact, scenario, pay_url, pay_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $orderId,
            $pending['trade_no'],
            $pending['subject'],
            (float)$pending['amount'],
            'pending',
            $pending['customer'],
            $pending['contact'] ?? '',
            $pending['scenario'] ?? 'credits_recharge',
            $pending['pay_url'] ?? '',
            $pending['pay_type'] ?? 'alipay',
            $pending['created_at'] ?? now()
        ]);
        $stmt = db()->prepare("SELECT * FROM orders WHERE trade_no = ?");
        $stmt->execute([$tradeNo]);
        $order = $stmt->fetch();
        if (!$order) return null;
    }

    $wasPaid = (($order['status'] ?? '') === 'paid');
    if (!$wasPaid) {
        db()->prepare("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ?")->execute([now(), $order['id']]);
        $order['status'] = 'paid';
        $order['paid_at'] = now();
    }

    if (($order['scenario'] ?? '') === 'credits_recharge') {
        $creditResult = applyTenantRecharge((string)$order['customer'], (string)$order['trade_no'], (float)$order['amount']);
        $order['credits'] = $creditResult['credits'];
        $order['applied'] = $creditResult['applied'];
    } elseif (!$wasPaid) {
        provisionOrder($order);
    }

    return $order;
}

function addLog($admin, string $action, string $target = '', string $result = 'success', string $detail = ''): void {
    if (!isDbAvailable()) return;
    try {
        $stmt = db()->prepare("INSERT INTO logs (admin_id, username, action, target, result, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $admin['id'] ?? null,
            $admin['username'] ?? 'system',
            $action,
            $target,
            $result,
            $detail,
            getClientIP(),
            now()
        ]);
    } catch (Throwable $e) {
    }
}

function createAdminSession(array $admin): string {
    if (!isDbAvailable()) {
        return jwtEncode(['aid' => $admin['id'], 'username' => $admin['username'], 'role' => $admin['role']]);
    }
    $token = bin2hex(random_bytes(32));
    try {
        $stmt = db()->prepare("INSERT INTO admin_sessions (admin_id, token, ip, user_agent, created_at, expires_at) VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))");
        $stmt->execute([$admin['id'], $token, getClientIP(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
    } catch (Throwable $e) {
        return jwtEncode(['aid' => $admin['id'], 'username' => $admin['username'], 'role' => $admin['role']]);
    }
    return $token;
}

function validateAdminToken(string $token): ?array {
    if (!$token) return null;
    if (isDbAvailable()) {
        try {
            $stmt = db()->prepare("SELECT s.admin_id, a.username, a.name, a.role, a.status FROM admin_sessions s JOIN admins a ON s.admin_id = a.id WHERE s.token = ? AND s.expires_at > NOW() AND a.status = 'enabled'");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row) {
                return ['id' => $row['admin_id'], 'username' => $row['username'], 'name' => $row['name'], 'role' => $row['role'], 'status' => $row['status']];
            }
        } catch (Throwable $e) {
        }
    }
    $payload = jwtDecode($token);
    if (!$payload) return null;
    return [
        'id' => $payload['aid'] ?? 0,
        'username' => $payload['username'] ?? '',
        'name' => $payload['username'] ?? '',
        'role' => $payload['role'] ?? 'admin',
        'status' => 'enabled'
    ];
}

function destroyAdminSession(string $token): void {
    if (!isDbAvailable()) return;
    try {
        db()->prepare("DELETE FROM admin_sessions WHERE token = ?")->execute([$token]);
    } catch (Throwable $e) {
    }
}

function demoDomains(): array {
    return [[
        'id' => 'd_example1',
        'domain' => 'example.com',
        'group' => '演示',
        'platform' => 'aliyun',
        'status' => 'active',
        'available' => 999,
        'pricePerMonth' => 29,
        'plans' => [['id' => 'standard', 'name' => '月租', 'price' => 29, 'desc' => '按月出租，适合各类业务场景']]
    ]];
}

function normalizePlatformAccount(array $row): array {
    return [
        'id' => $row['id'] ?? '',
        'name' => $row['name'] ?? '',
        'platform' => $row['platform'] ?? 'aliyun',
        'accessKey' => $row['access_key'] ?? ($row['accessKey'] ?? ''),
        'hasSecret' => !empty($row['secret_key'] ?? $row['secretKey'] ?? ''),
        'status' => $row['status'] ?? 'enabled',
        'health' => $row['health'] ?? 'unchecked',
        'lastSyncAt' => $row['last_sync_at'] ?? ($row['lastSyncAt'] ?? null),
        'createdAt' => $row['created_at'] ?? ($row['createdAt'] ?? null),
        'updatedAt' => $row['updated_at'] ?? ($row['updatedAt'] ?? null)
    ];
}

function httpRequest(string $url, array $options = []): array {
    $method = strtoupper($options['method'] ?? 'GET');
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new Exception('网络请求失败：' . $error);
        return ['status' => $status, 'body' => (string)$response];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $response = file_get_contents($url, false, $context);
    $status = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($response === false) throw new Exception('网络请求失败，请检查服务器是否允许外网访问');
    return ['status' => $status, 'body' => (string)$response];
}

function fetchAliyunDomains(string $accessKey, string $secretKey): array {
    $params = [
        'Format' => 'JSON',
        'Version' => '2015-01-09',
        'Action' => 'DescribeDomains',
        'AccessKeyId' => $accessKey,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => bin2hex(random_bytes(8)),
        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'PageSize' => 100
    ];
    ksort($params);
    $canonical = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $stringToSign = 'GET&%2F&' . rawurlencode($canonical);
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey . '&', true));
    $url = 'https://alidns.aliyuncs.com/?' . $canonical . '&Signature=' . rawurlencode($signature);
    $result = httpRequest($url);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('阿里云返回非 JSON，HTTP ' . $result['status']);
    if (isset($json['Code'])) throw new Exception('阿里云错误：' . ($json['Message'] ?? $json['Code']));
    $items = $json['Domains']['Domain'] ?? [];
    $domains = [];
    foreach ($items as $item) {
        if (!empty($item['DomainName'])) $domains[] = strtolower((string)$item['DomainName']);
    }
    return array_values(array_unique($domains));
}

function aliyunSignedRequest(array $params, string $accessKey, string $secretKey): array {
    $params = array_merge([
        'Format' => 'JSON',
        'Version' => '2015-01-09',
        'AccessKeyId' => $accessKey,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => bin2hex(random_bytes(8)),
        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ], $params);
    ksort($params);
    $canonical = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $stringToSign = 'GET&%2F&' . rawurlencode($canonical);
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey . '&', true));
    $url = 'https://alidns.aliyuncs.com/?' . $canonical . '&Signature=' . rawurlencode($signature);
    $result = httpRequest($url);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('阿里云返回非 JSON，HTTP ' . $result['status']);
    if (isset($json['Code'])) throw new Exception('阿里云错误：' . ($json['Message'] ?? $json['Code']));
    return $json;
}

function addAliyunRecord(string $domainName, string $rr, string $type, string $value, int $ttl, string $accessKey, string $secretKey): string {
    [$dnsValue, $mxPriority] = normalizeDnsRecordValue($type, $value);
    $params = [
        'Action' => 'AddDomainRecord',
        'DomainName' => $domainName,
        'RR' => $rr,
        'Type' => strtoupper($type),
        'Value' => $dnsValue,
        'TTL' => max(60, $ttl),
        'Line' => 'default'
    ];
    if ($mxPriority !== null) $params['Priority'] = $mxPriority;
    $json = aliyunSignedRequest($params, $accessKey, $secretKey);
    return (string)($json['RecordId'] ?? '');
}

function normalizeDnsRecordValue(string $type, string $value): array {
    $type = strtoupper(trim($type));
    $value = trim($value);
    $mxPriority = null;
    if ($type === 'MX' && preg_match('/^(\d{1,3})\s+(.+)$/', $value, $m)) {
        $mxPriority = max(1, min(50, (int)$m[1]));
        $value = trim($m[2]);
    }
    return [$value, $mxPriority];
}

function normalizeDnsRecordType(string $type): string {
    $type = strtoupper(trim($type));
    $allowed = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
    if (!in_array($type, $allowed, true)) {
        throw new Exception('不支持的解析类型：' . $type);
    }
    return $type;
}

function tencentCloudSignedRequest(string $secretId, string $secretKey, string $action, array $payload): array {
    $host = 'dnspod.tencentcloudapi.com';
    $service = 'dnspod';
    $version = '2021-03-23';
    $region = 'ap-guangzhou';
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) $payloadJson = '{}';

    $contentType = 'application/json; charset=utf-8';
    $canonicalHeaders = "content-type:$contentType\nhost:$host\nx-tc-action:" . strtolower($action) . "\n";
    $signedHeaders = 'content-type;host;x-tc-action';
    $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', $payloadJson);
    $credentialScope = $date . '/' . $service . '/tc3_request';
    $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
    $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', $service, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
    $authorization = 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $result = httpRequest('https://' . $host . '/', [
        'method' => 'POST',
        'headers' => [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Region: ' . $region,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp
        ],
        'body' => $payloadJson
    ]);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('Tencent DNSPod returned non-JSON, HTTP ' . $result['status'] . ': ' . substr($result['body'], 0, 120));
    $response = $json['Response'] ?? [];
    if (isset($response['Error'])) {
        $err = $response['Error'];
        throw new Exception('Tencent DNSPod error: ' . ($err['Message'] ?? $err['Code'] ?? 'unknown error'));
    }
    return $response;
}

function addTencentCloudRecord(string $domainName, string $rr, string $type, string $value, int $ttl, string $secretId, string $secretKey): string {
    [$dnsValue, $mxPriority] = normalizeDnsRecordValue($type, $value);
    $payload = [
        'Domain' => $domainName,
        'SubDomain' => $rr,
        'RecordType' => strtoupper($type),
        'RecordLine' => '默认',
        'Value' => $dnsValue,
        'TTL' => max(60, $ttl)
    ];
    if ($mxPriority !== null) $payload['MX'] = $mxPriority;
    $response = tencentCloudSignedRequest($secretId, $secretKey, 'CreateRecord', $payload);
    return (string)($response['RecordId'] ?? '');
}

function addDnspodTokenRecord(string $domainName, string $rr, string $type, string $value, int $ttl, string $tokenId, string $token): string {
    [$dnsValue, $mxPriority] = normalizeDnsRecordValue($type, $value);
    $params = [
        'login_token' => $tokenId . ',' . $token,
        'format' => 'json',
        'domain' => $domainName,
        'sub_domain' => $rr,
        'record_type' => strtoupper($type),
        'record_line' => '默认',
        'value' => $dnsValue,
        'ttl' => max(60, $ttl)
    ];
    if ($mxPriority !== null) $params['mx'] = $mxPriority;
    $result = httpRequest('https://dnsapi.cn/Record.Create', [
        'method' => 'POST',
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: DNSDomainSystem/1.0'
        ],
        'body' => http_build_query($params)
    ]);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('Tencent DNSPod returned non-JSON, HTTP ' . $result['status'] . ': ' . substr($result['body'], 0, 120));
    if (!isset($json['status']) || (string)$json['status']['code'] !== '1') {
        throw new Exception('Tencent DNSPod error: ' . ($json['status']['message'] ?? 'unknown error'));
    }
    return (string)($json['record']['id'] ?? '');
}

function addTencentRecord(string $domainName, string $rr, string $type, string $value, int $ttl, string $accessKey, string $secretKey): string {
    $accessKey = trim($accessKey);
    $secretKey = trim($secretKey);
    if (stripos($accessKey, 'AKID') === 0) {
        return addTencentCloudRecord($domainName, $rr, $type, $value, $ttl, $accessKey, $secretKey);
    }
    return addDnspodTokenRecord($domainName, $rr, $type, $value, $ttl, $accessKey, $secretKey);
}

function addPlatformRecord(string $domainId, string $host, string $type, string $value, int $ttl, ?PDO $pdo = null): ?string {
    if (!$pdo && !isDbAvailable()) return null;
    $pdo = $pdo ?: db();
    $domainStmt = $pdo->prepare("SELECT d.domain, d.platform, d.platform_account_id, pa.access_key, pa.secret_key FROM domains d LEFT JOIN platform_accounts pa ON d.platform_account_id = pa.id WHERE d.id = ?");
    $domainStmt->execute([$domainId]);
    $domain = $domainStmt->fetch();
    if (!$domain) throw new Exception('Domain not found, cannot create DNS record');
    if (($domain['platform'] ?? '') === 'aliyun') {
        if (empty($domain['access_key']) || empty($domain['secret_key'])) {
            throw new Exception('Aliyun account keys are missing, cannot create DNS record');
        }
        $platformRecordId = addAliyunRecord(
            (string)$domain['domain'],
            $host,
            $type,
            $value,
            $ttl,
            (string)$domain['access_key'],
            (string)$domain['secret_key']
        );
        if ($platformRecordId === '') throw new Exception('Aliyun did not return DNS record ID');
        return $platformRecordId;
    }
    if (($domain['platform'] ?? '') === 'tencent') {
        if (empty($domain['access_key']) || empty($domain['secret_key'])) {
            throw new Exception('Tencent DNSPod account keys are missing, cannot create DNS record');
        }
        $platformRecordId = addTencentRecord(
            (string)$domain['domain'],
            $host,
            $type,
            $value,
            $ttl,
            (string)$domain['access_key'],
            (string)$domain['secret_key']
        );
        if ($platformRecordId === '') throw new Exception('Tencent DNSPod did not return DNS record ID');
        return $platformRecordId;
    }
    if (!in_array(($domain['platform'] ?? ''), ['', 'local'], true)) {
        throw new Exception('Automatic DNS record creation is not implemented for ' . ($domain['platform'] ?? 'unknown') . ' platform');
    }
    return null;
}

function fetchTencentCloudDomains(string $secretId, string $secretKey): array {
    $secretId = trim($secretId);
    $secretKey = trim($secretKey);
    $host = 'dnspod.tencentcloudapi.com';
    $service = 'dnspod';
    $version = '2021-03-23';
    $action = 'DescribeDomainList';
    $region = 'ap-guangzhou';
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $payload = json_encode(['Offset' => 0, 'Limit' => 3000], JSON_UNESCAPED_SLASHES);
    if ($payload === false) $payload = '{}';

    $contentType = 'application/json; charset=utf-8';
    $canonicalHeaders = "content-type:$contentType\nhost:$host\nx-tc-action:" . strtolower($action) . "\n";
    $signedHeaders = 'content-type;host;x-tc-action';
    $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', $payload);
    $credentialScope = $date . '/' . $service . '/tc3_request';
    $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
    $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', $service, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
    $authorization = 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $result = httpRequest('https://' . $host . '/', [
        'method' => 'POST',
        'headers' => [
            'Authorization: ' . $authorization,
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Region: ' . $region,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp
        ],
        'body' => $payload
    ]);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('Tencent DNSPod returned non-JSON, HTTP ' . $result['status'] . ': ' . substr($result['body'], 0, 120));
    $response = $json['Response'] ?? [];
    if (isset($response['Error'])) {
        $err = $response['Error'];
        throw new Exception('Tencent DNSPod error: ' . ($err['Message'] ?? $err['Code'] ?? 'unknown error'));
    }
    $domains = [];
    foreach (($response['DomainList'] ?? []) as $item) {
        if (!empty($item['Name'])) $domains[] = strtolower((string)$item['Name']);
        if (!empty($item['Domain'])) $domains[] = strtolower((string)$item['Domain']);
    }
    return array_values(array_unique($domains));
}

function fetchDnspodTokenDomains(string $tokenId, string $token): array {
    $body = http_build_query([
        'login_token' => $tokenId . ',' . $token,
        'format' => 'json',
        'offset' => 0,
        'length' => 500
    ]);
    $result = httpRequest('https://dnsapi.cn/Domain.List', [
        'method' => 'POST',
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: DNSDomainSystem/1.0'
        ],
        'body' => $body
    ]);
    $json = json_decode($result['body'], true);
    if (!$json) throw new Exception('Tencent DNSPod returned non-JSON, HTTP ' . $result['status'] . ': ' . substr($result['body'], 0, 120));
    if (!isset($json['status']) || (string)$json['status']['code'] !== '1') {
        throw new Exception('Tencent DNSPod error: ' . ($json['status']['message'] ?? 'unknown error'));
    }
    $domains = [];
    foreach (($json['domains'] ?? []) as $item) {
        if (!empty($item['name'])) $domains[] = strtolower((string)$item['name']);
    }
    return array_values(array_unique($domains));
}

function fetchTencentDomains(string $accessKey, string $secretKey): array {
    $accessKey = trim($accessKey);
    $secretKey = trim($secretKey);
    if (stripos($accessKey, 'AKID') === 0) {
        return fetchTencentCloudDomains($accessKey, $secretKey);
    }
    return fetchDnspodTokenDomains($accessKey, $secretKey);
}

function providerCall(string $accountId, string $action, array $payload = []): array {
    if (!isDbAvailable()) {
        return ['success' => false, 'error' => '数据库不可用，无法同步域名'];
    }
    try {
        $stmt = db()->prepare("SELECT * FROM platform_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) return ['success' => false, 'error' => '平台账号不存在'];
        if (($account['status'] ?? '') !== 'enabled') return ['success' => false, 'error' => '平台账号未启用'];

        if ($action === 'syncDomains') {
            if (empty($account['access_key']) || empty($account['secret_key'])) {
                return ['success' => false, 'error' => 'AccessKey 或 SecretKey 为空'];
            }

            if ($account['platform'] === 'aliyun') {
                $domainNames = fetchAliyunDomains($account['access_key'], $account['secret_key']);
            } elseif ($account['platform'] === 'tencent') {
                $domainNames = fetchTencentDomains($account['access_key'], $account['secret_key']);
            } else {
                return ['success' => false, 'error' => '未知平台：' . $account['platform']];
            }

            if (empty($domainNames)) {
                db()->prepare("UPDATE platform_accounts SET health = 'warning', updated_at = ? WHERE id = ?")
                    ->execute([now(), $accountId]);
                return ['success' => false, 'error' => '云平台没有返回域名，请确认该账号下已有 DNS 域名且密钥具备读取权限'];
            }

            $added = 0;
            $updated = 0;
            foreach ($domainNames as $name) {
                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $name)) continue;
                $stmt = db()->prepare("SELECT id FROM domains WHERE domain = ?");
                $stmt->execute([$name]);
                $existing = $stmt->fetch();
                if ($existing) {
                    db()->prepare("UPDATE domains SET platform_account_id = ?, platform = ?, owner = ?, status = 'active', updated_at = ? WHERE id = ?")
                        ->execute([$accountId, $account['platform'], $account['name'], now(), $existing['id']]);
                    $updated++;
                } else {
                    $domainId = genId('d');
                    $stmt = db()->prepare("INSERT INTO domains (id, domain, platform_account_id, platform, owner, `group`, tags, status, price_per_month, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $domainId,
                        $name,
                        $accountId,
                        $account['platform'],
                        $account['name'],
                        '同步',
                        json_encode(['同步', '新'], JSON_UNESCAPED_UNICODE),
                        'active',
                        29,
                        '',
                        now(),
                        now()
                    ]);
                    $added++;
                }
            }

            db()->prepare("UPDATE platform_accounts SET health = 'normal', last_sync_at = ?, updated_at = ? WHERE id = ?")
                ->execute([now(), now(), $accountId]);
            return ['success' => true, 'totalFetched' => count($domainNames), 'addedCount' => $added, 'updatedCount' => $updated];
        }
        return ['success' => true];
    } catch (Throwable $e) {
        try {
            db()->prepare("UPDATE platform_accounts SET health = 'error', updated_at = ? WHERE id = ?")->execute([now(), $accountId]);
        } catch (Throwable $ignored) {
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function buildCatalog(): array {
    if (!isDbAvailable()) return [];
    try {
        $rows = db()->query("SELECT * FROM domains WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM records WHERE domain_id = ? AND rented_to IS NOT NULL AND rented_to != '' AND status != 'deleted'");
            $stmt->execute([$row['id']]);
            $price = (float)($row['price_per_month'] ?? $row['price_starter'] ?? $row['price_starter_1m'] ?? 29);
            $result[] = [
                'id' => $row['id'],
                'domain' => $row['domain'],
                'group' => $row['group'] ?? '默认分组',
                'platform' => $row['platform'] ?? 'aliyun',
                'status' => $row['status'],
                'available' => max(0, 999 - (int)$stmt->fetchColumn()),
                'pricePerMonth' => $price,
                'plans' => [['id' => 'standard', 'name' => '月租', 'price' => $price, 'desc' => '按月出租，适合各类业务场景']]
            ];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

function isHostAvailable($domainId, $host): array {
    $clean = strtolower(trim((string)$host));
    if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $clean)) {
        return ['ok' => false, 'reason' => '前缀只能包含小写字母、数字和中横线，且不能以中横线开头或结尾'];
    }
    if (in_array($clean, ['www', 'mail', 'api', 'admin', 'root', 'dns', 'ns1', 'ns2'], true)) {
        return ['ok' => false, 'reason' => '该前缀为系统保留前缀'];
    }
    if (isDbAvailable()) {
        try {
            $stmt = db()->prepare("SELECT COUNT(*) FROM records WHERE domain_id = ? AND LOWER(host) = ? AND status != 'deleted'");
            $stmt->execute([$domainId, $clean]);
            if ((int)$stmt->fetchColumn() > 0) return ['ok' => false, 'reason' => '该二级域名已被占用'];
        } catch (Throwable $e) {
        }
    }
    return ['ok' => true, 'reason' => '可租用'];
}

function planPrice($plan, int $months, $domainId = null): float {
    $price = 29.0;
    if ($domainId && isDbAvailable()) {
        try {
            $stmt = db()->prepare("SELECT price_per_month, price_starter, price_starter_1m FROM domains WHERE id = ?");
            $stmt->execute([$domainId]);
            $row = $stmt->fetch();
            if ($row) $price = (float)($row['price_per_month'] ?? $row['price_starter'] ?? $row['price_starter_1m'] ?? 29);
        } catch (Throwable $e) {
        }
    }
    return (float)number_format($price * max(1, $months), 2, '.', '');
}

function provisionOrder(array $order, ?PDO $pdo = null): ?array {
    if (!$pdo && !isDbAvailable()) return null;
        $pdo = $pdo ?: db();
    try {
        $domainId = (string)$order['domain_id'];
        $host = strtolower(trim((string)$order['host']));
        $dnsType = normalizeDnsRecordType((string)($order['record_type'] ?? 'CNAME'));
        $dnsValue = trim((string)($order['record_value'] ?? ''));
        if ($dnsType === '' || $dnsValue === '') throw new Exception('解析类型和记录值不能为空');

        $platformRecordId = addPlatformRecord(
            $domainId,
            $host,
            $dnsType,
            $dnsValue,
            (int)($order['ttl'] ?? 600),
            $pdo
        );
        /*
        $domainStmt = $pdo->prepare("SELECT d.domain, d.platform, d.platform_account_id, pa.access_key, pa.secret_key FROM domains d LEFT JOIN platform_accounts pa ON d.platform_account_id = pa.id WHERE d.id = ?");
        $domainStmt->execute([$order['domain_id']]);
        $domain = $domainStmt->fetch();
        if (!$domain) throw new Exception('主域名不存在，无法创建解析');
        $platformRecordId = null;
        if (($domain['platform'] ?? '') === 'aliyun') {
            if (empty($domain['access_key']) || empty($domain['secret_key'])) {
                throw new Exception('阿里云平台账号密钥未配置，无法下发解析');
            }
            $platformRecordId = addAliyunRecord(
                (string)$domain['domain'],
                (string)$order['host'],
                (string)($order['record_type'] ?? 'A'),
                (string)($order['record_value'] ?? ''),
                (int)($order['ttl'] ?? 600),
                (string)$domain['access_key'],
                (string)$domain['secret_key']
            );
            if ($platformRecordId === '') throw new Exception('阿里云未返回解析记录 ID');
        } elseif (!in_array(($domain['platform'] ?? ''), ['', 'local'], true)) {
            throw new Exception('暂未实现 ' . ($domain['platform'] ?? '未知平台') . ' 的自动解析下发');
        }
        */
        $recordId = genId('r');
        $stmt = $pdo->prepare("INSERT INTO records (id, domain_id, host, type, line, value, ttl, weight, status, rented_to, expires_at, platform_record_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $recordId,
            $domainId,
            $host,
            $dnsType,
            $order['line'] ?? 'default',
            $dnsValue,
            (int)($order['ttl'] ?? 600),
            (int)($order['weight'] ?? 100),
            'enabled',
            $order['customer'] ?? '',
            !empty($order['months']) ? date('Y-m-d', strtotime('+' . max(1, (int)$order['months']) . ' months')) : null,
            $platformRecordId,
            now()
        ]);
        $pdo->prepare("UPDATE orders SET record_id = ?, provisioned_at = ? WHERE id = ?")->execute([$recordId, now(), $order['id']]);
        return ['id' => $recordId];
    } catch (Throwable $e) {
        throw $e;
    }
}

function readBody(): array {
    $input = file_get_contents('php://input');
    if (($input === '' || $input === false) && !empty($_POST)) return $_POST;
    if ($input === '' || $input === false) return [];
    $body = json_decode($input, true);
    if (is_array($body)) return $body;
    parse_str($input, $parsed);
    return is_array($parsed) ? $parsed : [];
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = rtrim((string)($_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/') ?: '/';
$body = readBody();
$user = null;

try {
    if ($uri === '/api/login' && $method === 'POST') {
        $username = cleanInput($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        if ($username === '' || $password === '') sendJson(400, ['error' => '请输入账号和密码']);

        if (!isDbAvailable()) {
            if ($username === 'admin' && $password === 'admin123') {
                $admin = ['id' => 1, 'username' => 'admin', 'name' => '演示管理员', 'role' => 'super_admin'];
                sendJson(200, ['token' => createAdminSession($admin), 'user' => $admin]);
            }
            sendJson(401, ['error' => '账号或密码错误']);
        }

        $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? AND status = 'enabled'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if (!$admin || !verifyMd5Password($password, (string)$admin['salt'], (string)$admin['password'])) {
            addLog(['username' => $username], '登录失败', 'auth', 'failed', '密码错误 IP:' . getClientIP());
            sendJson(401, ['error' => '账号或密码错误']);
        }
        try {
            db()->prepare("UPDATE admins SET login_ip = ?, login_at = NOW(), login_count = login_count + 1 WHERE id = ?")->execute([getClientIP(), $admin['id']]);
        } catch (Throwable $e) {
        }
        $token = createAdminSession($admin);
        addLog($admin, '登录系统', 'auth', 'success', 'IP:' . getClientIP());
        sendJson(200, ['token' => $token, 'user' => ['id' => $admin['id'], 'username' => $admin['username'], 'name' => $admin['name'], 'role' => $admin['role']]]);
    }

    if ($uri === '/api/payment/notify' && in_array($method, ['GET', 'POST'], true)) {
        $notifyBody = array_merge($_GET, $body);
        $tradeNo = (string)($notifyBody['out_trade_no'] ?? '');
        $epay = loadSetting('epay', []);
        if (!empty($epay['key'])) {
            $sign = (string)($notifyBody['sign'] ?? '');
            if ($sign === '' || !hash_equals(md5Sign($notifyBody, (string)$epay['key']), $sign)) {
                echo 'fail';
                exit;
            }
        }
        if (!empty($notifyBody['trade_status']) && $notifyBody['trade_status'] !== 'TRADE_SUCCESS') {
            echo 'fail';
            exit;
        }
        settlePaymentOrder($tradeNo);
        echo 'success';
        exit;
    }

    if ($uri === '/api/payment/return' && $method === 'GET') {
        $tradeNo = (string)($_GET['out_trade_no'] ?? $_GET['trade_no'] ?? '');
        $epay = loadSetting('epay', []);
        $ok = true;
        if (!empty($epay['key'])) {
            $sign = (string)($_GET['sign'] ?? '');
            $ok = $sign !== '' && hash_equals(md5Sign($_GET, (string)$epay['key']), $sign);
        }
        if ($ok && (empty($_GET['trade_status']) || $_GET['trade_status'] === 'TRADE_SUCCESS')) {
            settlePaymentOrder($tradeNo);
        }
        $target = baseUrl() . '/user?recharge=' . ($ok ? 'success' : 'fail') . '&trade_no=' . urlencode($tradeNo);
        header('Location: ' . $target, true, 302);
        exit;
    }

    if ($uri === '/api/public/catalog' && $method === 'GET') {
        sendJson(200, ['domains' => buildCatalog()]);
    }

    if ($uri === '/api/public/site-settings' && $method === 'GET') {
        sendJson(200, [
            'systemName' => loadSetting('systemName', loadSetting('system_name', '二级域名租用平台')),
            'siteLogoUrl' => loadSetting('siteLogoUrl', ''),
            'homeImageUrl' => loadSetting('homeImageUrl', ''),
            'authImageUrl' => loadSetting('authImageUrl', ''),
            'defaultAvatarUrl' => loadSetting('defaultAvatarUrl', ''),
            'userNotices' => array_values(array_filter((array)loadSetting('userNotices', []), function($notice) {
                return is_array($notice) && (($notice['status'] ?? 'active') === 'active');
            }))
        ]);
    }

    if ($uri === '/api/public/check' && $method === 'POST') {
        requireFields($body, ['domainId', 'host']);
        sendJson(200, isHostAvailable($body['domainId'], $body['host']));
    }

    if ($uri === '/api/public/tenant-users' && $method === 'POST') {
        try {
            if (empty($body['password']) || strlen((string)$body['password']) < 6) {
                sendJson(400, ['error' => '密码至少 6 位']);
            }
            if (!ensureTenantUserTables()) sendJson(500, ['error' => '用户表初始化失败，请联系管理员']);
            $username = strtolower(trim((string)($body['username'] ?? '')));
            $stmt = db()->prepare("SELECT username, password FROM tenant_users WHERE username = ? AND status != 'deleted'");
            $stmt->execute([$username]);
            $existingTenant = $stmt->fetch();
            if ($existingTenant && !empty($existingTenant['password'])) {
                sendJson(400, ['error' => '该账号已注册，请直接登录']);
            }
            $tenant = upsertTenantUser($body, false);
            sendJson(201, ['success' => true, 'user' => $tenant]);
        } catch (Throwable $e) {
            sendJson(400, ['error' => $e->getMessage()]);
        }
    }

    if ($uri === '/api/public/tenant-login' && $method === 'POST') {
        $username = strtolower(trim((string)($body['username'] ?? '')));
        $password = (string)($body['password'] ?? '');
        if ($username === '' || $password === '') sendJson(400, ['error' => '请输入账号和密码']);
        if (!ensureTenantUserTables()) sendJson(500, ['error' => '用户表初始化失败，请联系管理员']);
        $stmt = db()->prepare("SELECT * FROM tenant_users WHERE username = ?");
        $stmt->execute([$username]);
        $tenant = $stmt->fetch();
        if (!$tenant) sendJson(404, ['error' => '账号不存在，请先注册']);
        if (in_array((string)($tenant['status'] ?? ''), ['disabled', 'deleted'], true)) {
            sendJson(403, ['error' => '账号已被管理员停用，请联系管理员']);
        }
        $hash = (string)($tenant['password'] ?? '');
        if ($hash === '' || !hash_equals($hash, tenantPasswordHash($password))) {
            sendJson(400, ['error' => '账号或密码错误']);
        }
        sendJson(200, ['success' => true, 'user' => getTenantUser($username)]);
    }

    if ($uri === '/api/public/tenant-password' && $method === 'POST') {
        $username = strtolower(trim((string)($body['username'] ?? '')));
        $currentPassword = (string)($body['currentPassword'] ?? '');
        $newPassword = (string)($body['newPassword'] ?? '');
        if ($username === '' || $currentPassword === '' || $newPassword === '') sendJson(400, ['error' => '请填写完整密码信息']);
        if (strlen($newPassword) < 6) sendJson(400, ['error' => '新密码至少 6 位']);
        if (!ensureTenantUserTables()) sendJson(500, ['error' => '用户表初始化失败，请联系管理员']);
        $stmt = db()->prepare("SELECT * FROM tenant_users WHERE username = ?");
        $stmt->execute([$username]);
        $tenant = $stmt->fetch();
        if (!$tenant) sendJson(404, ['error' => '账号不存在']);
        if (in_array((string)($tenant['status'] ?? ''), ['disabled', 'deleted'], true)) {
            sendJson(403, ['error' => '账号已被管理员停用，请联系管理员']);
        }
        if (empty($tenant['password']) || !hash_equals((string)$tenant['password'], tenantPasswordHash($currentPassword))) {
            sendJson(400, ['error' => '当前密码错误']);
        }
        db()->prepare("UPDATE tenant_users SET password = ?, updated_at = ? WHERE username = ?")
            ->execute([tenantPasswordHash($newPassword), now(), $username]);
        sendJson(200, ['success' => true, 'user' => getTenantUser($username)]);
    }

    if (preg_match('#^/api/public/tenant-users/([^/]+)$#', $uri, $m) && $method === 'GET') {
        $tenant = getTenantUser(urldecode($m[1]));
        if (!$tenant) sendJson(404, ['error' => '用户不存在']);
        sendJson(200, ['user' => $tenant]);
    }

    if (preg_match('#^/api/public/tenant-users/([^/]+)$#', $uri, $m) && $method === 'PATCH') {
        $body['username'] = urldecode($m[1]);
        try {
            $tenant = upsertTenantUser($body, false);
            sendJson(200, ['success' => true, 'user' => $tenant]);
        } catch (Throwable $e) {
            sendJson(400, ['error' => $e->getMessage()]);
        }
    }

    if ($uri === '/api/public/credits' && $method === 'GET') {
        $username = (string)($_GET['username'] ?? '');
        if (trim($username) === '') sendJson(400, ['error' => '缺少用户账号']);
        $tenantInfo = getTenantUser($username);
        sendJson(200, array_merge(['username' => trim($username), 'credits' => getTenantCredits($username)], $tenantInfo ?: []));
    }

    if ($uri === '/api/public/credit-logs' && $method === 'GET') {
        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '') sendJson(400, ['error' => '缺少用户账号']);
        if (!ensureTenantCreditTables()) sendJson(200, ['logs' => []]);
        $stmt = db()->prepare("SELECT trade_no, type, amount, remark, created_at FROM tenant_credit_logs WHERE username = ? ORDER BY created_at DESC LIMIT 30");
        $stmt->execute([$username]);
        sendJson(200, ['logs' => $stmt->fetchAll()]);
    }

    if ($uri === '/api/public/recharge' && $method === 'POST') {
        requireFields($body, ['amount', 'username']);
        $amount = (float)$body['amount'];
        $username = trim((string)$body['username']);
        if ($username === '') sendJson(400, ['error' => '缺少用户账号']);
        if ($amount <= 0) sendJson(400, ['error' => '充值积分数量必须大于 0']);
        $tradeNo = 'C' . time() . mt_rand(100, 999);
        $epay = loadSetting('epay', []);
        $params = [
            'pid' => $epay['pid'] ?? '',
            'type' => $body['payType'] ?? 'alipay',
            'out_trade_no' => $tradeNo,
            'notify_url' => baseUrl() . '/api/payment/notify',
            'return_url' => baseUrl() . '/api/payment/return',
            'name' => '积分充值 ' . number_format($amount, 0, '.', '') . ' 积分',
            'money' => number_format($amount, 2, '.', '')
        ];
        if (!empty($epay['key'])) {
            $params['sign'] = md5Sign($params, (string)$epay['key']);
            $params['sign_type'] = 'MD5';
        }
        $payUrl = ($epay['gateway'] ?? 'https://pay.example.com') . '?' . http_build_query($params);
        $pendingOrder = [
            'id' => genId('o'),
            'trade_no' => $tradeNo,
            'subject' => '积分充值 ' . number_format($amount, 0, '.', '') . ' 积分',
            'amount' => $amount,
            'customer' => $username,
            'contact' => $body['contact'] ?? '',
            'scenario' => 'credits_recharge',
            'pay_url' => $payUrl,
            'pay_type' => $body['payType'] ?? 'alipay',
            'created_at' => now()
        ];
        $orderSaved = savePendingRecharge($pendingOrder);
        sendJson(200, ['tradeNo' => $tradeNo, 'amount' => $amount, 'credits' => (int)$amount, 'payUrl' => $payUrl, 'orderSaved' => $orderSaved]);
    }

    if (preg_match('#^/api/public/recharge/([^/]+)$#', $uri, $m) && $method === 'GET') {
        if (!isDbAvailable()) sendJson(200, ['tradeNo' => $m[1], 'status' => 'pending']);
        $stmt = db()->prepare("SELECT trade_no, amount, status, paid_at, customer FROM orders WHERE trade_no = ? AND scenario = 'credits_recharge'");
        $stmt->execute([$m[1]]);
        $order = $stmt->fetch();
        if (!$order) sendJson(404, ['error' => '充值订单不存在']);
        sendJson(200, [
            'tradeNo' => $order['trade_no'],
            'amount' => (float)$order['amount'],
            'addedCredits' => (float)$order['amount'],
            'credits' => getTenantCredits((string)$order['customer']),
            'status' => $order['status'],
            'paidAt' => $order['paid_at'] ?? null
        ]);
    }

    if ($uri === '/api/public/orders' && $method === 'POST') {
        requireFields($body, ['domainId', 'host', 'plan', 'months', 'recordType', 'recordValue', 'customer', 'contact']);
        $available = isHostAvailable($body['domainId'], $body['host']);
        if (!$available['ok']) sendJson(400, ['error' => $available['reason']]);

        $host = strtolower(trim((string)$body['host']));
        $tradeNo = 'T' . time() . mt_rand(100, 999);
        $amount = planPrice($body['plan'], (int)$body['months'], $body['domainId']);
        $payType = $body['payType'] ?? 'alipay';
        $isBalancePay = in_array($payType, ['balance', 'credits'], true);
        $tenantUsername = trim((string)($body['username'] ?? ''));
        $orderCustomer = $tenantUsername !== '' ? $tenantUsername : (string)$body['customer'];
        if ($isBalancePay && $tenantUsername === '') sendJson(400, ['error' => '缺少用户账号，无法扣除积分']);
        if ($isBalancePay && !isDbAvailable()) sendJson(500, ['error' => '积分数据库不可用，请稍后重试']);
        if ($isBalancePay && !ensureTenantCreditTables()) sendJson(500, ['error' => '积分表初始化失败，请联系管理员']);
        if ($isBalancePay && getTenantCredits($tenantUsername) < $amount) sendJson(400, ['error' => '积分不足，请先充值积分']);
        $status = 'pending';
        $epay = loadSetting('epay', []);
        $payUrl = ($epay['gateway'] ?? 'https://pay.example.com') . '?' . http_build_query([
            'out_trade_no' => $tradeNo,
            'name' => $host . ' 二级域名租用',
            'money' => number_format($amount, 2, '.', '')
        ]);
        if (isDbAvailable()) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM records WHERE domain_id = ? AND LOWER(host) = ? AND status != 'deleted' FOR UPDATE");
                $stmt->execute([$body['domainId'], $host]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('该二级域名已被占用');
                }
                $orderId = genId('o');
                $orderData = [
                    'id' => $orderId,
                    'trade_no' => $tradeNo,
                    'subject' => $host . ' 二级域名租用',
                    'amount' => $amount,
                    'status' => $status,
                    'customer' => $orderCustomer,
                    'contact' => $body['contact'],
                    'scenario' => 'subdomain_rent',
                    'domain_id' => $body['domainId'],
                    'host' => $host,
                    'plan' => $body['plan'],
                    'months' => (int)$body['months'],
                    'record_type' => $body['recordType'],
                    'record_value' => $body['recordValue'],
                    'pay_url' => $isBalancePay ? '' : $payUrl,
                    'pay_type' => $payType,
                    'created_at' => now(),
                    'paid_at' => null
                ];
                $stmt = $pdo->prepare("INSERT INTO orders (id, trade_no, subject, amount, status, customer, contact, scenario, domain_id, host, plan, months, record_type, record_value, pay_url, pay_type, created_at, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $orderId,
                    $tradeNo,
                    $orderData['subject'],
                    $amount,
                    $status,
                    $orderCustomer,
                    $body['contact'],
                    'subdomain_rent',
                    $body['domainId'],
                    $host,
                    $body['plan'],
                    (int)$body['months'],
                    $body['recordType'],
                    $body['recordValue'],
                    $orderData['pay_url'],
                    $payType,
                    $orderData['created_at'],
                    $orderData['paid_at']
                ]);
                if ($isBalancePay) {
                    $creditResult = applyTenantSpend($tenantUsername, $tradeNo, $amount, $host . ' 二级域名租用', $pdo);
                    $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ?")->execute([now(), $orderId]);
                    $orderData['status'] = 'paid';
                    $orderData['paid_at'] = now();
                    $provisioned = provisionOrder($orderData, $pdo);
                    if (!$provisioned) throw new Exception('解析记录创建失败，请稍后重试');

                }
                $pdo->commit();
                addLog(null, '创建订单', $tradeNo);
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                sendJson(400, ['error' => $e->getMessage()]);
            }
        }
        sendJson(200, [
            'tradeNo' => $tradeNo,
            'subject' => $host . ' 二级域名租用',
            'amount' => $amount,
            'payUrl' => $isBalancePay ? '' : $payUrl,
            'status' => $isBalancePay ? 'paid' : $status,
            'credits' => isset($creditResult) ? $creditResult['credits'] : null
        ]);
    }

    $token = getAuthToken();
    if ($token) $user = validateAdminToken($token);

    $publicApis = ['/api/login', '/api/payment/notify', '/api/payment/return', '/api/public/catalog', '/api/public/check', '/api/public/credits', '/api/public/credit-logs', '/api/public/recharge', '/api/public/orders', '/api/public/tenant-users', '/api/public/tenant-login', '/api/public/tenant-password'];
    $isPublicTenantUserApi = preg_match('#^/api/public/tenant-users/[^/]+$#', $uri);
    if (!$user && strpos($uri, '/api/') === 0 && !in_array($uri, $publicApis, true) && !$isPublicTenantUserApi) {
        sendJson(401, ['error' => '未登录或登录已过期']);
    }

    if ($uri === '/api/user' && $method === 'GET') {
        sendJson(200, ['id' => $user['id'], 'username' => $user['username'], 'name' => $user['name'], 'role' => $user['role']]);
    }

    if ($uri === '/api/logout' && $method === 'POST') {
        destroyAdminSession($token);
        sendJson(200, ['success' => true]);
    }

    if (($uri === '/api/init' || $uri === '/api/bootstrap') && $method === 'GET') {
        if (!isDbAvailable()) {
            sendJson(200, [
                'settings' => ['systemName' => 'DNS二级域名分发系统', 'adminLogoText' => 'DNS', 'adminBrandName' => '分发出租', 'defaultAvatarUrl' => ''],
                'users' => [['id' => 1, 'username' => 'admin', 'name' => '演示管理员', 'role' => 'super_admin', 'status' => 'enabled']],
                'platformAccounts' => [],
                'domains' => [],
                'records' => [],
                'policies' => [],
                'monitors' => [],
                'alerts' => [],
                'orders' => [],
                'dashboardStats' => [
                    'revenueToday' => 0,
                    'revenue7Days' => 0,
                    'revenue30Days' => 0,
                    'revenueTotal' => 0
                ],
                'tenantUsers' => [],
                'logs' => []
            ]);
        }
        $pdo = db();
        $data = ['settings' => [], 'platformAccounts' => [], 'domains' => [], 'records' => [], 'domainAccessLogs' => [], 'policies' => [], 'monitors' => [], 'alerts' => [], 'orders' => [], 'dashboardStats' => [], 'logs' => [], 'users' => [], 'tenantUsers' => []];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
            $settingKey = (string)$row['setting_key'];
            $decoded = json_decode($row['setting_value'] ?? '', true);
            if ($settingKey === 'epay' && is_array($decoded)) {
                $decoded['hasKey'] = !empty($decoded['key']);
                unset($decoded['key']);
            }
            $data['settings'][$settingKey] = $decoded === null ? $row['setting_value'] : $decoded;
        }
        foreach ($pdo->query("SELECT id, username, name, role, status, login_ip, login_at, login_count FROM admins") as $row) $data['users'][] = $row;
        foreach ($pdo->query("SELECT * FROM platform_accounts WHERE COALESCE(status, '') != 'deleted' ORDER BY created_at DESC") as $row) $data['platformAccounts'][] = normalizePlatformAccount($row);
        foreach ($pdo->query("SELECT * FROM domains WHERE status != 'deleted' ORDER BY created_at DESC") as $row) {
            $row['pricePerMonth'] = (float)($row['price_per_month'] ?? $row['price_starter'] ?? 29);
            $data['domains'][] = $row;
        }
        foreach ($pdo->query("SELECT * FROM records WHERE status != 'deleted' ORDER BY updated_at DESC") as $row) $data['records'][] = $row;
        try {
            foreach ($pdo->query("SELECT * FROM domain_access_logs ORDER BY visited_at DESC LIMIT 300") as $row) $data['domainAccessLogs'][] = $row;
        } catch (Throwable $e) {
        }
        foreach ($pdo->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 100") as $row) $data['orders'][] = $row;
        $data['dashboardStats'] = dashboardStats($pdo);
        $data['tenantUsers'] = listTenantUsers();
        foreach ($pdo->query("SELECT * FROM logs ORDER BY created_at DESC LIMIT 100") as $row) $data['logs'][] = $row;
        sendJson(200, $data);
    }

    if ($uri === '/api/orders' && $method === 'GET') {
        if (!isDbAvailable()) sendJson(200, []);
        sendJson(200, db()->query("SELECT * FROM orders ORDER BY created_at DESC LIMIT 100")->fetchAll());
    }

    if ($uri === '/api/domain-access-logs' && $method === 'DELETE') {
        if (!isDbAvailable()) sendJson(500, ['error' => '数据库不可用']);
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) sendJson(400, ['error' => '缺少要删除的日志项']);
        $pdo = db();
        $deleted = 0;
        foreach ($items as $item) {
            $host = trim((string)($item['host'] ?? ''));
            $path = trim((string)($item['path'] ?? '/'));
            if ($host === '') continue;
                        $stmt = $pdo->prepare("DELETE FROM domain_access_logs WHERE host = ? AND path = ?");
            $stmt->execute([$host, $path]);
            $deleted += $stmt->rowCount();
        }
        addLog($user, '删除访问日志', "批量删除 {$deleted} 条");
        sendJson(200, ['success' => true, 'deleted' => $deleted]);
    }

    if ($uri === '/api/orders' && $method === 'POST') {
        requireFields($body, ['subject', 'amount', 'customer']);
        if (!isDbAvailable()) sendJson(201, ['id' => genId('o'), 'trade_no' => 'T' . time(), 'status' => 'pending']);
        $id = genId('o');
        $tradeNo = 'T' . time() . mt_rand(100, 999);
        $stmt = db()->prepare("INSERT INTO orders (id, trade_no, subject, amount, status, customer, contact, scenario, pay_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $tradeNo, $body['subject'], (float)$body['amount'], 'pending', $body['customer'], $body['contact'] ?? '', 'manual', $body['payType'] ?? 'manual', now()]);
        sendJson(201, ['id' => $id, 'trade_no' => $tradeNo, 'status' => 'pending']);
    }

    if (preg_match('#^/api/orders/([^/]+)/mark-paid$#', $uri, $m) && $method === 'POST') {
        if (isDbAvailable()) {
            $stmt = db()->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$m[1]]);
            $order = $stmt->fetch();
            if (!$order) sendJson(404, ['error' => '订单不存在']);
            settlePaymentOrder((string)$order['trade_no']);
        }
        sendJson(200, ['success' => true]);
    }

    if ($uri === '/api/domains' && $method === 'GET') {
        sendJson(200, buildCatalog());
    }

    if ($uri === '/api/domains' && $method === 'POST') {
        requireFields($body, ['domain']);
        if (!isDbAvailable()) sendJson(201, ['id' => genId('d'), 'domain' => $body['domain'], 'status' => 'active']);
        $id = genId('d');
        $stmt = db()->prepare("INSERT INTO domains (id, domain, platform_account_id, platform, owner, `group`, tags, status, price_per_month, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $body['domain'], $body['platformAccountId'] ?? null, $body['platform'] ?? 'aliyun', $body['owner'] ?? '', $body['group'] ?? '', json_encode($body['tags'] ?? [], JSON_UNESCAPED_UNICODE), $body['status'] ?? 'active', $body['pricePerMonth'] ?? 29, $body['remark'] ?? '', now(), now()]);
        sendJson(201, ['id' => $id, 'domain' => $body['domain'], 'status' => 'active']);
    }

    if (preg_match('#^/api/domains/([^/]+)$#', $uri, $m) && $method === 'PATCH') {
        if (!isDbAvailable()) sendJson(200, ['success' => true]);
        $updates = [];
        $params = [];
        $map = ['platformAccountId' => 'platform_account_id', 'pricePerMonth' => 'price_per_month'];
        foreach ($body as $key => $value) {
            $field = $map[$key] ?? $key;
            if (in_array($field, ['domain', 'platform_account_id', 'platform', 'owner', 'group', 'status', 'price_per_month', 'remark'], true)) {
                $updates[] = "`$field` = ?";
                $params[] = $value;
            }
        }
        if ($updates) {
            $updates[] = "updated_at = ?";
            $params[] = now();
            $params[] = $m[1];
            db()->prepare("UPDATE domains SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/domains/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
        if (isDbAvailable()) {
            db()->prepare("DELETE FROM domains WHERE id = ?")->execute([$m[1]]);
        }
        sendJson(200, ['success' => true]);
    }

    if ($uri === '/api/platform-accounts' && $method === 'GET') {
        if (!isDbAvailable()) sendJson(200, []);
        $rows = [];
        foreach (db()->query("SELECT * FROM platform_accounts WHERE COALESCE(status, '') != 'deleted' ORDER BY created_at DESC") as $row) $rows[] = normalizePlatformAccount($row);
        sendJson(200, $rows);
    }

    if ($uri === '/api/platform-accounts' && $method === 'POST') {
        requireFields($body, ['name', 'platform', 'accessKey', 'secretKey']);
        if (!isDbAvailable()) sendJson(201, normalizePlatformAccount(['id' => genId('pa'), 'name' => $body['name'], 'platform' => $body['platform'], 'access_key' => $body['accessKey'], 'secret_key' => $body['secretKey'], 'status' => $body['status'] ?? 'enabled']));
        $id = genId('pa');
        $stmt = db()->prepare("INSERT INTO platform_accounts (id, name, platform, access_key, secret_key, status, health, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $body['name'], $body['platform'], $body['accessKey'], $body['secretKey'], $body['status'] ?? 'enabled', 'unchecked', now(), now()]);
        addLog($user, '创建平台账号', (string)$body['name']);
        sendJson(201, normalizePlatformAccount(['id' => $id, 'name' => $body['name'], 'platform' => $body['platform'], 'access_key' => $body['accessKey'], 'secret_key' => $body['secretKey'], 'status' => $body['status'] ?? 'enabled', 'health' => 'unchecked']));
    }

    if (preg_match('#^/api/platform-accounts/([^/]+)$#', $uri, $m) && $method === 'PATCH') {
        if (!isDbAvailable()) sendJson(200, ['success' => true]);
        $stmt = db()->prepare("SELECT * FROM platform_accounts WHERE id = ?");
        $stmt->execute([$m[1]]);
        $account = $stmt->fetch();
        if (!$account) sendJson(404, ['error' => '平台账号不存在']);

        $updates = [];
        $params = [];
        if (isset($body['name'])) { $updates[] = 'name = ?'; $params[] = $body['name']; }
        if (isset($body['platform'])) { $updates[] = 'platform = ?'; $params[] = $body['platform']; }
        if (isset($body['accessKey'])) { $updates[] = 'access_key = ?'; $params[] = $body['accessKey']; }
        if (isset($body['secretKey']) && trim((string)$body['secretKey']) !== '' && $body['secretKey'] !== '******') { $updates[] = 'secret_key = ?'; $params[] = $body['secretKey']; }
        if (isset($body['status'])) { $updates[] = 'status = ?'; $params[] = $body['status']; }
        if ($updates) {
            $updates[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $m[1];
            db()->prepare("UPDATE platform_accounts SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        addLog($user, '更新平台账号', (string)$m[1]);
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/platform-accounts/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
        if (isDbAvailable()) {
            $stmt = db()->prepare("SELECT id, name FROM platform_accounts WHERE id = ?");
            $stmt->execute([$m[1]]);
            $account = $stmt->fetch();
            if (!$account) sendJson(404, ['error' => '平台账号不存在']);
            db()->prepare("DELETE FROM platform_accounts WHERE id = ?")->execute([$m[1]]);
            addLog($user, '删除平台账号', (string)($account['name'] ?? $m[1]));
        }
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/platform-accounts/([^/]+)/sync$#', $uri, $m) && $method === 'POST') {
        $result = providerCall($m[1], 'syncDomains', $body);
        if (!$result['success']) sendJson(400, $result);
        sendJson(200, $result);
    }

    if ($uri === '/api/records' && $method === 'GET') {
        if (!isDbAvailable()) sendJson(200, []);
        sendJson(200, db()->query("SELECT * FROM records WHERE status != 'deleted' ORDER BY updated_at DESC")->fetchAll());
    }

    if ($uri === '/api/records' && $method === 'POST') {
        requireFields($body, ['domainId', 'host', 'type', 'value']);
        if (!isDbAvailable()) sendJson(201, ['id' => genId('r'), 'status' => $body['status'] ?? 'enabled']);
        $pdo = db();
        try {
            $host = strtolower(trim((string)$body['host']));
            $type = normalizeDnsRecordType((string)$body['type']);
            $value = trim((string)$body['value']);
            $ttl = max(60, (int)($body['ttl'] ?? 600));
            if ($host === '' || $type === '' || $value === '') throw new Exception('Host, type and value are required');
            $available = isHostAvailable($body['domainId'], $host);
            if (!$available['ok']) throw new Exception($available['reason']);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM records WHERE domain_id = ? AND LOWER(host) = ? AND status != 'deleted' FOR UPDATE");
            $stmt->execute([$body['domainId'], $host]);
            if ((int)$stmt->fetchColumn() > 0) throw new Exception('DNS record already exists');

            $platformRecordId = addPlatformRecord((string)$body['domainId'], $host, $type, $value, $ttl, $pdo);
            $id = genId('r');
            $stmt = $pdo->prepare("INSERT INTO records (id, domain_id, host, type, line, value, ttl, weight, status, rented_to, expires_at, platform_record_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $body['domainId'], $host, $type, $body['line'] ?? 'default', $value, $ttl, (int)($body['weight'] ?? 100), $body['status'] ?? 'enabled', $body['rentedTo'] ?? '', $body['expiresAt'] ?? null, $platformRecordId, now()]);
            $pdo->commit();
            sendJson(201, ['id' => $id, 'status' => $body['status'] ?? 'enabled', 'platformRecordId' => $platformRecordId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendJson(400, ['error' => $e->getMessage()]);
        }
    }

    if (preg_match('#^/api/records/([^/]+)$#', $uri, $m) && $method === 'PATCH') {
        if (!isDbAvailable()) sendJson(200, ['success' => true]);
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM records WHERE id = ?");
        $stmt->execute([$m[1]]);
        $currentRecord = $stmt->fetch();
        if (!$currentRecord) sendJson(404, ['error' => 'DNS record not found']);

        if (isset($body['type'])) $body['type'] = normalizeDnsRecordType((string)$body['type']);

        $updates = [];
        $params = [];
        $map = ['domainId' => 'domain_id', 'rentedTo' => 'rented_to', 'expiresAt' => 'expires_at'];
        foreach ($body as $key => $value) {
            $field = $map[$key] ?? $key;
            if (in_array($field, ['domain_id', 'host', 'type', 'line', 'value', 'ttl', 'weight', 'status', 'rented_to', 'expires_at'], true)) {
                $updates[] = "`$field` = ?";
                $params[] = $value;
            }
        }
        if ($updates) {
            $updates[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $m[1];
            $pdo->prepare("UPDATE records SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/records/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
        if (isDbAvailable()) {
            db()->prepare("DELETE FROM records WHERE id = ?")->execute([$m[1]]);
        }
        sendJson(200, ['success' => true]);
    }

    if ($uri === '/api/settings' && $method === 'PATCH') {
        $settingsCache = readSettingsCache();
        if (isDbAvailable()) {
            if (isset($body['epay']) && is_array($body['epay'])) {
                $oldEpay = loadSetting('epay', []);
                if ((empty($body['epay']['key']) || $body['epay']['key'] === '******') && !empty($oldEpay['key'])) {
                    $body['epay']['key'] = $oldEpay['key'];
                }
            }
            if (array_key_exists('systemName', $body)) $body['system_name'] = $body['systemName'];
            if (array_key_exists('syncIntervalSeconds', $body)) $body['sync_interval_seconds'] = $body['syncIntervalSeconds'];
            foreach ($body as $key => $value) {
                $encoded = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
                $stmt = db()->prepare("SELECT setting_key FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                if ($stmt->fetch()) {
                    db()->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$encoded, $key]);
                } else {
                    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $encoded]);
                }
            }
        }
        foreach ($body as $key => $value) {
            $settingsCache[$key] = $value;
        }
        writeSettingsCache($settingsCache);
        sendJson(200, ['success' => true]);
    }

    if ($uri === '/api/users' && $method === 'GET') {
        if (!isDbAvailable()) sendJson(200, [['id' => 1, 'username' => 'admin', 'name' => '演示管理员', 'role' => 'super_admin', 'status' => 'enabled']]);
        sendJson(200, db()->query("SELECT id, username, name, role, status, login_ip, login_at, login_count FROM admins")->fetchAll());
    }

    if ($uri === '/api/users' && $method === 'POST') {
        if (!isDbAvailable()) sendJson(500, ['error' => '数据库不可用']);
        $newUsername = trim((string)($body['username'] ?? ''));
        $newPassword = trim((string)($body['password'] ?? ''));
        $newName = trim((string)($body['name'] ?? ''));
        $newRole = in_array(($body['role'] ?? ''), ['super_admin', 'admin'], true) ? $body['role'] : 'admin';
        if ($newUsername === '' || $newPassword === '') sendJson(400, ['error' => '用户名和密码不能为空']);
        if (strlen($newPassword) < 6) sendJson(400, ['error' => '密码至少 6 位']);
        $stmt = db()->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->execute([$newUsername]);
        if ($stmt->fetch()) sendJson(400, ['error' => '用户名已存在']);
        $salt = bin2hex(random_bytes(8));
        $hash = md5($salt . $newPassword);
        db()->prepare("INSERT INTO admins (username, password, salt, name, role, status, login_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'enabled', 0, NOW(), NOW())")
            ->execute([$newUsername, $hash, $salt, $newName ?: $newUsername, $newRole]);
        addLog($user, '创建管理员', $newUsername);
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/users/(\d+)$#', $uri, $m) && $method === 'PATCH') {
        if (!isDbAvailable()) sendJson(500, ['error' => '数据库不可用']);
        $targetId = (int)$m[1];
        $stmt = db()->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) sendJson(404, ['error' => '管理员不存在']);
        $updates = [];
        $params = [];
        if (isset($body['name']) && trim((string)$body['name']) !== '') {
            $updates[] = 'name = ?';
            $params[] = trim((string)$body['name']);
        }
        if (isset($body['role']) && in_array($body['role'], ['super_admin', 'admin'], true)) {
            $updates[] = 'role = ?';
            $params[] = $body['role'];
        }
        if (isset($body['status']) && in_array($body['status'], ['enabled', 'disabled'], true)) {
            $updates[] = 'status = ?';
            $params[] = $body['status'];
        }
        $newPwd = trim((string)($body['password'] ?? $body['newPassword'] ?? ''));
        if ($newPwd !== '') {
            if (strlen($newPwd) < 6) sendJson(400, ['error' => '密码至少 6 位']);
            $salt = bin2hex(random_bytes(8));
            $updates[] = 'password = ?';
            $params[] = md5($salt . $newPwd);
            $updates[] = 'salt = ?';
            $params[] = $salt;
        }
        if ($updates) {
            $updates[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $targetId;
            db()->prepare("UPDATE admins SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        addLog($user, '更新管理员', (string)$target['username']);
        sendJson(200, ['success' => true]);
    }

    if (preg_match('#^/api/users/(\d+)$#', $uri, $m) && $method === 'DELETE') {
        if (!isDbAvailable()) sendJson(500, ['error' => '数据库不可用']);
        $targetId = (int)$m[1];
        $stmt = db()->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) sendJson(404, ['error' => '管理员不存在']);
        if ((int)$target['id'] === (int)($user['id'] ?? 0)) sendJson(400, ['error' => '不能删除自己']);
        db()->prepare("DELETE FROM admins WHERE id = ?")->execute([$targetId]);
        addLog($user, '删除管理员', (string)$target['username']);
        sendJson(200, ['success' => true]);
    }

    if ($uri === '/api/tenant-users' && $method === 'GET') {
        sendJson(200, listTenantUsers());
    }

    if (preg_match('#^/api/tenant-users/([^/]+)$#', $uri, $m) && $method === 'PATCH') {
        $body['username'] = urldecode($m[1]);
        try {
            $tenant = upsertTenantUser($body, true);
            if (array_key_exists('credits', $body)) {
                $creditValue = (float)$body['credits'];
                setTenantCreditsByAdmin((string)$body['username'], $creditValue);
                $tenant = getTenantUser((string)$body['username']) ?: $tenant;
            }
            addLog($user, '更新注册用户', (string)$body['username']);
            sendJson(200, ['success' => true, 'user' => $tenant]);
        } catch (Throwable $e) {
            sendJson(400, ['error' => $e->getMessage()]);
        }
    }

    if (preg_match('#^/api/tenant-users/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
        if (isDbAvailable() && ensureTenantUserTables()) {
            $username = urldecode($m[1]);
            db()->prepare("INSERT INTO tenant_users (id, username, status, created_at, updated_at) VALUES (?, ?, 'deleted', ?, ?) ON DUPLICATE KEY UPDATE status = 'deleted', updated_at = VALUES(updated_at)")
                ->execute([genId('tu'), $username, now(), now()]);
            addLog($user, '删除注册用户', $username);
        }
        sendJson(200, ['success' => true]);
    }

    sendJson(404, ['error' => '接口不存在']);

} catch (Throwable $e) {
    addLog($user, '接口异常', $uri, 'failed', $e->getMessage());
    sendJson(400, ['error' => $e->getMessage()]);
}
