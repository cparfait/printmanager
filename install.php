<?php
// ============================================================
//  PrintManager – Installation
//  Script de déploiement (crée la base de données, les tables,
//  les index d'optimisation et le compte administrateur initial)
// ============================================================
require_once __DIR__ . '/config.php';

$errors  = [];
$success = false;

$allTables = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100), email VARCHAR(100),
    role ENUM('admin','user') DEFAULT 'user',
    active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL, direction VARCHAR(100),
    contact_name VARCHAR(100), contact_email VARCHAR(100),
    phone VARCHAR(30), notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL, contact_name VARCHAR(100),
    email VARCHAR(100), phone VARCHAR(30),
    address TEXT, website VARCHAR(200), notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS cartridge_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand VARCHAR(50) NOT NULL, model VARCHAR(100) NOT NULL,
    reference VARCHAR(80), color VARCHAR(40) DEFAULT 'Noir',
    type ENUM('laser','inkjet','toner','ruban') DEFAULT 'laser',
    page_yield INT DEFAULT 0, unit_price DECIMAL(10,2) DEFAULT 0,
    alert_threshold INT DEFAULT 3, notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS printers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT, brand VARCHAR(50) NOT NULL, model VARCHAR(100) NOT NULL,
    serial_number VARCHAR(100), ip_address VARCHAR(45), location VARCHAR(200),
    status ENUM('active','inactive','maintenance') DEFAULT 'active',
    purchase_date DATE, warranty_end DATE, notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS printer_cartridges (
    printer_id INT NOT NULL, cartridge_model_id INT NOT NULL,
    PRIMARY KEY (printer_id, cartridge_model_id),
    FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock (
    cartridge_model_id INT PRIMARY KEY,
    quantity_available INT DEFAULT 0, quantity_reserved INT DEFAULT 0,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartridge_model_id INT NOT NULL, supplier_id INT,
    quantity INT NOT NULL, unit_price DECIMAL(10,2),
    entry_date DATE NOT NULL, invoice_ref VARCHAR(100),
    created_by INT, notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartridge_model_id INT NOT NULL, service_id INT,
    quantity_requested INT NOT NULL, quantity_fulfilled INT DEFAULT 0,
    status ENUM('pending','partial','fulfilled','cancelled') DEFAULT 'pending',
    requested_date DATE NOT NULL, fulfilled_date DATE,
    notes TEXT, created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_exits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cartridge_model_id INT NOT NULL, service_id INT, printer_id INT,
    quantity INT NOT NULL, exit_date DATE NOT NULL,
    person_name VARCHAR(100), reservation_id INT,
    notes TEXT, created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT, action VARCHAR(100),
    entity_type VARCHAR(50), entity_id INT,
    description TEXT, ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT, order_date DATE NOT NULL,
    expected_date DATE, received_date DATE,
    status ENUM('pending','partial','received','cancelled') DEFAULT 'pending',
    notes TEXT, created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL, cartridge_model_id INT NOT NULL,
    quantity_ordered INT NOT NULL DEFAULT 1,
    quantity_received INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id)
) ENGINE=InnoDB;

-- ─── Index d'optimisation (Performances) ───
CREATE INDEX IF NOT EXISTS idx_stock_exits_date ON stock_exits(exit_date);
CREATE INDEX IF NOT EXISTS idx_stock_entries_date ON stock_entries(entry_date);
CREATE INDEX IF NOT EXISTS idx_reservations_status ON reservations(status);
CREATE INDEX IF NOT EXISTS idx_po_status ON purchase_orders(status);
CREATE INDEX IF NOT EXISTS idx_cartridges_active ON cartridge_models(active);
";

function runSQL(PDO $pdo, string $sql): void {
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) $pdo->exec($stmt);
    }
}

// Vérifier si la base de données existe déjà
$dbExists = false;
try { 
    new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS); 
    $dbExists = true; 
} catch(PDOException $e) {}

// Traitement du formulaire d'installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbExists) {
    $admin_user  = trim($_POST['admin_user']  ?? 'admin');
    $admin_pass  = trim($_POST['admin_pass']  ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_name  = trim($_POST['admin_name']  ?? 'Administrateur');
    
    if (strlen($admin_pass) < 6) {
        $errors[] = 'Le mot de passe doit faire au moins 6 caractères.';
    }
    
    if (empty($errors)) {
        try {
            // Connexion sans spécifier la base de données pour pouvoir la créer
            $pdo = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `".DB_NAME."`");
            
            // Création des tables et index
            runSQL($pdo, $allTables);
            
            // Création de l'administrateur
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT IGNORE INTO users(username,password_hash,full_name,email,role)VALUES(?,?,?,?,'admin')")
                ->execute([$admin_user, $hash, $admin_name, $admin_email]);
                
            header('Location: install.php?done=install'); exit;
        } catch (PDOException $e) { 
            $errors[] = 'Erreur SQL : ' . $e->getMessage(); 
        }
    }
}

