<?php
// ============================================================
//  seed_data.php — Données de test riches pour PrintManager
//  ⚠️  À SUPPRIMER EN PRODUCTION
// ============================================================
session_start();
require_once 'config.php';

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Accès refusé.</p>');
}

if (!isset($_GET['confirm'])) { ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Seed data</title>
<style>
body{font-family:'Segoe UI',sans-serif;max-width:640px;margin:4rem auto;padding:2rem;background:#f0f2f7}
.card{background:#fff;border-radius:14px;padding:2rem;box-shadow:0 4px 20px rgba(0,0,0,.1)}
h2{margin-bottom:.4rem}p{color:#718096;margin-bottom:1.2rem}
ul{color:#4a5568;font-size:.9rem;line-height:2;margin-bottom:1.5rem;padding-left:1.5rem}
.warn{background:#fff5f5;border:1px solid #feb2b2;border-radius:8px;padding:.8rem 1.1rem;color:#c53030;font-size:.85rem;margin-bottom:1.5rem}
.row{display:flex;gap:.75rem;align-items:center}
.btn{padding:.7rem 1.75rem;background:#e53e3e;color:#fff;border-radius:8px;text-decoration:none;font-weight:700}
.cancel{color:#718096;text-decoration:none;font-size:.88rem}
</style></head><body><div class="card">
<h2>🌱 Données de test riches</h2>
<p>Connecté : <strong><?=htmlspecialchars($_SESSION['user']['full_name']??$_SESSION['user']['username'])?></strong></p>
<div class="warn">⚠️ <strong>Vide toutes les tables</strong> (sauf utilisateurs) avant d'insérer.</div>
<ul>
  <li>8 services avec contact</li>
  <li>5 fournisseurs</li>
  <li>5 modèles d'imprimantes</li>
  <li>35 modèles de cartouches (HP, Canon, Epson, Brother, Xerox)</li>
  <li>20 imprimantes réparties dans les services</li>
  <li>Stocks variés (alertes, ruptures, normaux)</li>
  <li>25 entrées de stock sur 6 mois</li>
  <li>40 sorties de stock</li>
  <li>8 commandes fournisseurs</li>
  <li>12 demandes de cartouches</li>
</ul>
<div class="row">
  <a href="?confirm=1" class="btn">🚨 Confirmer et injecter</a>
  <a href="index.php" class="cancel">Annuler</a>
</div>
</div></body></html>
<?php exit; }

// ── Init ──────────────────────────────────────────────────────────────────────
$db = getDB();
$log = []; $ok = 0; $err = 0;
$adminId = (int)$_SESSION['user']['id'];

function ins(PDO $db, string $sql, array $p = []): int {
    global $ok, $err, $log;
    try { $db->prepare($sql)->execute($p); $ok++; return (int)$db->lastInsertId(); }
    catch (Exception $e) { $err++; $log[] = '❌ '.$e->getMessage().' | '.substr($sql,0,60); return 0; }
}

// ── Vider les tables ──────────────────────────────────────────────────────────
$db->exec("SET FOREIGN_KEY_CHECKS=0");
foreach ([
    'activity_log','stock_exits','stock_entries','purchase_order_lines',
    'purchase_orders','reservations','printer_cartridges','printer_model_cartridges',
    'stock','cartridge_models','printers','printer_models','suppliers','services'
] as $t) {
    try { $db->exec("DELETE FROM `$t`"); $db->exec("ALTER TABLE `$t` AUTO_INCREMENT=1"); }
    catch(Exception $e) {}
}
$db->exec("SET FOREIGN_KEY_CHECKS=1");
$log[] = '🗑️ Tables vidées';

// ── 1. Services ───────────────────────────────────────────────────────────────
$svc = [];
foreach ([
    ['DSI',            'Direction des Systèmes d\'Information', 'Marc FONTAINE',  'marc.fontaine@ville.fr',   '04 91 11 22 33'],
    ['RH',             'Pôle Ressources Humaines',              'Claire DURAND',  'c.durand@ville.fr',        '04 91 11 22 44'],
    ['Direction',      'Direction Générale',                    'Alain ROUX',     'direction@ville.fr',        '04 91 11 22 01'],
    ['Comptabilité',   'Pôle Finances',                        'Nathalie BRUN',  'n.brun@ville.fr',          '04 91 11 22 55'],
    ['Cuisine',        'Service de Restauration',              'Pierre LEROY',   'cuisine@ville.fr',         '04 91 11 22 66'],
    ['Communication',  'Service Communication & Presse',       'Julie MARTIN',   'j.martin@ville.fr',        '04 91 11 22 77'],
    ['Technique',      'Direction des Services Techniques',    'Robert MOREAU',  'technique@ville.fr',        '04 91 11 22 88'],
    ['Accueil',        'Service Accueil & Relation Citoyens',  'Sophie LEBLANC', 's.leblanc@ville.fr',        '04 91 11 22 99'],
] as [$name,$dir,$contact,$email,$phone]) {
    $svc[$name] = ins($db,
        "INSERT INTO services(name,direction,contact_name,contact_email,phone) VALUES(?,?,?,?,?)",
        [$name,$dir,$contact,$email,$phone]
    );
}
$log[] = '✅ '.count($svc).' services';

// ── 2. Fournisseurs ───────────────────────────────────────────────────────────
$sup = [];
foreach ([
    ['TonerPlus',       'commandes@tonerplus.fr',       '01 23 45 67 89', 'www.tonerplus.fr'],
    ['Bureau Vallee',   'pro@bureauvallee.fr',           '01 98 76 54 32', 'pro.bureauvallee.fr'],
    ['Office Depot',    'commandes@officedepot.fr',     '01 11 22 33 44', 'www.officedepot.fr'],
    ['Manutan',         'collectivites@manutan.fr',     '01 55 66 77 88', 'www.manutan.fr'],
    ['Lyreco',          'service.public@lyreco.com',    '01 44 55 66 77', 'www.lyreco.com'],
] as [$name,$email,$phone,$web]) {
    $sup[$name] = ins($db,
        "INSERT INTO suppliers(name,email,phone,website) VALUES(?,?,?,?)",
        [$name,$email,$phone,$web]
    );
}
$log[] = '✅ '.count($sup).' fournisseurs';

// ── 3. Modèles d'imprimantes ──────────────────────────────────────────────────
$pm = [];
foreach ([
    ['HP',     'LaserJet Pro M404dn',  'Laser mono A4, 38ppm, recto-verso, reseau'],
    ['HP',     'Color LaserJet M454dw','Laser couleur A4, 28ppm, Wifi, recto-verso'],
    ['Canon',  'PIXMA G3420',          'Jet d\'encre couleur A4, reservoir rechargeable'],
    ['Brother','HL-L2350DW',           'Laser mono A4, 30ppm, Wifi, recto-verso auto'],
    ['Xerox',  'VersaLink C405',       'Laser couleur A4, 35ppm, reseau, recto-verso'],
] as [$brand,$model,$notes]) {
    $pm["$brand $model"] = ins($db,
        "INSERT INTO printer_models(brand,model,notes) VALUES(?,?,?)",[$brand,$model,$notes]
    );
}
$log[] = '✅ '.count($pm).' modèles d\'imprimantes';

// ── 4. Cartouches ─────────────────────────────────────────────────────────────
// [brand, reference, model, color, type, page_yield, unit_price, alert_threshold]
$crt = [];
$cartridgeDefs = [
    // HP LaserJet Pro M404dn
    ['HP','CF259A',   'HP 59A',     'Noir',   'laser',  3000, 14.90, 2],
    ['HP','CF259X',   'HP 59X',     'Noir',   'laser',  10000,34.90, 1],
    // HP Color LaserJet M454dw
    ['HP','W2010A',   'HP 210A',    'Noir',   'laser',  2400, 22.50, 2],
    ['HP','W2011A',   'HP 210A',    'Cyan',   'laser',  2000, 28.90, 1],
    ['HP','W2012A',   'HP 210A',    'Jaune',  'laser',  2000, 28.90, 1],
    ['HP','W2013A',   'HP 210A',    'Magenta','laser',  2000, 28.90, 1],
    ['HP','W2010X',   'HP 210X',    'Noir',   'laser',  7500, 42.00, 2],
    ['HP','W2011X',   'HP 210X',    'Cyan',   'laser',  5500, 48.00, 1],
    ['HP','W2012X',   'HP 210X',    'Jaune',  'laser',  5500, 48.00, 1],
    ['HP','W2013X',   'HP 210X',    'Magenta','laser',  5500, 48.00, 1],
    // HP génériques
    ['HP','CF294A',   'HP 94A',     'Noir',   'laser',  1200, 12.00, 3],
    ['HP','CF294X',   'HP 94X',     'Noir',   'laser',  2800, 22.00, 2],
    // Canon PIXMA G3420
    ['Canon','GI-490BK', 'GI-490', 'Noir',   'inkjet', 7000,  8.50, 3],
    ['Canon','GI-490C',  'GI-490', 'Cyan',   'inkjet', 7000,  8.50, 3],
    ['Canon','GI-490M',  'GI-490', 'Magenta','inkjet', 7000,  8.50, 3],
    ['Canon','GI-490Y',  'GI-490', 'Jaune',  'inkjet', 7000,  8.50, 3],
    // Brother HL-L2350DW
    ['Brother','TN-2420', 'TN-2420','Noir',  'laser',  3000, 18.90, 2],
    ['Brother','TN-2410', 'TN-2410','Noir',  'laser',  1200, 12.90, 3],
    ['Brother','DR-2400', 'DR-2400','Noir',  'laser',  12000,24.90, 1],
    // Xerox VersaLink C405
    ['Xerox','106R03520','106R03520','Noir',  'laser',  10500,55.00, 1],
    ['Xerox','106R03517','106R03517','Cyan',  'laser',  8000, 65.00, 1],
    ['Xerox','106R03518','106R03518','Magenta','laser', 8000, 65.00, 1],
    ['Xerox','106R03519','106R03519','Jaune', 'laser',  8000, 65.00, 1],
    ['Xerox','106R03480','106R03480','Noir',  'laser',  5100, 45.00, 2],
    ['Xerox','106R03477','106R03477','Cyan',  'laser',  4800, 55.00, 1],
    // Compatibles génériques
    ['COMPATIBLE','COMP-59A',  'COMP 59A',  'Noir',   'laser', 2800, 6.90,  4],
    ['COMPATIBLE','COMP-210BK','COMP 210BK','Noir',   'laser', 2200, 9.90,  3],
    ['COMPATIBLE','COMP-210C', 'COMP 210C', 'Cyan',   'laser', 1800, 12.90, 2],
    ['COMPATIBLE','COMP-210M', 'COMP 210M', 'Magenta','laser', 1800, 12.90, 2],
    ['COMPATIBLE','COMP-210Y', 'COMP 210Y', 'Jaune',  'laser', 1800, 12.90, 2],
    ['COMPATIBLE','COMP-2420', 'COMP TN2420','Noir',  'laser', 2800, 8.90,  3],
    // Epson (imprimante bureau)
    ['Epson','T7551',  'T7551',  'Noir',   'inkjet', 4000, 22.00, 2],
    ['Epson','T7552',  'T7552',  'Cyan',   'inkjet', 3500, 18.00, 1],
    ['Epson','T7553',  'T7553',  'Magenta','inkjet', 3500, 18.00, 1],
    ['Epson','T7554',  'T7554',  'Jaune',  'inkjet', 3500, 18.00, 1],
];
foreach ($cartridgeDefs as [$brand,$ref,$model,$color,$type,$yield,$price,$threshold]) {
    $id = ins($db,
        "INSERT INTO cartridge_models(brand,model,reference,color,type,page_yield,unit_price,alert_threshold,active) VALUES(?,?,?,?,?,?,?,?,1)",
        [$brand,$model,$ref,$color,$type,$yield,$price,$threshold]
    );
    $crt["$brand|$ref"] = $id;
}
$log[] = '✅ '.count($crt).' cartouches';

// ── 5. Associations modèle → cartouches ──────────────────────────────────────
$assoc = [
    'HP LaserJet Pro M404dn'   => ['HP|CF259A','HP|CF259X','HP|CF294A','HP|CF294X','COMPATIBLE|COMP-59A'],
    'HP Color LaserJet M454dw' => ['HP|W2010A','HP|W2011A','HP|W2012A','HP|W2013A','HP|W2010X','HP|W2011X','HP|W2012X','HP|W2013X','COMPATIBLE|COMP-210BK','COMPATIBLE|COMP-210C','COMPATIBLE|COMP-210M','COMPATIBLE|COMP-210Y'],
    'Canon PIXMA G3420'        => ['Canon|GI-490BK','Canon|GI-490C','Canon|GI-490M','Canon|GI-490Y'],
    'Brother HL-L2350DW'       => ['Brother|TN-2420','Brother|TN-2410','Brother|DR-2400','COMPATIBLE|COMP-2420'],
    'Xerox VersaLink C405'     => ['Xerox|106R03520','Xerox|106R03517','Xerox|106R03518','Xerox|106R03519','Xerox|106R03480','Xerox|106R03477'],
];
foreach ($assoc as $pmKey => $crtKeys) {
    $pmid = $pm[$pmKey] ?? 0;
    if (!$pmid) continue;
    foreach ($crtKeys as $ck) {
        $cid = $crt[$ck] ?? 0;
        if ($cid) ins($db,"INSERT IGNORE INTO printer_model_cartridges(printer_model_id,cartridge_model_id) VALUES(?,?)",[$pmid,$cid]);
    }
}
$log[] = '✅ Associations modèle→cartouches';

// ── 6. Imprimantes ────────────────────────────────────────────────────────────
// [brand, model, serial, ip, location, status, service_key, model_key]
$prtDefs = [
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-001','192.168.1.10','Bat. A - Bureau DSI 101',    'active','DSI',          'HP LaserJet Pro M404dn'],
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-002','192.168.1.11','Bat. A - Salle serveurs',    'active','DSI',          'HP LaserJet Pro M404dn'],
    ['HP','Color LaserJet M454dw','SN-HP-M454-001','192.168.1.20','Bat. B - Direction 201',    'active','Direction',    'HP Color LaserJet M454dw'],
    ['HP','Color LaserJet M454dw','SN-HP-M454-002','192.168.1.21','Bat. B - Salle conf.',      'active','Communication','HP Color LaserJet M454dw'],
    ['HP','Color LaserJet M454dw','SN-HP-M454-003','192.168.1.22','Bat. C - Accueil principal','active','Accueil',      'HP Color LaserJet M454dw'],
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-003','192.168.1.12','Bat. B - RH Bureau 210',    'active','RH',           'HP LaserJet Pro M404dn'],
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-004','192.168.1.13','Bat. B - RH Bureau 215',    'active','RH',           'HP LaserJet Pro M404dn'],
    ['Brother','HL-L2350DW',     'SN-BRO-001',    '192.168.1.30','Bat. C - Compta Bureau 301','active','Comptabilité', 'Brother HL-L2350DW'],
    ['Brother','HL-L2350DW',     'SN-BRO-002',    '192.168.1.31','Bat. C - Compta Bureau 310','active','Comptabilité', 'Brother HL-L2350DW'],
    ['Brother','HL-L2350DW',     'SN-BRO-003',    '192.168.1.32','Bat. A - Technique Atelier','active','Technique',    'Brother HL-L2350DW'],
    ['Canon','PIXMA G3420',      'SN-CAN-001',    '192.168.1.40','Cuisine - Bureau chef',     'active','Cuisine',      'Canon PIXMA G3420'],
    ['Canon','PIXMA G3420',      'SN-CAN-002',    '192.168.1.41','Cuisine - Salle de pause',  'active','Cuisine',      'Canon PIXMA G3420'],
    ['Xerox','VersaLink C405',   'SN-XER-001',    '192.168.1.50','Bat. A - Reprographie',     'active','DSI',          'Xerox VersaLink C405'],
    ['Xerox','VersaLink C405',   'SN-XER-002',    '192.168.1.51','Bat. B - Reprographie',     'active','Communication','Xerox VersaLink C405'],
    ['HP','Color LaserJet M454dw','SN-HP-M454-004','192.168.1.23','Bat. C - Technique Bureau', 'active','Technique',   'HP Color LaserJet M454dw'],
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-005','192.168.1.14','Bat. A - Accueil banque',   'active','Accueil',      'HP LaserJet Pro M404dn'],
    ['Brother','HL-L2350DW',     'SN-BRO-004',    '192.168.1.33','Bat. B - Maintenance',      'maintenance','Technique','Brother HL-L2350DW'],
    ['HP','LaserJet Pro M404dn', 'SN-HP-M404-006','192.168.1.15','Bat. C - Archives',         'inactive','RH',         'HP LaserJet Pro M404dn'],
    ['Canon','PIXMA G3420',      'SN-CAN-003',    '',            'Communication - Graphisme',  'active','Communication','Canon PIXMA G3420'],
    ['Xerox','VersaLink C405',   'SN-XER-003',    '192.168.1.52','Bat. A - DSI Grande salle', 'active','DSI',          'Xerox VersaLink C405'],
];
$prt = [];
foreach ($prtDefs as $i => [$brand,$model,$sn,$ip,$loc,$status,$svcKey,$pmKey]) {
    $svcid = $svc[$svcKey] ?? 0;
    $pmid  = $pm[$pmKey]   ?? 0;
    $pid   = ins($db,
        "INSERT INTO printers(brand,model,serial_number,ip_address,location,status,service_id,printer_model_id,purchase_date) VALUES(?,?,?,?,?,?,?,?,?)",
        [$brand,$model,$sn,$ip,$loc,$status,$svcid,$pmid,date('Y-m-d',strtotime('-'.rand(6,48).' months'))]
    );
    $prt[$i] = $pid;
    // Cartouches héritées du modèle
    foreach (($assoc[$pmKey] ?? []) as $ck) {
        $cid = $crt[$ck] ?? 0;
        if ($cid && $pid) ins($db,"INSERT IGNORE INTO printer_cartridges(printer_id,cartridge_model_id) VALUES(?,?)",[$pid,$cid]);
    }
}
$log[] = '✅ '.count($prt).' imprimantes';

// ── 7. Stock ──────────────────────────────────────────────────────────────────
$stocks = [
    'HP|CF259A'    => 12, 'HP|CF259X'   => 4,
    'HP|W2010A'    => 6,  'HP|W2011A'   => 3,  'HP|W2012A'   => 3,  'HP|W2013A'   => 2,
    'HP|W2010X'    => 2,  'HP|W2011X'   => 1,  'HP|W2012X'   => 1,  'HP|W2013X'   => 0,  // rupture
    'HP|CF294A'    => 8,  'HP|CF294X'   => 5,
    'Canon|GI-490BK'=> 6, 'Canon|GI-490C'=> 3, 'Canon|GI-490M'=> 3, 'Canon|GI-490Y'=> 2,
    'Brother|TN-2420'=>10,'Brother|TN-2410'=>5, 'Brother|DR-2400'=>2,
    'Xerox|106R03520'=>2, 'Xerox|106R03517'=>1, 'Xerox|106R03518'=>0, // alertes/rupture
    'Xerox|106R03519'=>1, 'Xerox|106R03480'=>3, 'Xerox|106R03477'=>0,
    'COMPATIBLE|COMP-59A'=>15,'COMPATIBLE|COMP-210BK'=>8,'COMPATIBLE|COMP-210C'=>5,
    'COMPATIBLE|COMP-210M'=>5,'COMPATIBLE|COMP-210Y'=>4,'COMPATIBLE|COMP-2420'=>12,
    'Epson|T7551'=>0,'Epson|T7552'=>0,'Epson|T7553'=>0,'Epson|T7554'=>0,
];
foreach ($stocks as $k => $qty) {
    $cid = $crt[$k] ?? 0;
    if ($cid) ins($db,"INSERT INTO stock(cartridge_model_id,quantity_available) VALUES(?,?) ON DUPLICATE KEY UPDATE quantity_available=?",[$cid,$qty,$qty]);
}
$log[] = '✅ Stocks';

// ── 8. Entrées de stock ───────────────────────────────────────────────────────
$entries = [
    ['HP|CF259A',     'TonerPlus',    20, 13.90, '-180 days','FAC-2025-120'],
    ['HP|CF294A',     'Bureau Vallee',15, 11.50, '-150 days','FAC-2025-145'],
    ['Brother|TN-2420','Lyreco',      12, 17.50, '-140 days','FAC-2025-149'],
    ['Canon|GI-490BK','Bureau Vallee',10,  8.20, '-130 days','FAC-2025-163'],
    ['Canon|GI-490C', 'Bureau Vallee', 6,  8.20, '-130 days','FAC-2025-163'],
    ['Canon|GI-490M', 'Bureau Vallee', 6,  8.20, '-130 days','FAC-2025-163'],
    ['Canon|GI-490Y', 'Bureau Vallee', 6,  8.20, '-130 days','FAC-2025-163'],
    ['HP|W2010A',     'Manutan',      10, 21.50, '-120 days','FAC-2025-178'],
    ['HP|W2011A',     'Manutan',       6, 27.50, '-120 days','FAC-2025-178'],
    ['HP|W2012A',     'Manutan',       6, 27.50, '-120 days','FAC-2025-178'],
    ['HP|W2013A',     'Manutan',       6, 27.50, '-120 days','FAC-2025-178'],
    ['Xerox|106R03480','Office Depot', 5, 43.00, '-100 days','FAC-2025-201'],
    ['Xerox|106R03477','Office Depot', 3, 52.00, '-100 days','FAC-2025-201'],
    ['COMPATIBLE|COMP-59A','TonerPlus',30, 6.50,  '-90 days','FAC-2025-215'],
    ['COMPATIBLE|COMP-2420','Lyreco', 20,  8.50,  '-80 days','FAC-2025-228'],
    ['HP|CF259A',     'TonerPlus',    15, 14.50,  '-60 days','FAC-2026-012'],
    ['HP|CF259X',     'TonerPlus',     6, 33.00,  '-55 days','FAC-2026-012'],
    ['Brother|TN-2420','Lyreco',      10, 18.00,  '-50 days','FAC-2026-018'],
    ['HP|W2010X',     'Manutan',       4, 40.00,  '-45 days','FAC-2026-025'],
    ['HP|W2011X',     'Manutan',       2, 46.00,  '-45 days','FAC-2026-025'],
    ['Xerox|106R03520','Office Depot', 3, 53.00,  '-40 days','FAC-2026-031'],
    ['COMPATIBLE|COMP-210BK','Bureau Vallee',15, 9.50,'-30 days','FAC-2026-042'],
    ['HP|CF294X',     'TonerPlus',     8, 21.00,  '-20 days','FAC-2026-058'],
    ['Canon|GI-490BK','Bureau Vallee', 8,  8.50,  '-10 days','FAC-2026-067'],
    ['HP|W2010A',     'Manutan',       6, 22.00,   '-3 days','FAC-2026-078'],
];
foreach ($entries as [$ck,$supName,$qty,$price,$when,$ref]) {
    $cid = $crt[$ck] ?? 0; $sid = $sup[$supName] ?? 0;
    if ($cid && $sid) ins($db,
        "INSERT INTO stock_entries(cartridge_model_id,supplier_id,quantity,unit_price,entry_date,invoice_ref,created_by) VALUES(?,?,?,?,?,?,?)",
        [$cid,$sid,$qty,$price,date('Y-m-d',strtotime($when)),$ref,$adminId]
    );
}
$log[] = '✅ '.count($entries).' entrées de stock';

// ── 9. Sorties de stock ───────────────────────────────────────────────────────
$svcMap = [
    'DSI'=>$svc['DSI'],'RH'=>$svc['RH'],'Direction'=>$svc['Direction'],
    'Compta'=>$svc['Comptabilité'],'Cuisine'=>$svc['Cuisine'],
    'Comm'=>$svc['Communication'],'Tech'=>$svc['Technique'],'Accueil'=>$svc['Accueil'],
];
// [cart_key, svc_key, printer_idx, qty, when, person, notes]
$exits = [
    ['HP|CF259A',          'DSI',      0,  2, '-170 days','Marc FONTAINE',    'Remplacement bac 1'],
    ['HP|CF259A',          'DSI',      1,  1, '-150 days','Marc FONTAINE',    ''],
    ['HP|W2010A',          'Direction',2,  1, '-145 days','Alain ROUX',       'Impression rapport annuel'],
    ['HP|W2011A',          'Comm',     3,  1, '-140 days','Julie MARTIN',     'Flyer événement'],
    ['HP|W2012A',          'Comm',     3,  1, '-140 days','Julie MARTIN',     'Flyer événement'],
    ['HP|CF294A',          'RH',       5,  2, '-135 days','Claire DURAND',    ''],
    ['Brother|TN-2420',    'Compta',   7,  1, '-130 days','Nathalie BRUN',    ''],
    ['Brother|TN-2420',    'Compta',   8,  1, '-125 days','Nathalie BRUN',    ''],
    ['Canon|GI-490BK',     'Cuisine',  10, 1, '-120 days','Pierre LEROY',     'Menu semaine'],
    ['Canon|GI-490C',      'Cuisine',  10, 1, '-120 days','Pierre LEROY',     'Menu semaine'],
    ['HP|CF259A',          'DSI',      0,  1, '-115 days','Marc FONTAINE',    ''],
    ['Xerox|106R03480',    'DSI',      12, 1, '-110 days','Marc FONTAINE',    'Reprographie DSI'],
    ['HP|W2010A',          'Accueil',  4,  1, '-105 days','Sophie LEBLANC',   ''],
    ['Brother|TN-2410',    'Tech',     9,  1, '-100 days','Robert MOREAU',    'Atelier Technique'],
    ['COMPATIBLE|COMP-59A','RH',       5,  2,  '-95 days','Claire DURAND',    'Stock compatible'],
    ['HP|W2013A',          'Direction',2,  1,  '-90 days','Alain ROUX',       ''],
    ['Canon|GI-490M',      'Cuisine',  11, 1,  '-85 days','Pierre LEROY',     ''],
    ['HP|CF259X',          'DSI',      1,  1,  '-80 days','Marc FONTAINE',    'Haute capacite'],
    ['Brother|TN-2420',    'Tech',     9,  2,  '-75 days','Robert MOREAU',    ''],
    ['HP|CF294X',          'RH',       6,  1,  '-70 days','Claire DURAND',    ''],
    ['Xerox|106R03477',    'DSI',      12, 1,  '-65 days','Marc FONTAINE',    'Cyan reprographie'],
    ['HP|W2010X',          'Comm',     13, 1,  '-60 days','Julie MARTIN',     'Plaquette trimestrielle'],
    ['COMPATIBLE|COMP-2420','Compta',  7,  2,  '-55 days','Nathalie BRUN',    ''],
    ['HP|CF259A',          'Accueil',  15, 1,  '-50 days','Sophie LEBLANC',   ''],
    ['Canon|GI-490Y',      'Cuisine',  10, 1,  '-45 days','Pierre LEROY',     ''],
    ['HP|W2012A',          'Direction',2,  1,  '-40 days','Alain ROUX',       'Couleur'],
    ['Brother|TN-2420',    'Compta',   8,  1,  '-35 days','Nathalie BRUN',    ''],
    ['HP|CF259A',          'DSI',      0,  1,  '-30 days','Marc FONTAINE',    ''],
    ['Xerox|106R03520',    'DSI',      19, 1,  '-25 days','Marc FONTAINE',    'Grande reprographie'],
    ['COMPATIBLE|COMP-210BK','Accueil',4,  2,  '-20 days','Sophie LEBLANC',   ''],
    ['HP|CF259A',          'RH',       6,  1,  '-15 days','Claire DURAND',    ''],
    ['Canon|GI-490BK',     'Comm',     18, 1,  '-12 days','Julie MARTIN',     'Graphisme'],
    ['HP|W2011X',          'Direction',2,  1,  '-10 days','Alain ROUX',       ''],
    ['Brother|TN-2410',    'Compta',   7,  1,   '-8 days','Nathalie BRUN',    'Stock bas'],
    ['HP|CF259A',          'DSI',      1,  2,   '-5 days','Marc FONTAINE',    ''],
    ['Canon|GI-490M',      'Cuisine',  10, 1,   '-4 days','Pierre LEROY',     ''],
    ['HP|W2010A',          'Comm',     3,  1,   '-3 days','Julie MARTIN',     ''],
    ['Xerox|106R03480',    'DSI',      12, 1,   '-2 days','Marc FONTAINE',    ''],
    ['HP|CF294A',          'Accueil',  15, 1,   '-1 days','Sophie LEBLANC',   ''],
    ['Brother|TN-2420',    'Tech',     9,  1,    '0 days','Robert MOREAU',    'Atelier - urgent'],
];
foreach ($exits as [$ck,$svcK,$prtIdx,$qty,$when,$person,$notes]) {
    $cid   = $crt[$ck]  ?? 0;
    $svcid = $svcMap[$svcK] ?? 0;
    $prtid = $prt[$prtIdx] ?? 0;
    if ($cid && $svcid && $prtid) ins($db,
        "INSERT INTO stock_exits(cartridge_model_id,service_id,printer_id,quantity,exit_date,person_name,notes,created_by) VALUES(?,?,?,?,?,?,?,?)",
        [$cid,$svcid,$prtid,$qty,date('Y-m-d',strtotime($when)),$person,$notes,$adminId]
    );
}
$log[] = '✅ '.count($exits).' sorties de stock';

// ── 10. Commandes ─────────────────────────────────────────────────────────────
foreach ([
    ['pending',  'TonerPlus',    '-15 days', null,       [['HP|CF259A',20,14.00],['HP|CF259X',5,33.00],['COMPATIBLE|COMP-59A',30,6.50]]],
    ['partial',  'Lyreco',       '-20 days', null,       [['Brother|TN-2420',10,17.50],['Brother|TN-2410',8,12.50]]],
    ['pending',  'Manutan',      '-10 days', null,       [['HP|W2010A',8,21.50],['HP|W2011A',4,27.00],['HP|W2012A',4,27.00],['HP|W2013A',4,27.00]]],
    ['received', 'Office Depot', '-60 days', '-50 days', [['Xerox|106R03520',3,52.00],['Xerox|106R03517',2,63.00]]],
    ['received', 'Bureau Vallee','-90 days', '-80 days', [['Canon|GI-490BK',12,8.20],['Canon|GI-490C',8,8.20],['Canon|GI-490M',8,8.20],['Canon|GI-490Y',8,8.20]]],
    ['received', 'TonerPlus',    '-120 days','-110 days',[['HP|CF259A',25,13.50],['COMPATIBLE|COMP-59A',40,6.20]]],
    ['cancelled','Manutan',      '-150 days', null,      [['Epson|T7551',4,21.00],['Epson|T7552',4,17.00]]],
    ['partial',  'Lyreco',       '-30 days', null,       [['Brother|TN-2420',15,18.00],['COMPATIBLE|COMP-2420',20,8.50]]],
] as [$status,$supName,$odate,$rdate,$lines]) {
    $sid = $sup[$supName] ?? 0;
    if (!$sid) continue;
    $oid = ins($db,
        "INSERT INTO purchase_orders(supplier_id,order_date,expected_date,status,received_date,created_by) VALUES(?,?,?,?,?,?)",
        [$sid,date('Y-m-d',strtotime($odate)),date('Y-m-d',strtotime($odate.' +14 days')),$status,$rdate?date('Y-m-d',strtotime($rdate)):null,$adminId]
    );
    $ratio = $status==='received'?1.0:($status==='partial'?0.5:0.0);
    foreach ($lines as [$ck,$qty,$price]) {
        $cid = $crt[$ck] ?? 0;
        if ($cid) ins($db,
            "INSERT INTO purchase_order_lines(order_id,cartridge_model_id,quantity_ordered,quantity_received,unit_price) VALUES(?,?,?,?,?)",
            [$oid,$cid,$qty,(int)($qty*$ratio),$price]
        );
    }
}
$log[] = '✅ Commandes';

// ── 11. Demandes ──────────────────────────────────────────────────────────────
// [cart_key, svc_key, printer_idx, qty, when, notes, status]
foreach ([
    ['Xerox|106R03518',     'DSI',     12, 2, '-8 days',  'Rupture - urgent',                       'pending'],
    ['Xerox|106R03477',     'DSI',     12, 1, '-10 days', 'Stock cyan epuise',                      'pending'],
    ['HP|W2013X',           'Direction',2, 2, '-5 days',  'Impression rapport conseil',             'pending'],
    ['HP|W2011X',           'Comm',    13, 1, '-3 days',  'Plaquette institutionnelle',             'pending'],
    ['Brother|TN-2420',     'Compta',   7, 2, '-12 days', 'Bilan annuel',                           'partial'],
    ['Canon|GI-490Y',       'Cuisine', 10, 2, '-6 days',  'Menus couleur hebdo',                   'pending'],
    ['HP|CF259A',           'Accueil', 15, 3, '-15 days', 'Stock bas accueil',                      'pending'],
    ['HP|CF259X',           'DSI',      0, 1, '-20 days', 'Haute capacite pour imprimante principale','fulfilled'],
    ['COMPATIBLE|COMP-2420','Tech',     9, 3, '-25 days', 'Atelier impression plans',               'cancelled'],
    ['HP|W2012X',           'Direction',2, 1, '-7 days',  'Reunion conseil municipal',              'pending'],
    ['Canon|GI-490M',       'Comm',    18, 1, '-4 days',  'Graphisme campagne',                    'pending'],
    ['Brother|TN-2410',     'Compta',   8, 2, '-18 days', 'Urgence fin de mois',                   'partial'],
] as [$ck,$svcK,$prtIdx,$qty,$when,$notes,$status]) {
    $cid   = $crt[$ck]      ?? 0;
    $svcid = $svcMap[$svcK] ?? 0;
    $prtid = $prt[$prtIdx]  ?? 0;
    if (!$cid || !$svcid || !$prtid) continue;
    $fulfilled = $status==='fulfilled'?$qty:($status==='partial'?1:0);
    ins($db,
        "INSERT INTO reservations(cartridge_model_id,service_id,printer_id,quantity_requested,quantity_fulfilled,requested_date,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?)",
        [$cid,$svcid,$prtid,$qty,$fulfilled,date('Y-m-d',strtotime($when)),$notes,$status,$adminId]
    );
}
$log[] = '✅ Demandes';

// ── 12. Logs ──────────────────────────────────────────────────────────────────
foreach ([
    ['stock_in',     'cartridge','Entree 20 cartouches HP 59A'],
    ['stock_out',    'cartridge','Sortie 2 cartouches - Marc FONTAINE'],
    ['stock_out',    'cartridge','Sortie 1 cartouche - Alain ROUX'],
    ['order_create', 'order',   'Commande TonerPlus passee'],
    ['order_receive','order',   'Reception commande Office Depot'],
    ['stock_out',    'cartridge','Sortie 1 cartouche - Julie MARTIN'],
    ['login',        'user',    'Connexion'],
] as [$action,$etype,$desc]) {
    ins($db,"INSERT INTO activity_log(user_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)",
        [$adminId,$action,$etype,1,$desc,'127.0.0.1']
    );
}
$log[] = '✅ Logs';

// ── Résumé ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Seed terminé</title>
<style>
body{font-family:'Segoe UI',sans-serif;max-width:680px;margin:3rem auto;padding:2rem;background:#f0f2f7}
.card{background:#fff;border-radius:14px;padding:2rem;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.ok{color:#276749;font-weight:700}
.err{color:#c53030}
ul{margin:.75rem 0;padding-left:1.25rem;font-size:.88rem;line-height:2.2}
li.e{color:#c53030;font-size:.8rem}
.btn{display:inline-block;margin-top:1.25rem;padding:.65rem 1.75rem;background:#4361ee;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
</style></head><body>
<div class="card">
  <h2>🌱 Données de test injectées</h2>
  <p class="ok">✔ <?=$ok?> opérations réussies<?php if($err): ?> &nbsp;|&nbsp; <span class="err">✖ <?=$err?> erreur(s)</span><?php endif ?></p>
  <ul>
    <?php foreach($log as $l): ?>
    <li class="<?=str_starts_with($l,'❌')?'e':''?>"><?=htmlspecialchars($l)?></li>
    <?php endforeach ?>
  </ul>
</div>
<a href="index.php" class="btn">← Tableau de bord</a>
</body></html>
