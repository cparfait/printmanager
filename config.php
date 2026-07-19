<?php
// ============================================================
//  PrintManager – Configuration
//  Modifier ces valeurs selon votre environnement
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'cartouches');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_NAME', 'Gestion des Cartouches');
define('APP_VERSION', '1.1.0');
define('APP_URL', 'http://localhost/cartouches');
define('MIN_PASSWORD_LEN', 12); // longueur minimale des mots de passe (recommandation ANSSI)

// Erreurs : jamais affichées à l'écran, toujours dans le log du serveur
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Connexion PDO (singleton)
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('PrintManager DB : ' . $e->getMessage());
            die('<pre style="color:red;padding:2rem">Erreur de connexion à la base de données.' . "\n\nVérifiez config.php et lancez install.php</pre>");
        }
    }
    return $pdo;
}

// Session sécurisée (HttpOnly + SameSite=Lax, Secure si HTTPS)
function secureSessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Protection CSRF
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}
function csrfCheck($token): bool {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Flash messages via session
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function getFlashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// Sécurité
function h(?string $s = ''): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function sanitize(string $s): string { return trim(strip_tags($s)); }
// Neutralise l'injection de formule dans les exports CSV (=, +, -, @ en début de cellule)
function csvSafe($v): string {
    $v = (string)$v;
    return ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t"], true)) ? "'" . $v : $v;
}

// Auth helpers
function isLogged(): bool { return !empty($_SESSION['user']); }
function isAdmin(): bool  { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function requireLogin(): void {
    if (!isLogged()) { header('Location: index.php?page=login'); exit; }
}
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) { header('Location: index.php?page=dashboard'); exit; }
}
