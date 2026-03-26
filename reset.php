<?php
// ============================================================
//  reset_data.php — Vidage complet de la base (HORS utilisateurs)
//  ⚠️  À SUPPRIMER EN PRODUCTION
// ============================================================
session_start();
require_once 'config.php'; // charge getDB(), isLogged(), etc. sans tout index.php

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Accès refusé. Connectez-vous en administrateur.</p>');
}

$done = false;
$errors = [];
$db = getDB();

// Ordre strict : dépendances en premier, tables parentes en dernier
$tables = [
    'activity_log'             => "Logs d'activité",
    'stock_exits'              => 'Sorties de stock',
    'stock_entries'            => 'Entrées de stock',
    'reservations'             => 'Demandes',
    'purchase_order_lines'     => 'Lignes de commande',
    'purchase_orders'          => 'Commandes',
    'printer_cartridges'       => 'Associations imprimante↔cartouche',
    'printer_model_cartridges' => 'Associations modèle↔cartouche',
    'stock'                    => 'Stock',
    'cartridge_models'         => 'Modèles de cartouches',
    'printers'                 => 'Imprimantes',
    'printer_models'           => "Modèles d'imprimantes",
    'services'                 => 'Services',
    'suppliers'                => 'Fournisseurs',
];

// Comptage avant reset
$counts = [];
foreach ($tables as $t => $label) {
    try {
        $counts[$t] = ['label' => $label, 'n' => (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()];
    } catch (Exception $e) {
        $counts[$t] = ['label' => $label, 'n' => '?'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'VIDER') {
    try {
        // Désactiver FK pour éviter tout blocage
        $db->exec("SET FOREIGN_KEY_CHECKS=0");

        foreach (array_keys($tables) as $t) {
            try {
                // DELETE puis RESET AUTO_INCREMENT (plus fiable que TRUNCATE avec FK)
                $db->exec("DELETE FROM `$t`");
                try { $db->exec("ALTER TABLE `$t` AUTO_INCREMENT=1"); } catch(Exception $e2) {}
            } catch (Exception $e) {
                $errors[] = "Erreur $t : " . $e->getMessage();
            }
        }

        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        $done = true;
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        try { $db->exec("SET FOREIGN_KEY_CHECKS=1"); } catch(Exception $e2) {}
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Reset BDD — PrintManager</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.1);padding:2.5rem;max-width:660px;width:100%}
h1{font-size:1.4rem;margin-bottom:.2rem;color:#1a202c}
.sub{color:#718096;font-size:.85rem;margin-bottom:1.75rem}
.warn{background:#fff5f5;border:1px solid #feb2b2;border-radius:8px;padding:1rem 1.2rem;color:#c53030;font-size:.88rem;line-height:1.7;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:1.75rem}
th{text-align:left;padding:.5rem .75rem;background:#f7fafc;color:#4a5568;font-weight:700;border-bottom:2px solid #e2e8f0}
td{padding:.5rem .75rem;border-bottom:1px solid #edf2f7;color:#2d3748}
td:last-child{text-align:right;font-family:monospace;font-weight:700;color:#e53e3e}
td.zero{color:#aaa}
.row{display:flex;gap:.75rem;align-items:center;margin-top:1.25rem}
input[type=text]{flex:1;padding:.65rem 1rem;border:2px solid #e2e8f0;border-radius:8px;font-size:.95rem;transition:border-color .2s}
input[type=text]:focus{outline:none;border-color:#e53e3e}
.btn{background:#e53e3e;color:#fff;border:none;border-radius:8px;padding:.65rem 1.5rem;font-weight:700;font-size:.95rem;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.85}
.cancel{color:#718096;text-decoration:none;font-size:.88rem}
.ok{background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;padding:1.25rem;color:#276749;margin-bottom:1.25rem;line-height:1.7}
.errbox{background:#fff5f5;border:1px solid #feb2b2;border-radius:8px;padding:1rem;color:#c53030;font-size:.82rem;margin-top:.75rem}
.back{display:inline-block;margin-top:1.25rem;padding:.6rem 1.5rem;background:#4361ee;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
code{font-size:.78rem;background:#edf2f7;padding:.1rem .4rem;border-radius:4px}
</style>
</head>
<body>
<div class="card">

<?php if ($done): ?>
  <div class="ok">
    <strong>✅ Base de données réinitialisée.</strong><br>
    Toutes les données ont été supprimées. Les utilisateurs sont conservés.<br>
    <?php if ($errors): ?>
    <span style="color:#c53030">⚠️ <?=count($errors)?> erreur(s) — voir ci-dessous.</span>
    <?php endif ?>
  </div>
  <?php if ($errors): ?>
  <div class="errbox"><?=implode('<br>', array_map('htmlspecialchars', $errors))?></div>
  <?php endif ?>
  <a href="index.php" class="back">← Retour au tableau de bord</a>

<?php else: ?>
  <h1>🗑️ Vider la base de données</h1>
  <p class="sub">PrintManager &mdash; Connecté : <strong><?=htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'])?></strong></p>

  <div class="warn">
    ⚠️ <strong>Cette action est irréversible.</strong><br>
    Toutes les données ci-dessous seront définitivement supprimées.<br>
    Les <strong>comptes utilisateurs</strong> sont préservés.
  </div>

  <table>
    <thead><tr><th>Table</th><th>Contenu</th><th>Lignes</th></tr></thead>
    <tbody>
    <?php foreach ($counts as $t => ['label' => $label, 'n' => $n]): ?>
    <tr>
      <td><code><?=htmlspecialchars($t)?></code></td>
      <td><?=htmlspecialchars($label)?></td>
      <td class="<?=$n===0?'zero':''?>"><?=$n?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>

  <form method="post">
    <label style="font-size:.85rem;color:#4a5568;font-weight:600">
      Tapez <strong>VIDER</strong> pour confirmer la suppression :
    </label>
    <div class="row">
      <input type="text" name="confirm" placeholder="VIDER" autocomplete="off" autofocus>
      <button type="submit" class="btn">Vider la base</button>
      <a href="index.php" class="cancel">Annuler</a>
    </div>
  </form>

  <?php if ($errors): ?>
  <div class="errbox"><?=implode('<br>', array_map('htmlspecialchars', $errors))?></div>
  <?php endif ?>

<?php endif ?>

</div>
</body>
</html>
