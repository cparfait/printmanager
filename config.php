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
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/cartouches');

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
            die('<pre style="color:red;padding:2rem">Erreur DB : ' . $e->getMessage() . "\n\nVérifiez config.php et lancez install.php</pre>");
        }
    }
    return $pdo;
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
function h(string $s = ''): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function sanitize(string $s): string { return trim(strip_tags($s)); }
 
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
 