$done = $_GET['done'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation – PrintManager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#0a0d1a;display:flex;align-items:center;justify-content:center;font-family:'DM Sans',sans-serif;color:#f1f5f9;padding:2rem}
.wrap{width:100%;max-width:560px;display:flex;flex-direction:column;gap:1rem}
.card{background:#111827;border:1px solid #1e3a5f;border-radius:20px;padding:2.5rem;box-shadow:0 25px 60px rgba(0,0,0,.5)}
.logo{text-align:center;margin-bottom:2rem}
.logo .icon{font-size:3rem;display:block;margin-bottom:.5rem;filter:drop-shadow(0 0 12px rgba(67,97,238,.5))}
.logo h1{font-family:'Outfit',sans-serif;font-size:2rem;font-weight:700;color:#4361ee;letter-spacing:-1px}
.logo p{color:#64748b;font-size:.88rem;margin-top:.3rem}
h3{font-family:'Outfit',sans-serif;font-size:.95rem;color:#94a3b8;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid #1e3a5f}
label{display:block;font-size:.78rem;color:#94a3b8;margin-bottom:.35rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em}
input{width:100%;background:#1a2035;border:1px solid #2d3748;border-radius:10px;padding:.75rem 1rem;color:#f1f5f9;font-size:.9rem;margin-bottom:1.1rem;transition:border-color .2s}
input:focus{outline:none;border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.15)}
.btn{width:100%;border:none;border-radius:10px;padding:.9rem;color:#fff;font-size:.95rem;font-weight:600;cursor:pointer;font-family:'Outfit',sans-serif;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,#4361ee,#3a86ff);box-shadow:0 4px 15px rgba(67,97,238,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(67,97,238,.4)}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.25rem;color:#fca5a5;font-size:.88rem}
.success-box{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:16px;padding:2rem;text-align:center}
.success-box .big{font-size:3rem;display:block;margin-bottom:.75rem}
.success-box h2{font-family:'Outfit',sans-serif;font-size:1.4rem;margin-bottom:.5rem;color:#f1f5f9}
.success-box p{color:#94a3b8;font-size:.88rem;line-height:1.6}
.success-box a{display:inline-block;margin-top:1.25rem;background:linear-gradient(135deg,#4361ee,#3a86ff);color:#fff;padding:.75rem 2rem;border-radius:10px;text-decoration:none;font-weight:600;font-family:'Outfit',sans-serif}
.db-badge{display:inline-flex;align-items:center;gap:.4rem;background:#0f1420;border:1px solid #1e3a5f;border-radius:8px;padding:.5rem .85rem;font-size:.8rem;color:#64748b;margin-bottom:1.5rem}
.db-badge strong{color:#94a3b8}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-ok{background:#10b981;box-shadow:0 0 6px rgba(16,185,129,.5)}
.dot-ko{background:#ef4444}
code{font-family:monospace;background:rgba(255,255,255,.08);padding:.1rem .4rem;border-radius:4px;font-size:.82rem}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
  <div class="logo">
    <span class="icon">🖨️</span>
    <h1>PrintManager</h1>
    <p>Installation de l'application</p>
  </div>

  <div style="text-align:center">
    <span class="db-badge">
      <span class="dot <?=$dbExists?'dot-ok':'dot-ko'?>"></span>
      Base <strong><?=DB_NAME?></strong> — <?=DB_HOST?>
      &nbsp;·&nbsp; <?=$dbExists?'<span style="color:#10b981">détectée</span>':'<span style="color:#ef4444">introuvable</span>'?>
    </span>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="error">⚠️ <?=htmlspecialchars($err)?></div>
  <?php endforeach ?>

  <?php if ($done === 'install'): ?>
  <div class="success-box">
    <span class="big">🚀</span>
    <h2>Installation réussie !</h2>
    <p>Base de données et compte administrateur créés avec succès.<br>
    <span style="color:#f59e0b;font-size:.82rem;display:block;margin-top:.5rem">⚠️ Pour des raisons de sécurité, veuillez supprimer le fichier <code>install.php</code> de votre serveur.</span></p>
    <a href="index.php">Accéder à l'application →</a>
  </div>

  <?php else: ?>

    <?php if ($dbExists): ?>
    <div style="text-align:center;padding:1.5rem 0">
      <span style="font-size:2.5rem;display:block;margin-bottom:1rem">✅</span>
      <h3 style="border:none;margin-bottom:.5rem;color:#f1f5f9">Application déjà installée</h3>
      <p style="color:#94a3b8;font-size:.88rem;line-height:1.6">
        La base de données <strong style="color:#f1f5f9"><?=DB_NAME?></strong> est déjà présente et configurée sur votre serveur.
      </p>
      <a href="index.php" style="display:inline-block;margin-top:1.5rem;color:#4361ee;text-decoration:none;font-weight:600">→ Retourner à l'accueil</a>
    </div>

    <?php else: ?>
    <form method="post">
      <h3>🆕 Création de l'administrateur</h3>
      <label>Identifiant admin</label>
      <input type="text" name="admin_user" value="admin" required autocomplete="off">
      <label>Mot de passe (min. 6 caractères)</label>
      <input type="password" name="admin_pass" required autocomplete="new-password">
      <label>Nom complet</label>
      <input type="text" name="admin_name" placeholder="Jean Dupont">
      <label>Email</label>
      <input type="email" name="admin_email" placeholder="admin@collectivite.fr">
      <button type="submit" class="btn btn-primary">🚀 Lancer l'installation</button>
    </form>
    <?php endif ?>

  <?php endif ?>
</div>
</div>
</body>
</html>