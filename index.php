<?php
// ============================================================
//  PrintManager v1.1 – Application principale
// ============================================================
ob_start(); // capturer tout output parasite avant les headers
require_once 'config.php'; // gère aussi display_errors/log_errors
secureSessionStart();

$page      = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'dashboard');
$id        = (int)($_GET['id'] ?? 0);
$autoOpen  = preg_replace('/[^a-z_\-]/', '', $_GET['open'] ?? '');


// ─── MODULES ────────────────────────────────────────────────
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/snmp.php';
require_once __DIR__ . '/inc/actions.php';
foreach (glob(__DIR__ . '/inc/pages/*.php') as $pmPageFile) require_once $pmPageFile;
unset($pmPageFile);

// ─── LOGIN ──────────────────────────────────────────────────
if ($page === 'login') {
    if (isLogged()) { header('Location: index.php'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfCheck($_POST['csrf'] ?? '')) {
            flash('error', 'Session expirée, veuillez réessayer.');
            header('Location: index.php?page=login'); exit;
        }
        try {
            $db = getDB();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            // Anti brute-force : 5 échecs max par IP sur 15 minutes
            $st = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE action='login_failed' AND ip_address=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $st->execute([$ip]);
            if ((int)$st->fetchColumn() >= 5) {
                flash('error', 'Trop de tentatives échouées. Réessayez dans 15 minutes.');
                header('Location: index.php?page=login'); exit;
            }
            $username = sanitize($_POST['username'] ?? '');
            $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1");
            $st->execute([$username]);
            $u = $st->fetch();
            if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
                session_regenerate_id(true);
                // Ne conserver en session que le nécessaire (jamais le hash du mot de passe)
                $_SESSION['user'] = ['id'=>(int)$u['id'],'username'=>$u['username'],'full_name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role'],'active'=>(int)$u['active']];
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
                logAct($db,(int)$u['id'],'login','user',(int)$u['id'],'Connexion');
                header('Location: index.php'); exit;
            }
            $db->prepare("INSERT INTO activity_log(user_id,action,entity_type,entity_id,description,ip_address)VALUES(NULL,'login_failed','user',0,?,?)")
               ->execute(['Échec de connexion : '.$username, $ip]);
            flash('error', "Identifiants invalides.");
        } catch (Exception $e) {
            error_log('PrintManager login : ' . $e->getMessage());
            flash('error', "Erreur interne. Consultez les logs du serveur.");
        }
        header('Location: index.php?page=login'); exit;
    }
    renderLogin(); exit;
}

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php?page=login'); exit;
}

requireLogin();

// ─── AJAX (exécutés avant tout rendu) ───────────────────────
require __DIR__ . '/inc/ajax.php';

try { $db = getDB(); } catch (Exception $e) {
    die('<div style="color:#ef4444;padding:3rem;font-family:sans-serif">Erreur DB : ' . $e->getMessage() . '<br><a href="install.php">Lancer install.php</a></div>');
}
// Migrations automatiques (bases créées avec un ancien install.php) —
// idempotentes, exécutées une seule fois par session pour ne pas pénaliser chaque requête
if (empty($_SESSION['pm_schema_ok'])) {
    foreach ([
        "ALTER TABLE cartridge_models ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE cartridge_models ADD COLUMN barcode VARCHAR(255) NULL DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS printer_models (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand VARCHAR(100) NOT NULL,
            model VARCHAR(100) NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS printer_model_cartridges (
            printer_model_id INT NOT NULL,
            cartridge_model_id INT NOT NULL,
            PRIMARY KEY (printer_model_id, cartridge_model_id),
            FOREIGN KEY (printer_model_id) REFERENCES printer_models(id) ON DELETE CASCADE,
            FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id) ON DELETE CASCADE
        )",
        "ALTER TABLE printers ADD COLUMN printer_model_id INT NULL",
        "ALTER TABLE reservations ADD COLUMN printer_id INT NULL",
        "ALTER TABLE printers ADD CONSTRAINT fk_printers_printer_model FOREIGN KEY (printer_model_id) REFERENCES printer_models(id) ON DELETE SET NULL",
        "ALTER TABLE reservations ADD CONSTRAINT fk_reservations_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL",
    ] as $mig) { try { $db->query($mig); } catch(Exception $e) {} }
    $_SESSION['pm_schema_ok'] = 1;
}
// Recharger rôle et statut depuis la base : droits à jour immédiatement,
// comptes désactivés déconnectés sans attendre l'expiration de session
try {
    $stU = $db->prepare("SELECT id, username, full_name, email, role, active FROM users WHERE id=?");
    $stU->execute([(int)($_SESSION['user']['id'] ?? 0)]);
    $freshU = $stU->fetch();
    if (!$freshU || !(int)$freshU['active']) {
        $_SESSION = []; session_destroy();
        header('Location: index.php?page=login'); exit;
    }
    $freshU['id'] = (int)$freshU['id'];
    $_SESSION['user'] = $freshU;
} catch (Exception $e) {}
$user = $_SESSION['user'];

