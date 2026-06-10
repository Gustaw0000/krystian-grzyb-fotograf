<?php
// admin/lib/auth.php — logowanie sesyjne, CSRF, limit prob, hashowane hasla
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function auth_init(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/admin',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('kg_admin');
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string { return $_SESSION['csrf'] ?? ''; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function csrf_check(): void {
    $given = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || !hash_equals(csrf_token(), $given)) {
        http_response_code(403);
        exit('Nieprawidlowy token CSRF. Odswiez strone i sprobuj ponownie.');
    }
}

function users_file(): string { return data_dir() . '/users.json'; }

function load_users(): array {
    $f = users_file();
    if (!is_file($f)) return [];
    $data = json_decode((string)file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function save_users(array $users): void {
    file_put_contents(users_file(), json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(users_file(), 0600);
}

function has_users(): bool { return count(load_users()) > 0; }

// Pierwsze uruchomienie: zalozenie konta administratora
function create_user(string $username, string $password): void {
    $username = trim($username);
    if (mb_strlen($username) < 3)  throw new RuntimeException('Login musi miec min. 3 znaki.');
    if (mb_strlen($password) < 8)  throw new RuntimeException('Haslo musi miec min. 8 znakow.');
    $users = load_users();
    $users[$username] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'created' => date('c')];
    save_users($users);
}

function is_logged_in(): bool { return !empty($_SESSION['user']); }
function current_user(): ?string { return $_SESSION['user'] ?? null; }

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /admin/?action=login');
        exit;
    }
}

function rate_limit_file(): string { return data_dir() . '/login-attempts.json'; }

function rate_limit_get(string $ip): array {
    $f = rate_limit_file();
    if (!is_file($f)) return ['count' => 0, 'until' => 0];
    $data = json_decode((string)file_get_contents($f), true) ?: [];
    return $data[$ip] ?? ['count' => 0, 'until' => 0];
}

function rate_limit_record(string $ip, bool $success): void {
    $f = rate_limit_file();
    $data = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($success) {
        unset($data[$ip]);
    } else {
        $entry = $data[$ip] ?? ['count' => 0, 'until' => 0];
        $entry['count']++;
        if ($entry['count'] >= 5) { $entry['until'] = time() + 600; $entry['count'] = 0; }
        $data[$ip] = $entry;
    }
    foreach ($data as $k => $v) {
        if (($v['until'] ?? 0) < time() - 3600 && ($v['count'] ?? 0) === 0) unset($data[$k]);
    }
    file_put_contents($f, json_encode($data), LOCK_EX);
}

function client_ip(): string {
    // Do limitu prob logowania uzywamy WYLACZNIE REMOTE_ADDR.
    // X-Forwarded-For jest sterowany przez klienta (latwo podrobic) i nie nadaje sie do decyzji bezpieczenstwa.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limit_remaining(string $ip): int {
    $rl = rate_limit_get($ip);
    return max(0, ($rl['until'] ?? 0) - time());
}

function login_attempt(string $username, string $password): bool {
    $ip = client_ip();
    if (rate_limit_remaining($ip) > 0) return false;
    $users = load_users();
    $user = $users[$username] ?? null;
    $ok = $user && password_verify($password, $user['hash']);
    rate_limit_record($ip, $ok);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