// ─── POST HANDLERS ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redir = 'index.php?page=' . $page;
    if (!csrfCheck($_POST['csrf'] ?? '')) {
        flash('error', '⛔ Jeton de sécurité invalide ou session expirée. Veuillez réessayer.');
        header('Location: ' . $redir); exit;
    }
    $ent = sanitize($_POST['_entity'] ?? '');
    $act = sanitize($_POST['_action'] ?? '');
    $pid = (int)($_POST['_id'] ?? 0);
    // Transaction globale : une action métier est appliquée entièrement ou pas du tout
    try {
        $db->beginTransaction();
        doPost($db, $ent, $act, $_POST, $pid);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('PrintManager POST (' . $ent . '/' . $act . ') : ' . $e->getMessage());
        flash('error', 'Erreur : ' . $e->getMessage());
    }
    header('Location: ' . $redir); exit;
}

// ─── MAIN LAYOUT ────────────────────────────────────────────
$pageTitle=['dashboard'=>'Tableau de bord','printers'=>'Imprimantes','printer_view'=>'Fiche imprimante','ink_levels'=>'Niveaux d\'encre','cartridges'=>'Cartouches',
    'stock_in'=>'Entrées de stock','stock_out'=>'Sorties de stock','reservations'=>'Demandes',
    'orders'=>'Commandes','order_view'=>'Détail commande',
    'services'=>'Services','suppliers'=>'Fournisseurs','stats'=>'Statistiques','admin'=>'Administration','cartridge_history'=>'Historique cartouche','export_exits'=>'Export sorties'];

// ─── DATA FETCHING for dashboard ────────────────────────────
// Ces deux compteurs sont toujours charges : ils alimentent les badges de la nav sidebar
$dashData = ['pending_demands' => 0, 'pending_orders' => 0];
try {
    $dashData['pending_demands'] = $db->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','partial')")->fetchColumn();
    $dashData['pending_orders']       = $db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','partial')")->fetchColumn();
} catch(Exception $e){}

// Le reste n'est necessaire que pour le dashboard et les stats
if ($page === 'dashboard' || $page === 'stats') {
    $dashData['printers_total']   = $db->query("SELECT COUNT(*) FROM printers")->fetchColumn();
    $dashData['printers_active']  = $db->query("SELECT COUNT(*) FROM printers WHERE status='active'")->fetchColumn();
    $dashData['cartridge_models'] = $db->query("SELECT COUNT(*) FROM cartridge_models")->fetchColumn();
    $dashData['stock_total']      = $db->query("SELECT COALESCE(SUM(quantity_available),0) FROM stock")->fetchColumn();
    $dashData['low_stock']        = $db->query("SELECT COUNT(*) FROM stock s JOIN cartridge_models cm ON s.cartridge_model_id=cm.id WHERE s.quantity_available <= cm.alert_threshold")->fetchColumn();
    $dashData['exits_month']      = $db->query("SELECT COALESCE(SUM(quantity),0) FROM stock_exits WHERE MONTH(exit_date)=MONTH(NOW()) AND YEAR(exit_date)=YEAR(NOW())")->fetchColumn();
    $dashData['services_count']   = $db->query("SELECT COUNT(*) FROM services")->fetchColumn();
}

// Output buffer for page content
ob_start();
switch($page) {
    case 'dashboard':    pageDashboard($db,$dashData); break;
    case 'services':     pageServices($db); break;
    case 'printers':     pagePrinters($db); break;
    case 'printer_view': pagePrinterView($db,$id); break;
    case 'ink_levels':   pageInkLevels($db); break;
    case 'cartridges':   pageCartridges($db); break;
    case 'suppliers':    pageSuppliers($db); break;
    case 'stock_in':     pageStockIn($db); break;
    case 'stock_out':    pageStockOut($db); break;
    case 'reservations': pageReservations($db); break;
    case 'orders':       pageOrders($db); break;
    case 'order_view':   pageOrderView($db,$id); break;
    case 'stats':        pageStats($db,$dashData); break;
    case 'admin':        pageAdmin($db); break;
    case 'cartridge_history': pageCartridgeHistory($db,$id); break;
    case 'export_exits':  pageExportExits($db); break;
    default:             echo '<div class="empty-state"><h2>Page introuvable</h2></div>';
}
$content = ob_get_clean();

// ─── RENDU ──────────────────────────────────────────────────
require __DIR__ . '/inc/layout.php';
