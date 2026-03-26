<?php
// ============================================================
//  PrintManager v1.0 – Application principale
// ============================================================
ob_start(); // capturer tout output parasite avant les headers
ini_set('display_errors', 0);
error_reporting(0);
session_start();
require_once 'config.php';

$page      = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'dashboard');
$id        = (int)($_GET['id'] ?? 0);
$autoOpen  = preg_replace('/[^a-z_\-]/', '', $_GET['open'] ?? '');

// ─── LOGIN ──────────────────────────────────────────────────
if ($page === 'login') {
    if (isLogged()) { header('Location: index.php'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $db = getDB();
            $st = $db->prepare("SELECT * FROM users WHERE username=? AND active=1");
            $st->execute([sanitize($_POST['username'] ?? '')]);
            $u = $st->fetch();
            if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = $u;
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
                logAct($db,$u['id'],'login','user',$u['id'],'Connexion');
                header('Location: index.php'); exit;
            }
            flash('error', "Identifiants invalides.");
        } catch (Exception $e) {
            flash('error', "Erreur DB : " . $e->getMessage());
        }
        header('Location: index.php?page=login'); exit;
    }
    renderLogin(); exit;
}

if ($page === 'logout') {
    session_destroy(); header('Location: index.php?page=login'); exit;
}

requireLogin();

if (!defined('OID_SUPPLY_DESC')) {
    define('OID_SUPPLY_DESC',    '1.3.6.1.2.1.43.11.1.1.6.1');
    define('OID_SUPPLY_MAX',     '1.3.6.1.2.1.43.11.1.1.8.1');
    define('OID_SUPPLY_CURRENT', '1.3.6.1.2.1.43.11.1.1.9.1');
    define('OID_PRINTER_STATUS', '1.3.6.1.2.1.25.3.5.1.1.1');
    define('OID_PRINTER_NAME',   '1.3.6.1.2.1.43.5.1.1.16.1');
    define('OID_PAGES_TOTAL',    '1.3.6.1.2.1.43.10.2.1.4.1.1');
}
function snmpQueryPrinter(string $ip, string $community='public', int $timeout=2): array {
    $r = ['ip'=>$ip,'reachable'=>false,'status'=>0,'pages_total'=>null,'supplies'=>[],'error'=>'','queried_at'=>date('H:i:s')];
    if (!function_exists('snmpget')) { $r['error']='extension_missing'; return $r; }
    set_error_handler(function(){});
    try {
        if (@snmpget($ip,$community,'1.3.6.1.2.1.1.1.0',$timeout*1000000,2)===false) { $r['error']='unreachable'; restore_error_handler(); return $r; }
        $r['reachable']=true;
        $st=@snmpget($ip,$community,OID_PRINTER_STATUS.'.0',$timeout*1000000,2); $r['status']=$st?(int)snmpClean($st):3;
        $pg=@snmpget($ip,$community,OID_PAGES_TOTAL,$timeout*1000000,2); $r['pages_total']=$pg?(int)snmpClean($pg):null;
        for($i=1;$i<=10;$i++){
            $d=@snmpget($ip,$community,OID_SUPPLY_DESC.'.'.$i,$timeout*1000000,2);
            $m=@snmpget($ip,$community,OID_SUPPLY_MAX.'.'.$i,$timeout*1000000,2);
            $c=@snmpget($ip,$community,OID_SUPPLY_CURRENT.'.'.$i,$timeout*1000000,2);
            if($d===false&&$m===false&&$c===false) break;
            if($d===false||$m===false||$c===false) continue;
            $dc=snmpClean($d); $mv=(int)snmpClean($m); $cv=(int)snmpClean($c);
            if(empty($dc)||$mv===0) continue;
            $pct=match(true){$cv===-3=>-1,$cv===-2=>100,$mv>0=>(int)round($cv/$mv*100),default=>-1};
            $r['supplies'][]=['description'=>$dc,'percent'=>$pct,'color'=>snmpColor($dc)];
        }
    } catch(Exception $e){ $r['error']=$e->getMessage(); }
    restore_error_handler();
    return $r;
}
function snmpClean(string $v): string {
    $v = trim($v);
    // Gérer le format Hex-STRING : "Hex-STRING: 43 61 72 74..."
    if (preg_match('/^Hex-STRING:\s*([0-9A-Fa-f\s]+)$/', $v, $m)) {
        $hex = preg_replace('/\s+/', '', trim($m[1]));
        // Supprimer les zéros de fin (octet null)
        $hex = rtrim($hex, '0');
        if (strlen($hex) % 2 !== 0) $hex .= '0';
        $decoded = '';
        foreach (str_split($hex, 2) as $byte) {
            $char = chr(hexdec($byte));
            if ($char !== "\x00") $decoded .= $char;
        }
        return trim($decoded);
    }
    // Format standard STRING, INTEGER, etc.
    return preg_match('/^(STRING|INTEGER|OID|Gauge32|Counter32|Timeticks):\s*"?(.+?)"?$/', $v, $m)
        ? trim($m[2])
        : $v;
}
function snmpColor(string $d): array {
    $d=strtolower($d);
    if(str_contains($d,'black')||str_contains($d,'noir')||str_contains($d,'bk')) return ['','#e2e8f0','Noir'];
    if(str_contains($d,'cyan'))    return ['','#67e8f9','Cyan'];
    if(str_contains($d,'magenta')) return ['','#f0abfc','Magenta'];
    if(str_contains($d,'yellow')||str_contains($d,'jaune')) return ['','#fde68a','Jaune'];
    if(str_contains($d,'waste')||str_contains($d,'maintenance')) return ['','#94a3b8','Maintenance'];
    if(str_contains($d,'drum')||str_contains($d,'tambour')) return ['','#a78bfa','Tambour'];
    return ['','#94a3b8','Inconnu'];
}

// ─── SNMP : handler AJAX (avant tout output) ────────────────
if (isset($_GET['ajax_snmp'], $_GET['printer_id'])) {
    session_write_close();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db2 = getDB();
        $st2 = $db2->prepare("SELECT * FROM printers WHERE id=?");
        $st2->execute([(int)$_GET['printer_id']]);
        $p2 = $st2->fetch();
        if (!$p2 || empty($p2['ip_address'])) { echo json_encode(['error'=>'no_ip']); exit; }
        echo json_encode(snmpQueryPrinter($p2['ip_address'], sanitize($_GET['community'] ?? 'public'), 5));
    } catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ─── AJAX : recherche cartouche par référence (scanner QR) ───────────────
if (isset($_GET['ajax_find_cartridge'])) {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db2 = getDB();
        $q = trim($_GET['q'] ?? '');
        $st = $db2->prepare("SELECT id, brand, model, color FROM cartridge_models WHERE barcode=? OR reference=? OR model=? OR CONCAT(brand,' ',model)=? LIMIT 1");
        $st->execute([$q,$q,$q,$q]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: null);
    } catch(Exception $e) { echo 'null'; }
    exit;
}
 // ───────────────────────────
if (isset($_GET['ajax_order_lines'], $_GET['order_id'])) {
    session_write_close();
    header('Content-Type: application/json');
    try {
        $db2 = getDB();
        $st = $db2->prepare("SELECT pol.*, cm.brand, cm.model, cm.color FROM purchase_order_lines pol JOIN cartridge_models cm ON pol.cartridge_model_id=cm.id WHERE pol.order_id=? ORDER BY pol.id");
        $st->execute([(int)$_GET['order_id']]);
        echo json_encode($st->fetchAll());
    } catch(Exception $e) { echo json_encode([]); }
    exit;
}

// ─── AJAX : cartouches d'un modèle d'imprimante ───────────
if (isset($_GET['ajax_printer_model_cids'], $_GET['model_id'])) {
    session_write_close();
    header('Content-Type: application/json');
    try {
        $db2 = getDB();
        $st = $db2->prepare("SELECT cartridge_model_id FROM printer_model_cartridges WHERE printer_model_id=?");
        $st->execute([(int)$_GET['model_id']]);
        echo json_encode($st->fetchAll(PDO::FETCH_COLUMN));
    } catch(Exception $e) { echo json_encode([]); }
    exit;
}

// ─── AJAX : recherche dashboard ──────────────────────────
if (isset($_GET['ajax_dash_search'])) {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db2 = getDB();
        $q   = '%' . trim($_GET['q'] ?? '') . '%';
        $sql = "SELECT * FROM (
          SELECT
            p.id as entity_id,
            1 as sort_order,
            'imprimante' as op_type,
            p.created_at as op_date,
            0 as quantity,
            p.serial_number as ref_name,
            p.brand, p.model, '' as color,
            COALESCE(sv.name,'Sans service') as ctx_name,
            '' as printer_name,
            CONCAT(COALESCE(p.location,''), IF(p.ip_address, CONCAT(' · ', p.ip_address),'')) as detail
          FROM printers p
          LEFT JOIN services sv ON p.service_id = sv.id
          WHERE p.brand LIKE ?
             OR p.model LIKE ?
             OR p.serial_number LIKE ?
             OR p.ip_address LIKE ?
             OR p.location LIKE ?
             OR sv.name LIKE ?
          UNION ALL
          SELECT
            cm.id as entity_id,
            2 as sort_order,
            'cartouche' as op_type,
            cm.created_at as op_date,
            COALESCE(s.quantity_available,0) as quantity,
            cm.reference as ref_name,
            cm.brand, cm.model, cm.color,
            '' as ctx_name,
            '' as printer_name,
            cm.type as detail
          FROM cartridge_models cm
          LEFT JOIN stock s ON s.cartridge_model_id = cm.id
          WHERE cm.brand LIKE ?
             OR cm.model LIKE ?
             OR cm.color LIKE ?
             OR cm.reference LIKE ?
             OR cm.type LIKE ?
          UNION ALL
          SELECT
            se.id as entity_id,
            3 as sort_order,
            'sortie' as op_type,
            se.exit_date as op_date,
            se.quantity,
            se.person_name as ref_name,
            cm.brand, cm.model, cm.color,
            COALESCE(sv.name,'Sans service') as ctx_name,
            CONCAT(COALESCE(p.brand,''),' ',COALESCE(p.model,'')) as printer_name,
            COALESCE(p.location,'') as detail
          FROM stock_exits se
          JOIN cartridge_models cm ON se.cartridge_model_id = cm.id
          LEFT JOIN services sv ON se.service_id = sv.id
          LEFT JOIN printers p ON se.printer_id = p.id
          WHERE sv.name LIKE ?
             OR cm.brand LIKE ?
             OR cm.model LIKE ?
             OR cm.color LIKE ?
             OR se.person_name LIKE ?
             OR p.brand LIKE ?
             OR p.model LIKE ?
             OR CONCAT(p.brand,' ',p.model) LIKE ?
          UNION ALL
          SELECT
            en.id as entity_id,
            4 as sort_order,
            'entree' as op_type,
            en.entry_date as op_date,
            en.quantity,
            en.invoice_ref as ref_name,
            cm.brand, cm.model, cm.color,
            COALESCE(sp.name,'Sans fournisseur') as ctx_name,
            '' as printer_name,
            '' as detail
          FROM stock_entries en
          JOIN cartridge_models cm ON en.cartridge_model_id = cm.id
          LEFT JOIN suppliers sp ON en.supplier_id = sp.id
          WHERE sp.name LIKE ?
             OR cm.brand LIKE ?
             OR cm.model LIKE ?
             OR cm.color LIKE ?
             OR en.invoice_ref LIKE ?
        ) results
        ORDER BY sort_order ASC, op_date DESC
        LIMIT 100";
        $st = $db2->prepare($sql);
        $st->execute([
            $q,$q,$q,$q,$q,$q,          // imprimantes (6)
            $q,$q,$q,$q,$q,             // cartouches (5)
            $q,$q,$q,$q,$q,$q,$q,$q,   // sorties (8)
            $q,$q,$q,$q,$q              // entrées (5)
        ]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch(Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
try { $db = getDB(); } catch (Exception $e) {
    die('<div style="color:#ef4444;padding:3rem;font-family:sans-serif">Erreur DB : ' . $e->getMessage() . '<br><a href="install.php">Lancer install.php</a></div>');
}
// Migrations automatiques
try { $db->query("ALTER TABLE cartridge_models ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e) {}
try { $db->query("ALTER TABLE cartridge_models ADD COLUMN barcode VARCHAR(255) NULL DEFAULT NULL"); } catch(Exception $e) {}
try { $db->query("CREATE TABLE IF NOT EXISTS printer_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); } catch(Exception $e) {}
try { $db->query("CREATE TABLE IF NOT EXISTS printer_model_cartridges (
    printer_model_id INT NOT NULL,
    cartridge_model_id INT NOT NULL,
    PRIMARY KEY (printer_model_id, cartridge_model_id),
    FOREIGN KEY (printer_model_id) REFERENCES printer_models(id) ON DELETE CASCADE,
    FOREIGN KEY (cartridge_model_id) REFERENCES cartridge_models(id) ON DELETE CASCADE
)"); } catch(Exception $e) {}
try { $db->query("ALTER TABLE printers ADD COLUMN printer_model_id INT NULL"); } catch(Exception $e) {}
try { $db->query("ALTER TABLE reservations ADD COLUMN printer_id INT NULL"); } catch(Exception $e) {}
$user = $_SESSION['user'];

// ─── POST HANDLERS ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ent = sanitize($_POST['_entity'] ?? '');
    $act = sanitize($_POST['_action'] ?? '');
    $pid = (int)($_POST['_id'] ?? 0);
    $redir = 'index.php?page=' . $page;
    try { doPost($db, $ent, $act, $_POST, $pid); }
    catch (Exception $e) { flash('error', 'Erreur : ' . $e->getMessage()); }
    header('Location: ' . $redir); exit;
}

function doPost(PDO $db, string $ent, string $act, array $d, int $id): void {
    global $user;
    switch ($ent) {
        case 'service':
            if ($act==='add')
                $db->prepare("INSERT INTO services(name,direction,contact_name,contact_email,phone,notes)VALUES(?,?,?,?,?,?)")
                   ->execute([S($d,'name'),S($d,'direction'),S($d,'contact_name'),S($d,'contact_email'),S($d,'phone'),S($d,'notes')]);
            elseif ($act==='edit')
                $db->prepare("UPDATE services SET name=?,direction=?,contact_name=?,contact_email=?,phone=?,notes=? WHERE id=?")
                   ->execute([S($d,'name'),S($d,'direction'),S($d,'contact_name'),S($d,'contact_email'),S($d,'phone'),S($d,'notes'),$id]);
            elseif ($act==='delete')
                $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
            flash('success', msg($act,'service'));
            break;
        case 'supplier':
            if ($act==='add')
                $db->prepare("INSERT INTO suppliers(name,contact_name,email,phone,address,website,notes)VALUES(?,?,?,?,?,?,?)")
                   ->execute([S($d,'name'),S($d,'contact_name'),S($d,'email'),S($d,'phone'),S($d,'address'),S($d,'website'),S($d,'notes')]);
            elseif ($act==='edit')
                $db->prepare("UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,address=?,website=?,notes=? WHERE id=?")
                   ->execute([S($d,'name'),S($d,'contact_name'),S($d,'email'),S($d,'phone'),S($d,'address'),S($d,'website'),S($d,'notes'),$id]);
            elseif ($act==='delete')
                $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
            flash('success', msg($act,'fournisseur'));
            break;
        case 'cartridge':
            if ($act==='add') {
                $db->prepare("INSERT INTO cartridge_models(brand,model,reference,color,type,page_yield,unit_price,alert_threshold,notes,barcode)VALUES(?,?,?,?,?,?,?,?,?,?)")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'reference'),S($d,'color','Noir'),S($d,'type','laser'),(int)IV($d,'page_yield'),(float)IV($d,'unit_price'),(int)IV($d,'alert_threshold',3),S($d,'notes'),S($d,'barcode')?:null]);
                $cid=$db->lastInsertId();
                $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available,quantity_reserved)VALUES(?,0,0)")->execute([$cid]);
            } elseif ($act==='edit')
                $db->prepare("UPDATE cartridge_models SET brand=?,model=?,reference=?,color=?,type=?,page_yield=?,unit_price=?,alert_threshold=?,notes=?,barcode=? WHERE id=?")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'reference'),S($d,'color','Noir'),S($d,'type','laser'),(int)IV($d,'page_yield'),(float)IV($d,'unit_price'),(int)IV($d,'alert_threshold',3),S($d,'notes'),S($d,'barcode')?:null,$id]);
            elseif ($act==='archive') {
                $db->prepare("UPDATE cartridge_models SET active=0 WHERE id=?")->execute([$id]);
                flash('success','🗄️ Cartouche archivée. Elle reste visible dans l\'historique.');
                break;
            } elseif ($act==='restore') {
                $db->prepare("UPDATE cartridge_models SET active=1 WHERE id=?")->execute([$id]);
                flash('success','✅ Cartouche restaurée.');
                break;
            } elseif ($act==='archive_orphans') {
                // Archiver toutes les cartouches actives non rattachées à une imprimante
                $orphans = $db->query(
                    "SELECT cm.id FROM cartridge_models cm
                     LEFT JOIN printer_cartridges pc ON pc.cartridge_model_id = cm.id
                     WHERE pc.printer_id IS NULL AND (cm.active = 1 OR cm.active IS NULL)"
                )->fetchAll(PDO::FETCH_COLUMN);
                if (empty($orphans)) {
                    flash('info','ℹ️ Aucune cartouche orpheline à archiver.');
                } else {
                    $ph = implode(',', array_fill(0, count($orphans), '?'));
                    $db->prepare("UPDATE cartridge_models SET active=0 WHERE id IN ($ph)")->execute($orphans);
                    flash('success','🗄️ '.count($orphans).' cartouche(s) non rattachée(s) à une imprimante ont été archivées.');
                }
                break;
            } elseif ($act==='delete') {
                // Vérifier si la cartouche a un historique (entrées, sorties, commandes, demandes)
                $hasHistory = false;
                $checks = [
                    "SELECT COUNT(*) FROM stock_entries WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM stock_exits WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM purchase_order_lines WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM reservations WHERE cartridge_model_id=?",
                ];
                foreach ($checks as $sql) {
                    $st = $db->prepare($sql); $st->execute([$id]);
                    if ((int)$st->fetchColumn() > 0) { $hasHistory = true; break; }
                }
                if ($hasHistory) {
                    // Archiver plutôt que supprimer
                    $db->prepare("UPDATE cartridge_models SET active=0 WHERE id=?")->execute([$id]);
                    flash('warning','⚠️ Cette cartouche a un historique et ne peut pas être supprimée. Elle a été <strong>archivée</strong> à la place.');
                    break;
                }
                $db->prepare("DELETE FROM cartridge_models WHERE id=?")->execute([$id]);
            }
            flash('success', msg($act,'cartouche'));
            break;
        case 'printer_model':
            if ($act==='add') {
                $db->prepare("INSERT INTO printer_models(brand,model,notes)VALUES(?,?,?)")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'notes')]);
                $pmid = $db->lastInsertId();
                assocCartridgesModel($db, $pmid, $d['cartridge_ids']??[]);
                flash('success','✅ Modèle d\'imprimante créé.');
            } elseif ($act==='edit') {
                $db->prepare("UPDATE printer_models SET brand=?,model=?,notes=? WHERE id=?")->execute([S($d,'brand'),S($d,'model'),S($d,'notes'),$id]);
                // Les cartridge_ids peuvent être absent si aucune case cochée — sentinel "cartridge_ids_sent" confirme soumission intentionnelle
                $newCids = array_map('intval', $d['cartridge_ids'] ?? []);
                if (!isset($d['cartridge_ids_sent'])) break; // ne devrait pas arriver
                // Màj cartouches du modèle
                $db->prepare("DELETE FROM printer_model_cartridges WHERE printer_model_id=?")->execute([$id]);
                if (!empty($newCids)) {
                    assocCartridgesModel($db, $id, $newCids);
                }
                // Récupérer les imprimantes liées AVANT de faire d'autres requêtes
                $stLinked = $db->prepare("SELECT id FROM printers WHERE printer_model_id=?");
                $stLinked->execute([$id]);
                $linkedIds = $stLinked->fetchAll(PDO::FETCH_COLUMN);
                // Propager aux imprimantes rattachées à ce modèle
                foreach ($linkedIds as $pid2) {
                    $db->prepare("DELETE FROM printer_cartridges WHERE printer_id=?")->execute([$pid2]);
                    if (!empty($newCids)) {
                        assocCartridges($db, $pid2, $newCids);
                    }
                }
                $nb = count($newCids);
                $linkedCount = count($linkedIds);
                flash('success','✅ Modèle mis à jour avec '.$nb.' cartouche(s). '.$linkedCount.' imprimante(s) liée(s) synchronisée(s).');
            } elseif ($act==='delete') {
                // Délier les imprimantes avant suppression
                $db->prepare("UPDATE printers SET printer_model_id=NULL WHERE printer_model_id=?")->execute([$id]);
                $db->prepare("DELETE FROM printer_models WHERE id=?")->execute([$id]);
                flash('success','Modèle supprimé.');
            }
            break;
        case 'printer':
            $svc = (int)IV($d,'service_id') ?: null;
            $pd  = NV($d,'purchase_date'); $wd = NV($d,'warranty_end');
            $pmid2 = (int)IV($d,'printer_model_id') ?: null;
            if ($act==='add') {
                $db->prepare("INSERT INTO printers(service_id,brand,model,serial_number,ip_address,location,status,purchase_date,warranty_end,notes,printer_model_id)VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$svc,S($d,'brand'),S($d,'model'),S($d,'serial_number'),S($d,'ip_address'),S($d,'location'),S($d,'status','active'),$pd,$wd,S($d,'notes'),$pmid2]);
                $pid2=$db->lastInsertId();
                // Cartouches héritées du modèle
                if ($pmid2) {
                    $stCids = $db->prepare("SELECT cartridge_model_id FROM printer_model_cartridges WHERE printer_model_id=?");
                    $stCids->execute([$pmid2]);
                    $cidsFromModel = $stCids->fetchAll(PDO::FETCH_COLUMN);
                    assocCartridges($db, $pid2, $cidsFromModel);
                } else {
                    assocCartridges($db,$pid2,$d['cartridge_ids']??[]);
                }
            } elseif ($act==='edit') {
                $newStatus = S($d,'status','active');
                // Récupérer le printer_model_id actuel pour ne pas l'écraser
                $stPmid = $db->prepare("SELECT printer_model_id FROM printers WHERE id=?");
                $stPmid->execute([$id]); $currentPmid = (int)$stPmid->fetchColumn() ?: null;
                $db->prepare("UPDATE printers SET service_id=?,brand=?,model=?,serial_number=?,ip_address=?,location=?,status=?,purchase_date=?,warranty_end=?,notes=? WHERE id=?")
                   ->execute([$svc,S($d,'brand'),S($d,'model'),S($d,'serial_number'),S($d,'ip_address'),S($d,'location'),$newStatus,$pd,$wd,S($d,'notes'),$id]);
                // Les cartouches sont gérées par le modèle — pas de mise à jour manuelle
                if ($newStatus === 'inactive') {
                    flash('warning','⚠️ Imprimante mise en <strong>Inactif</strong>.');
                }
            } elseif ($act==='delete')
                $db->prepare("DELETE FROM printers WHERE id=?")->execute([$id]);
            flash('success', msg($act,'imprimante'));
            break;
        case 'stock_in':
            if ($act==='add') {
                $cid=(int)$d['cartridge_model_id']; $qty=(int)$d['quantity'];
                if ($qty<1) { flash('error','Quantité invalide.'); break; }
                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("INSERT INTO stock_entries(cartridge_model_id,supplier_id,quantity,unit_price,entry_date,invoice_ref,created_by,notes)VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$cid,$sup,$qty,(float)IV($d,'unit_price'),S($d,'entry_date'),S($d,'invoice_ref'),$user['id'],S($d,'notes')]);
                $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available)VALUES(?,?)ON DUPLICATE KEY UPDATE quantity_available=quantity_available+?")
                   ->execute([$cid,$qty,$qty]);
                flash('success',"✅ Entrée de $qty unité(s) enregistrée.");
                logAct($db,$user['id'],'stock_in','cartridge',$cid,"Entrée $qty ".($qty>1?'cartouches':'cartouche'));
            }
            break;
        case 'stock_out':
            if ($act==='add') {
                $cid=(int)$d['cartridge_model_id']; $qty=(int)$d['quantity'];
                if ($qty<1) { flash('error','Quantité invalide.'); break; }

                $st2=$db->prepare("SELECT quantity_available FROM stock WHERE cartridge_model_id=?");
                $st2->execute([$cid]); $avail=(int)$st2->fetchColumn();
                if ($avail<$qty) { flash('error',"Stock insuffisant. Disponible : $avail u."); break; }

                $rid=(int)IV($d,'reservation_id')?:null;
                $svc=(int)IV($d,'service_id')?:null;

                // ── Contrôle seuil de réservation ──────────────────
                // Calculer la quantité totale réservée par d'autres demandes (hors celle liée à cette sortie)
                $reservedByOthers = 0;
                $stRes=$db->prepare(
                    "SELECT COALESCE(SUM(quantity_requested - quantity_fulfilled),0)
                     FROM reservations
                     WHERE cartridge_model_id=? AND status IN ('pending','partial')
                     AND id != COALESCE(?,0)"
                );
                $stRes->execute([$cid, $rid]);
                $reservedByOthers = (int)$stRes->fetchColumn();

                // Si la sortie n'est pas liée à une demande ET qu'il n'y a pas assez de stock libre
                $freeStock = $avail - $reservedByOthers;
                if (!$rid && $freeStock < $qty) {
                    $needed = $qty - $freeStock;
                    flash('error',
                        "⛔ Sortie bloquée : <strong>$reservedByOthers u.</strong> sont réservées pour des demandes en attente. "
                        . "Stock libre disponible : <strong>$freeStock u.</strong> "
                        . "Liez cette sortie à une demande existante, ou réduisez la quantité à $freeStock u."
                    );
                    break;
                }

                $prn=(int)IV($d,'printer_id')?:null;
                $db->prepare("INSERT INTO stock_exits(cartridge_model_id,service_id,printer_id,quantity,exit_date,person_name,reservation_id,notes,created_by)VALUES(?,?,?,?,?,?,?,?,?)")
                   ->execute([$cid,$svc,$prn,$qty,S($d,'exit_date'),S($d,'person_name'),$rid,S($d,'notes'),$user['id']]);
                // UPDATE atomique : décrémente uniquement si le stock est suffisant (protection race condition)
                $stUpd = $db->prepare("UPDATE stock SET quantity_available = quantity_available - ? WHERE cartridge_model_id = ? AND quantity_available >= ?");
                $stUpd->execute([$qty,$cid,$qty]);
                if ($stUpd->rowCount() === 0) {
                    // Le stock a été pris par une autre session entre le check et l'UPDATE — annuler la sortie
                    $db->prepare("DELETE FROM stock_exits WHERE id=?")->execute([$db->lastInsertId()]);
                    flash('error','⛔ Stock insuffisant au moment de la validation (modifié simultanément). Veuillez réessayer.');
                    break;
                }
                if ($rid) {
                    // Incrémenter quantity_fulfilled
                    $db->prepare("UPDATE reservations SET quantity_fulfilled = quantity_fulfilled + ? WHERE id=?")->execute([$qty, $rid]);
                    // Recalculer le statut APRÈS la mise à jour : fulfilled seulement si tout a été livré
                    $rSt = $db->prepare("SELECT quantity_requested, quantity_fulfilled FROM reservations WHERE id=?");
                    $rSt->execute([$rid]); $rRow = $rSt->fetch();
                    if ($rRow) {
                        $newStatus = ($rRow['quantity_fulfilled'] >= $rRow['quantity_requested']) ? 'fulfilled' : 'partial';
                        $db->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$newStatus, $rid]);
                    }
                }
                flash('success',"✅ Sortie de $qty unité(s) enregistrée.");
                logAct($db,$user['id'],'stock_out','cartridge',$cid,"Sortie $qty ".($qty>1?'cartouches':'cartouche')." - ".S($d,'person_name'));
            }
            break;
        case 'reservation':
            if ($act==='add') {
                $db->prepare("INSERT INTO reservations(cartridge_model_id,service_id,printer_id,quantity_requested,requested_date,notes,created_by)VALUES(?,?,?,?,?,?,?)")
                   ->execute([(int)$d['cartridge_model_id'],(int)IV($d,'service_id')?:null,(int)IV($d,'printer_id')?:null,(int)$d['quantity_requested'],S($d,'requested_date'),S($d,'notes'),$user['id']]);
                flash('success','Demande créée.');
            } elseif ($act==='edit') {
                $newQty = (int)$d['quantity_requested'];
                $db->prepare("UPDATE reservations SET cartridge_model_id=?,service_id=?,printer_id=?,quantity_requested=?,requested_date=?,notes=? WHERE id=?")
                   ->execute([(int)$d['cartridge_model_id'],(int)IV($d,'service_id')?:null,(int)IV($d,'printer_id')?:null,$newQty,S($d,'requested_date'),S($d,'notes'),$id]);
                // Recalculer le statut selon la nouvelle quantité demandée vs quantité déjà traitée
                $rChk = $db->prepare("SELECT quantity_requested, quantity_fulfilled, status FROM reservations WHERE id=?");
                $rChk->execute([$id]); $rRow = $rChk->fetch();
                if ($rRow && in_array($rRow['status'], ['pending','partial','fulfilled'])) {
                    $fulfilled = (int)$rRow['quantity_fulfilled'];
                    $requested = (int)$rRow['quantity_requested'];
                    if ($fulfilled >= $requested && $requested > 0) $newRStatus = 'fulfilled';
                    elseif ($fulfilled > 0) $newRStatus = 'partial';
                    else $newRStatus = 'pending';
                    $db->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$newRStatus,$id]);
                }
                flash('success','Demande modifiée.');
            } elseif ($act==='cancel') {
                $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
                flash('success','Demande annulée.');
            } elseif ($act==='delete') {
                $db->prepare("DELETE FROM reservations WHERE id=?")->execute([$id]);
                flash('success','Demande supprimée.');
            }
            break;
        case 'order':
            if ($act==='edit') {
                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,notes=? WHERE id=?")
                   ->execute([$sup,S($d,'order_date'),NV($d,'expected_date'),S($d,'notes'),$id]);
                // màj des lignes existantes
                $lineIds=$d['line_id']??[]; $qtys=$d['line_qty']??[]; $pxs=$d['line_price']??[];
                foreach($lineIds as $k=>$lid){
                    $lid=(int)$lid; $qty=(int)($qtys[$k]??0);
                    if($qty>0)
                        $db->prepare("UPDATE purchase_order_lines SET quantity_ordered=?,unit_price=? WHERE id=?")
                           ->execute([$qty,(float)($pxs[$k]??0),$lid]);
                    else
                        $db->prepare("DELETE FROM purchase_order_lines WHERE id=? AND quantity_received=0")->execute([$lid]);
                }
                // nouvelles lignes
                $cids=$d['cart_id']??[]; $nqtys=$d['cart_qty']??[]; $npxs=$d['cart_price']??[];
                $ins=$db->prepare("INSERT INTO purchase_order_lines(order_id,cartridge_model_id,quantity_ordered,unit_price)VALUES(?,?,?,?)");
                foreach($cids as $k=>$cid){ if((int)$cid>0&&(int)($nqtys[$k]??0)>0) $ins->execute([$id,(int)$cid,(int)$nqtys[$k],(float)($npxs[$k]??0)]); }
                flash('success','✅ Commande modifiée.');
            } elseif ($act==='add') {                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("INSERT INTO purchase_orders(supplier_id,order_date,expected_date,notes,status,created_by)VALUES(?,?,?,?,'pending',?)")
                   ->execute([$sup,S($d,'order_date'),NV($d,'expected_date'),S($d,'notes'),$user['id']]);
                $oid=$db->lastInsertId();
                // lignes de commande
                $cids=$d['cart_id']??[]; $qtys=$d['cart_qty']??[]; $pxs=$d['cart_price']??[];
                $ins=$db->prepare("INSERT INTO purchase_order_lines(order_id,cartridge_model_id,quantity_ordered,unit_price)VALUES(?,?,?,?)");
                foreach($cids as $k=>$cid){ if((int)$cid>0&&(int)($qtys[$k]??0)>0) $ins->execute([$oid,(int)$cid,(int)$qtys[$k],(float)($pxs[$k]??0)]); }
                flash('success','✅ Commande créée.');
                logAct($db,$user['id'],'order_create','order',$oid,'Nouvelle commande');
            } elseif ($act==='cancel') {
                $db->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]);
                flash('success','Commande annulée.');
            } elseif ($act==='delete') {
                $db->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);
                flash('success','Commande supprimée.');
            }
            break;
        case 'order_receive':
            if ($act==='receive') {
                $oid=(int)($d['order_id']??0);
                if (!$oid) { flash('error','Commande invalide.'); break; }
                // Récupérer le fournisseur de la commande pour l'associer aux entrées
                $orderInfo = $db->prepare("SELECT supplier_id FROM purchase_orders WHERE id=?");
                $orderInfo->execute([$oid]); $orderSupId = (int)$orderInfo->fetchColumn() ?: null;
                $lineIds=$d['line_id']??[]; $recvQtys=$d['recv_qty']??[]; $prices=$d['unit_price']??[];
                $anyReceived=false;
                foreach($lineIds as $k=>$lid){
                    $lid=(int)$lid; $rqty=(int)($recvQtys[$k]??0);
                    if($rqty<=0) continue;
                    // récupérer la ligne
                    $ln=$db->prepare("SELECT * FROM purchase_order_lines WHERE id=? AND order_id=?");
                    $ln->execute([$lid,$oid]); $line=$ln->fetch();
                    if(!$line) continue;
                    $newRecv=$line['quantity_received']+$rqty;
                    $db->prepare("UPDATE purchase_order_lines SET quantity_received=? WHERE id=?")->execute([$newRecv,$lid]);
                    // entrée stock avec le fournisseur de la commande
                    $up=(float)($prices[$k]??$line['unit_price']??0);
                    $db->prepare("INSERT INTO stock_entries(cartridge_model_id,supplier_id,quantity,unit_price,entry_date,invoice_ref,created_by,notes)VALUES(?,?,?,?,?,?,?,?)")
                       ->execute([$line['cartridge_model_id'],$orderSupId,$rqty,$up,date('Y-m-d'),'CMD-'.$oid,$user['id'],'Réception commande #'.$oid]);
                    $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available)VALUES(?,?)ON DUPLICATE KEY UPDATE quantity_available=quantity_available+?")
                       ->execute([$line['cartridge_model_id'],$rqty,$rqty]);
                    logAct($db,$user['id'],'order_receive','order',$oid,"Réception $rqty ".($rqty>1?'cartouches':'cartouche')." cart #{$line['cartridge_model_id']}");
                    $anyReceived=true;
                }
                if($anyReceived){
                    // recalc statut commande
                    $chk=$db->prepare("SELECT SUM(quantity_ordered) as tot, SUM(quantity_received) as rec FROM purchase_order_lines WHERE order_id=?");
                    $chk->execute([$oid]); $chkr=$chk->fetch();
                    $newStatus=$chkr['rec']>=$chkr['tot']?'received':($chkr['rec']>0?'partial':'pending');
                    $db->prepare("UPDATE purchase_orders SET status=?, received_date=IF(?='received',NOW(),received_date) WHERE id=?")->execute([$newStatus,$newStatus,$oid]);
                    if ($newStatus === 'received') {
                        flash('success','✅ Réception enregistrée. Commande complète — elle a été <strong>archivée</strong>.');
                    } else {
                        flash('success','✅ Réception enregistrée et stock mis à jour.');
                    }
                } else { flash('error','Aucune quantité saisie.'); }
            }
            break;
        case 'user':
            requireAdmin();
            if ($act==='add') {
                $hash=password_hash(S($d,'password'),PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO users(username,password_hash,full_name,email,role)VALUES(?,?,?,?,?)")
                   ->execute([S($d,'username'),$hash,S($d,'full_name'),S($d,'email'),S($d,'role','user')]);
                flash('success','Utilisateur créé.');
            } elseif ($act==='edit') {
                if (!empty($d['password'])) {
                    $hash=password_hash(S($d,'password'),PASSWORD_BCRYPT);
                    $db->prepare("UPDATE users SET full_name=?,email=?,role=?,active=?,password_hash=? WHERE id=?")
                       ->execute([S($d,'full_name'),S($d,'email'),S($d,'role','user'),(int)IV($d,'active',1),$hash,$id]);
                } else {
                    $db->prepare("UPDATE users SET full_name=?,email=?,role=?,active=? WHERE id=?")
                       ->execute([S($d,'full_name'),S($d,'email'),S($d,'role','user'),(int)IV($d,'active',1),$id]);
                }
                flash('success','Utilisateur modifié.');
            } elseif ($act==='delete') {
                if ($id===(int)$user['id']) { flash('error','Impossible de supprimer son propre compte.'); break; }
                $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
                flash('success','Utilisateur supprimé.');
            }
            break;
    }
}

// Helpers
function S(array $d, string $k, string $def=''): string { return sanitize($d[$k] ?? $def); }
function IV(array $d, string $k, $def=0) { return $d[$k] ?? $def; }
function NV(array $d, string $k): ?string { $v=trim($d[$k]??''); return $v?:null; }
function msg(string $act, string $e): string {
    return match($act){
        'add'=>"✅ ".ucfirst($e)." ajouté(e) avec succès.",
        'edit'=>"✅ ".ucfirst($e)." modifié(e).",
        'delete'=>"🗑️ ".ucfirst($e)." supprimé(e).",
        default=>"OK"
    };
}
function assocCartridgesModel(PDO $db, int $pmid, array $cids): void {
    if (!$cids) return;
    $ins = $db->prepare("INSERT IGNORE INTO printer_model_cartridges(printer_model_id,cartridge_model_id)VALUES(?,?)");
    foreach ($cids as $c) { if ((int)$c>0) $ins->execute([$pmid,(int)$c]); }
}
function assocCartridges(PDO $db, int $pid, array $cids): void {
    if (!$cids) return;
    $ins=$db->prepare("INSERT IGNORE INTO printer_cartridges(printer_id,cartridge_model_id)VALUES(?,?)");
    foreach ($cids as $c) { if ((int)$c>0) $ins->execute([$pid,(int)$c]); }
}
// ─── PAGINATION HELPER ───────────────────────────────────────────────────
function paginate(array $items, int $perPage=25): array {
    $page   = max(1, (int)($_GET['p'] ?? 1));
    $total  = count($items);
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    return ['items'=>array_slice($items,$offset,$perPage),'page'=>$page,'pages'=>$pages,
            'total'=>$total,'perPage'=>$perPage,'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)];
}
function paginationHtml(array $pg): string {
    if ($pg['pages'] <= 1) return '';
    $q = $_GET; unset($q['p']); unset($q['open']); // exclure 'open' pour ne pas rouvrir le modal
    $base = '?' . http_build_query($q); $sep = $base==='?'?'':'&';
    $out = '<div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-top:1px solid var(--border);font-size:.82rem;color:var(--text3)">';
    $out .= '<span>'.$pg['from'].'–'.$pg['to'].' sur '.$pg['total'].'</span><div style="display:flex;gap:.35rem">';
    if ($pg['page'] > 1)
        $out .= '<a href="'.$base.$sep.'p='.($pg['page']-1).'" class="pg-btn">← Préc.</a>';
    for ($i=1; $i<=$pg['pages']; $i++) {
        if ($pg['pages']>7 && abs($i-$pg['page'])>2 && $i!==1 && $i!==$pg['pages']) {
            if ($i===2||$i===$pg['pages']-1) $out .= '<span style="padding:.3rem .4rem;color:var(--text3)">…</span>';
            continue;
        }
        $a = $i===$pg['page'];
        $out .= '<a href="'.$base.$sep.'p='.$i.'" class="pg-btn'.($a?' pg-btn-active':'').'">'.$i.'</a>';
    }
    if ($pg['page'] < $pg['pages'])
        $out .= '<a href="'.$base.$sep.'p='.($pg['page']+1).'" class="pg-btn">Suiv. →</a>';
    return $out.'</div></div>';
}

function logAct(PDO $db,int $uid,string $action,string $etype,int $eid,string $desc): void {
    try { $db->prepare("INSERT INTO activity_log(user_id,action,entity_type,entity_id,description,ip_address)VALUES(?,?,?,?,?,?)")
              ->execute([$uid,$action,$etype,$eid,$desc,$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
}
function statusBadge(string $s): string {
    $map=['active'=>['Actif','badge-success'],'inactive'=>['Inactif','badge-danger'],'maintenance'=>['Maintenance','badge-warning'],
          'pending'=>['En attente','badge-warning'],'partial'=>['Partiel','badge-info'],'fulfilled'=>['Traité','badge-success'],'cancelled'=>['Annulé','badge-danger']];
    [$label,$cls]=$map[$s]??[$s,'badge-muted'];
    return "<span class='badge $cls'>$label</span>";
}
function colorDot(string $color): string {
    $map=['Noir'=>'#111','Cyan'=>'#06b6d4','Magenta'=>'#d946ef','Jaune'=>'#eab308','Bleu'=>'#3b82f6','Rouge'=>'#ef4444','Vert'=>'#10b981'];
    $c=$map[$color]??'#888';
    return "<span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:$c;border:1px solid rgba(255,255,255,.2);vertical-align:middle;margin-right:5px'></span>$color";
}

// ─── MAIN LAYOUT ────────────────────────────────────────────
$navItems = [
    'dashboard'    => ['🏠','Tableau de bord',''],
    'printers'     => ['🖨️','Imprimantes',''],
    'cartridges'   => ['🖋️','Cartouches',''],
    'stock_in'     => ['📦','Entrées stock','stock'],
    'stock_out'    => ['📤','Sorties stock','stock'],
    'reservations' => ['📋','Demandes','stock'],
    'services'     => ['🏢','Services','admin2'],
    'suppliers'    => ['🏭','Fournisseurs','admin2'],
    'stats'        => ['📊','Statistiques',''],
    'admin'        => ['⚙️','Administration','admin'],
];
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

// ════════════════════════════════════════════════════════════
//  PAGE FUNCTIONS
// ════════════════════════════════════════════════════════════

function pageInkLevels(PDO $db): void {
    $printers = $db->query(
        "SELECT p.*, s.name as service_name FROM printers p
         LEFT JOIN services s ON p.service_id=s.id
         WHERE p.ip_address != '' AND p.ip_address IS NOT NULL AND p.status='active'
         ORDER BY s.name, p.brand, p.model"
    )->fetchAll();
    $snmpOk = function_exists('snmpget');
?>
<?php if(!$snmpOk): ?>
<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius);padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:flex-start;font-size:.88rem">
  <span style="font-size:1.5rem;flex-shrink:0">⚠️</span>
  <div>
    <strong style="color:var(--warning)">Extension PHP SNMP non activée — mode démonstration</strong><br>
    <span style="color:var(--text2)">Laragon : Menu → PHP → <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">php.ini</code> → chercher <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">;extension=snmp</code> → supprimer le <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">;</code> → Redémarrer Apache.</span>
  </div>
</div>
<?php endif; ?>

<?php if(empty($printers)): ?>
<div style="text-align:center;padding:4rem;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);color:var(--text3)">
  <div style="font-size:3rem;margin-bottom:.75rem">🖨️</div>
  <p>Aucune imprimante active avec adresse IP configurée.<br>
  <a href="index.php?page=printers" style="color:var(--primary)">Gérer le parc →</a></p>
</div>
<?php else: ?>

<!-- TOOLBAR -->
<div style="display:flex;align-items:center;gap:1rem;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <span style="font-size:.75rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">Communauté SNMP</span>
  <input type="text" id="snmp-community" value="public" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.45rem .85rem;color:var(--text);font-family:var(--font-mono);font-size:.85rem;width:130px">
  <button class="btn-primary" id="btn-scan-all" onclick="inkScanAll()" style="padding:.5rem 1.1rem;font-size:.85rem">↺ Scanner toutes</button>
  <button class="btn-secondary" onclick="inkResetAll()" style="padding:.5rem 1rem;font-size:.85rem">✕ Réinitialiser</button>
  <div style="flex:1"></div>
  <span id="ink-scan-status" style="font-size:.78rem;color:var(--text3);font-family:var(--font-mono)"></span>
</div>

<!-- LÉGENDE -->
<div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:center;margin-bottom:1.25rem;font-size:.78rem;color:var(--text2)">
  <span style="color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;font-size:.72rem">Niveaux :</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#059669,#10b981);display:inline-block"></span>OK (&gt; 25%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#d97706,#f59e0b);display:inline-block"></span>Faible (10–25%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#dc2626,#ef4444);display:inline-block"></span>Critique (&lt; 10%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:var(--text3);display:inline-block"></span>Inconnu</span>
</div>

<!-- TABLE -->
<div class="card">
<table class="data-table">
  <thead>
    <tr>
      <th style="width:14px"></th>
      <th>Imprimante</th>
      <th>Service</th>
      <th>Emplacement</th>
      <th>Adresse IP</th>
      <th>Pages imprimées</th>
      <th>Niveaux d'encre</th>
      <th style="text-align:center;width:50px">↺</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($printers as $p): ?>
  <tr>
    <td><span id="inkdot-<?=$p['id']?>" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3)"></span></td>
    <td>
      <strong><?=h($p['brand'].' '.$p['model'])?></strong>
      <a href="index.php?page=printer_view&id=<?=$p['id']?>" style="margin-left:.4rem;font-size:.75rem;color:var(--text3);text-decoration:none;transition:color .15s" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text3)'" title="Voir la fiche">↗</a>
    </td>
    <td class="muted"><?=h($p['service_name']??'–')?></td>
    <td class="muted"><?=h($p['location']??'–')?></td>
    <td><code class="ref"><?=h($p['ip_address'])?></code></td>
    <td id="inkpages-<?=$p['id']?>" style="color:var(--text3);font-family:var(--font-mono);font-size:.78rem">–</td>
    <td id="inkcell-<?=$p['id']?>" style="min-width:200px">
      <span style="font-size:.78rem;color:var(--text3);display:flex;align-items:center;gap:.4rem">💤 non scanné</span>
    </td>
    <td style="text-align:center">
      <button id="inkbtn-<?=$p['id']?>" onclick="inkScanOne(<?=$p['id']?>)" title="Scanner" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1rem;padding:.25rem .4rem;border-radius:4px;transition:all .15s" onmouseover="this.style.background='var(--primary-dim)';this.style.color='var(--primary)'" onmouseout="this.style.background='none';this.style.color='var(--text3)'">↺</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>

<style>
.ink-list{display:flex;flex-direction:column;gap:5px}
.ink-row{display:flex;align-items:center;gap:7px}
.ink-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.ink-track{flex:1;height:6px;background:var(--bg3);border-radius:99px;overflow:hidden;min-width:80px}
.ink-fill{height:100%;border-radius:99px;transition:width .9s cubic-bezier(.4,0,.2,1)}
.ink-pct{font-family:var(--font-mono);font-size:.72rem;font-weight:600;min-width:32px;text-align:right}
.spin-xs{width:12px;height:12px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<script>
const INK_DEMO = <?=$snmpOk?'false':'true'?>;
const INK_IDS  = <?=json_encode(array_column($printers,'id'))?>;
let   inkBusy  = false;

function inkRnd(a,b){return Math.floor(Math.random()*(b-a)+a);}
function inkEsc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function inkDemoData(){
  return {reachable:true,demo:true,status:3,
    pages_total:inkRnd(500,80000),
    supplies:[
      {description:'Black',   percent:inkRnd(5,90), color:['','#e2e8f0','']},
      {description:'Cyan',    percent:inkRnd(5,95), color:['','#67e8f9','']},
      {description:'Magenta', percent:inkRnd(5,80), color:['','#f0abfc','']},
      {description:'Yellow',  percent:inkRnd(5,85), color:['','#fde68a','']},
    ]};
}

async function inkScanOne(pid) {
  const btn  = document.getElementById('inkbtn-'+pid);
  const dot  = document.getElementById('inkdot-'+pid);
  const cell = document.getElementById('inkcell-'+pid);
  const pages= document.getElementById('inkpages-'+pid);

  if(btn){btn.disabled=true;btn.textContent='…';}
  dot.style.cssText='display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3);animation:pulse 1s infinite';
  cell.innerHTML='<span style="font-size:.78rem;color:var(--text2);display:flex;align-items:center;gap:.4rem"><span class="spin-xs"></span>scan…</span>';

  let data;
  if(INK_DEMO){
    await new Promise(r=>setTimeout(r,500+Math.random()*700));
    data=inkDemoData();
  } else {
    const com=document.getElementById('snmp-community').value||'public';
    try{
      const r=await fetch(`index.php?ajax_snmp=1&printer_id=${pid}&community=${encodeURIComponent(com)}`);
      data=await r.json();
    }catch(e){
      cell.innerHTML='<span style="font-size:.78rem;color:var(--danger)">❌ erreur réseau</span>';
      dot.style.background='var(--danger)';dot.style.animation='';
      if(btn){btn.disabled=false;btn.textContent='↺';}
      return;
    }
  }

  // Dot statut
  if(!data.reachable||data.error==='unreachable'){
    dot.style.background='var(--danger)';dot.style.animation='';
    cell.innerHTML='<span style="font-size:.78rem;color:var(--danger)">🔴 inaccessible</span>';
    pages.textContent='–';
    if(btn){btn.disabled=false;btn.textContent='↺';}
    return;
  }
  const dotColors={3:'#10b981',4:'#94a3b8',5:'#f59e0b',6:'#ef4444'};
  dot.style.background=dotColors[data.status]||'#94a3b8';
  dot.style.animation='';
  dot.style.boxShadow=data.status===3?'0 0 6px rgba(16,185,129,.5)':'';

  if(data.pages_total) pages.textContent=data.pages_total.toLocaleString('fr-FR')+(data.demo?' ⚠️':'');
  else pages.textContent='–';

  if(!data.supplies||!data.supplies.length){
    cell.innerHTML='<span style="font-size:.78rem;color:var(--text3)">Aucune donnée SNMP</span>';
    if(btn){btn.disabled=false;btn.textContent='↺';}
    return;
  }

  const bars=data.supplies.map(s=>{
    const pct=s.percent, fg=s.color[1]||'#94a3b8';
    let grad,col,lbl;
    if(pct<0)      {grad='var(--text3)';                           col='var(--text3)';lbl='?';}
    else if(pct<10){grad='linear-gradient(90deg,#dc2626,#ef4444)'; col='#ef4444';    lbl=pct+'%';}
    else if(pct<25){grad='linear-gradient(90deg,#d97706,#f59e0b)'; col='#f59e0b';    lbl=pct+'%';}
    else           {grad='linear-gradient(90deg,#059669,#10b981)'; col='#10b981';    lbl=pct+'%';}
    const w=pct<0?2:Math.max(2,pct);
    return `<div class="ink-row"><div class="ink-dot" style="background:${fg}"></div><div class="ink-track"><div class="ink-fill" data-w="${w}%" style="width:0%;background:${grad}"></div></div><span class="ink-pct" style="color:${col}">${lbl}</span></div>`;
  }).join('');

  cell.innerHTML=`<div class="ink-list">${bars}</div>`;
  requestAnimationFrame(()=>cell.querySelectorAll('[data-w]').forEach(el=>el.style.width=el.dataset.w));
  if(btn){btn.disabled=false;btn.textContent='↺';btn.title='Actualiser';}
}

async function inkScanAll(){
  if(inkBusy)return; inkBusy=true;
  const btn=document.getElementById('btn-scan-all');
  const status=document.getElementById('ink-scan-status');
  btn.disabled=true; let done=0;
  for(let i=0;i<INK_IDS.length;i+=3){
    await Promise.all(INK_IDS.slice(i,i+3).map(id=>inkScanOne(id)));
    done+=Math.min(3,INK_IDS.length-i);
    status.textContent=`${Math.min(done,INK_IDS.length)} / ${INK_IDS.length}`;
  }
  btn.disabled=false; btn.textContent='↺ Tout rescanner';
  status.textContent=`✅ terminé · ${new Date().toLocaleTimeString('fr-FR')}`;
  inkBusy=false;
}

function inkResetAll(){
  INK_IDS.forEach(pid=>{
    document.getElementById('inkdot-'+pid).style.cssText='display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3)';
    document.getElementById('inkcell-'+pid).innerHTML='<span style="font-size:.78rem;color:var(--text3);display:flex;align-items:center;gap:.4rem">💤 non scanné</span>';
    document.getElementById('inkpages-'+pid).textContent='–';
    const b=document.getElementById('inkbtn-'+pid); if(b){b.disabled=false;b.textContent='↺';}
  });
  document.getElementById('ink-scan-status').textContent='';
  document.getElementById('btn-scan-all').textContent='↺ Scanner toutes';
}

<?php if($snmpOk && !empty($printers)): ?>
window.addEventListener('DOMContentLoaded',()=>setTimeout(inkScanAll,400));
<?php endif; ?>
</script>
<?php
}

function pageDashboard(PDO $db, array $d): void {
    $alerts = $db->query("SELECT cm.id, cm.brand,cm.model,cm.color,cm.alert_threshold,COALESCE(s.quantity_available,0) as qty FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE COALESCE(s.quantity_available,0) <= cm.alert_threshold ORDER BY qty ASC LIMIT 5")->fetchAll();
    $recent = $db->query("SELECT al.*, u.full_name, u.username FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();
    // Historique (sorties + entrées) pour la recherche dashboard — désormais via AJAX
    $recentExits = [];
    $last10exits = $db->query(
        "SELECT se.exit_date, se.quantity, se.person_name,
         cm.brand, cm.model, cm.color,
         COALESCE(sv.name,'–') as service_name,
         u.full_name as user_name,
         CONCAT(COALESCE(p.brand,''),' ',COALESCE(p.model,'')) as printer_name,
         COALESCE(p.location,'') as printer_location
         FROM stock_exits se
         JOIN cartridge_models cm ON se.cartridge_model_id = cm.id
         LEFT JOIN services sv ON se.service_id = sv.id
         LEFT JOIN users u ON se.created_by = u.id
         LEFT JOIN printers p ON se.printer_id = p.id
         ORDER BY se.exit_date DESC, se.id DESC
         LIMIT 10"
    )->fetchAll();
    $pendingOrders = 0;
    try { $pendingOrders = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','partial')")->fetchColumn(); } catch(Exception $e){}
    // Demandes en attente avec détail
    $pendingDemands = [];
    try {
        $pendingDemands = $db->query(
            "SELECT r.id, r.quantity_requested, r.quantity_fulfilled, r.requested_date,
             cm.brand, cm.model, cm.color,
             COALESCE(sv.name,'Sans service') as service_name,
             COALESCE(s.quantity_available,0) as qty_avail,
             r.status
             FROM reservations r
             JOIN cartridge_models cm ON r.cartridge_model_id = cm.id
             LEFT JOIN services sv ON r.service_id = sv.id
             LEFT JOIN stock s ON s.cartridge_model_id = cm.id
             WHERE r.status IN ('pending','partial')
             ORDER BY r.requested_date ASC
             LIMIT 8"
        )->fetchAll();
    } catch(Exception $e) {}
?>
<div class="dashboard-grid">

  <!-- Barre de recherche + raccourcis (même bloc = pas de gap entre eux) -->
  <div style="display:flex;flex-direction:column;gap:1rem">
    <div class="search-bar-wrap" style="margin-bottom:0">
      <div class="search-bar">
        <span class="search-bar-icon">🔍</span>
        <input type="text" id="dash-search" placeholder="Rechercher par service, cartouche ou personne…" oninput="dashSearch(this)" autocomplete="off">
        <button class="search-bar-clear" id="dash-clear" onclick="dashClear()">✕</button>
      </div>
      <div class="search-count" id="dash-count"></div>
    </div>
    <div id="dash-results" style="display:none">
      <div class="card">
        <div class="card-header"><span class="card-title" id="dash-res-title">Résultats</span></div>
        <table class="data-table">
          <thead><tr><th>Type</th><th>Date / Lien</th><th>Élément</th><th>Service / Type</th><th>Détail</th><th>Stock / Qté</th></tr></thead>
          <tbody id="dash-res-body"></tbody>
        </table>
      </div>
    </div>

    <!-- Boutons raccourcis -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
      <a href="index.php?page=stock_out&open=modal-add" class="shortcut-btn shortcut-out">
        <span class="shortcut-icon">📤</span>
        <span class="shortcut-label">Sortie de stock</span>
        <span class="shortcut-sub">Remettre une cartouche</span>
      </a>
      <a href="index.php?page=stock_in&open=modal-add" class="shortcut-btn shortcut-in">
        <span class="shortcut-icon">📦</span>
        <span class="shortcut-label">Entrée de stock</span>
        <span class="shortcut-sub">Réceptionner des cartouches</span>
      </a>
      <a href="index.php?page=orders" class="shortcut-btn shortcut-order">
        <span class="shortcut-icon">🛒</span>
        <span class="shortcut-label">Nouvelle commande</span>
        <span class="shortcut-sub">Commander des cartouches</span>
      </a>
      <a href="index.php?page=reservations" class="shortcut-btn shortcut-resa<?=$d['pending_demands']>0?' shortcut-resa-urgent':''?>">
        <span class="shortcut-icon">📌</span>
        <span class="shortcut-label">Demandes
          <?php if($d['pending_demands']>0): ?>
          <span style="background:#f59e0b;color:#000;border-radius:99px;padding:.05rem .45rem;font-size:.72rem;font-weight:800;margin-left:.35rem;vertical-align:middle"><?=$d['pending_demands']?></span>
          <?php endif ?>
        </span>
        <span class="shortcut-sub"><?=$d['pending_demands']>0?$d['pending_demands'].' en attente de traitement':'Aucune demande active'?></span>
      </a>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="kpi-row">
    <a href="index.php?page=printers" class="kpi-card kpi-blue" style="text-decoration:none">
      <div class="kpi-icon">🖨️</div>
      <div class="kpi-info"><span class="kpi-val"><?=h($d['printers_total'])?></span><span class="kpi-label">Imprimantes</span></div>
      <div class="kpi-sub"><?=h($d['printers_active'])?> actives</div>
    </a>
    <a href="index.php?page=cartridges" class="kpi-card kpi-violet" style="text-decoration:none">
      <div class="kpi-icon">🖋️</div>
      <div class="kpi-info"><span class="kpi-val"><?=h($d['cartridge_models'])?></span><span class="kpi-label">Modèles cartouche</span></div>
    </a>
    <a href="index.php?page=orders" class="kpi-card <?=($pendingOrders>0)?'kpi-orange':'kpi-teal'?>" style="text-decoration:none">
      <div class="kpi-icon">🛒</div>
      <div class="kpi-info"><span class="kpi-val"><?=$pendingOrders?></span><span class="kpi-label">Commandes en cours</span></div>
    </a>
    <a href="index.php?page=cartridges" class="kpi-card <?=($d['low_stock']>0)?'kpi-amber':'kpi-green'?>" style="text-decoration:none">
      <div class="kpi-icon">📦</div>
      <div class="kpi-info"><span class="kpi-val"><?=h($d['stock_total'])?></span><span class="kpi-label">Unités en stock</span></div>
      <div class="kpi-sub"><?=$d['low_stock']>0?"⚠️ {$d['low_stock']} alerte(s)":'✅ Stock OK'?></div>
    </a>
  </div>

  <div class="dash-row">
    <div class="card dash-chart">
      <div class="card-header">
        <span class="card-title">📤 Dernières sorties de cartouches</span>
        <a href="index.php?page=stock_out" style="font-size:.78rem;color:var(--primary);text-decoration:none;font-weight:600">Voir tout →</a>
      </div>
      <?php if(empty($last10exits)): ?>
      <div class="empty-mini">Aucune sortie enregistrée</div>
      <?php else: ?>
      <div>
        <?php foreach($last10exits as $e):
          $colorMap = ['Noir'=>'#e2e8f0','Cyan'=>'#67e8f9','Magenta'=>'#f0abfc','Jaune'=>'#fde68a','Bleu'=>'#38bdf8','Rouge'=>'#ef4444','Vert'=>'#10b981'];
          $dot = $colorMap[$e['color']] ?? '#94a3b8';
        ?>
        <div style="display:flex;align-items:center;gap:.85rem;padding:.75rem 1.25rem;border-bottom:1px solid var(--border);transition:background .15s" onmouseover="this.style.background='rgba(0,0,0,.02)'" onmouseout="this.style.background=''">
          <div style="width:10px;height:10px;border-radius:50%;background:<?=$dot?>;flex-shrink:0;border:1px solid rgba(0,0,0,.1)"></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.88rem;font-weight:700;color:var(--text)"><?=h($e['brand'].' '.$e['model'])?></div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-top:.2rem;flex-wrap:wrap">
              <?php if($e['service_name'] !== '–'): ?>
              <span style="font-size:.75rem;color:var(--text2);background:var(--card2);border:1px solid var(--border);border-radius:4px;padding:.05rem .45rem"><?=h($e['service_name'])?></span>
              <?php endif ?>
              <?php $pname = trim($e['printer_name'] ?? ''); if($pname): ?>
              <span style="font-size:.75rem;color:var(--text3)">🖨️ <?=h($pname)?><?=($e['printer_location'] ?? '') ? ' <span style="opacity:.7">('.h($e['printer_location']).')</span>' : ''?></span>
              <?php endif ?>
              <?php if($e['person_name']): ?>
              <span style="font-size:.75rem;color:var(--text3)" title="Récupérée par">📥 <?=h($e['person_name'])?></span>
              <?php endif ?>
              <?php if($e['user_name'] && $e['user_name'] !== $e['person_name']): ?>
              <span style="font-size:.75rem;color:var(--text3)" title="Délivré par">🖊️ <?=h($e['user_name'])?></span>
              <?php endif ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <span class="stock-pill stock-pill-out" style="font-size:.82rem">-<?=h($e['quantity'])?> <?=$e['quantity']>1?'cartouches':'cartouche'?></span>
            <div style="font-size:.72rem;color:var(--text3);margin-top:.3rem"><?=date('d/m/Y',strtotime($e['exit_date']))?></div>
          </div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>
    <div class="card dash-alerts">
      <div class="card-header"><span class="card-title">⚠️ Alertes stock bas</span></div>
      <?php if (empty($alerts)): ?>
        <div class="empty-mini">✅ Tous les stocks sont suffisants</div>
      <?php else: foreach($alerts as $a):
        $alertBg = $a['qty'] <= 1 ? 'rgba(239,68,68,.12)' : 'rgba(245,158,11,.08)';
        $alertBorder = $a['qty'] <= 1 ? 'rgba(239,68,68,.35)' : 'rgba(245,158,11,.25)';
        $alertIcon = $a['qty'] === 0 ? '🔴' : ($a['qty'] <= 1 ? '🔴' : '🟠');
      ?>
        <a href="index.php?page=cartridges#cartridge-<?=$a['id']?>" style="text-decoration:none;display:block">
        <div class="alert-item" style="background:<?=$alertBg?>;border-left:3px solid <?=$alertBorder?>;transition:filter .15s" onmouseover="this.style.filter='brightness(1.15)'" onmouseout="this.style.filter=''">
          <div class="alert-left">
            <span style="font-size:1rem"><?=$alertIcon?></span>
            <div>
              <div class="alert-name"><?=h($a['brand'].' '.$a['model'])?></div>
              <div class="alert-thresh" style="color:var(--text3)">
                <span style="background:rgba(255,255,255,.07);padding:.1rem .45rem;border-radius:4px;font-size:.72rem"><?=h($a['color'])?></span>
                &nbsp;Seuil : <?=h($a['alert_threshold'])?>
              </div>
            </div>
          </div>
          <span class="stock-badge <?=($a['qty']===0)?'stock-empty':'stock-low'?>"><?=h($a['qty'])?></span>
        </div>
        </a>
      <?php endforeach; endif; ?>
      <a href="index.php?page=stock_in" class="btn-link">+ Ajouter du stock</a>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">🕐 Activité récente</span></div>
    <div class="activity-list">
      <?php if(empty($recent)): ?>
        <div class="empty-mini">Aucune activité enregistrée</div>
      <?php else: foreach($recent as $r):
        $icons=['login'=>'🔑','logout'=>'🚪','stock_in'=>'📦','stock_out'=>'📤','order_create'=>'🛒','order_receive'=>'✅'];
        $ic=$icons[$r['action']]??'📌'; ?>
        <div class="activity-item">
          <div class="activity-icon"><?=$ic?></div>
          <div class="activity-info">
            <span class="activity-desc"><?php
              $desc = $r['description'] ?? $r['action'];
              // Remplacer les anciens "X u." par "X cartouche(s)"
              $desc = preg_replace_callback('/(\d+)\s+u\./', function($m) {
                  $n = (int)$m[1];
                  return $n . ' ' . ($n > 1 ? 'cartouches' : 'cartouche');
              }, $desc);
              echo h($desc);
            ?></span>
            <span class="activity-user"><?=h($r['full_name']??$r['username']??'Système')?></span>
          </div>
          <span class="activity-time"><?=date('d/m H:i',strtotime($r['created_at']))?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Panneau demandes en attente -->
  <?php if(!empty($pendingDemands) || $d['pending_demands'] > 0): ?>
  <div class="card" style="border-color:rgba(245,158,11,.25)">
    <div class="card-header" style="border-bottom-color:rgba(245,158,11,.15)">
      <span class="card-title">📌 Demandes en attente</span>
      <a href="index.php?page=reservations" style="font-size:.78rem;color:var(--primary);text-decoration:none;font-weight:600">Voir tout →</a>
    </div>
    <?php if(empty($pendingDemands)): ?>
      <div class="empty-mini">Aucune demande active</div>
    <?php else: foreach($pendingDemands as $dem):
      $remain = $dem['quantity_requested'] - $dem['quantity_fulfilled'];
      $hasStock = $dem['qty_avail'] >= $remain;
      $daysAgo = (int)round((time() - strtotime($dem['requested_date'])) / 86400);
    ?>
    <a href="index.php?page=reservations" style="text-decoration:none;display:block">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid var(--border);gap:.75rem;transition:filter .15s" onmouseover="this.style.filter='brightness(1.12)'" onmouseout="this.style.filter=''">
      <div style="display:flex;align-items:center;gap:.65rem;min-width:0">
        <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;background:<?=$hasStock?'#10b981':'#f59e0b'?>;box-shadow:0 0 6px <?=$hasStock?'rgba(16,185,129,.5)':'rgba(245,158,11,.5)'?>"></div>
        <div style="min-width:0">
          <div style="font-size:.85rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($dem['brand'].' '.$dem['model'])?> <span style="font-size:.72rem;font-weight:400;color:var(--text3)">(<?=h($dem['color'])?>)</span></div>
          <div style="font-size:.75rem;color:var(--text3);margin-top:.1rem"><?=h($dem['service_name'])?> · il y a <?=$daysAgo?> j.</div>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:.85rem;font-weight:700;color:#f59e0b">×<?=$remain?></div>
        <div style="font-size:.7rem;margin-top:.1rem;color:<?=$hasStock?'#10b981':'#ef4444'?>"><?=$hasStock?'✅ stock dispo':'⚠️ rupture'?></div>
      </div>
    </div>
    </a>
    <?php endforeach; ?>
    <a href="index.php?page=orders" class="btn-link" style="color:#f59e0b">🛒 Créer une commande</a>
    <?php endif ?>
  </div>
  <?php endif ?>

</div>

<style>
.shortcut-btn {
  display:flex;flex-direction:column;gap:.35rem;padding:1.25rem 1.5rem;
  border-radius:var(--radius);border:1px solid var(--border);
  text-decoration:none;transition:all .2s;position:relative;overflow:hidden;
}
.shortcut-btn::before{content:'';position:absolute;inset:0;opacity:.06;transition:opacity .2s}
.shortcut-btn:hover{transform:translateY(-2px);border-color:var(--border2)}
.shortcut-btn:hover::before{opacity:.12}
.shortcut-in   {background:rgba(16,185,129,.08); border-color:rgba(16,185,129,.2)} .shortcut-in::before{background:#10b981}
.shortcut-out  {background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.2)} .shortcut-out::before{background:#f59e0b}
.shortcut-order{background:rgba(67,97,238,.08);  border-color:rgba(67,97,238,.2)}  .shortcut-order::before{background:#4361ee}
.shortcut-resa {background:rgba(56,189,248,.08); border-color:rgba(56,189,248,.2)} .shortcut-resa::before{background:#38bdf8}
.shortcut-resa-urgent {background:rgba(245,158,11,.1); border-color:rgba(245,158,11,.4); animation:pulse-border 2s ease-in-out infinite}
.shortcut-resa-urgent::before{background:#f59e0b}
@keyframes pulse-border { 0%,100%{border-color:rgba(245,158,11,.4)} 50%{border-color:rgba(245,158,11,.85)} }
.shortcut-icon {font-size:1.6rem;line-height:1}
.shortcut-label{font-family:var(--font-display);font-weight:700;font-size:.95rem;color:var(--text)}
.shortcut-sub  {font-size:.75rem;color:var(--text3)}
</style>

<script>
// ── Recherche dashboard — AJAX (toutes les données, pas de limite JS) ──
let dashTimer = null;

function dashSearch(inp) {
    const q = inp.value.trim();
    const clear  = document.getElementById('dash-clear');
    const resDiv = document.getElementById('dash-results');
    const count  = document.getElementById('dash-count');
    const body   = document.getElementById('dash-res-body');
    const title  = document.getElementById('dash-res-title');

    clear.style.display = q ? 'block' : 'none';

    if (!q) {
        resDiv.style.display = 'none';
        count.textContent = '';
        clearTimeout(dashTimer);
        return;
    }

    count.textContent = '🔍 Recherche…';
    clearTimeout(dashTimer);
    dashTimer = setTimeout(async function() {
        try {
            const resp = await fetch('index.php?ajax_dash_search=1&q=' + encodeURIComponent(q));
            const rows = await resp.json();

            if (!rows || rows.error) {
                count.textContent = 'Erreur : ' + (rows?.error || 'inconnue');
                return;
            }
            if (!rows.length) {
                resDiv.style.display = 'none';
                count.textContent = 'Aucun résultat pour "' + q + '".';
                return;
            }

            title.textContent = 'Résultats (' + rows.length + ')';
            count.textContent = rows.length + ' résultat(s) trouvé(s)';

            const esc = function(s) {
                return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;');
            };
            body.innerHTML = rows.map(function(e) {
                var badge, pill, col2, col3, col4, col5;
                const date = (e.op_date || '').substring(0, 10);

                if (e.op_type === 'sortie') {
                    badge = '<span class="badge badge-warning">📤 Sortie</span>';
                    pill  = '<span class="stock-pill stock-pill-out">-' + esc(e.quantity) + '</span>';
                    col2  = esc(date);
                    col3  = '<strong>' + esc(e.brand + ' ' + e.model) + '</strong> <small style="color:var(--text3)">' + esc(e.color) + '</small>';
                    col4  = esc(e.ctx_name);
                    var pinfo = (e.printer_name||'').trim();
                    var ploc  = (e.detail||'').trim();
                    col5  = (pinfo ? '🖨️ ' + esc(pinfo) : '–')
                           + (ploc ? ' <small style="color:var(--text3)">(' + esc(ploc) + ')</small>' : '')
                           + (e.ref_name ? ' · <span style="color:var(--text3)">' + esc(e.ref_name) + '</span>' : '');
                } else if (e.op_type === 'entree') {
                    badge = '<span class="badge badge-success">📦 Entrée</span>';
                    pill  = '<span class="stock-pill stock-pill-ok">+' + esc(e.quantity) + '</span>';
                    col2  = esc(date);
                    col3  = '<strong>' + esc(e.brand + ' ' + e.model) + '</strong> <small style="color:var(--text3)">' + esc(e.color) + '</small>';
                    col4  = esc(e.ctx_name);
                    col5  = esc(e.ref_name || '–');
                } else if (e.op_type === 'imprimante') {
                    badge = '<span class="badge badge-info">🖨️ Imprimante</span>';
                    pill  = '';
                    col2  = '<a href="index.php?page=printer_view&id=' + e.entity_id + '" style="color:var(--primary);text-decoration:none;font-size:.8rem">Voir la fiche →</a>';
                    col3  = '<strong>' + esc(e.brand + ' ' + e.model) + '</strong>';
                    col4  = esc(e.ctx_name || '–');
                    col5  = esc(e.detail || '') + (e.ref_name ? ' · <small style="color:var(--text3)">S/N: ' + esc(e.ref_name) + '</small>' : '');
                } else { // cartouche
                    badge = '<span class="badge badge-muted">🖋️ Cartouche</span>';
                    pill  = '<span class="stock-pill ' + (parseInt(e.quantity) > 0 ? 'stock-pill-ok' : 'stock-pill-low') + '">' + esc(e.quantity) + ' en stock</span>';
                    col2  = '<a href="index.php?page=stock_out&open=modal-add&prefill_cid=' + e.entity_id + '" style="color:var(--primary);text-decoration:none;font-size:.8rem">Enregistrer une sortie →</a>';
                    col3  = '<strong>' + esc(e.brand + ' ' + e.model) + '</strong> <small style="color:var(--text3)">' + esc(e.color) + '</small>';
                    col4  = esc(e.detail || '–');
                    col5  = esc(e.ref_name || '–');
                }

                return '<tr>'
                    + '<td>' + badge + '</td>'
                    + '<td style="font-size:.82rem">' + col2 + '</td>'
                    + '<td>' + col3 + '</td>'
                    + '<td style="font-size:.82rem">' + col4 + '</td>'
                    + '<td style="font-size:.82rem">' + col5 + '</td>'
                    + '<td>' + pill + '</td>'
                    + '</tr>';
            }).join('');
            resDiv.style.display = 'block';
        } catch(err) {
            count.textContent = 'Erreur réseau.';
        }
    }, 300);
}

function dashClear() {
    const inp = document.getElementById('dash-search');
    inp.value = ''; dashSearch(inp); inp.focus();
}
</script>

<?php
}

function pageOrders(PDO $db): void {
    $showArchived = isset($_GET['archived']);
    $orders = [];
    try {
        $statusFilter = $showArchived ? "po.status IN ('received','cancelled')" : "po.status IN ('pending','partial')";
        $orders = $db->query(
            "SELECT po.*, sp.name as supplier_name, u.full_name as user_name,
             COUNT(pol.id) as line_count,
             SUM(pol.quantity_ordered) as qty_total,
             SUM(pol.quantity_received) as qty_received
             FROM purchase_orders po
             LEFT JOIN suppliers sp ON po.supplier_id=sp.id
             LEFT JOIN users u ON po.created_by=u.id
             LEFT JOIN purchase_order_lines pol ON pol.order_id=po.id
             WHERE $statusFilter
             GROUP BY po.id ORDER BY po.order_date DESC"
        )->fetchAll();
    } catch(Exception $e) {}
    // Comptage des archivées
    $archivedCount = 0;
    $activeCount = 0;
    try {
        $archivedCount = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('received','cancelled')")->fetchColumn();
        $activeCount   = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','partial')")->fetchColumn();
    } catch(Exception $e) {}
    $suppliers   = $db->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
    $cartridges  = $db->query("SELECT id,brand,model,color,unit_price FROM cartridge_models WHERE active=1 OR active IS NULL ORDER BY brand,model")->fetchAll();
    // Demandes en attente groupées par cartouche
    $pendingDemands = [];
    try {
        $pendingDemands = $db->query(
            "SELECT r.cartridge_model_id, cm.brand, cm.model, cm.color,
             COALESCE(cm.unit_price,0) as unit_price,
             SUM(r.quantity_requested - r.quantity_fulfilled) as qty_needed,
             GROUP_CONCAT(DISTINCT COALESCE(sv.name,'Sans service') ORDER BY sv.name SEPARATOR ', ') as services
             FROM reservations r
             JOIN cartridge_models cm ON r.cartridge_model_id = cm.id
             LEFT JOIN services sv ON r.service_id = sv.id
             WHERE r.status IN ('pending','partial')
             GROUP BY r.cartridge_model_id, cm.brand, cm.model, cm.color, cm.unit_price
             ORDER BY qty_needed DESC"
        )->fetchAll();
    } catch(Exception $e) {}
?>
<div class="page-header">
  <span class="page-title-txt">🛒 Commandes de Cartouches</span>
  <div style="display:flex;gap:.6rem;align-items:center">
    <a href="?page=orders<?=$showArchived?'':'&archived=1'?>"
       style="padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .15s;<?=$showArchived?'background:var(--primary);color:#fff':'background:var(--card2);color:var(--text2);border:1px solid var(--border)'?>">
      🗄️ Archivées (<?=$archivedCount?>)
    </a>
    <?php if(!$showArchived): ?>
    <button class="btn-primary" onclick="openModal('modal-add')">+ Nouvelle commande</button>
    <?php endif ?>
  </div>
</div>

<?php if($showArchived): ?>
<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#f59e0b">
  🗄️ Affichage des commandes reçues et annulées. <a href="?page=orders" style="color:var(--primary);text-decoration:underline">← Retour aux commandes en cours</a>
</div>
<?php endif ?>

<?php if(empty($orders)): ?>
<div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
  <div style="font-size:3rem;margin-bottom:.75rem">🛒</div>
  <p>Aucune commande enregistrée.<br>Cliquez sur <strong style="color:var(--text)">+ Nouvelle commande</strong> pour démarrer.</p>
</div>
<?php else: ?>
<div class="card">
<table class="data-table">
  <thead><tr><th>N° Commande</th><th>Date</th><th>Fournisseur</th><th>Lignes</th><th>Qté commandée</th><th>Qté reçue</th><th>Dem.</th><th>Statut</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($orders as $o):
    $pct = $o['qty_total']>0 ? round($o['qty_received']/$o['qty_total']*100) : 0;
  ?>
  <tr>
    <td><a href="index.php?page=order_view&id=<?=$o['id']?>" style="color:var(--primary);text-decoration:none;font-weight:600;font-family:var(--font-mono)">#<?=str_pad($o['id'],4,'0',STR_PAD_LEFT)?></a></td>
    <td><?=date('d/m/Y',strtotime($o['order_date']))?><?=$o['expected_date']?'<br><small class="muted">Prévu : '.date('d/m/Y',strtotime($o['expected_date'])).'</small>':''?></td>
    <td><?=h($o['supplier_name']??'–')?></td>
    <td style="text-align:center"><span class="badge badge-muted"><?=$o['line_count']?> art.</span></td>
    <td style="font-family:var(--font-mono)"><?=$o['qty_total']?:0?></td>
    <td>
      <div style="display:flex;align-items:center;gap:.6rem">
        <div style="flex:1;height:6px;background:var(--bg3);border-radius:99px;overflow:hidden;min-width:60px">
          <div style="height:100%;border-radius:99px;background:<?=$pct>=100?'linear-gradient(90deg,#059669,#10b981)':($pct>0?'linear-gradient(90deg,#d97706,#f59e0b)':'var(--text3)')?>;width:<?=$pct?>%"></div>
        </div>
        <span style="font-family:var(--font-mono);font-size:.78rem;color:var(--text2)"><?=$o['qty_received']?:0?> / <?=$o['qty_total']?:0?></span>
      </div>
    </td>
    <td><?php
      $oDemands = 0;
      try { $dst=$db->prepare("SELECT COUNT(DISTINCT r.cartridge_model_id) FROM reservations r JOIN purchase_order_lines pol ON pol.cartridge_model_id=r.cartridge_model_id WHERE pol.order_id=? AND r.status IN ('pending','partial')"); $dst->execute([$o['id']]); $oDemands=(int)$dst->fetchColumn(); } catch(Exception $e){}
    ?><?=$oDemands>0?"<span title='$oDemands cartouche(s) avec demandes' style='background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.15rem .5rem;font-size:.72rem;font-weight:700;color:#f59e0b'>📌 $oDemands</span>":'<span style="color:var(--text3);font-size:.78rem">–</span>'?></td>
    <td><?=orderStatusBadge($o['status'])?></td>
    <td class="actions">
      <a href="index.php?page=order_view&id=<?=$o['id']?>" class="btn-icon" title="Voir / Réceptionner">📬</a>
      <?php if(in_array($o['status'],['pending','partial'])): ?>
      <button class="btn-icon btn-edit" title="Modifier" onclick="openEditOrder(<?=htmlspecialchars(json_encode($o, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),ENT_QUOTES)?>)">✏️</button>
      <form method="post" style="display:inline"><input type="hidden" name="_entity" value="order"><input type="hidden" name="_action" value="cancel"><input type="hidden" name="_id" value="<?=$o['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Annuler cette commande ?')" title="Annuler">✕</button></form>
      <?php endif ?>
      <?php if(in_array($o['status'],['received','cancelled'])): ?>
      <form method="post" style="display:inline"><input type="hidden" name="_entity" value="order"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$o['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Supprimer cette commande ?')" title="Supprimer">🗑️</button></form>
      <?php endif ?>
    </td>
  </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php endif ?>

<!-- Modal nouvelle commande -->
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-xl">
    <div class="modal-header"><h3>🛒 Nouvelle commande</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
    <form method="post">
      <input type="hidden" name="_entity" value="order"><input type="hidden" name="_action" value="add">
      <div class="form-grid">
        <div class="form-group"><label>Fournisseur</label>
          <select name="supplier_id"><option value="">-- Aucun --</option>
          <?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach?>
          </select></div>
        <div class="form-group"><label>Date de commande *</label><input type="date" name="order_date" value="<?=date('Y-m-d')?>" required></div>
        <div class="form-group"><label>Date de livraison prévue</label><input type="date" name="expected_date"></div>
        <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Référence devis, conditions..."></textarea></div>
      </div>

      <!-- Demandes en attente -->
      <?php if(!empty($pendingDemands)): ?>
      <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm);padding:.85rem 1.1rem;margin-top:1.25rem;margin-bottom:.75rem">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
          <div style="font-size:.85rem;font-weight:700;color:#f59e0b">📌 <?=count($pendingDemands)?> cartouche(s) avec demandes en attente</div>
          <button type="button" onclick="importDemands()" style="background:rgba(245,158,11,.2);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.35rem .85rem;font-size:.78rem;font-weight:700;color:#f59e0b;cursor:pointer;transition:all .15s" onmouseover="this.style.background='rgba(245,158,11,.35)'" onmouseout="this.style.background='rgba(245,158,11,.2)'">⬇️ Importer les demandes</button>
        </div>
        <div style="margin-top:.6rem;display:flex;flex-wrap:wrap;gap:.4rem">
          <?php foreach($pendingDemands as $dem): ?>
          <span style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:4px;padding:.15rem .5rem;font-size:.75rem;color:#f59e0b">
            <?=colorDot($dem['color'])?> <?=h($dem['brand'].' '.$dem['model'])?> <strong>×<?=$dem['qty_needed']?></strong>
            <span style="color:var(--text3)">(<?=h($dem['services'])?>)</span>
          </span>
          <?php endforeach ?>
        </div>
      </div>
      <?php endif ?>

      <!-- Lignes de commande -->
      <div style="margin-top:1.25rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
          <label style="font-size:.82rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">Articles commandés</label>
          <button type="button" class="btn-secondary" style="padding:.4rem .9rem;font-size:.82rem" onclick="addOrderLine()">+ Ajouter une ligne</button>
        </div>
        <div id="order-lines">
          <div class="order-line-header" style="display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;padding:.4rem .5rem;font-size:.72rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">
            <span>Cartouche</span><span>Quantité</span><span>Prix unit. (€)</span><span></span>
          </div>
          <!-- ligne 1 par défaut -->
          <div class="order-line" style="display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;margin-bottom:.5rem;align-items:center">
            <select name="cart_id[]" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .85rem;color:var(--text);font-size:.88rem">
              <option value="">-- Cartouche --</option>
              <?php foreach($cartridges as $c):?><option value="<?=$c['id']?>" data-price="<?=$c['unit_price']?>"><?=h($c['brand'].' '.$c['model'].' ('.$c['color'].')')?></option><?php endforeach?>
            </select>
            <input type="number" name="cart_qty[]" min="1" value="1" placeholder="Qté" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem;text-align:center">
            <input type="number" name="cart_price[]" min="0" step="0.01" placeholder="0.00" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem">
            <button type="button" onclick="this.closest('.order-line').remove()" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1.1rem;line-height:1;transition:color .15s" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text3)'">✕</button>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button>
        <button type="submit" class="btn-primary">✅ Créer la commande</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal édition commande -->
<div class="modal-overlay" id="modal-edit-order">
  <div class="modal modal-xl">
    <div class="modal-header"><h3>✏️ Modifier la commande</h3><button class="modal-close" onclick="closeModal('modal-edit-order')">✕</button></div>
    <form method="post" id="form-edit-order">
      <input type="hidden" name="_entity" value="order">
      <input type="hidden" name="_action" value="edit">
      <input type="hidden" name="_id" id="edit-order-id">
      <div class="form-grid">
        <div class="form-group"><label>Fournisseur</label>
          <select name="supplier_id" id="edit-order-supplier">
            <option value="">-- Aucun --</option>
            <?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach?>
          </select></div>
        <div class="form-group"><label>Date de commande *</label><input type="date" name="order_date" id="edit-order-date" required></div>
        <div class="form-group"><label>Date de livraison prévue</label><input type="date" name="expected_date" id="edit-order-expected"></div>
        <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-order-notes" rows="2"></textarea></div>
      </div>

      <div style="margin-top:1.25rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
          <label style="font-size:.82rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">Lignes de commande</label>
          <button type="button" class="btn-secondary" style="padding:.4rem .9rem;font-size:.82rem" onclick="addEditOrderLine()">+ Ajouter une ligne</button>
        </div>
        <div id="edit-order-lines">
          <div style="display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;padding:.35rem .5rem;font-size:.72rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">
            <span>Cartouche</span><span>Quantité</span><span>Prix unit. (€)</span><span></span>
          </div>
          <!-- lignes injectées par JS -->
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('modal-edit-order')">Annuler</button>
        <button type="submit" class="btn-primary">✅ Enregistrer les modifications</button>
      </div>
    </form>
  </div>
</div>

<script>
const cartridgesData = <?=json_encode(array_map(fn($c)=>['id'=>$c['id'],'label'=>$c['brand'].' '.$c['model'].' ('.$c['color'].')','price'=>$c['unit_price']],$cartridges))?>;

// ── Ouvrir modal édition ──────────────────────────────────────
async function openEditOrder(order) {
  document.getElementById('edit-order-id').value      = order.id;
  document.getElementById('edit-order-date').value    = order.order_date;
  document.getElementById('edit-order-expected').value= order.expected_date || '';
  document.getElementById('edit-order-notes').value   = order.notes || '';
  document.getElementById('edit-order-supplier').value= order.supplier_id || '';

  // Charger les lignes via fetch
  const container = document.getElementById('edit-order-lines');
  container.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text3);font-size:.85rem">⏳ Chargement…</div>';
  openModal('modal-edit-order');

  try {
    const r = await fetch(`index.php?ajax_order_lines=1&order_id=${order.id}`);
    const lines = await r.json();
    renderEditLines(lines);
  } catch(e) {
    container.innerHTML = '<div style="color:var(--danger);padding:1rem">Erreur de chargement</div>';
  }
}

function renderEditLines(lines) {
  const container = document.getElementById('edit-order-lines');
  // Conserver le header
  const header = container.firstElementChild;
  container.innerHTML = '';
  if (header) container.appendChild(header);

  // Lignes existantes
  lines.forEach(l => {
    const div = document.createElement('div');
    div.style.cssText = 'display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;margin-bottom:.5rem;align-items:center';
    const opts = cartridgesData.map(c=>`<option value="${c.id}"${c.id==l.cartridge_model_id?' selected':''}>${escH(c.label)}</option>`).join('');
    div.innerHTML = `
      <input type="hidden" name="line_id[]" value="${l.id}">
      <select name="line_cart[]" disabled style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .85rem;color:var(--text);font-size:.88rem;opacity:.7">
        ${opts}
      </select>
      <input type="number" name="line_qty[]" value="${l.quantity_ordered}" min="${l.quantity_received||0}" style="${inputStyle()}">
      <input type="number" name="line_price[]" value="${l.unit_price}" min="0" step="0.01" style="${inputStyle()}">
      <button type="button" onclick="removeLine(this, ${l.quantity_received})" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1.1rem;line-height:1;transition:color .15s" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text3)'">✕</button>`;
    if(l.quantity_received > 0) {
      div.querySelector('input[name="line_qty[]"]').title = `Min: ${l.quantity_received} (déjà reçu)`;
    }
    container.appendChild(div);
  });
}

function removeLine(btn, received) {
  if (received > 0) { alert('Cette ligne a déjà des réceptions enregistrées, elle ne peut pas être supprimée.'); return; }
  // Mettre qty à 0 pour signaler la suppression
  const row = btn.closest('div');
  const qtyInput = row.querySelector('input[name="line_qty[]"]');
  if (qtyInput) qtyInput.value = 0;
  row.style.opacity = '.3';
  row.style.pointerEvents = 'none';
  btn.style.display = 'none';
}

function addEditOrderLine() {
  const container = document.getElementById('edit-order-lines');
  const opts = cartridgesData.map(c=>`<option value="${c.id}" data-price="${c.price}">${escH(c.label)}</option>`).join('');
  const div = document.createElement('div');
  div.style.cssText = 'display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;margin-bottom:.5rem;align-items:center';
  div.innerHTML = `
    <select name="cart_id[]" style="${inputStyle('select')}"><option value="">-- Cartouche --</option>${opts}</select>
    <input type="number" name="cart_qty[]" min="1" value="1" style="${inputStyle()}">
    <input type="number" name="cart_price[]" min="0" step="0.01" placeholder="0.00" style="${inputStyle()}">
    <button type="button" onclick="this.closest('div').remove()" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1.1rem;line-height:1" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text3)'">✕</button>`;
  div.querySelector('select').addEventListener('change', function(){
    const c = cartridgesData.find(x=>x.id==this.value);
    if(c&&c.price) div.querySelector('input[name="cart_price[]"]').value = c.price;
  });
  container.appendChild(div);
}

function inputStyle(t) {
  return 'background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem;width:100%'+(t==='select'?';padding:.6rem .85rem':'');
}
function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Modal ajout (existant) ────────────────────────────────────
// Données demandes injectées depuis PHP
const PENDING_DEMANDS = <?=json_encode(
    array_map(function($d){ return [
        'cartridge_model_id' => (int)$d['cartridge_model_id'],
        'label'  => $d['brand'].' '.$d['model'].' ('.$d['color'].')',
        'qty'    => (int)$d['qty_needed'],
        'price'  => (float)$d['unit_price'],
    ]; }, $pendingDemands)
, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

function importDemands() {
  // Vider les lignes existantes
  const container = document.getElementById('order-lines');
  container.querySelectorAll('.order-line').forEach(l => l.remove());
  // Ajouter une ligne par demande
  PENDING_DEMANDS.forEach(function(d) {
    const div = document.createElement('div');
    div.className = 'order-line';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;margin-bottom:.5rem;align-items:center';
    const selHtml = document.getElementById('order-lines').closest('form').querySelector('select[name="cart_id[]"]')
                    ? document.getElementById('order-lines').closest('form').querySelector('select[name="cart_id[]"]').outerHTML.replace(/name="cart_id\[\]"/, 'name="cart_id[]"')
                    : '';
    // Reconstruire le select avec la bonne option sélectionnée
    const allSelects = document.querySelectorAll('#modal-add select[name="cart_id[]"]');
    const refSelect  = allSelects.length ? allSelects[0].cloneNode(true) : null;
    if (refSelect) {
      refSelect.value = d.cartridge_model_id;
      div.appendChild(refSelect);
    } else {
      const inp = document.createElement('input');
      inp.type='hidden'; inp.name='cart_id[]'; inp.value=d.cartridge_model_id;
      const lbl = document.createElement('span');
      lbl.style.cssText='color:var(--text);font-size:.88rem;padding:.6rem';
      lbl.textContent = d.label;
      div.appendChild(lbl); div.appendChild(inp);
    }
    const qtyInp = document.createElement('input');
    qtyInp.type='number'; qtyInp.name='cart_qty[]'; qtyInp.min=1; qtyInp.value=d.qty;
    qtyInp.style.cssText='background:var(--bg3);border:1px solid rgba(245,158,11,.4);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem;text-align:center';
    qtyInp.title='Demande : '+d.qty+' unités';
    div.appendChild(qtyInp);
    const priceInp = document.createElement('input');
    priceInp.type='number'; priceInp.name='cart_price[]'; priceInp.min=0; priceInp.step='0.01'; priceInp.value=d.price||'';
    priceInp.style.cssText='background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem';
    div.appendChild(priceInp);
    const delBtn = document.createElement('button');
    delBtn.type='button'; delBtn.textContent='✕';
    delBtn.style.cssText='background:none;border:none;cursor:pointer;color:var(--text3);font-size:1.1rem;line-height:1;transition:color .15s';
    delBtn.onmouseover=()=>delBtn.style.color='var(--danger)';
    delBtn.onmouseout=()=>delBtn.style.color='var(--text3)';
    delBtn.onclick=()=>div.remove();
    div.appendChild(delBtn);
    container.appendChild(div);
  });
  // Attach price autofill to new selects
  container.querySelectorAll('.order-line select').forEach(sel=>{
    sel.addEventListener('change', function(){ const p=this.options[this.selectedIndex]?.dataset?.price; const pi=this.closest('.order-line')?.querySelector('input[name="cart_price[]"]'); if(p&&pi&&!pi.value) pi.value=parseFloat(p).toFixed(2); });
  });
}

function addOrderLine() {
  const opts = cartridgesData.map(c=>`<option value="${c.id}" data-price="${c.price}">${escH(c.label)}</option>`).join('');
  const div = document.createElement('div');
  div.className='order-line';
  div.style.cssText='display:grid;grid-template-columns:1fr 100px 100px 30px;gap:.75rem;margin-bottom:.5rem;align-items:center';
  div.innerHTML=`
    <select name="cart_id[]" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .85rem;color:var(--text);font-size:.88rem"><option value="">-- Cartouche --</option>${opts}</select>
    <input type="number" name="cart_qty[]" min="1" value="1" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem;text-align:center">
    <input type="number" name="cart_price[]" min="0" step="0.01" placeholder="0.00" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;color:var(--text);font-size:.88rem">
    <button type="button" onclick="this.closest('.order-line').remove()" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1.1rem" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text3)'">✕</button>`;
  div.querySelector('select').addEventListener('change', function(){
    const c=cartridgesData.find(x=>x.id==this.value);
    if(c&&c.price) div.querySelector('input[name="cart_price[]"]').value=c.price;
  });
  document.getElementById('order-lines').appendChild(div);
}
document.querySelectorAll('.order-line select').forEach(sel=>{
  sel.addEventListener('change',function(){
    const c=cartridgesData.find(x=>x.id==this.value);
    if(c&&c.price) this.closest('.order-line').querySelector('input[name="cart_price[]"]').value=c.price;
  });
});
</script>
<?php
}

function pageOrderView(PDO $db, int $id): void {
    if (!$id) { header('Location: index.php?page=orders'); exit; }
    $order = null;
    try {
        $st = $db->prepare("SELECT po.*, sp.name as supplier_name, sp.email as supplier_email, sp.phone as supplier_phone, u.full_name as user_name FROM purchase_orders po LEFT JOIN suppliers sp ON po.supplier_id=sp.id LEFT JOIN users u ON po.created_by=u.id WHERE po.id=?");
        $st->execute([$id]); $order=$st->fetch();
    } catch(Exception $e){}
    if (!$order) { header('Location: index.php?page=orders'); exit; }

    $lines = [];
    try {
        $st2 = $db->prepare("SELECT pol.*, cm.brand, cm.model, cm.color, cm.reference FROM purchase_order_lines pol JOIN cartridge_models cm ON pol.cartridge_model_id=cm.id WHERE pol.order_id=? ORDER BY pol.id");
        $st2->execute([$id]); $lines=$st2->fetch()?$st2->fetchAll():[];
        // refetch properly
        $st2->execute([$id]); $lines=$st2->fetchAll();
    } catch(Exception $e){}

    $canReceive = in_array($order['status'],['pending','partial']);
    $totalOrdered  = array_sum(array_column($lines,'quantity_ordered'));
    $totalReceived = array_sum(array_column($lines,'quantity_received'));
?>
<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-size:.85rem;color:var(--text3)">
  <a href="index.php?page=orders" style="color:var(--text3);text-decoration:none" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text3)'">← Commandes</a>
  <span>/</span><span style="color:var(--text2)">Commande #<?=str_pad($id,4,'0',STR_PAD_LEFT)?></span>
</div>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
  <div>
    <h1 style="font-family:var(--font-display);font-size:1.5rem;font-weight:800">Commande <span style="color:var(--primary);font-family:var(--font-mono)">#<?=str_pad($id,4,'0',STR_PAD_LEFT)?></span></h1>
    <div style="display:flex;gap:.75rem;align-items:center;margin-top:.4rem;flex-wrap:wrap">
      <?=orderStatusBadge($order['status'])?>
      <span style="font-size:.82rem;color:var(--text2)">📅 <?=date('d/m/Y',strtotime($order['order_date']))?></span>
      <?=$order['expected_date']?'<span style="font-size:.82rem;color:var(--text2)">🚚 Prévu : '.date('d/m/Y',strtotime($order['expected_date'])).'</span>':''?>
      <span style="font-size:.82rem;color:var(--text2)">👤 <?=h($order['user_name']??'–')?></span>
    </div>
  </div>
  <?php if($canReceive): ?>
  <button class="btn-primary" onclick="openModal('modal-receive')" style="font-size:.9rem">📬 Enregistrer une réception</button>
  <?php endif ?>
</div>

<!-- Infos commande -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
  <div class="card">
    <div class="card-header"><span class="card-title">🏭 Fournisseur</span></div>
    <div style="padding:1.25rem;font-size:.88rem;display:flex;flex-direction:column;gap:.6rem">
      <?php if($order['supplier_name']): ?>
      <div><strong style="font-size:1rem"><?=h($order['supplier_name'])?></strong></div>
      <?=$order['supplier_email']?'<div>📧 <a href="mailto:'.h($order['supplier_email']).'" style="color:var(--primary)">'.h($order['supplier_email']).'</a></div>':''?>
      <?=$order['supplier_phone']?'<div>📞 '.h($order['supplier_phone']).'</div>':''?>
      <?php else: ?><span style="color:var(--text3)">Aucun fournisseur</span><?php endif ?>
      <?=$order['notes']?'<div style="background:var(--bg3);border-radius:var(--radius-sm);padding:.65rem;color:var(--text2);margin-top:.25rem">'.nl2br(h($order['notes'])).'</div>':''?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Avancement</span></div>
    <div style="padding:1.25rem">
      <?php $pct=$totalOrdered>0?round($totalReceived/$totalOrdered*100):0; ?>
      <div style="display:flex;justify-content:space-between;margin-bottom:.6rem">
        <span style="font-size:.88rem;color:var(--text2)">Réception</span>
        <span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;color:<?=$pct>=100?'var(--success)':($pct>0?'var(--warning)':'var(--text3)')?>"><?=$totalReceived?> / <?=$totalOrdered?> unités</span>
      </div>
      <div style="height:10px;background:var(--bg3);border-radius:99px;overflow:hidden">
        <div style="height:100%;border-radius:99px;width:<?=$pct?>%;background:<?=$pct>=100?'linear-gradient(90deg,#059669,#10b981)':($pct>0?'linear-gradient(90deg,#d97706,#f59e0b)':'var(--text3)')?>;transition:width .8s ease"></div>
      </div>
      <div style="font-size:2rem;font-weight:800;font-family:var(--font-display);margin-top:.75rem;color:<?=$pct>=100?'var(--success)':'var(--text)'?>"><?=$pct?>%</div>
    </div>
  </div>
</div>

<!-- Lignes commande -->
<div class="card">
  <div class="card-header"><span class="card-title">📋 Lignes de commande</span></div>
  <table class="data-table">
    <thead><tr><th>Cartouche</th><th>Couleur</th><th>Demandes</th><th>Réf.</th><th>Prix unit.</th><th>Qté commandée</th><th>Qté reçue</th><th>Reste</th><th>Avancement</th></tr></thead>
    <tbody>
    <?php foreach($lines as $l):
      $reste=max(0,$l['quantity_ordered']-$l['quantity_received']);
      $lpct=$l['quantity_ordered']>0?round($l['quantity_received']/$l['quantity_ordered']*100):0;
    ?>
    <tr>
      <td><strong><?=h($l['brand'].' '.$l['model'])?></strong></td>
      <td><?=colorDot($l['color'])?></td>
      <td><?php
        $lineDem=0; $lineSvc='';
        try {
          $lst=$db->prepare("SELECT COALESCE(SUM(r.quantity_requested-r.quantity_fulfilled),0) as need, GROUP_CONCAT(DISTINCT COALESCE(sv.name,'?') SEPARATOR ', ') as svcs FROM reservations r LEFT JOIN services sv ON r.service_id=sv.id WHERE r.cartridge_model_id=? AND r.status IN ('pending','partial')");
          $lst->execute([$l['cartridge_model_id']]); $ldr=$lst->fetch(); $lineDem=(int)$ldr['need']; $lineSvc=$ldr['svcs']??'';
        } catch(Exception $e){}
      ?><?php if($lineDem>0): ?><span title="Demandé par : <?=h($lineSvc)?>" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.15rem .5rem;font-size:.75rem;font-weight:700;color:#f59e0b;white-space:nowrap">📌 <?=$lineDem?> u.<br><span style="font-size:.68rem;color:var(--text3)"><?=h(mb_strimwidth($lineSvc,0,30,'…'))?></span></span><?php else: ?><span style="color:var(--text3);font-size:.78rem">–</span><?php endif ?></td>
      <td><code class="ref"><?=h($l['reference']??'–')?></code></td>
      <td style="font-family:var(--font-mono)"><?=$l['unit_price']?number_format($l['unit_price'],2,',',' ').' €':'–'?></td>
      <td style="text-align:center;font-weight:600"><?=$l['quantity_ordered']?></td>
      <td style="text-align:center"><span class="badge <?=$l['quantity_received']>=$l['quantity_ordered']?'badge-success':($l['quantity_received']>0?'badge-warning':'badge-muted')?>"><?=$l['quantity_received']?></span></td>
      <td style="text-align:center"><?=$reste>0?"<span class='badge badge-warning'>$reste</span>":'<span class="badge badge-success">✓</span>'?></td>
      <td style="min-width:100px">
        <div style="height:6px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;border-radius:99px;width:<?=$lpct?>%;background:<?=$lpct>=100?'linear-gradient(90deg,#059669,#10b981)':'linear-gradient(90deg,#d97706,#f59e0b)'?>"></div>
        </div>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<?php if($canReceive && !empty($lines)): ?>
<!-- Modal réception -->
<div class="modal-overlay" id="modal-receive">
  <div class="modal modal-lg">
    <div class="modal-header"><h3>📬 Enregistrer une réception</h3><button class="modal-close" onclick="closeModal('modal-receive')">✕</button></div>
    <form method="post">
      <input type="hidden" name="_entity" value="order_receive">
      <input type="hidden" name="_action" value="receive">
      <input type="hidden" name="order_id" value="<?=$id?>">
      <p style="color:var(--text2);font-size:.85rem;margin-bottom:1.25rem">Saisissez les quantités <strong style="color:var(--text)">effectivement reçues</strong> pour chaque article. Laissez à 0 ce qui n'est pas encore arrivé.</p>
      <table style="width:100%;border-collapse:collapse">
        <thead><tr>
          <th style="text-align:left;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Cartouche</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Commandé</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Déjà reçu</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Reste</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Reçu maintenant</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">📌 Demandes</th>
          <th style="text-align:center;font-size:.72rem;color:var(--text3);padding:.5rem;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em">Prix unit. (€)</th>
        </tr></thead>
        <tbody>
        <?php foreach($lines as $l):
          $reste=max(0,$l['quantity_ordered']-$l['quantity_received']);
          if($reste<=0) continue;
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <input type="hidden" name="line_id[]" value="<?=$l['id']?>">
          <td style="padding:.65rem .5rem;font-size:.88rem"><?=colorDot($l['color'])?> <strong><?=h($l['brand'].' '.$l['model'])?></strong></td>
          <td style="text-align:center;padding:.65rem .5rem;font-family:var(--font-mono);font-size:.85rem"><?=$l['quantity_ordered']?></td>
          <td style="text-align:center;padding:.65rem .5rem;font-family:var(--font-mono);font-size:.85rem;color:var(--text2)"><?=$l['quantity_received']?></td>
          <td style="text-align:center;padding:.65rem .5rem"><span class="badge badge-warning"><?=$reste?></span></td>
          <td style="padding:.5rem;text-align:center">
            <input type="number" name="recv_qty[]" value="<?=$reste?>" min="0" max="<?=$reste?>"
              style="width:70px;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.45rem .5rem;color:var(--text);font-family:var(--font-mono);font-size:.9rem;font-weight:600;text-align:center">
          </td>
          <td style="padding:.5rem;text-align:center"><?php
            $recvDem=0; $recvSvc='';
            try {
              $rds=$db->prepare("SELECT COALESCE(SUM(r.quantity_requested-r.quantity_fulfilled),0) as need, GROUP_CONCAT(DISTINCT COALESCE(sv.name,'?') SEPARATOR ', ') as svcs FROM reservations r LEFT JOIN services sv ON r.service_id=sv.id WHERE r.cartridge_model_id=? AND r.status IN ('pending','partial')");
              $rds->execute([$l['cartridge_model_id']]); $rdr=$rds->fetch(); $recvDem=(int)$rdr['need']; $recvSvc=$rdr['svcs']??'';
            } catch(Exception $e){}
          ?><?php if($recvDem>0): ?><span style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.2rem .5rem;font-size:.75rem;font-weight:700;color:#f59e0b;display:inline-block;text-align:center">📌 <?=$recvDem?> u.<br><span style="font-size:.65rem;color:var(--text3);font-weight:400"><?=h(mb_strimwidth($recvSvc,0,25,'…'))?></span></span><?php else: ?><span style="color:var(--text3)">–</span><?php endif ?></td>
          <td style="padding:.5rem;text-align:center">
            <input type="number" name="unit_price[]" value="<?=number_format($l['unit_price'],2,'.','');?>" min="0" step="0.01"
              style="width:80px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.45rem .5rem;color:var(--text);font-family:var(--font-mono);font-size:.85rem;text-align:center">
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('modal-receive')">Annuler</button>
        <button type="submit" class="btn-primary">✅ Valider la réception</button>
      </div>
    </form>
  </div>
</div>
<?php endif ?>
<?php
}

function orderStatusBadge(string $s): string {
    $map=['pending'=>['En attente','badge-warning'],'partial'=>['Partielle','badge-info'],'received'=>['Reçue','badge-success'],'cancelled'=>['Annulée','badge-danger']];
    [$label,$cls]=$map[$s]??[$s,'badge-muted'];
    return "<span class='badge $cls'>$label</span>";
}

function pageServices(PDO $db): void {
    $services = $db->query("SELECT s.*, COUNT(DISTINCT p.id) as printer_count FROM services s LEFT JOIN printers p ON p.service_id=s.id GROUP BY s.id ORDER BY s.name")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">🏢 Gestion des Services</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Nouveau service</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Nom du service</th><th>Direction</th><th>Contact</th><th>Email / Téléphone</th><th>Imprimantes</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($services)): ?><tr><td colspan="6" class="empty-cell">Aucun service enregistré</td></tr>
    <?php else: foreach($services as $s): ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong></td>
      <td><?=h($s['direction'])?></td>
      <td><?=h($s['contact_name'])?></td>
      <td><?=$s['contact_email']?'<a href="mailto:'.h($s['contact_email']).'">'.h($s['contact_email']).'</a>':''?>
          <?=$s['phone']?' <span class="muted">'.h($s['phone']).'</span>':''?></td>
      <td><span class="badge badge-info"><?=h($s['printer_count'])?> 🖨️</span></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"service")'title="Modifier">✏️</button>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$s['id']?>,"service","<?=h(addslashes($s['name']))?>")'title="Supprimer">🗑️</button>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<!-- Add Modal -->
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouveau service</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="service"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Nom du service *</label><input type="text" name="name" required placeholder="ex: Direction des Finances"></div>
    <div class="form-group"><label>Direction / Pôle</label><input type="text" name="direction" placeholder="ex: Pôle Ressources"></div>
    <div class="form-group"><label>Nom du contact</label><input type="text" name="contact_name" placeholder="Jean Dupont"></div>
    <div class="form-group"><label>Email</label><input type="email" name="contact_email" placeholder="jean.dupont@collectivite.fr"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" placeholder="01 23 45 67 89"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Informations complémentaires..."></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<!-- Edit Modal -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier le service</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="service"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" id="edit-name" required></div>
    <div class="form-group"><label>Direction</label><input type="text" name="direction" id="edit-direction"></div>
    <div class="form-group"><label>Contact</label><input type="text" name="contact_name" id="edit-contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="contact_email" id="edit-contact_email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" id="edit-phone"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('service'); }

function pageSuppliers(PDO $db): void {
    $suppliers = $db->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">🏭 Fournisseurs</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Nouveau fournisseur</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Fournisseur</th><th>Contact</th><th>Email</th><th>Téléphone</th><th>Site web</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($suppliers)): ?><tr><td colspan="6" class="empty-cell">Aucun fournisseur</td></tr>
    <?php else: foreach($suppliers as $s): ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong><?=$s['address']?'<br><small class="muted">'.h($s['address']).'</small>':''?></td>
      <td><?=h($s['contact_name'])?></td>
      <td><?=$s['email']?'<a href="mailto:'.h($s['email']).'">'.h($s['email']).'</a>':'-'?></td>
      <td><?=h($s['phone'])?:'-'?></td>
      <td><?=$s['website']?'<a href="'.h($s['website']).'" target="_blank">🔗 Voir</a>':'-'?></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"supplier")'>✏️</button>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$s['id']?>,"supplier","<?=h(addslashes($s['name']))?>")'  >🗑️</button>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouveau fournisseur</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="supplier"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" required></div>
    <div class="form-group"><label>Nom du contact</label><input type="text" name="contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone"></div>
    <div class="form-group form-full"><label>Adresse</label><input type="text" name="address"></div>
    <div class="form-group form-full"><label>Site web</label><input type="url" name="website" placeholder="https://"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier le fournisseur</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="supplier"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" id="edit-name" required></div>
    <div class="form-group"><label>Contact</label><input type="text" name="contact_name" id="edit-contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" id="edit-phone"></div>
    <div class="form-group form-full"><label>Adresse</label><input type="text" name="address" id="edit-address"></div>
    <div class="form-group form-full"><label>Site web</label><input type="url" name="website" id="edit-website"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('supplier'); }

function pageCartridges(PDO $db): void {
    $showArchived = isset($_GET['archived']);
    $sortBy  = $_GET['sort'] ?? 'name';
    $sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $sortMap = [
        'name'  => 'cm.brand, cm.model',
        'stock' => 'qty_avail',
        'color' => 'cm.color, cm.brand',
        'type'  => 'cm.type, cm.brand',
    ];
    $orderSql = $sortMap[$sortBy] ?? 'cm.brand, cm.model';
    if ($sortDir === 'desc') $orderSql .= ' DESC';
    $cartridges = $db->query(
        "SELECT cm.*,
         COALESCE(s.quantity_available,0) as qty_avail,
         COALESCE(s.quantity_reserved,0) as qty_res,
         COUNT(DISTINCT pc.printer_id) as printer_count,
         GROUP_CONCAT(DISTINCT CONCAT(p.brand,' ',p.model) ORDER BY p.brand,p.model SEPARATOR '|') as printer_list,
         GROUP_CONCAT(DISTINCT p.id ORDER BY p.brand,p.model SEPARATOR ',') as printer_ids
         FROM cartridge_models cm
         LEFT JOIN stock s ON s.cartridge_model_id=cm.id
         LEFT JOIN printer_cartridges pc ON pc.cartridge_model_id=cm.id
         LEFT JOIN printers p ON p.id=pc.printer_id
         GROUP BY cm.id ORDER BY cm.active DESC, $orderSql"
    )->fetchAll();
    $archivedCount = count(array_filter($cartridges, fn($c) => !($c['active'] ?? 1)));
    $displayed = $showArchived ? $cartridges : array_values(array_filter($cartridges, fn($c) => ($c['active'] ?? 1)));
    $pgCarts  = paginate($displayed, 25);
    $displayed = $pgCarts['items'];
?>
<div class="page-header">
  <span class="page-title-txt">🖋️ Modèles de Cartouches</span>
  <div style="display:flex;gap:.6rem;align-items:center">
    <?php if($archivedCount > 0): ?>
    <a href="?page=cartridges<?=$showArchived?'':' &archived=1'?>"
       style="padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .15s;<?=$showArchived?'background:var(--primary);color:#fff':'background:var(--card2);color:var(--text2);border:1px solid var(--border)'?>">
      🗄️ Archivées (<?=$archivedCount?>)
    </a>
    <?php endif ?>
    <button class="btn-primary" onclick="openModal('modal-add')">+ Nouveau modèle</button>
    <?php
    // Compter les cartouches orphelines (actives et sans imprimante)
    $orphanCount = 0;
    try {
        $orphanCount = (int)$db->query(
            "SELECT COUNT(*) FROM cartridge_models cm
             LEFT JOIN printer_cartridges pc ON pc.cartridge_model_id = cm.id
             WHERE pc.printer_id IS NULL AND (cm.active = 1 OR cm.active IS NULL)"
        )->fetchColumn();
    } catch(Exception $e) {}
    ?>
    <?php if($orphanCount > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Archiver <?=$orphanCount?> cartouche(s) non rattachée(s) à une imprimante ?')">
      <input type="hidden" name="_entity" value="cartridge">
      <input type="hidden" name="_action" value="archive_orphans">
      <button type="submit" class="btn-secondary" title="Archiver les cartouches sans imprimante associée">
        🗄️ Archiver orphelines (<?=$orphanCount?>)
      </button>
    </form>
    <?php endif ?>
  </div>
</div>

<?php if($showArchived): ?>
<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#f59e0b">
  🗄️ Affichage des cartouches archivées. <a href="?page=cartridges" style="color:var(--primary);text-decoration:underline">← Retour aux actives</a>
</div>
<?php endif ?>

<div class="search-bar-wrap">
  <div class="search-bar">
    <span class="search-bar-icon">🔍</span>
    <input type="text" id="cart-search" placeholder="Rechercher par marque, modèle, couleur, type, référence…" oninput="tableSearch(this,'cart-tbody','cart-count')" autocomplete="off">
    <button class="search-bar-clear" id="cart-clear" onclick="clearSearch('cart-search','cart-tbody','cart-count','cart-clear')">✕</button>
  </div>
  <div class="search-count" id="cart-count"></div>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr>
      <?php
      function cartSortTh(string $label, string $key, string $cur, string $dir, bool $nowrap=false): string {
          $q = array_merge($_GET, ['page'=>'cartridges','sort'=>$key,'dir'=>($cur===$key && $dir==='asc')?'desc':'asc','p'=>1]);
          unset($q['open']);
          $url = '?'.http_build_query($q);
          $arrow = $cur===$key ? ($dir==='asc'?' ↑':' ↓') : '';
          $ws = $nowrap ? 'white-space:nowrap;' : '';
          return '<th><a href="'.htmlspecialchars($url).'" style="text-decoration:none;color:inherit;cursor:pointer;user-select:none;'.$ws.'">'.$label.$arrow.'</a></th>';
      }
      echo cartSortTh('Marque / Modèle','name',$sortBy,$sortDir);
      echo '<th>Référence</th>';
      echo cartSortTh('Couleur','color',$sortBy,$sortDir);
      echo cartSortTh('Type','type',$sortBy,$sortDir);
      echo '<th>Rendement</th>';
      echo cartSortTh('Stock','stock',$sortBy,$sortDir);
      echo '<th>Imprimantes</th>';
      echo '<th>Statut</th>';
      echo '<th>Actions</th>';
      ?></tr></thead>
    <tbody id="cart-tbody">
    <?php if(empty($displayed)): ?><tr><td colspan="9" class="empty-cell">Aucun modèle<?=$showArchived?' archivé':''?></td></tr>
    <?php else: foreach($displayed as $c):
      $isActive = (bool)($c['active'] ?? 1);
      $lowStock = $isActive && $c['qty_avail'] <= $c['alert_threshold']; ?>
    <tr id="cartridge-<?=$c['id']?>" style="<?=$isActive?'':'opacity:.5'?><?=$lowStock?';background:rgba(239,68,68,.04)':''?>">
      <td>
        <a href="index.php?page=cartridge_history&id=<?=$c['id']?>" style="text-decoration:none;color:inherit" title="Voir l'historique">
          <strong><?=h($c['brand'])?></strong><br>
          <span class="muted"><?=h($c['model'])?></span>
        </a>
      </td>
      <td><code class="ref"><?=h($c['reference'])?:'-'?></code></td>
      <td><?=colorDot($c['color'])?></td>
      <td><span class="badge badge-muted"><?=strtoupper(h($c['type']))?></span></td>
      <td><?=$c['page_yield']?h(number_format($c['page_yield'],0,',',' ')).' p.':'N/A'?></td>
      <td>
        <?php if($isActive):
          $pendDem = 0;
          try { $pdst=$db->prepare("SELECT COALESCE(SUM(quantity_requested-quantity_fulfilled),0) FROM reservations WHERE cartridge_model_id=? AND status IN ('pending','partial')"); $pdst->execute([$c['id']]); $pendDem=(int)$pdst->fetchColumn(); } catch(Exception $e){}
        ?>
        <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
          <span class="stock-pill <?=$lowStock?'stock-pill-low':'stock-pill-ok'?>"><?=h($c['qty_avail'])?></span>
          <?php if($pendDem>0): ?>
          <span title="<?=$pendDem?> demande(s) en attente" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.15rem .5rem;font-size:.72rem;font-weight:700;color:#f59e0b;white-space:nowrap">📌 <?=$pendDem?> dem.</span>
          <?php endif ?>
          <?=$lowStock?'<span class="badge badge-warning" title="Stock bas">⚠️</span>':''?>
        </div>
        <?php else: ?>
        <span style="color:var(--text3);font-size:.78rem">–</span>
        <?php endif ?>
      </td>
      <td>
        <?php
        if ($c['printer_count'] == 0):
        ?>
        <span style="color:var(--text3);font-size:.78rem">–</span>
        <?php else:
          $printerNames = explode('|', $c['printer_list'] ?? '');
          $printerIdsArr = explode(',', $c['printer_ids'] ?? '');
        ?>
        <div style="display:flex;flex-direction:column;gap:.25rem">
          <?php foreach($printerNames as $i => $pname): if(!trim($pname)) continue; ?>
          <a href="index.php?page=printer_view&id=<?=intval($printerIdsArr[$i] ?? 0)?>"
             style="display:inline-flex;align-items:center;gap:.35rem;font-size:.78rem;color:var(--primary);text-decoration:none;white-space:nowrap;transition:opacity .15s"
             onmouseover="this.style.opacity='.7'" onmouseout="this.style.opacity='1'">
            🖨️ <?=h(trim($pname))?>
          </a>
          <?php endforeach ?>
        </div>
        <?php endif ?>
      </td>
      <td>
        <?php if($isActive): ?>
        <span class="badge badge-success">Actif</span>
        <?php else: ?>
        <span class="badge badge-muted">Archivé</span>
        <?php endif ?>
      </td>
      <td class="actions">
        <?php if($isActive): ?>
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"cartridge")' title="Modifier">✏️</button>
        <form method="post" style="display:inline" title="Archiver">
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="archive">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Archiver" onclick="return confirm('Archiver cette cartouche ?\nElle restera dans l\'historique mais ne sera plus proposée.')">🗄️</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement ?\nSi des données sont liées, la cartouche sera archivée automatiquement.')">🗑️</button>
        </form>
        <?php else: ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="restore">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon" title="Restaurer" style="color:var(--success)">♻️</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement cette cartouche archivée ?\nSi des données sont liées, elle sera conservée.')">🗑️</button>
        </form>
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgCarts)?>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>Nouveau modèle de cartouche</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="cartridge"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Marque *</label><input type="text" name="brand" required placeholder="HP, Canon, Epson..."></div>
    <div class="form-group"><label>Modèle *</label><input type="text" name="model" required placeholder="304XL, TN-2420..."></div>
    <div class="form-group"><label>Référence fournisseur</label><input type="text" name="reference" id="cart-add-reference" placeholder="REF-001"></div>
  <div class="form-group"><label style="display:flex;align-items:center;justify-content:space-between">Code-barres / QR boîte <button type="button" onclick="openQrScanner('cart-add-barcode','cart-add')" class="btn-secondary" style="font-size:.75rem;padding:.25rem .65rem;font-weight:500">📷 Scanner</button></label><input type="text" name="barcode" id="cart-add-barcode" placeholder="Scanner ou saisir le code de la boîte"></div>
    <div class="form-group"><label>Couleur</label>
      <select name="color"><option>Noir</option><option>Cyan</option><option>Magenta</option><option>Jaune</option><option>Tricolore</option><option>Bleu</option></select></div>
    <div class="form-group"><label>Type</label>
      <select name="type"><option value="laser">Laser</option><option value="inkjet">Jet d'encre</option><option value="toner">Toner</option><option value="ruban">Ruban</option></select></div>
    <div class="form-group"><label>Rendement (pages)</label><input type="number" name="page_yield" min="0" placeholder="1500"></div>
    <div class="form-group"><label>Prix unitaire (€)</label><input type="number" name="unit_price" step="0.01" min="0" placeholder="25.90"></div>
    <div class="form-group"><label>Seuil d'alerte</label><input type="number" name="alert_threshold" min="0" value="3"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<div class="modal-overlay" id="modal-edit">
  <div class="modal modal-lg"><div class="modal-header"><h3>Modifier le modèle</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="cartridge"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group"><label>Marque *</label><input type="text" name="brand" id="edit-brand" required></div>
    <div class="form-group"><label>Modèle *</label><input type="text" name="model" id="edit-model" required></div>
    <div class="form-group"><label>Référence</label><input type="text" name="reference" id="edit-reference"></div>
    <div class="form-group"><label style="display:flex;align-items:center;justify-content:space-between">Code-barres / QR boîte <button type="button" onclick="openQrScanner('edit-barcode','cart-edit')" class="btn-secondary" style="font-size:.75rem;padding:.25rem .65rem;font-weight:500">📷 Scanner</button></label><input type="text" name="barcode" id="edit-barcode" placeholder="Scanner ou saisir le code de la boîte"></div>
    <div class="form-group"><label>Couleur</label><select name="color" id="edit-color"><option>Noir</option><option>Cyan</option><option>Magenta</option><option>Jaune</option><option>Tricolore</option><option>Bleu</option></select></div>
    <div class="form-group"><label>Type</label><select name="type" id="edit-type"><option value="laser">Laser</option><option value="inkjet">Jet d'encre</option><option value="toner">Toner</option><option value="ruban">Ruban</option></select></div>
    <div class="form-group"><label>Rendement</label><input type="number" name="page_yield" id="edit-page_yield" min="0"></div>
    <div class="form-group"><label>Prix unitaire (€)</label><input type="number" name="unit_price" id="edit-unit_price" step="0.01" min="0"></div>
    <div class="form-group"><label>Seuil d'alerte</label><input type="number" name="alert_threshold" id="edit-alert_threshold" min="0"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('cartridge'); }

function pagePrinters(PDO $db): void {
    $sortBy = $_GET['sort'] ?? 'printer';
    $sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $orderMap = [
        'printer' => 'p.brand, p.model',
        'service' => 's.name, p.brand',
        'location'=> 'p.location, p.brand',
        'status'  => 'p.status, p.brand',
    ];
    $orderSql = $orderMap[$sortBy] ?? 'p.brand, p.model';
    if ($sortDir === 'desc') {
        $orderSql = implode(' DESC, ', explode(', ', $orderSql)) . ' DESC';
    }
    $printersAll = $db->query("SELECT p.*, s.name as service_name, pm.brand as model_brand, pm.model as model_name, GROUP_CONCAT(DISTINCT CONCAT(cm.id,'|',cm.brand,'|',cm.model,'|',cm.color) ORDER BY cm.brand,cm.model SEPARATOR ';;') as cartridges_raw FROM printers p LEFT JOIN services s ON p.service_id=s.id LEFT JOIN printer_models pm ON p.printer_model_id=pm.id LEFT JOIN printer_cartridges pc ON pc.printer_id=p.id LEFT JOIN cartridge_models cm ON pc.cartridge_model_id=cm.id GROUP BY p.id ORDER BY $orderSql")->fetchAll();
    $pgPrinters = paginate($printersAll, 20);
    $printers   = $pgPrinters['items'];
    $services = $db->query("SELECT id,name FROM services ORDER BY name")->fetchAll();
    $cartridgeModels = $db->query("SELECT id,brand,model,color FROM cartridge_models WHERE active=1 OR active IS NULL ORDER BY brand,model")->fetchAll();
    $printerModels = $db->query("SELECT pm.*, COUNT(DISTINCT pmc.cartridge_model_id) as cart_count, COUNT(DISTINCT p.id) as printer_count FROM printer_models pm LEFT JOIN printer_model_cartridges pmc ON pmc.printer_model_id=pm.id LEFT JOIN printers p ON p.printer_model_id=pm.id GROUP BY pm.id ORDER BY pm.brand, pm.model")->fetchAll();
?>
<?php $tab = isset($_GET['tab']) && $_GET['tab'] === 'models' ? 'models' : 'parc'; ?>

<!-- ── ONGLETS ── -->
<div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.25rem;border-bottom:1px solid var(--border);padding-bottom:0">
  <div style="display:flex;gap:0">
    <a href="?page=printers&tab=parc"
       style="display:flex;align-items:center;gap:.4rem;padding:.65rem 1.25rem;font-size:.88rem;font-weight:600;text-decoration:none;border:1px solid var(--border);border-bottom:none;border-radius:var(--radius-sm) var(--radius-sm) 0 0;margin-right:2px;position:relative;top:1px;transition:all .15s;
       <?=$tab==='parc'?'background:var(--card);color:var(--primary);border-color:var(--border);border-bottom-color:var(--card)':'background:var(--bg3);color:var(--text3);border-color:var(--border)'?>">
       🖨️ Parc <span class="badge badge-muted" style="font-size:.68rem"><?=count($printers)?></span>
    </a>
    <a href="?page=printers&tab=models"
       style="display:flex;align-items:center;gap:.4rem;padding:.65rem 1.25rem;font-size:.88rem;font-weight:600;text-decoration:none;border:1px solid var(--border);border-bottom:none;border-radius:var(--radius-sm) var(--radius-sm) 0 0;position:relative;top:1px;transition:all .15s;
       <?=$tab==='models'?'background:var(--card);color:var(--primary);border-color:var(--border);border-bottom-color:var(--card)':'background:var(--bg3);color:var(--text3);border-color:var(--border)'?>">
       📋 Modèles <span class="badge badge-muted" style="font-size:.68rem"><?=count($printerModels)?></span>
    </a>
  </div>
  <!-- Boutons selon l'onglet actif -->
  <div style="display:flex;gap:.6rem;align-items:center;padding-bottom:.5rem">
    <?php if($tab === 'parc'): ?>
    <button class="btn-secondary" id="btn-scan-all" onclick="scanAllInk()" title="Scanner toutes les imprimantes réseau">↺ Scanner toutes les encres</button>
    <button class="btn-primary" onclick="openModal('modal-add')">+ Ajouter une imprimante</button>
    <?php else: ?>
    <button class="btn-primary" onclick="openModal('modal-model-add')">+ Nouveau modèle</button>
    <?php endif ?>
  </div>
</div>

<!-- Modales : toujours présentes (utilisées par les deux onglets) -->
<!-- Modal Nouveau modèle -->
<div class="modal-overlay" id="modal-model-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📋 Nouveau modèle d'imprimante</h3><button class="modal-close" onclick="closeModal('modal-model-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Marque *</label><input type="text" name="brand" required placeholder="HP, Canon, Epson..."></div>
    <div class="form-group"><label>Modèle *</label><input type="text" name="model" required placeholder="LaserJet Pro M404..."></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Informations sur ce modèle..."></textarea></div>
    <div class="form-group form-full"><label>Cartouches compatibles</label>
      <div class="checkbox-grid">
        <?php foreach($cartridgeModels as $cm): ?>
        <label class="checkbox-item"><input type="checkbox" name="cartridge_ids[]" value="<?=$cm['id']?>" class="pmcart-check-add"> <?=colorDot($cm['color'])?> <?=h($cm['brand'].' '.$cm['model'])?></label>
        <?php endforeach ?>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-model-add')">Annuler</button><button type="submit" class="btn-primary">Créer le modèle</button></div>
  </form></div>
</div>

<!-- Modal Édition modèle -->
<div class="modal-overlay" id="modal-model-edit">
  <div class="modal modal-lg"><div class="modal-header"><h3>✏️ Modifier le modèle</h3><button class="modal-close" onclick="closeModal('modal-model-edit')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="pme-id"><input type="hidden" name="cartridge_ids_sent" value="1">
  <div class="form-grid">
    <div class="form-group"><label>Marque *</label><input type="text" name="brand" id="pme-brand" required></div>
    <div class="form-group"><label>Modèle *</label><input type="text" name="model" id="pme-model" required></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="pme-notes" rows="2"></textarea></div>
    <div class="form-group form-full">
      <label>Cartouches compatibles <span style="color:var(--accent);font-size:.72rem">⚡ Mis à jour sur toutes les imprimantes liées</span></label>
      <div class="checkbox-grid">
        <?php foreach($cartridgeModels as $cm): ?>
        <label class="checkbox-item"><input type="checkbox" name="cartridge_ids[]" value="<?=$cm['id']?>" class="pmcart-check-edit"> <?=colorDot($cm['color'])?> <?=h($cm['brand'].' '.$cm['model'])?></label>
        <?php endforeach ?>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-model-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>

<script>
function openPrinterModelEdit(btn) {
  try { var pm = JSON.parse(btn.getAttribute('data-pm')); var cids = JSON.parse(btn.getAttribute('data-cids')||'[]'); } catch(e){ return; }
  document.getElementById('pme-id').value = pm.id;
  document.getElementById('pme-brand').value = pm.brand||'';
  document.getElementById('pme-model').value = pm.model||'';
  document.getElementById('pme-notes').value = pm.notes||'';
  document.querySelectorAll('.pmcart-check-edit').forEach(function(cb){
    cb.checked = cids.indexOf(parseInt(cb.value)) !== -1;
  });
  openModal('modal-model-edit');
}
</script>

<?php if($tab === 'parc'): ?>
<!-- ══ ONGLET PARC ══ -->
<div class="search-bar-wrap">
  <div class="search-bar">
    <span class="search-bar-icon">🔍</span>
    <input type="text" id="printer-search" placeholder="Rechercher par marque, modèle, service, emplacement, IP, N° série…" oninput="tableSearch(this,'printer-tbody','printer-count')" autocomplete="off">
    <button class="search-bar-clear" id="printer-clear" onclick="clearSearch('printer-search','printer-tbody','printer-count','printer-clear')">✕</button>
  </div>
  <div class="search-count" id="printer-count"></div>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr>
      <?php
      function sortTh(string $label, string $key, string $cur, string $dir): string {
          $url = '?'.http_build_query(array_merge($_GET, ['page'=>'printers','tab'=>'parc','sort'=>$key,'dir'=>($cur===$key && $dir==='asc')?'desc':'asc','p'=>1]));
          $arrow = $cur===$key ? ($dir==='asc'?' ↑':' ↓') : '';
          $style = 'text-decoration:none;color:inherit;cursor:pointer;user-select:none;white-space:nowrap';
          return '<th><a href="'.h($url).'" style="'.$style.'">'.h($label).$arrow.'</a></th>';
      }
      echo sortTh('Imprimante','printer',$sortBy,$sortDir);
      echo '<th>Modèle</th>';
      echo sortTh('Service','service',$sortBy,$sortDir);
      echo sortTh('Emplacement','location',$sortBy,$sortDir);
      echo '<th>N° Série / IP</th>';
      echo '<th>Cartouches compatibles</th>';
      echo '<th>Encre</th>';
      echo sortTh('Statut','status',$sortBy,$sortDir);
      echo '<th>Actions</th>';
      ?></tr></thead>
    <tbody id="printer-tbody">
    <?php if(empty($printers)): ?><tr><td colspan="9" class="empty-cell">Aucune imprimante</td></tr>
    <?php else: foreach($printers as $p): ?>
    <tr>
      <td><strong><?=h($p['brand'].' '.$p['model'])?></strong></td>
      <td><?=$p['model_brand']?'<span style="font-size:.8rem;color:var(--primary)">'.h($p['model_brand'].' '.$p['model_name']).'</span>':'<span style="color:var(--text3);font-size:.78rem">–</span>'?></td>
      <td><?=h($p['service_name']??'N/A')?></td>
      <td><?=h($p['location'])?:'-'?></td>
      <td><?=$p['serial_number']?'<code class="ref">'.h($p['serial_number']).'</code><br>':''?><?=$p['ip_address']?'<small class="muted">'.h($p['ip_address']).'</small>':''?></td>
      <td>
        <?php
        if (empty($p['cartridges_raw'])) {
            echo '<span style="color:var(--text3);font-size:.78rem">–</span>';
        } else {
            echo '<div style="display:flex;flex-direction:column;gap:.2rem">';
            foreach (explode(';;', $p['cartridges_raw']) as $entry) {
                $parts = explode('|', $entry, 4);
                if (count($parts) < 4) continue;
                [,$brand, $model, $color] = $parts;
                echo '<span style="font-size:.78rem">'.colorDot($color).' '.h($brand.' '.$model).'</span>';
            }
            echo '</div>';
        }
        ?>
      </td>
      <td id="ink-row-<?=$p['id']?>" style="min-width:160px">
        <?php if(!empty($p['ip_address'])): ?>
        <div class="ink-mini" style="display:flex;flex-direction:column;gap:4px">
          <span style="font-size:.72rem;color:var(--text3);font-family:var(--font-mono);display:flex;align-items:center;gap:5px">
            💤 non scanné
            <button data-scan-btn="<?=$p['id']?>" onclick="scanOneInk(<?=$p['id']?>, this)" style="background:none;border:none;cursor:pointer;font-size:1rem;opacity:.45;padding:0;line-height:1;color:#fff;transition:opacity .15s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.45" title="Scanner">↺</button>
          </span>
        </div>
        <?php else: ?>
        <span style="font-size:.75rem;color:var(--text3)">– pas d'IP</span>
        <?php endif ?>
      </td>
      <td><?=statusBadge($p['status'])?></td>
      <td class="actions">
        <a href="index.php?page=printer_view&id=<?=$p['id']?>" class="btn-icon" title="Voir la fiche">🔍</a>
        <button class="btn-icon btn-edit" title="Modifier"
          onclick="openPrinterEdit(this)"
          data-printer='<?=htmlspecialchars(json_encode(["id"=>(int)$p["id"],"brand"=>(string)($p["brand"]??""),"model"=>(string)($p["model"]??""),"serial_number"=>(string)($p["serial_number"]??""),"ip_address"=>(string)($p["ip_address"]??""),"location"=>(string)($p["location"]??""),"status"=>(string)($p["status"]??"active"),"service_id"=>$p["service_id"]?(int)$p["service_id"]:null,"purchase_date"=>(string)($p["purchase_date"]??""),"warranty_end"=>(string)($p["warranty_end"]??""),"notes"=>(string)($p["notes"]??"")],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'
          data-cids='<?=htmlspecialchars(json_encode(getPrinterCartridgeIds($db,(int)$p["id"])),ENT_QUOTES)?>'>✏️</button>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$p['id']?>,"printer","<?=h(addslashes($p['brand'].' '.$p['model']))?>")'  >🗑️</button>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgPrinters)?>
</div>

<?php else: ?>
<!-- ══ ONGLET MODÈLES ══ -->
<div class="card">
  <table class="data-table">
    <thead><tr><th>Marque / Modèle</th><th>Cartouches compatibles</th><th>Imprimantes liées</th><th>Notes</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($printerModels)): ?><tr><td colspan="5" class="empty-cell">Aucun modèle défini. Cliquez sur "+ Nouveau modèle" pour commencer.</td></tr>
    <?php else: foreach($printerModels as $pm):
      $pmCarts = $db->prepare("SELECT cm.brand,cm.model,cm.color,cm.id FROM printer_model_cartridges pmc JOIN cartridge_models cm ON cm.id=pmc.cartridge_model_id WHERE pmc.printer_model_id=? ORDER BY cm.brand,cm.model");
      $pmCarts->execute([$pm['id']]); $pmCartList = $pmCarts->fetchAll();
    ?>
    <tr>
      <td><strong><?=h($pm['brand'])?></strong><br><span class="muted"><?=h($pm['model'])?></span></td>
      <td>
        <?php if(empty($pmCartList)): ?>
        <span style="color:var(--text3);font-size:.78rem">–</span>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.2rem">
          <?php foreach($pmCartList as $pmc): ?><span style="font-size:.78rem"><?=colorDot($pmc['color'])?> <?=h($pmc['brand'].' '.$pmc['model'])?></span><?php endforeach ?>
        </div>
        <?php endif ?>
      </td>
      <td style="text-align:center">
        <?=$pm['printer_count']>0?'<span class="badge badge-info">'.$pm['printer_count'].' 🖨️</span>':'<span style="color:var(--text3);font-size:.78rem">–</span>'?>
      </td>
      <td class="muted"><?=h($pm['notes']??'')?></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick="openPrinterModelEdit(this)"
          data-pm='<?=htmlspecialchars(json_encode(['id'=>(int)$pm['id'],'brand'=>(string)$pm['brand'],'model'=>(string)$pm['model'],'notes'=>(string)($pm['notes']??'')],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'
          data-cids='<?=htmlspecialchars(json_encode(array_column($pmCartList,'id')),ENT_QUOTES)?>'>✏️</button>
        <form method="post" style="display:inline"><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$pm['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Supprimer ce modèle ?')">🗑️</button></form>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php printerModal('modal-add','add','Ajouter une imprimante',$services,$cartridgeModels,$printerModels); ?>
<?php printerModal('modal-edit','edit','Modifier l\'imprimante',$services,$cartridgeModels,$printerModels); ?>
<?php deleteModal('printer'); ?>

<script>
// Données cartouches pour auto-fill modèle (scope pagePrinters)
const PM_CART_LABELS = <?=json_encode(
    array_reduce(
        $cartridgeModels,
        function($map, $cm) {
            $map[(int)$cm['id']] = ['label' => $cm['brand'].' '.$cm['model'], 'color' => $cm['color']];
            return $map;
        }, []
    ), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT
)?>;
</script>

<style>
.ink-bar-wrap { display:flex; align-items:center; gap:5px; }
.ink-bar-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.ink-bar-track{ flex:1; height:6px; background:var(--bg3); border-radius:99px; overflow:hidden; min-width:50px; }
.ink-bar-fill { height:100%; border-radius:99px; transition:width .8s cubic-bezier(.4,0,.2,1); }
.ink-pct      { font-family:var(--font-mono); font-size:.7rem; min-width:28px; text-align:right; }
</style>

<script>
// ── Données imprimantes (IP) ──
const printerIPs = <?=json_encode(array_combine(
    array_column($printers,'id'),
    array_map(fn($p)=>$p['ip_address']??'',$printers)
))?>;
const SNMP_OK = <?=function_exists('snmpget')?'true':'false'?>;



function colorForPct(pct) {
  if (pct < 0)  return ['#4b5563','#4b5563','var(--text3)'];
  if (pct < 10) return ['#ef4444','linear-gradient(90deg,#dc2626,#ef4444)','#ef4444'];
  if (pct < 25) return ['#f59e0b','linear-gradient(90deg,#d97706,#f59e0b)','#f59e0b'];
  return ['#10b981','linear-gradient(90deg,#059669,#10b981)','#10b981'];
}

function dotColor(desc) {
  const d = desc.toLowerCase();
  if (d.includes('black')||d.includes('noir')||d.includes('bk')) return '#e2e8f0';
  if (d.includes('cyan'))    return '#67e8f9';
  if (d.includes('magenta')) return '#f0abfc';
  if (d.includes('yellow')||d.includes('jaune')) return '#fde68a';
  return '#94a3b8';
}

function buildDemoSupplies() {
  return [
    {description:'Black',   percent: Math.floor(Math.random()*80+5)},
    {description:'Cyan',    percent: Math.floor(Math.random()*90+5)},
    {description:'Magenta', percent: Math.floor(Math.random()*60+5)},
    {description:'Yellow',  percent: Math.floor(Math.random()*70+5)},
  ];
}

function renderInkRow(pid, supplies, demo) {
  const cell = document.getElementById('ink-row-' + pid);
  if (!cell) return;

  if (!supplies || supplies.length === 0) {
    cell.innerHTML = `<span style="font-size:.75rem;color:var(--text3)">Aucune donnée</span>`;
    return;
  }

  const html = supplies.map(s => {
    const pct = s.percent;
    const [dot, grad, txtColor] = colorForPct(pct);
    const w = pct < 0 ? 2 : Math.max(2, pct);
    return `
    <div class="ink-bar-wrap">
      <div class="ink-bar-dot" style="background:${dotColor(s.description)}"></div>
      <div class="ink-bar-track">
        <div class="ink-bar-fill" data-w="${w}%" style="width:0%;background:${grad}"></div>
      </div>
      <span class="ink-pct" style="color:${txtColor}">${pct < 0 ? '?' : pct + '%'}</span>
    </div>`;
  }).join('');

  cell.innerHTML = `<div style="display:flex;flex-direction:column;gap:4px">${html}${demo ? '<span style="font-size:.65rem;color:var(--text3)">⚠️ démo</span>' : ''}</div>`;

  requestAnimationFrame(() => {
    cell.querySelectorAll('[data-w]').forEach(el => el.style.width = el.dataset.w);
    // Ajouter le bouton ↺ en bas de la cellule
    const wrap = cell.querySelector('div');
    if (wrap) {
      const rb = document.createElement('button');
      rb.dataset.scanBtn = pid;
      rb.onclick = () => scanOneInk(pid, rb);
      rb.title = 'Actualiser';
      rb.style.cssText = 'background:none;border:none;cursor:pointer;font-size:.9rem;opacity:.4;padding:0;color:#fff;align-self:flex-start;margin-top:2px;transition:opacity .15s';
      rb.onmouseover = () => rb.style.opacity = 1;
      rb.onmouseout  = () => rb.style.opacity = .4;
      rb.textContent = '↺';
      wrap.appendChild(rb);
    }
  });
}

async function scanAllInk() {
  const btn = document.getElementById('btn-scan-all');
  btn.disabled = true;
  btn.textContent = '⏳ Scan en cours...';

  const ids = Object.keys(printerIPs).filter(id => printerIPs[id]);
  let done = 0;
  for (let i = 0; i < ids.length; i += 3) {
    await Promise.all(ids.slice(i, i + 3).map(id => {
      const rowBtn = document.querySelector(`[data-scan-btn="${id}"]`);
      return scanOneInk(parseInt(id), rowBtn);
    }));
    done += Math.min(3, ids.length - i);
    btn.textContent = `⏳ ${done}/${ids.length}...`;
  }

  btn.disabled = false;
  btn.textContent = '🔄 Tout rescanner';
}

async function scanOneInk(pid, btn) {
  const cell = document.getElementById('ink-row-' + pid);
  if (!cell || !printerIPs[pid]) return;

  // Feedback sur le bouton
  const origTxt = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  cell.innerHTML = `<span style="font-size:.72rem;color:var(--text3)">⏳ scan...</span>`;

  let data;
  if (!SNMP_OK) {
    await new Promise(r => setTimeout(r, 500 + Math.random()*600));
    data = { reachable: true, demo: true, supplies: buildDemoSupplies() };
  } else {
    try {
      const r = await fetch(`index.php?ajax_snmp=1&printer_id=${pid}&community=public`);
      data = await r.json();
    } catch(e) {
      cell.innerHTML = `<span style="font-size:.72rem;color:var(--danger)">❌ erreur réseau</span>`;
      if (btn) { btn.disabled = false; btn.textContent = origTxt; }
      return;
    }
  }

  if (!data.reachable || data.error === 'unreachable') {
    cell.innerHTML = `<span style="font-size:.72rem;color:var(--danger)" title="${printerIPs[pid]}">🔴 inaccessible</span>`;
    if (btn) { btn.disabled = false; btn.title = 'Réessayer'; }
    return;
  }

  renderInkRow(pid, data.supplies, data.demo);
  if (btn) { btn.disabled = false; btn.textContent = '🔄'; btn.title = 'Actualiser'; }
}
</script>
<?php
}

function pagePrinterView(PDO $db, int $id): void {
    if (!$id) { header('Location: index.php?page=printers'); exit; }

    $st = $db->prepare("SELECT p.*, s.name as service_name FROM printers p LEFT JOIN services s ON p.service_id=s.id WHERE p.id=?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { header('Location: index.php?page=printers'); exit; }

    // Cartouches compatibles avec stock
    $carts = $db->prepare("SELECT cm.*, COALESCE(s.quantity_available,0) as qty FROM cartridge_models cm JOIN printer_cartridges pc ON pc.cartridge_model_id=cm.id LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE pc.printer_id=? ORDER BY cm.color");
    $carts->execute([$id]);
    $cartridges = $carts->fetchAll();

    // Historique des 10 dernières sorties pour cette imprimante
    $hist = $db->prepare("SELECT se.*, cm.brand, cm.model, cm.color, u.full_name FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN users u ON se.created_by=u.id WHERE se.printer_id=? ORDER BY se.exit_date DESC LIMIT 10");
    $hist->execute([$id]);
    $history = $hist->fetchAll();

    // Consommation totale par cartouche sur cette imprimante
    $cons = $db->prepare("SELECT cm.brand, cm.model, cm.color, SUM(se.quantity) as total FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id WHERE se.printer_id=? GROUP BY cm.id ORDER BY total DESC");
    $cons->execute([$id]);
    $consumption = $cons->fetchAll();

    // Indicateur consommation : mois en cours + année en cours
    $stCM = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_exits WHERE printer_id=? AND MONTH(exit_date)=MONTH(NOW()) AND YEAR(exit_date)=YEAR(NOW())");
    $stCM->execute([$id]); $consThisMonth = (int)$stCM->fetchColumn();
    $stCY = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_exits WHERE printer_id=? AND YEAR(exit_date)=YEAR(NOW())");
    $stCY->execute([$id]); $consThisYear = (int)$stCY->fetchColumn();
    $stAvg = $db->prepare("SELECT COALESCE(AVG(monthly),0) FROM (SELECT DATE_FORMAT(exit_date,'%Y-%m') as mo, SUM(quantity) as monthly FROM stock_exits WHERE printer_id=? GROUP BY mo) t");
    $stAvg->execute([$id]); $consAvgMonth = round((float)$stAvg->fetchColumn(), 1);

    $hasIP = !empty($p['ip_address']);
    $warrantyExpired = $p['warranty_end'] && $p['warranty_end'] < date('Y-m-d');
    $warrantyOk      = $p['warranty_end'] && $p['warranty_end'] >= date('Y-m-d');
?>
<!-- ─── BREADCRUMB ─── -->
<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-size:.85rem;color:var(--text3)">
  <a href="index.php?page=printers" style="color:var(--text3);text-decoration:none;transition:color .15s" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text3)'">← Imprimantes</a>
  <span>/</span>
  <span style="color:var(--text2)"><?=h($p['brand'].' '.$p['model'])?></span>
</div>

<!-- ─── HEADER FICHE ─── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <div style="display:flex;align-items:center;gap:1.25rem">
    <div style="width:64px;height:64px;background:var(--primary-dim);border:2px solid var(--border2);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:2rem;flex-shrink:0">🖨️</div>
    <div>
      <h1 style="font-family:var(--font-display);font-size:1.6rem;font-weight:800;letter-spacing:-.5px"><?=h($p['brand'].' '.$p['model'])?></h1>
      <div style="display:flex;align-items:center;gap:.75rem;margin-top:.35rem;flex-wrap:wrap">
        <?=statusBadge($p['status'])?>
        <?php if($p['service_name']): ?><span style="font-size:.82rem;color:var(--text2)">🏢 <?=h($p['service_name'])?></span><?php endif ?>
        <?php if($p['location']): ?><span style="font-size:.82rem;color:var(--text2)">📍 <?=h($p['location'])?></span><?php endif ?>
        <?php if($p['ip_address']): ?><code style="font-family:var(--font-mono);font-size:.75rem;background:var(--bg3);padding:.15rem .5rem;border-radius:4px;color:var(--text2)"><?=h($p['ip_address'])?></code><?php endif ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:.6rem">
    <button class="btn-primary" title="Modifier"
      onclick="openPrinterEdit(this)"
      data-printer='<?=htmlspecialchars(json_encode(["id"=>(int)$p["id"],"brand"=>(string)($p["brand"]??""),"model"=>(string)($p["model"]??""),"serial_number"=>(string)($p["serial_number"]??""),"ip_address"=>(string)($p["ip_address"]??""),"location"=>(string)($p["location"]??""),"status"=>(string)($p["status"]??"active"),"service_id"=>$p["service_id"]?(int)$p["service_id"]:null,"purchase_date"=>(string)($p["purchase_date"]??""),"warranty_end"=>(string)($p["warranty_end"]??""),"notes"=>(string)($p["notes"]??"")],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'
      data-cids='<?=htmlspecialchars(json_encode(getPrinterCartridgeIds($db,(int)$p["id"])),ENT_QUOTES)?>'>✏️ Modifier</button>
  </div>
</div>

<!-- ─── INFOS + ENCRE ─── -->
<div style="display:grid;grid-template-columns:320px 1fr;gap:1.25rem;margin-bottom:1.5rem;align-items:start">

  <!-- Infos générales -->
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Informations</span></div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.85rem">
      <?php $rows=[
        ['🏷️','Marque',$p['brand']],['📟','Modèle',$p['model']],
        ['🔢','N° série',$p['serial_number']??'–'],
        ['🌐','Adresse IP',$p['ip_address']??'–'],
        ['📍','Emplacement',$p['location']??'–'],
        ['🏢','Service',$p['service_name']??'–'],
        ['📅','Date d\'achat',$p['purchase_date']?date('d/m/Y',strtotime($p['purchase_date'])):'–'],
      ];
      foreach($rows as [$ic,$lb,$vl]): ?>
      <div style="display:flex;align-items:center;gap:.75rem;font-size:.88rem">
        <span style="width:20px;text-align:center"><?=$ic?></span>
        <span style="color:var(--text3);min-width:110px"><?=h($lb)?></span>
        <span style="color:var(--text);font-weight:500"><?=h($vl)?></span>
      </div>
      <?php endforeach ?>
      <!-- Garantie avec badge coloré -->
      <div style="display:flex;align-items:center;gap:.75rem;font-size:.88rem">
        <span style="width:20px;text-align:center">🛡️</span>
        <span style="color:var(--text3);min-width:110px">Garantie</span>
        <span>
          <?php if($warrantyExpired): ?>
            <span class="badge badge-danger">Expirée le <?=date('d/m/Y',strtotime($p['warranty_end']))?></span>
          <?php elseif($warrantyOk): ?>
            <span class="badge badge-success">Jusqu'au <?=date('d/m/Y',strtotime($p['warranty_end']))?></span>
          <?php else: ?>
            <span style="color:var(--text3)">–</span>
          <?php endif ?>
        </span>
      </div>
      <?php if($p['notes']): ?>
      <div style="background:var(--bg3);border-radius:var(--radius-sm);padding:.75rem;font-size:.85rem;color:var(--text2);margin-top:.25rem;line-height:1.6">
        <?=nl2br(h($p['notes']))?>
      </div>
      <?php endif ?>
    </div>
  </div>

  <!-- Colonne droite : KPI consommation + niveaux d'encre -->
  <div style="display:flex;flex-direction:column;gap:1rem">

    <!-- KPI consommation -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
      <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">Ce mois</div>
        <div style="font-size:1.8rem;font-weight:800;color:var(--primary);font-family:var(--font-display)"><?=$consThisMonth?></div>
        <div style="font-size:.78rem;color:var(--text3);margin-top:.15rem">cartouche<?=$consThisMonth>1?'s':''?> sortie<?=$consThisMonth>1?'s':''?></div>
      </div>
      <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">Cette année</div>
        <div style="font-size:1.8rem;font-weight:800;color:var(--primary);font-family:var(--font-display)"><?=$consThisYear?></div>
        <div style="font-size:.78rem;color:var(--text3);margin-top:.15rem">cartouche<?=$consThisYear>1?'s':''?> sortie<?=$consThisYear>1?'s':''?></div>
      </div>
      <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">Moy. mensuelle</div>
        <div style="font-size:1.8rem;font-weight:800;color:var(--accent);font-family:var(--font-display)"><?=$consAvgMonth?></div>
        <div style="font-size:.78rem;color:var(--text3);margin-top:.15rem">cartouches / mois</div>
      </div>
    </div>
    <!-- Niveaux d'encre -->
    <div class="card" id="ink-card" style="flex:1">
    <div class="card-header" style="justify-content:space-between">
      <span class="card-title">🎨 Niveaux d'encre</span>
      <div style="display:flex;align-items:center;gap:.75rem">
        <span id="ink-status" style="font-size:.75rem;color:var(--text3);font-family:var(--font-mono)"></span>
        <?php if($hasIP): ?>
          <button class="btn-primary" id="btn-scan" onclick="scanInk()" style="padding:.45rem 1rem;font-size:.82rem">🔍 Scanner</button>
        <?php else: ?>
          <span class="badge badge-warning">Aucune IP configurée</span>
        <?php endif ?>
      </div>
    </div>

    <?php if(!$hasIP): ?>
    <!-- Pas d'IP -->
    <div style="padding:2.5rem;text-align:center;color:var(--text3)">
      <div style="font-size:2.5rem;margin-bottom:.75rem;opacity:.4">🌐</div>
      <p style="font-size:.9rem">Aucune adresse IP renseignée pour cette imprimante.</p>
      <p style="font-size:.82rem;margin-top:.4rem">Ajoutez-en une en <button style="background:none;border:none;color:var(--primary);cursor:pointer;font-size:.82rem;text-decoration:underline"
          onclick="openPrinterEdit(this)"
          data-printer='<?=htmlspecialchars(json_encode(["id"=>(int)$p["id"],"brand"=>(string)($p["brand"]??""),"model"=>(string)($p["model"]??""),"serial_number"=>(string)($p["serial_number"]??""),"ip_address"=>(string)($p["ip_address"]??""),"location"=>(string)($p["location"]??""),"status"=>(string)($p["status"]??"active"),"service_id"=>$p["service_id"]?(int)$p["service_id"]:null,"purchase_date"=>(string)($p["purchase_date"]??""),"warranty_end"=>(string)($p["warranty_end"]??""),"notes"=>(string)($p["notes"]??"")],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'
          data-cids='<?=htmlspecialchars(json_encode(getPrinterCartridgeIds($db,(int)$p["id"])),ENT_QUOTES)?>'>modifiant la fiche</button>.</p>
    </div>

    <?php elseif(!function_exists('snmpget')): ?>
    <!-- SNMP désactivé mais IP présente — mode démo -->
    <div id="ink-content">
      <div style="background:rgba(245,158,11,.08);border-bottom:1px solid rgba(245,158,11,.2);padding:.6rem 1.25rem;font-size:.78rem;color:var(--warning);display:flex;align-items:center;gap:.5rem">
        ⚠️ Extension PHP SNMP non activée — données simulées · <a href="#" style="color:var(--warning)" onclick="showSnmpHelp()">Comment activer ?</a>
      </div>
      <div id="ink-supplies" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem"></div>
    </div>

    <?php else: ?>
    <!-- SNMP OK -->
    <div id="ink-content">
      <div id="ink-supplies" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
        <div style="text-align:center;padding:2rem;color:var(--text3)">
          <div style="font-size:2rem;margin-bottom:.5rem">💤</div>
          <p style="font-size:.88rem">Cliquez sur <strong style="color:var(--text)">Scanner</strong> pour interroger l'imprimante</p>
        </div>
      </div>
    </div>
    <?php endif ?>
  </div><!-- /ink-card -->
  </div><!-- /right column -->
</div><!-- /info grid -->

<!-- ─── CARTOUCHES COMPATIBLES + HISTORIQUE ─── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem">

  <!-- Cartouches compatibles -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">🖋️ Cartouches compatibles</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <span class="badge badge-info"><?=count($cartridges)?> modèle<?=count($cartridges)>1?'s':''?></span>
        <a href="index.php?page=printers" title="Gérer les cartouches via les modèles d'imprimantes"
           style="display:inline-flex;align-items:center;gap:.3rem;background:var(--primary-dim);border:1px solid var(--border2);border-radius:6px;padding:.2rem .55rem;font-size:.75rem;font-weight:600;color:var(--primary);text-decoration:none;transition:all .15s"
           onmouseover="this.style.background='rgba(67,97,238,.25)'" onmouseout="this.style.background='var(--primary-dim)'">
          ＋ Gérer
        </a>
      </div>
    </div>
    <?php if(empty($cartridges)): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--text3);font-size:.88rem">
      Aucune cartouche associée.<br>
      <a href="index.php?page=printers" style="color:var(--primary);font-size:.82rem">Gérer via les modèles d'imprimantes →</a>
    </div>
    <?php else: ?>
    <div style="padding:.75rem 1.25rem;display:flex;flex-wrap:wrap;gap:.45rem">
      <?php foreach($cartridges as $c):
        $low = $c['qty'] <= $c['alert_threshold'];
        $colorMap=['Noir'=>'#e2e8f0','Cyan'=>'#67e8f9','Magenta'=>'#f0abfc','Jaune'=>'#fde68a','Bleu'=>'#38bdf8','Rouge'=>'#ef4444','Vert'=>'#10b981'];
        $dot = $colorMap[$c['color']] ?? '#94a3b8';
      ?>
      <div title="<?=h($c['brand'].' '.$c['model'])?> — Stock : <?=h($c['qty'])?>"
           style="display:inline-flex;align-items:center;gap:.4rem;background:var(--card2);border:1px solid <?=$low?'rgba(239,68,68,.4)':'var(--border)'?>;border-radius:8px;padding:.35rem .7rem;font-size:.8rem;cursor:default">
        <span style="width:8px;height:8px;border-radius:50%;background:<?=$dot?>;flex-shrink:0"></span>
        <span style="font-weight:600"><?=h($c['brand'].' '.$c['model'])?></span>
        <span class="stock-pill <?=$low?'stock-pill-low':'stock-pill-ok'?>" style="padding:.1rem .4rem;font-size:.72rem"><?=h($c['qty'])?></span>
        <?=$low?'<span title="Stock bas">⚠️</span>':''?>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <!-- Consommation totale -->
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Consommation totale</span></div>
    <?php if(empty($consumption)): ?>
    <div style="padding:2rem;text-align:center;color:var(--text3);font-size:.88rem">Aucune sortie enregistrée</div>
    <?php else:
      $maxTotal = max(array_column($consumption,'total')); ?>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
      <?php foreach($consumption as $c): ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:.35rem;font-size:.85rem">
          <span><?=colorDot($c['color'])?> <?=h($c['brand'].' '.$c['model'])?></span>
          <span style="font-family:var(--font-mono);font-weight:600"><?=h($c['total'])?> u.</span>
        </div>
        <div style="height:7px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;background:linear-gradient(90deg,var(--primary),#3a86ff);border-radius:99px;width:<?=round($c['total']/$maxTotal*100)?>%"></div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
</div>

<!-- ─── HISTORIQUE SORTIES ─── -->
<div class="card">
  <div class="card-header">
    <span class="card-title">🕐 Historique des sorties</span>
    <a href="index.php?page=stock_out" class="btn-primary" style="font-size:.8rem;padding:.4rem .9rem">+ Nouvelle sortie</a>
  </div>
  <?php if(empty($history)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3);font-size:.88rem">Aucune sortie enregistrée pour cette imprimante</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Date</th><th>Cartouche</th><th>Qté</th><th>Personne</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($history as $h): ?>
    <tr>
      <td><?=date('d/m/Y',strtotime($h['exit_date']))?></td>
      <td><?=colorDot($h['color'])?> <?=h($h['brand'].' '.$h['model'])?></td>
      <td><span class="stock-pill stock-pill-out">-<?=h($h['quantity'])?></span></td>
      <td><?=h($h['person_name']??$h['full_name']??'–')?></td>
      <td class="muted"><?=h($h['notes'])?:''?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- ─── MODALS (edit) ─── -->
<?php
    $services = $db->query("SELECT id,name FROM services ORDER BY name")->fetchAll();
    $cartridgeModels = $db->query("SELECT id,brand,model,color FROM cartridge_models ORDER BY brand,model")->fetchAll();
    printerModal('modal-edit','edit','Modifier l\'imprimante',$services,$cartridgeModels);
    deleteModal('printer');
?>

<!-- ─── SNMP HELP MODAL ─── -->
<div class="modal-overlay" id="modal-snmp-help">
  <div class="modal modal-sm">
    <div class="modal-header"><h3>⚙️ Activer l'extension SNMP</h3><button class="modal-close" onclick="closeModal('modal-snmp-help')">✕</button></div>
    <div style="padding:1.25rem;color:var(--text2);font-size:.88rem;line-height:1.8">
      <p><strong style="color:var(--text)">Laragon :</strong></p>
      <ol style="margin:.5rem 0 1rem 1.25rem">
        <li>Menu Laragon → PHP → <code>php.ini</code></li>
        <li>Chercher <code>;extension=snmp</code></li>
        <li>Supprimer le <code>;</code> au début</li>
        <li>Sauvegarder → Laragon → <strong>Reload</strong></li>
      </ol>
      <p><strong style="color:var(--text)">Linux :</strong><br>
      <code>sudo apt install php-snmp && sudo systemctl restart apache2</code></p>
      <p style="margin-top:1rem;color:var(--text3);font-size:.8rem">Après redémarrage, rechargez cette page.</p>
    </div>
    <div class="modal-footer"><button class="btn-primary" onclick="closeModal('modal-snmp-help')">Compris</button></div>
  </div>
</div>

<script>



</script>

<script>
// ═══════════════════════════════════════════════
//  SNMP Ink Level – Fiche imprimante
// ═══════════════════════════════════════════════
const PRINTER_ID  = <?=$p['id']?>;
const PRINTER_IP  = '<?=h($p['ip_address']??'')?>';
const SNMP_AVAIL  = <?=function_exists('snmpget')?'true':'false'?>;
const DEMO_MODE   = !SNMP_AVAIL && PRINTER_IP !== '';

// Auto-scan si SNMP dispo et IP présente
<?php if($hasIP && function_exists('snmpget')): ?>
window.addEventListener('DOMContentLoaded', () => setTimeout(scanInk, 400));
<?php elseif($hasIP && !function_exists('snmpget')): ?>
window.addEventListener('DOMContentLoaded', () => setTimeout(loadDemoInk, 400));
<?php endif ?>

function showSnmpHelp() { openModal('modal-snmp-help'); }

async function scanInk() {
  const btn = document.getElementById('btn-scan');
  const status = document.getElementById('ink-status');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Scan...'; }
  status.textContent = 'Interrogation en cours...';

  let data;
  if (!SNMP_AVAIL) {
    await new Promise(r => setTimeout(r, 700));
    data = buildDemoData();
  } else {
    try {
      const community = 'public';
      const r = await fetch(`index.php?ajax_snmp=1&printer_id=${PRINTER_ID}&community=${community}`);
      data = await r.json();
    } catch(e) {
      status.textContent = '❌ Erreur réseau';
      if (btn) { btn.disabled = false; btn.textContent = '🔍 Scanner'; }
      return;
    }
  }

  renderInk(data);
  if (btn) { btn.disabled = false; btn.textContent = '🔄 Actualiser'; }
  status.textContent = data.demo ? '⚠️ Démo · ' + now() : '✅ ' + now();
}

function loadDemoInk() { renderInk(buildDemoData()); }

function buildDemoData() {
  return {
    reachable: true, demo: true,
    supplies: [
      {description:'Black Toner',   percent:Math.floor(Math.random()*75+5),  color:['#111','#e2e8f0','Noir']},
      {description:'Cyan Toner',    percent:Math.floor(Math.random()*80+10), color:['#0e4f6e','#67e8f9','Cyan']},
      {description:'Magenta Toner', percent:Math.floor(Math.random()*60+5),  color:['#6b1a4f','#f0abfc','Magenta']},
      {description:'Yellow Toner',  percent:Math.floor(Math.random()*70+5),  color:['#4a3500','#fde68a','Jaune']},
    ]
  };
}

function renderInk(data) {
  const container = document.getElementById('ink-supplies');
  if (!container) return;

  if (!data.reachable || data.error === 'unreachable') {
    container.innerHTML = `
      <div style="text-align:center;padding:2rem;color:var(--text3)">
        <div style="font-size:2rem;margin-bottom:.5rem">🔴</div>
        <p style="font-size:.88rem">Imprimante inaccessible (${PRINTER_IP})</p>
        <p style="font-size:.78rem;margin-top:.3rem;font-family:var(--font-mono)">Vérifiez le réseau ou la configuration SNMP</p>
      </div>`;
    return;
  }

  if (!data.supplies || data.supplies.length === 0) {
    container.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--text3);font-size:.88rem">Aucune donnée de consommable disponible via SNMP</div>`;
    return;
  }

  container.innerHTML = data.supplies.map(s => {
    const pct = s.percent;
    const [bg, fg] = s.color;
    let barGrad, pctColor, pctText, label;

    if      (pct < 0)  { barGrad='var(--text3)'; pctColor='var(--text3)'; pctText='Inconnu';  label='bar-unknown'; }
    else if (pct < 10) { barGrad='linear-gradient(90deg,#dc2626,#ef4444)'; pctColor='#ef4444'; pctText=pct+'%'; label='critique'; }
    else if (pct < 25) { barGrad='linear-gradient(90deg,#d97706,#f59e0b)'; pctColor='#f59e0b'; pctText=pct+'%'; label='faible'; }
    else               { barGrad='linear-gradient(90deg,#059669,#10b981)'; pctColor='#10b981'; pctText=pct+'%'; label='ok'; }

    const width = pct < 0 ? 2 : Math.max(2, pct);

    return `
    <div style="display:flex;align-items:center;gap:1rem">
      <!-- Dot couleur cartouche -->
      <div style="width:12px;height:12px;border-radius:50%;background:${fg};border:2px solid rgba(255,255,255,.15);flex-shrink:0"></div>
      <!-- Label -->
      <div style="min-width:140px;font-size:.85rem;font-weight:500;color:var(--text2)">${escHtml(s.description)}</div>
      <!-- Barre -->
      <div style="flex:1;height:10px;background:var(--bg3);border-radius:99px;overflow:hidden">
        <div data-w="${width}%" style="height:100%;border-radius:99px;background:${barGrad};width:0%;transition:width 1s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden">
          <div style="position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.15),transparent);animation:shimmer 2s infinite"></div>
        </div>
      </div>
      <!-- % -->
      <div style="min-width:52px;text-align:right;font-family:var(--font-mono);font-size:.88rem;font-weight:700;color:${pctColor}">${pctText}</div>
    </div>`;
  }).join('');

  // Animer les barres
  requestAnimationFrame(() => {
    container.querySelectorAll('[data-w]').forEach(el => {
      el.style.width = el.dataset.w;
    });
  });
}

function now() { return new Date().toLocaleTimeString('fr-FR'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }


</script>
<?php
}

function getPrinterCartridgeIds(PDO $db, int $pid): array {
    $st = $db->prepare("SELECT cartridge_model_id FROM printer_cartridges WHERE printer_id=?");
    $st->execute([$pid]);
    return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}

function printerModal(string $mid, string $act, string $title, array $services, array $carts, array $printerModels=[]): void { ?>
<div class="modal-overlay" id="<?=$mid?>">
  <div class="modal modal-xl"><div class="modal-header"><h3><?=h($title)?></h3><button class="modal-close" onclick="closeModal('<?=$mid?>')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="printer"><input type="hidden" name="_action" value="<?=$act?>">
  <?php if($act==='edit'):?><input type="hidden" name="_id" id="edit-id"><?php endif;?>
  <div class="form-grid">

    <?php if($act==='add' && !empty($printerModels)): ?>
    <!-- Sélecteur de modèle -->
    <div class="form-group form-full" style="background:var(--primary-dim);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.85rem 1.1rem">
      <label style="color:var(--primary)">📋 Modèle d'imprimante</label>
      <select id="<?=$act?>-model-select" name="printer_model_id" onchange="fillFromPrinterModel('<?=$act?>')" style="margin-top:.4rem" required>
        <option value="">— Choisir un modèle —</option>
        <?php foreach($printerModels as $pm): ?>
        <option value="<?=$pm['id']?>" data-brand="<?=h($pm['brand'])?>" data-model="<?=h($pm['model'])?>">
          <?=h($pm['brand'].' '.$pm['model'])?>
        </option>
        <?php endforeach ?>
      </select>
    </div>
    <?php endif ?>

    <?php if($act==='add'): ?>
    <!-- En mode ajout : marque/modèle toujours en lecture seule, remplis par le sélecteur de modèle -->
    <div class="form-group">
      <label>Marque</label>
      <div id="add-brand-display" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.7rem 1rem;font-size:.9rem;color:var(--text3);font-style:italic">— sélectionner un modèle —</div>
      <input type="hidden" name="brand" id="add-brand">
    </div>
    <div class="form-group">
      <label>Modèle</label>
      <div id="add-model-display" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.7rem 1rem;font-size:.9rem;color:var(--text3);font-style:italic">— sélectionner un modèle —</div>
      <input type="hidden" name="model" id="add-model">
    </div>
    <?php else: ?>
    <!-- En mode édition : marque/modèle affichées en lecture seule (définies par le modèle) -->
    <div class="form-group">
      <label>Marque</label>
      <div id="edit-brand-display" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.7rem 1rem;font-size:.9rem;color:var(--text2)">–</div>
      <input type="hidden" name="brand" id="edit-brand">
    </div>
    <div class="form-group">
      <label>Modèle</label>
      <div id="edit-model-display" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.7rem 1rem;font-size:.9rem;color:var(--text2)">–</div>
      <input type="hidden" name="model" id="edit-model">
    </div>
    <?php endif ?>
    <div class="form-group"><label>Service</label><select name="service_id" id="<?=$act?>-service_id">
      <option value="">-- Aucun --</option>
      <?php foreach($services as $s): ?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?>
    </select></div>
    <div class="form-group"><label>Statut</label><select name="status" id="<?=$act?>-status">
      <option value="active">Actif</option><option value="inactive">Inactif</option><option value="maintenance">Maintenance</option>
    </select></div>
    <div class="form-group"><label>N° de série</label><input type="text" name="serial_number" id="<?=$act?>-serial_number"></div>
    <div class="form-group"><label>Adresse IP</label><input type="text" name="ip_address" id="<?=$act?>-ip_address" placeholder="192.168.1.x"></div>
    <div class="form-group form-full"><label>Emplacement</label><input type="text" name="location" id="<?=$act?>-location" placeholder="Bâtiment A, Bureau 214..."></div>
    <div class="form-group"><label>Date d'achat</label><input type="date" name="purchase_date" id="<?=$act?>-purchase_date"></div>
    <div class="form-group"><label>Fin de garantie</label><input type="date" name="warranty_end" id="<?=$act?>-warranty_end"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="<?=$act?>-notes" rows="2"></textarea></div>

    <?php if(!empty($carts)):?>
    <div class="form-group form-full" id="<?=$act?>-cart-section">
      <label>Cartouches compatibles
        <?php if($act==='add' && !empty($printerModels)): ?>
        <span id="<?=$act?>-cart-source" style="font-size:.72rem;color:var(--text3);font-weight:400"> — héritées du modèle</span>
        <?php endif ?>
      </label>
      <!-- Zone lecture seule quand modèle sélectionné (add) -->
      <?php if($act==='add' && !empty($printerModels)): ?>
      <div id="<?=$act?>-cart-readonly" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem 1rem;color:var(--text3);font-size:.85rem;font-style:italic">
        Sélectionnez un modèle ci-dessus pour voir les cartouches associées.
      </div>
      <!-- Inputs cachés pour soumettre les cids venant du modèle -->
      <div id="<?=$act?>-cart-hidden"></div>
      <?php else: ?>
      <!-- Edition : cartouches gérées par modèle, affichage lecture seule -->
      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:.82rem">
        <div id="edit-cart-list" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem">
          <span style="color:var(--text3);font-style:italic">Chargement…</span>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem;border-top:1px solid var(--border);padding-top:.5rem;margin-top:.25rem;font-size:.78rem;color:var(--text3)">
          🔒 Définies par le modèle —
          <a href="index.php?page=printers&tab=models" style="color:var(--primary);text-decoration:underline">Gérer les modèles →</a>
        </div>
      </div>
      <?php endif ?>
    </div>
    <?php endif;?>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('<?=$mid?>')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php }

function pageStockIn(PDO $db): void {
    $entriesAll = $db->query("SELECT se.*, cm.brand, cm.model, cm.color, sp.name as supplier_name, u.full_name as user_name FROM stock_entries se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN suppliers sp ON se.supplier_id=sp.id LEFT JOIN users u ON se.created_by=u.id ORDER BY se.created_at DESC")->fetchAll();
    $pgIn = paginate($entriesAll, 25);
    $entries = $pgIn['items'];
    $cartridges = $db->query("SELECT id,brand,model,color FROM cartridge_models WHERE active=1 OR active IS NULL ORDER BY brand,model")->fetchAll();
    $suppliers = $db->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();

    // Commandes en cours avec leurs lignes non entièrement reçues
    $pendingOrders = [];
    try {
        $pendingOrders = $db->query(
            "SELECT po.id, po.order_date, COALESCE(sp.name,'Sans fournisseur') as supplier_name, po.supplier_id,
             po.status, po.expected_date
             FROM purchase_orders po
             LEFT JOIN suppliers sp ON po.supplier_id = sp.id
             WHERE po.status IN ('pending','partial')
             ORDER BY po.order_date DESC"
        )->fetchAll();
        foreach ($pendingOrders as &$ord) {
            $st = $db->prepare(
                "SELECT pol.id, pol.cartridge_model_id, cm.brand, cm.model, cm.color,
                 pol.quantity_ordered, pol.quantity_received, pol.unit_price,
                 (pol.quantity_ordered - pol.quantity_received) as qty_remaining
                 FROM purchase_order_lines pol
                 JOIN cartridge_models cm ON pol.cartridge_model_id = cm.id
                 WHERE pol.order_id = ? AND pol.quantity_ordered > pol.quantity_received
                 ORDER BY cm.brand, cm.model"
            );
            $st->execute([$ord['id']]);
            $ord['lines'] = $st->fetchAll();
        }
        unset($ord);
        // Ne garder que les commandes qui ont encore des lignes à recevoir
        $pendingOrders = array_values(array_filter($pendingOrders, fn($o) => !empty($o['lines'])));
    } catch(Exception $e) {}
?>
<div class="page-header"><span class="page-title-txt">📦 Entrées de Stock</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Nouvelle entrée</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Date</th><th>Cartouche</th><th>Quantité</th><th>Fournisseur</th><th>Prix unit.</th><th>Réf. facture</th><th>Enregistré par</th><th>Notes</th></tr></thead>
    <tbody>
    <?php if(empty($entries)): ?><tr><td colspan="8" class="empty-cell">Aucune entrée de stock</td></tr>
    <?php else: foreach($entries as $e): ?>
    <tr>
      <td><?=date('d/m/Y',strtotime($e['entry_date']))?></td>
      <td><?=colorDot($e['color'])?> <strong><?=h($e['brand'].' '.$e['model'])?></strong></td>
      <td><span class="stock-pill stock-pill-ok">+<?=h($e['quantity'])?></span></td>
      <td><?=h($e['supplier_name']??'N/A')?></td>
      <td><?=$e['unit_price']?number_format($e['unit_price'],2,',',' ').' €':'–'?></td>
      <td><code class="ref"><?=h($e['invoice_ref'])?:'-'?></code></td>
      <td><?=h($e['user_name']??'–')?></td>
      <td class="muted"><?=h($e['notes'])?:''?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgIn)?>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📦 Nouvelle entrée de stock</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="stock_in"><input type="hidden" name="_action" value="add">

  <?php if(!empty($pendingOrders)): ?>
  <!-- Sélecteur commande en cours -->
  <div style="background:rgba(67,97,238,.08);border:1px solid rgba(67,97,238,.25);border-radius:var(--radius-sm);padding:.85rem 1.1rem;margin-bottom:1.25rem">
    <label style="display:block;font-size:.75rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem">🛒 Lier à une commande en cours</label>
    <select id="si-order-select" onchange="stockInFillFromOrder(this.value)" style="width:100%;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.6rem .85rem;color:var(--text);font-size:.88rem">
      <option value="">— Saisie manuelle (sans commande) —</option>
      <?php foreach($pendingOrders as $ord): ?>
      <option value="<?=$ord['id']?>">
        #<?=str_pad($ord['id'],4,'0',STR_PAD_LEFT)?> · <?=h($ord['supplier_name'])?>
        · <?=date('d/m/Y',strtotime($ord['order_date']))?>
        <?=$ord['expected_date']?' (prévu '.date('d/m/Y',strtotime($ord['expected_date'])).')':''?>
        · <?=count($ord['lines'])?> ligne(s) restante(s)
      </option>
      <?php endforeach ?>
    </select>
    <!-- Résumé des lignes restantes (affiché dynamiquement) -->
    <div id="si-order-lines" style="margin-top:.65rem;display:flex;flex-wrap:wrap;gap:.35rem"></div>
  </div>
  <?php endif ?>

  <div class="form-grid">
    <div class="form-group form-full"><label style="display:flex;align-items:center;justify-content:space-between">Cartouche * <button type="button" onclick="openQrScanner('si-cartridge','si')" class="btn-secondary" style="font-size:.75rem;padding:.25rem .65rem;font-weight:500">📷 Scanner QR</button></label>
      <select name="cartridge_model_id" id="si-cartridge" required>
        <option value="">-- Sélectionner --</option>
        <?php foreach($cartridges as $c):?><option value="<?=$c['id']?>"><?=h($c['brand'].' '.$c['model'].' ('.$c['color'].')')?></option><?php endforeach;?>
      </select></div>
    <div class="form-group"><label>Quantité *</label><input type="number" name="quantity" id="si-qty" min="1" required></div>
    <div class="form-group"><label>Date d'entrée *</label><input type="date" name="entry_date" id="si-date" value="<?=date('Y-m-d')?>" required></div>
    <div class="form-group"><label>Fournisseur</label><select name="supplier_id" id="si-supplier">
      <option value="">-- Aucun --</option>
      <?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?>
    </select></div>
    <div class="form-group"><label>Prix unitaire (€)</label><input type="number" name="unit_price" id="si-price" step="0.01" min="0"></div>
    <div class="form-group"><label>Réf. facture / bon de commande</label><input type="text" name="invoice_ref" id="si-ref" placeholder="FAC-2024-001"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="si-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">✅ Valider l'entrée</button></div>
  </form></div>
</div>

<script>
// Données commandes pour l'autofill
const SI_ORDERS = <?=json_encode(
    array_map(function($ord) {
        return [
            'id'          => (int)$ord['id'],
            'supplier_id' => $ord['supplier_id'] ? (int)$ord['supplier_id'] : null,
            'ref'         => 'CMD-'.str_pad($ord['id'],4,'0',STR_PAD_LEFT),
            'lines'       => array_map(function($l) {
                return [
                    'cartridge_model_id' => (int)$l['cartridge_model_id'],
                    'label'  => $l['brand'].' '.$l['model'].' ('.$l['color'].')',
                    'qty'    => (int)$l['qty_remaining'],
                    'price'  => (float)$l['unit_price'],
                ];
            }, $ord['lines']),
        ];
    }, $pendingOrders)
, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

function stockInFillFromOrder(orderId) {
    const linesDiv = document.getElementById('si-order-lines');
    linesDiv.innerHTML = '';
    if (!orderId) return;

    const ord = SI_ORDERS.find(o => o.id == orderId);
    if (!ord) return;

    // Pré-remplir le fournisseur et la référence
    const supSel = document.getElementById('si-supplier');
    if (supSel && ord.supplier_id) supSel.value = ord.supplier_id;
    const refInp = document.getElementById('si-ref');
    if (refInp && !refInp.value) refInp.value = ord.ref;

    if (ord.lines.length === 0) return;

    // Si une seule ligne : auto-remplir cartouche + qté + prix
    if (ord.lines.length === 1) {
        const l = ord.lines[0];
        const sel = document.getElementById('si-cartridge');
        if (sel) sel.value = l.cartridge_model_id;
        const qty = document.getElementById('si-qty');
        if (qty) qty.value = l.qty;
        const price = document.getElementById('si-price');
        if (price && l.price) price.value = l.price.toFixed(2);
        linesDiv.innerHTML = '<span style="font-size:.78rem;color:var(--success)">✅ Ligne pré-remplie automatiquement</span>';
        return;
    }

    // Plusieurs lignes : afficher les badges cliquables pour choisir
    linesDiv.innerHTML = '<span style="font-size:.75rem;color:var(--text3);margin-right:.35rem">Choisir une ligne :</span>';
    ord.lines.forEach(function(l) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.style.cssText = 'background:var(--primary-dim);border:1px solid var(--border2);border-radius:6px;padding:.2rem .6rem;font-size:.75rem;color:var(--primary);cursor:pointer;transition:all .15s;white-space:nowrap';
        btn.onmouseover = () => btn.style.background = 'rgba(67,97,238,.3)';
        btn.onmouseout  = () => btn.style.background = 'var(--primary-dim)';
        btn.textContent = l.label + ' ×' + l.qty;
        btn.onclick = function() {
            const sel = document.getElementById('si-cartridge');
            if (sel) sel.value = l.cartridge_model_id;
            const qty = document.getElementById('si-qty');
            if (qty) qty.value = l.qty;
            const price = document.getElementById('si-price');
            if (price && l.price) price.value = l.price.toFixed(2);
            // Marquer ce bouton comme sélectionné
            linesDiv.querySelectorAll('button').forEach(b => b.style.background = 'var(--primary-dim)');
            btn.style.background = 'rgba(67,97,238,.45)';
            btn.style.borderColor = 'var(--primary)';
        };
        linesDiv.appendChild(btn);
    });
}
</script>

<?php }

function pageStockOut(PDO $db): void {
    $filterSvc = (int)($_GET['fsvc'] ?? 0);
    $filterFrom = $_GET['from'] ?? '';
    $filterTo   = $_GET['to']   ?? '';
    $whereClause = '1=1';
    if ($filterSvc)  $whereClause .= " AND se.service_id = $filterSvc";
    if ($filterFrom) $whereClause .= " AND se.exit_date >= ".($db->quote($filterFrom));
    if ($filterTo)   $whereClause .= " AND se.exit_date <= ".($db->quote($filterTo));
    $services = $db->query("SELECT id,name FROM services ORDER BY name")->fetchAll();
    $exitsAll = $db->query("SELECT se.*, cm.brand, cm.model, cm.color, sv.name as service_name, p.brand as printer_brand, p.model as printer_model, u.full_name as user_name FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN services sv ON se.service_id=sv.id LEFT JOIN printers p ON se.printer_id=p.id LEFT JOIN users u ON se.created_by=u.id WHERE $whereClause ORDER BY se.exit_date DESC, se.id DESC")->fetchAll();
    $pgOut = paginate($exitsAll, 25);
    $exits = $pgOut['items'];
    $cartridges = $db->query("SELECT cm.id,cm.brand,cm.model,cm.color,COALESCE(s.quantity_available,0) as qty FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE cm.active=1 OR cm.active IS NULL ORDER BY cm.brand,cm.model")->fetchAll();
    $printers = $db->query("SELECT p.id, CONCAT(p.brand,' ',p.model,' - ',COALESCE(s.name,'?')) as label, p.service_id FROM printers p LEFT JOIN services s ON p.service_id=s.id ORDER BY p.brand,p.model")->fetchAll();

    // Map imprimante → cartouches compatibles (pour filtrage JS)
    $printerCartridges = [];
    try {
        $rows = $db->query("SELECT pc.printer_id, pc.cartridge_model_id FROM printer_cartridges pc")->fetchAll();
        foreach ($rows as $r) {
            $printerCartridges[(int)$r['printer_id']][] = (int)$r['cartridge_model_id'];
        }
    } catch(Exception $e) {}

    // Demandes actives avec détail service, pour alertes dynamiques JS
    $demandsRaw = $db->query(
        "SELECT r.id, r.cartridge_model_id, r.service_id, r.quantity_requested, r.quantity_fulfilled,
         COALESCE(sv.name,'Sans service') as service_name,
         (r.quantity_requested - r.quantity_fulfilled) as qty_remain
         FROM reservations r
         LEFT JOIN services sv ON r.service_id = sv.id
         WHERE r.status IN ('pending','partial')
         ORDER BY r.requested_date"
    )->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">📤 Sorties de Stock</span>
  <div style="display:flex;gap:.6rem;align-items:center">
    <a href="index.php?page=export_exits&<?=http_build_query(['fsvc'=>$filterSvc,'from'=>$filterFrom,'to'=>$filterTo])?>" class="btn-secondary" style="font-size:.82rem">📥 Export Excel</a>
    <button class="btn-primary" onclick="openModal('modal-add')">+ Enregistrer une sortie</button>
  </div>
</div>
<!-- Filtres -->
<form method="get" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem">
  <input type="hidden" name="page" value="stock_out">
  <div>
    <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:.2rem">Service</label>
    <select name="fsvc" style="padding:.45rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--card2);color:var(--text);font-size:.85rem">
      <option value="0">Tous les services</option>
      <?php foreach($services as $s): ?><option value="<?=$s['id']?>" <?=$filterSvc===$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach ?>
    </select>
  </div>
  <div>
    <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:.2rem">Du</label>
    <input type="date" name="from" value="<?=h($filterFrom)?>" style="padding:.45rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--card2);color:var(--text);font-size:.85rem">
  </div>
  <div>
    <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:.2rem">Au</label>
    <input type="date" name="to" value="<?=h($filterTo)?>" style="padding:.45rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--card2);color:var(--text);font-size:.85rem">
  </div>
  <button type="submit" class="btn-primary" style="font-size:.85rem;padding:.5rem 1rem">Filtrer</button>
  <?php if($filterSvc || $filterFrom || $filterTo): ?>
  <a href="index.php?page=stock_out" class="btn-secondary" style="font-size:.85rem;padding:.5rem 1rem">✕ Réinitialiser</a>
  <?php endif ?>
</form>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Date</th><th>Cartouche</th><th>Qté</th><th>Service</th><th>Imprimante</th><th>Récupérée par</th><th>Délivré par</th><th>Notes</th></tr></thead>
    <tbody>
    <?php if(empty($exits)): ?><tr><td colspan="8" class="empty-cell">Aucune sortie enregistrée</td></tr>
    <?php else: foreach($exits as $e): ?>
    <tr>
      <td><?=date('d/m/Y',strtotime($e['exit_date']))?></td>
      <td><?=colorDot($e['color'])?> <strong><?=h($e['brand'].' '.$e['model'])?></strong></td>
      <td><span class="stock-pill stock-pill-out">-<?=h($e['quantity'])?></span></td>
      <td><?=h($e['service_name']??'–')?></td>
      <td><?=$e['printer_brand']?h($e['printer_brand'].' '.$e['printer_model']):'–'?></td>
      <td><?=h($e['person_name']??'–')?></td>
      <td><?=h($e['user_name']??'–')?></td>
      <td class="muted"><?=h($e['notes'])?:''?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgOut)?>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📤 Enregistrer une sortie</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post" id="form-stock-out"><input type="hidden" name="_entity" value="stock_out"><input type="hidden" name="_action" value="add">

  <!-- Bannière dynamique demandes -->
  <div id="so-demand-banner" style="display:none;border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem"></div>

  <div class="form-grid">
    <div class="form-group"><label>Service</label>
      <select name="service_id" id="so-service" onchange="soServiceChange()">
        <option value="">-- Aucun --</option>
        <?php foreach($services as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label>Imprimante associée</label>
      <select name="printer_id" id="so-printer" onchange="soAutoService()">
        <option value="">-- Aucune --</option>
        <?php foreach($printers as $p):?><option value="<?=$p['id']?>" data-service="<?=(int)$p['service_id']?>"><?=h($p['label'])?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group form-full"><label style="display:flex;align-items:center;justify-content:space-between">Cartouche * <button type="button" onclick="openQrScanner('so-cartridge','so')" class="btn-secondary" style="font-size:.75rem;padding:.25rem .65rem;font-weight:500">📷 Scanner QR</button></label>
      <select name="cartridge_model_id" id="so-cartridge" onchange="soUpdate()" required>
        <option value="">-- Sélectionner --</option>
        <?php foreach($cartridges as $c):?>
        <option value="<?=$c['id']?>" data-qty="<?=$c['qty']?>" <?=$c['qty']<1?'style="color:#ef4444"':''?>><?=h($c['brand'].' '.$c['model'].' ('.$c['color'].') – Stock: '.$c['qty'])?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label>Quantité *</label><input type="number" name="quantity" id="so-qty" min="1" value="1" onchange="soUpdate()" required></div>
    <div class="form-group"><label>Date de sortie *</label><input type="date" name="exit_date" value="<?=date('Y-m-d')?>" required></div>
    <div class="form-group"><label>Nom de la personne</label><input type="text" name="person_name" placeholder="Prénom Nom"></div>
    <div class="form-group form-full" id="so-demand-select-wrap" style="display:none">
      <label id="so-demand-label">Lier à une demande</label>
      <select name="reservation_id" id="so-demand-sel" onchange="soUpdate()">
        <option value="">-- Ne pas lier --</option>
      </select>
    </div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button>
    <button type="submit" id="so-submit" class="btn-primary">✅ Valider la sortie</button>
  </div>
  </form></div>
</div>

<script>
// Données demandes actives par cartouche
const SO_DEMANDS = <?=json_encode(
    array_reduce($demandsRaw, function($map, $r) {
        $cid = (int)$r['cartridge_model_id'];
        if (!isset($map[$cid])) $map[$cid] = [];
        $map[$cid][] = [
            'id'           => (int)$r['id'],
            'service_id'   => $r['service_id'] ? (int)$r['service_id'] : null,
            'service_name' => $r['service_name'],
            'qty_remain'   => (int)$r['qty_remain'],
        ];
        return $map;
    }, [])
, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

// Cartouches compatibles par imprimante : {printer_id: [cid, ...]}
const SO_PRINTER_CIDS = <?=json_encode($printerCartridges, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

// Pré-remplissage depuis une demande
<?php
$prefillCid = (int)($_GET['prefill_cid'] ?? 0);
$prefillSvc = (int)($_GET['prefill_svc'] ?? 0);
$prefillPrt = (int)($_GET['prefill_prt'] ?? 0);
$prefillQty = (int)($_GET['prefill_qty'] ?? 1);
$prefillRid = (int)($_GET['prefill_rid'] ?? 0);
if ($prefillCid): ?>
window.addEventListener('DOMContentLoaded', function() {
    // 1. Service → filtre imprimantes
    const svcSel = document.getElementById('so-service');
    if (svcSel && <?=$prefillSvc?>) {
        svcSel.value = '<?=$prefillSvc?>';
        soServiceChange(); // filtre imprimantes, ne bloque pas encore la cartouche
    }
    // 2. Imprimante → déverrouille + filtre cartouches compatibles
    const prtSel = document.getElementById('so-printer');
    if (prtSel && <?=$prefillPrt?>) {
        prtSel.value = '<?=$prefillPrt?>';
        soAutoService(); // aligne service + filtre cartouches + déverrouille select
    }
    // 3. Cartouche (select déverrouillé à cette étape)
    const crtSel = document.getElementById('so-cartridge');
    if (crtSel) {
        // Forcer le déverrouillage au cas où l'imprimante n'a pas de cartouches listées
        crtSel.disabled = false;
        crtSel.style.opacity = '';
        crtSel.style.cursor = '';
        // Rendre visible l'option correspondante si elle était cachée
        Array.from(crtSel.options).forEach(function(o) {
            if (o.value === '<?=$prefillCid?>') o.style.display = '';
        });
        crtSel.value = '<?=$prefillCid?>';
    }
    // 4. Quantité
    const qtyEl = document.getElementById('so-qty');
    if (qtyEl) qtyEl.value = '<?=max(1,$prefillQty)?>';
    // 5. Bannière demande + lien (avec délai pour laisser soUpdate construire les options)
    setTimeout(function() {
        soUpdate();
        <?php if($prefillRid): ?>
        const demSel = document.getElementById('so-demand-sel');
        if (demSel) demSel.value = '<?=$prefillRid?>';
        <?php endif ?>
    }, 80);
});
<?php endif ?>

function soServiceChange() {
    const svcId = document.getElementById('so-service').value;
    const pSel  = document.getElementById('so-printer');
    // Filtrer les imprimantes selon le service
    Array.from(pSel.options).forEach(function(opt) {
        if (!opt.value) { opt.style.display = ''; return; }
        if (!svcId) { opt.style.display = ''; }
        else { opt.style.display = (opt.dataset.service === svcId) ? '' : 'none'; }
    });
    // Reset imprimante si elle n'appartient plus au service sélectionné
    const cur = pSel.options[pSel.selectedIndex];
    if (cur && cur.value && svcId && cur.dataset.service !== svcId) {
        pSel.value = '';
    }
    // Bloquer/débloquer le select cartouche selon imprimante
    soLockCartridgeIfNoPrinter();
    soUpdate();
}

function soAutoService() {
    const pSel = document.getElementById('so-printer');
    const sSel = document.getElementById('so-service');
    const opt  = pSel.options[pSel.selectedIndex];
    const svc  = opt ? opt.dataset.service : '';
    const pid  = parseInt(pSel.value) || 0;

    // Mettre à jour le service si l'imprimante a un service
    if (svc && sSel) { sSel.value = svc; }

    // Filtrer les cartouches selon les compatibilités de l'imprimante
    const cSel = document.getElementById('so-cartridge');
    const allowedCids = pid && SO_PRINTER_CIDS[pid] ? SO_PRINTER_CIDS[pid] : null;
    const prevCid = cSel.value;
    Array.from(cSel.options).forEach(function(opt) {
        if (!opt.value) { opt.style.display = ''; return; }
        if (!allowedCids) { opt.style.display = 'none'; } // masquer tant que pas d'imprimante
        else { opt.style.display = allowedCids.indexOf(parseInt(opt.value)) !== -1 ? '' : 'none'; }
    });
    // Reset cartouche si elle n'est plus dans la liste
    if (!allowedCids || (prevCid && allowedCids.indexOf(parseInt(prevCid)) === -1)) {
        cSel.value = '';
    }

    soLockCartridgeIfNoPrinter();
    soUpdate();
}

function soLockCartridgeIfNoPrinter() {
    const pid   = parseInt(document.getElementById('so-printer').value) || 0;
    const cSel  = document.getElementById('so-cartridge');
    const noOpt = cSel.options[0];
    if (!pid) {
        cSel.disabled = true;
        cSel.style.opacity = '.45';
        cSel.style.cursor  = 'not-allowed';
        if (noOpt) noOpt.textContent = '— Sélectionner une imprimante d\'abord —';
        cSel.value = '';
    } else {
        cSel.disabled = false;
        cSel.style.opacity = '';
        cSel.style.cursor  = '';
        if (noOpt) noOpt.textContent = '-- Sélectionner --';
    }
}

// Appliquer au chargement
document.addEventListener('DOMContentLoaded', function() {
    soLockCartridgeIfNoPrinter();
});

function soUpdate() {
    const cid  = parseInt(document.getElementById('so-cartridge').value) || 0;
    const svc  = document.getElementById('so-service').value || '';
    const svcInt = parseInt(svc) || 0;
    const qty  = parseInt(document.getElementById('so-qty').value)       || 1;
    const banner = document.getElementById('so-demand-banner');
    const wrap   = document.getElementById('so-demand-select-wrap');
    const lbl    = document.getElementById('so-demand-label');
    const sel    = document.getElementById('so-demand-sel');
    const submit = document.getElementById('so-submit');
    const stock  = parseInt(document.getElementById('so-cartridge').options[document.getElementById('so-cartridge').selectedIndex]?.dataset?.qty) || 0;

    banner.style.display = 'none';
    wrap.style.display   = 'none';
    sel.innerHTML = '<option value="">-- Ne pas lier --</option>';
    submit.disabled = false;
    submit.style.opacity = '';

    if (!cid) return;

    const demands = SO_DEMANDS[cid] || [];
    if (!demands.length) return;

    const totalReserved = demands.reduce((s, d) => s + d.qty_remain, 0);
    const freeStock = stock - totalReserved;
    const linkedDemand = parseInt(sel.value) || 0;

    // Trouver les demandes qui correspondent au service sélectionné
    const myDemands  = demands.filter(d => d.service_id === svcInt);
    const otherDems  = demands.filter(d => d.service_id !== svcInt);

    // Peupler le select avec les demandes du bon service en premier
    [...myDemands, ...otherDems].forEach(function(d) {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.dataset.qty = d.qty_remain;
        opt.textContent = (myDemands.includes(d) ? '✅ ' : '⚠️ ') + d.service_name + ' — ×' + d.qty_remain + ' restante(s)';
        if (myDemands.includes(d)) opt.style.color = '#6ee7b7';
        sel.appendChild(opt);
    });

    wrap.style.display   = 'block';
    const currentRid = parseInt(sel.value) || 0;

    if (myDemands.length > 0) {
        // Service a une demande → pré-sélectionner, info verte
        if (!currentRid) sel.value = myDemands[0].id;
        lbl.textContent = '✅ Lier à la demande de ce service';
        banner.style.cssText = 'display:block;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#6ee7b7';
        banner.innerHTML = '✅ Ce service a <strong>' + myDemands.length + ' demande(s)</strong> en attente pour cette cartouche. La sortie sera automatiquement liée.';
    } else if (otherDems.length > 0) {
        // Autre service a une demande → avertissement
        const names = [...new Set(otherDems.map(d => d.service_name))].join(', ');
        lbl.textContent = '⚠️ Lier à une demande (autre service)';
        banner.style.cssText = 'display:block;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#f59e0b';
        banner.innerHTML = '⚠️ <strong>' + totalReserved + ' u.</strong> sont réservées pour : <strong>' + names + '</strong>.<br>'
            + 'Stock libre (hors réservations) : <strong>' + Math.max(0, freeStock) + ' u.</strong>';

        // Bloquer si dépasse le stock libre et pas lié à une demande
        const currentRidNow = parseInt(document.getElementById('so-demand-sel').value) || 0;
        if (!currentRidNow && qty > freeStock) {
            banner.innerHTML += '<br><span style="color:#ef4444;font-weight:700">⛔ Quantité demandée (' + qty + ') dépasse le stock libre (' + Math.max(0,freeStock) + '). Liez à une demande ou réduisez la quantité.</span>';
            submit.disabled = true;
            submit.style.opacity = '.45';
        }
    }
}
</script>

<?php }

function pageReservations(PDO $db): void {
    $showArchived = isset($_GET['archived']);
    $statusFilter = $showArchived ? "r.status IN ('fulfilled','cancelled')" : "r.status IN ('pending','partial')";
    $reservations = $db->query("SELECT r.*, cm.brand, cm.model, cm.color, sv.name as service_name, COALESCE(s.quantity_available,0) as qty_avail, u.full_name as user_name FROM reservations r JOIN cartridge_models cm ON r.cartridge_model_id=cm.id LEFT JOIN services sv ON r.service_id=sv.id LEFT JOIN stock s ON s.cartridge_model_id=cm.id LEFT JOIN users u ON r.created_by=u.id WHERE $statusFilter ORDER BY r.requested_date DESC")->fetchAll();
    $archivedCount = 0; $activeCount = 0;
    try {
        $archivedCount = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE status IN ('fulfilled','cancelled')")->fetchColumn();
        $activeCount   = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','partial')")->fetchColumn();
    } catch(Exception $e) {}
    $cartridges = $db->query("SELECT cm.id, cm.brand, cm.model, cm.color, COALESCE(s.quantity_available,0) as qty FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE cm.active=1 OR cm.active IS NULL ORDER BY cm.brand, cm.model")->fetchAll();
    $services = $db->query("SELECT id,name FROM services ORDER BY name")->fetchAll();
    $printers = $db->query("SELECT p.id, p.brand, p.model, p.service_id FROM printers p WHERE p.status='active' ORDER BY p.brand, p.model")->fetchAll();

    // Map service → cartouches compatibles (via les imprimantes du service)
    $serviceCarts = [];
    try {
        $rows = $db->query(
            "SELECT DISTINCT p.service_id, pc.cartridge_model_id
             FROM printers p
             JOIN printer_cartridges pc ON pc.printer_id = p.id
             WHERE p.service_id IS NOT NULL"
        )->fetchAll();
        foreach ($rows as $row) {
            $serviceCarts[(int)$row['service_id']][] = (int)$row['cartridge_model_id'];
        }
    } catch(Exception $e) {}
?>
<div class="page-header"><span class="page-title-txt">📋 Demandes de cartouches</span>
  <div style="display:flex;gap:.6rem;align-items:center">
    <?php if($archivedCount > 0): ?>
    <a href="?page=reservations<?=$showArchived?'':'&archived=1'?>"
       style="padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .15s;<?=$showArchived?'background:var(--primary);color:#fff':'background:var(--card2);color:var(--text2);border:1px solid var(--border)'?>">
      🗄️ Archivées (<?=$archivedCount?>)
    </a>
    <?php endif ?>
    <?php if(!$showArchived): ?>
    <button class="btn-primary" onclick="openModal('modal-add')">+ Nouvelle demande</button>
    <?php endif ?>
  </div>
</div>

<?php if($showArchived): ?>
<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm);padding:.75rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#f59e0b">
  🗄️ Affichage des demandes traitées et annulées. <a href="?page=reservations" style="color:var(--primary);text-decoration:underline">← Retour aux demandes actives</a>
</div>
<?php else: ?>
<div class="info-banner">ℹ️ Les demandes permettent à un service de signaler un besoin en cartouches actuellement en rupture de stock. Elles seront traitées lors des prochaines commandes/entrées.</div>
<?php endif ?>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Demandé le</th><th>Cartouche</th><th>Service</th><th>Qté demandée</th><th>Qté traitée</th><th>Stock dispo</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($reservations)): ?><tr><td colspan="8" class="empty-cell">Aucune demande<?=$showArchived?' archivée':''?></td></tr>
    <?php else: foreach($reservations as $r): ?>
    <tr class="<?=$r['status']==='cancelled'?'row-cancelled':($r['status']==='fulfilled'?'row-fulfilled':'')?>">
      <td><?=date('d/m/Y',strtotime($r['requested_date']))?></td>
      <td><?=colorDot($r['color'])?> <strong><?=h($r['brand'].' '.$r['model'])?></strong></td>
      <td><?=h($r['service_name']??'–')?></td>
      <td><?=h($r['quantity_requested'])?></td>
      <td><?=h($r['quantity_fulfilled'])?></td>
      <td><span class="stock-pill <?=$r['qty_avail']>0?'stock-pill-ok':'stock-pill-low'?>"><?=h($r['qty_avail'])?></span></td>
      <td><?=statusBadge($r['status'])?></td>
      <td class="actions">
        <?php if(in_array($r['status'],['pending','partial'])): ?>
          <button class="btn-icon btn-edit" title="Modifier"
            onclick="openReservationEdit(this)"
            data-r='<?=htmlspecialchars(json_encode([
              "id"                 => (int)$r["id"],
              "cartridge_model_id" => (int)$r["cartridge_model_id"],
              "service_id"         => $r["service_id"] ? (int)$r["service_id"] : null,
              "printer_id"         => isset($r["printer_id"]) && $r["printer_id"] ? (int)$r["printer_id"] : null,
              "quantity_requested" => (int)$r["quantity_requested"],
              "requested_date"     => (string)($r["requested_date"] ?? ""),
              "notes"              => (string)($r["notes"] ?? ""),
            ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES)?>'>✏️</button>
          <a href="index.php?page=stock_out&open=modal-add&prefill_rid=<?=$r['id']?>&prefill_cid=<?=(int)$r['cartridge_model_id']?>&prefill_svc=<?=(int)($r['service_id']??0)?>&prefill_prt=<?=(int)($r['printer_id']??0)?>&prefill_qty=<?=(int)($r['quantity_requested']-(int)$r['quantity_fulfilled'])?>" class="btn-icon btn-edit" title="Traiter via sortie">📤</a>
          <form method="post" style="display:inline"><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="cancel"><input type="hidden" name="_id" value="<?=$r['id']?>"><button type="submit" class="btn-icon btn-del" title="Annuler" onclick="return confirm('Annuler cette demande ?')">✕</button></form>
        <?php endif;?>
        <?php if($r['status']==='cancelled' || $r['status']==='fulfilled'):?>
          <form method="post" style="display:inline"><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$r['id']?>"><button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement ?')">🗑️</button></form>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouvelle demande</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Service demandeur</label>
      <select name="service_id" id="res-add-service" onchange="resOnServiceChange('add')">
        <option value="">-- Aucun --</option>
        <?php foreach($services as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group"><label>Quantité demandée *</label>
      <input type="number" name="quantity_requested" min="1" value="1" required>
    </div>
    <div class="form-group form-full"><label>Imprimante concernée</label>
      <select name="printer_id" id="res-add-printer" onchange="resOnPrinterChange('add')">
        <option value="">-- Aucune / Toutes --</option>
        <?php foreach($printers as $p):?>
        <option value="<?=$p['id']?>" data-service="<?=(int)$p['service_id']?>"><?=h($p['brand'].' '.$p['model'])?></option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="form-group form-full"><label>Cartouche *</label>
      <select name="cartridge_model_id" id="res-add-cartridge" required>
        <option value="">-- Sélectionner un service d'abord --</option>
        <?php foreach($cartridges as $c):
          $qty = (int)($c['qty'] ?? 0);
          $stockLabel = $qty > 0 ? ' — ✅ '.$qty.' en stock' : ' — ⚠️ rupture';
          $style = $qty === 0 ? ' style="color:#f87171"' : '';
        ?><option value="<?=$c['id']?>"<?=$style?>><?=h($c['brand'].' '.$c['model'].' ('.$c['color'].')'.$stockLabel)?></option><?php endforeach;?>
      </select>
    </div>
    <div class="form-group form-full"><label>Date de la demande</label><input type="date" name="requested_date" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group form-full"><label>Notes / Justification</label><textarea name="notes" rows="2" placeholder="Raison de la demande..."></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Créer la demande</button></div>
  </form></div>
</div>

<!-- Modal édition réservation -->
<div class="modal-overlay" id="modal-edit-reservation">
  <div class="modal"><div class="modal-header"><h3>✏️ Modifier la demande</h3><button class="modal-close" onclick="closeModal('modal-edit-reservation')">✕</button></div>
  <form method="post">
    <input type="hidden" name="_entity" value="reservation">
    <input type="hidden" name="_action" value="edit">
    <input type="hidden" name="_id" id="redit-id">
    <div class="form-grid">
      <div class="form-group"><label>Service demandeur</label>
        <select name="service_id" id="redit-service_id" onchange="resOnServiceChange('edit')">
          <option value="">-- Aucun --</option>
          <?php foreach($services as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?>
        </select>
      </div>
      <div class="form-group"><label>Quantité demandée *</label>
        <input type="number" name="quantity_requested" id="redit-quantity_requested" min="1" required>
      </div>
      <div class="form-group form-full"><label>Imprimante concernée</label>
        <select name="printer_id" id="redit-printer_id" onchange="resOnPrinterChange('edit')">
          <option value="">-- Aucune / Toutes --</option>
          <?php foreach($printers as $p):?>
          <option value="<?=$p['id']?>" data-service="<?=(int)$p['service_id']?>"><?=h($p['brand'].' '.$p['model'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="form-group form-full"><label>Cartouche</label>
        <select name="cartridge_model_id" id="redit-cartridge_model_id" required>
          <option value="">-- Sélectionner --</option>
          <?php foreach($cartridges as $c):
            $qty = (int)($c['qty'] ?? 0);
            $stockLabel = $qty > 0 ? ' — ✅ '.$qty.' en stock' : ' — ⚠️ rupture';
            $style = $qty === 0 ? ' style="color:#f87171"' : '';
          ?><option value="<?=$c['id']?>"<?=$style?>><?=h($c['brand'].' '.$c['model'].' ('.$c['color'].')'.$stockLabel)?></option><?php endforeach;?>
        </select>
      </div>
      <div class="form-group"><label>Date de la demande</label>
        <input type="date" name="requested_date" id="redit-requested_date">
      </div>
      <div class="form-group form-full"><label>Notes</label>
        <textarea name="notes" id="redit-notes" rows="2"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-secondary" onclick="closeModal('modal-edit-reservation')">Annuler</button>
      <button type="submit" class="btn-primary">Enregistrer</button>
    </div>
  </form></div>
</div>

<script>
// Map service_id → [cartridge_model_id, ...]
const RES_SERVICE_CARTS = <?=json_encode($serviceCarts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

// Préfixes des IDs selon mode add/edit
const RES_IDS = {
  add:  { svc: 'res-add-service',  printer: 'res-add-printer',  cart: 'res-add-cartridge' },
  edit: { svc: 'redit-service_id', printer: 'redit-printer_id', cart: 'redit-cartridge_model_id' }
};

function resFilterPrinters(mode) {
    const ids    = RES_IDS[mode];
    const svcId  = document.getElementById(ids.svc).value;
    const pSel   = document.getElementById(ids.printer);
    Array.from(pSel.options).forEach(function(opt) {
        if (!opt.value) { opt.style.display = ''; return; }
        opt.style.display = (!svcId || opt.dataset.service === svcId) ? '' : 'none';
    });
    // Reset imprimante si elle n'est plus dans le service
    const cur = pSel.options[pSel.selectedIndex];
    if (cur && cur.value && svcId && cur.dataset.service !== svcId) pSel.value = '';
}

function resFilterCarts(mode) {
    const ids     = RES_IDS[mode];
    const svcId   = parseInt(document.getElementById(ids.svc).value) || 0;
    const cartSel = document.getElementById(ids.cart);
    const allowed = svcId && RES_SERVICE_CARTS[svcId] ? RES_SERVICE_CARTS[svcId] : null;
    const prev    = cartSel.value;
    Array.from(cartSel.options).forEach(function(opt) {
        if (!opt.value) {
            opt.textContent = allowed ? '-- Sélectionner --' : '-- Sélectionner un service d\'abord --';
            opt.style.display = '';
            return;
        }
        opt.style.display = (!allowed || allowed.indexOf(parseInt(opt.value)) !== -1) ? '' : 'none';
    });
    if (prev && allowed && allowed.indexOf(parseInt(prev)) === -1) cartSel.value = '';
}

function resOnServiceChange(mode) {
    resFilterPrinters(mode);
    resFilterCarts(mode);
}

function resOnPrinterChange(mode) {
    // Si on choisit une imprimante, aligner le service
    const ids  = RES_IDS[mode];
    const pSel = document.getElementById(ids.printer);
    const sSel = document.getElementById(ids.svc);
    const opt  = pSel.options[pSel.selectedIndex];
    if (opt && opt.value && opt.dataset.service) {
        sSel.value = opt.dataset.service;
        resFilterPrinters(mode);
        resFilterCarts(mode);
    }
}

function openReservationEdit(btn) {
  try { var r = JSON.parse(btn.getAttribute('data-r')); } catch(e) { console.error(e); return; }
  document.getElementById('redit-id').value                 = r.id;
  document.getElementById('redit-service_id').value         = r.service_id != null ? r.service_id : '';
  document.getElementById('redit-quantity_requested').value = r.quantity_requested || 1;
  document.getElementById('redit-requested_date').value     = r.requested_date || '';
  document.getElementById('redit-notes').value              = r.notes || '';
  // Filtrer imprimantes et cartouches selon le service
  resFilterPrinters('edit');
  resFilterCarts('edit');
  document.getElementById('redit-printer_id').value          = r.printer_id != null ? r.printer_id : '';
  document.getElementById('redit-cartridge_model_id').value  = r.cartridge_model_id || '';
  openModal('modal-edit-reservation');
}
</script>

<?php }

function pageStats(PDO $db, array $d): void {

    // ── Filtre période : 0 = toutes les données ─────────────
    $period = (int)($_GET['period'] ?? 12);
    if (!in_array($period,[0,1,3,6,12,24])) $period = 12;

    // ── Filtres service / imprimante ─────────────────────────
    $filterService = (int)($_GET['filter_service'] ?? 0);
    $filterPrinter = (int)($_GET['filter_printer'] ?? 0);

    // Listes pour les selects
    $servicesList = $db->query("SELECT id, name FROM services ORDER BY name")->fetchAll();
    $printersList = $db->query("SELECT p.id, CONCAT(p.brand,' ',p.model) as label, COALESCE(sv.name,'') as service, p.service_id FROM printers p LEFT JOIN services sv ON p.service_id=sv.id ORDER BY sv.name, p.brand, p.model")->fetchAll();

    // ── Construction des clauses WHERE dynamiques ────────────
    // Période
    if ($period === 0) {
        $dateWhereExit  = '';           // pas de filtre date
        $dateWhereEntry = '';
        $periodLabel    = 'Tout';
    } else {
        $dateWhereExit  = "AND se.exit_date  >= DATE_SUB(NOW(), INTERVAL $period MONTH)";
        $dateWhereEntry = "AND en.entry_date >= DATE_SUB(NOW(), INTERVAL $period MONTH)";
        $periodLabel    = "$period mois";
    }
    // Service
    $svcWhere = $filterService ? "AND se.service_id = $filterService" : '';
    // Imprimante
    $prnWhere = $filterPrinter ? "AND se.printer_id = $filterPrinter" : '';

    // Clause combinée pour toutes les requêtes stock_exits
    $exitWhere = "1=1 $dateWhereExit $svcWhere $prnWhere";

    // Pour le graphique mensuel entrées : pas de filtre service/printer (entrées indépendantes)
    $monthlyExitDateWhere  = $period > 0 ? "exit_date  >= DATE_SUB(NOW(), INTERVAL $period MONTH)" : '1=1';
    $monthlyEntryDateWhere = $period > 0 ? "entry_date >= DATE_SUB(NOW(), INTERVAL $period MONTH)" : '1=1';

    // Helper URL : préserve tous les params actifs
    $urlBase = 'index.php?page=stats';
    $urlPeriod   = function(int $p) use ($filterService,$filterPrinter): string {
        $q = ['page'=>'stats','period'=>$p];
        if ($filterService) $q['filter_service'] = $filterService;
        if ($filterPrinter) $q['filter_printer'] = $filterPrinter;
        return 'index.php?'.http_build_query($q);
    };

    // ── Requêtes ────────────────────────────────────────────
    $monthlyExits = $monthlyEntries = $byService = $byCartridge = [];
    $byPrinter = $stockLevels = $orderStats = $monthByColor = $recentOps = [];
    $stockValue = $totalExits = $totalEntries = $totalCost = 0;
    $avgPerMonth = 0; $statsError = null;

    try {
        $svcWhereRaw = $filterService ? "AND service_id = $filterService" : '';
        $prnWhereRaw = $filterPrinter ? "AND printer_id = $filterPrinter" : '';
        $monthlyExitsWhere = $period > 0
            ? "exit_date >= DATE_SUB(NOW(), INTERVAL $period MONTH) $svcWhereRaw $prnWhereRaw"
            : "1=1 $svcWhereRaw $prnWhereRaw";
        $monthlyExits  = $db->query("SELECT DATE_FORMAT(exit_date,'%b %Y') as m, DATE_FORMAT(exit_date,'%Y-%m') as ym, SUM(quantity) as total FROM stock_exits WHERE $monthlyExitsWhere GROUP BY DATE_FORMAT(exit_date,'%Y-%m'), DATE_FORMAT(exit_date,'%b %Y') ORDER BY ym")->fetchAll();
        $monthlyEntries= $db->query("SELECT DATE_FORMAT(entry_date,'%b %Y') as m, DATE_FORMAT(entry_date,'%Y-%m') as ym, SUM(quantity) as total FROM stock_entries WHERE $monthlyEntryDateWhere GROUP BY DATE_FORMAT(entry_date,'%Y-%m'), DATE_FORMAT(entry_date,'%b %Y') ORDER BY ym")->fetchAll();

        $byService   = $db->query("SELECT COALESCE(sv.name,'Sans service') as name, SUM(se.quantity) as total, COUNT(DISTINCT se.id) as ops FROM stock_exits se LEFT JOIN services sv ON se.service_id=sv.id WHERE $exitWhere GROUP BY sv.id, sv.name ORDER BY total DESC LIMIT 12")->fetchAll();
        $byCartridge = $db->query("SELECT cm.brand, cm.model, cm.color, cm.type, COALESCE(cm.unit_price,0) as unit_price, SUM(se.quantity) as total, COALESCE(MAX(s.quantity_available),0) as stock FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE $exitWhere GROUP BY cm.id, cm.brand, cm.model, cm.color, cm.type, cm.unit_price ORDER BY total DESC LIMIT 12")->fetchAll();
        $byPrinter   = $db->query("SELECT CONCAT(p.brand,' ',p.model) as printer, COALESCE(sv.name,'-') as service, SUM(se.quantity) as total, COUNT(DISTINCT se.cartridge_model_id) as cart_types, p.location FROM stock_exits se JOIN printers p ON se.printer_id=p.id LEFT JOIN services sv ON p.service_id=sv.id WHERE $exitWhere GROUP BY p.id, p.brand, p.model, p.location, sv.name ORDER BY total DESC LIMIT 10")->fetchAll();
        $stockLevels = $db->query("SELECT CONCAT(cm.brand,' ',cm.model) as name, cm.color, COALESCE(s.quantity_available,0) as qty, cm.alert_threshold, COALESCE(cm.unit_price,0)*COALESCE(s.quantity_available,0) as val FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id ORDER BY qty ASC LIMIT 15")->fetchAll();
        $stockValue  = $db->query("SELECT COALESCE(SUM(cm.unit_price * s.quantity_available),0) as val FROM stock s JOIN cartridge_models cm ON s.cartridge_model_id=cm.id")->fetchColumn();

        $totalExits  = $db->query("SELECT COALESCE(SUM(se.quantity),0) FROM stock_exits se WHERE $exitWhere")->fetchColumn();
        $totalEntries= $db->query("SELECT COALESCE(SUM(quantity),0) FROM stock_entries WHERE $monthlyEntryDateWhere")->fetchColumn();
        $totalCost   = $db->query("SELECT COALESCE(SUM(se.quantity*cm.unit_price),0) FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id WHERE $exitWhere")->fetchColumn();

        if ($period === 0) {
            // Calcul du vrai nombre de mois couverts
            $firstExit = $db->query("SELECT MIN(exit_date) FROM stock_exits")->fetchColumn();
            $spanMonths = $firstExit ? max(1, (int)round((time()-strtotime($firstExit))/2592000)) : 1;
            $avgPerMonth = round($totalExits / $spanMonths, 1);
        } else {
            $avgPerMonth = $period > 0 ? round($totalExits / $period, 1) : 0;
        }

        try { $orderStats = $db->query("SELECT status, COUNT(*) as cnt, COALESCE(SUM(pol.quantity_ordered*pol.unit_price),0) as val FROM purchase_orders po LEFT JOIN purchase_order_lines pol ON pol.order_id=po.id GROUP BY po.status")->fetchAll(); } catch(Exception $e){}
        $monthByColor = $db->query("SELECT cm.color, SUM(se.quantity) as total FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id WHERE $exitWhere GROUP BY cm.color ORDER BY total DESC")->fetchAll();
        $recentOps    = $db->query("SELECT 'sortie' as type, se.exit_date as op_date, se.quantity, CONCAT(cm.brand,' ',cm.model) as cart, COALESCE(sv.name,'-') as service, se.person_name FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN services sv ON se.service_id=sv.id WHERE $exitWhere UNION ALL SELECT 'entree' as type, en.entry_date, en.quantity, CONCAT(cm.brand,' ',cm.model), COALESCE(sp.name,'-'), en.invoice_ref FROM stock_entries en JOIN cartridge_models cm ON en.cartridge_model_id=cm.id LEFT JOIN suppliers sp ON en.supplier_id=sp.id WHERE $monthlyEntryDateWhere ORDER BY op_date DESC LIMIT 15")->fetchAll();
    } catch (Exception $e) {
        $statsError = $e->getMessage();
    }

    $activeFilters = ($filterService || $filterPrinter);
    $filterServiceName = '';
    $filterPrinterName = '';
    if ($filterService) { foreach($servicesList as $s) { if ($s['id']==$filterService) { $filterServiceName=$s['name']; break; } } }
    if ($filterPrinter) { foreach($printersList as $p) { if ($p['id']==$filterPrinter) { $filterPrinterName=$p['label']; break; } } }
?>

<?php if ($statsError): ?>
<div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:var(--radius);padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:flex-start">
  <span style="font-size:1.5rem;flex-shrink:0">⚠️</span>
  <div><strong style="color:#fca5a5">Erreur de chargement des statistiques</strong><br>
  <code style="font-size:.82rem;color:var(--text2)"><?=h($statsError)?></code></div>
</div>
<?php endif ?>

<!-- TOOLBAR : titre + période + filtres -->
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
  <span class="page-title-txt">📊 Statistiques & Rapports</span>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">

    <!-- Période -->
    <span style="font-size:.8rem;color:var(--text3)">Période :</span>
    <?php foreach([0=>'Tout',1=>'1 mois',3=>'3 mois',6=>'6 mois',12=>'12 mois',24=>'24 mois'] as $v=>$l): ?>
    <a href="<?=$urlPeriod($v)?>" style="padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .15s;<?=$period===$v?'background:var(--primary);color:#fff;':'background:var(--card2);color:var(--text2);border:1px solid var(--border)'?>"><?=h($l)?></a>
    <?php endforeach ?>

    <span style="width:1px;height:20px;background:var(--border);margin:0 .25rem"></span>

    <!-- Filtre service -->
    <select id="sel-service" onchange="statsFilter()" style="background:var(--card2);border:1px solid <?=$filterService?'var(--primary)':'var(--border)'?>;border-radius:var(--radius-sm);padding:.38rem .75rem;color:var(--text<?=$filterService?'':'2'?>);font-size:.82rem;cursor:pointer;min-width:130px">
      <option value="0"<?=$filterService===0?' selected':''?>>🏢 Tous les services</option>
      <?php foreach($servicesList as $s): ?>
      <option value="<?=$s['id']?>"<?=$filterService===$s['id']?' selected':''?>><?=h($s['name'])?></option>
      <?php endforeach ?>
    </select>

    <!-- Filtre imprimante (filtré dynamiquement selon le service sélectionné) -->
    <select id="sel-printer" onchange="statsFilter()" style="background:var(--card2);border:1px solid <?=$filterPrinter?'var(--primary)':'var(--border)'?>;border-radius:var(--radius-sm);padding:.38rem .75rem;color:var(--text<?=$filterPrinter?'':'2'?>);font-size:.82rem;cursor:pointer;min-width:150px">
      <option value="0"<?=$filterPrinter===0?' selected':''?>>🖨️ Toutes les imprimantes</option>
      <?php foreach($printersList as $p): ?>
      <option value="<?=$p['id']?>"
        data-svc="<?=(int)($p['service_id'] ?? 0)?>"
        <?=$filterPrinter===$p['id']?' selected':''?>><?=h($p['label'])?><?=$p['service']?' ('.$p['service'].')':''?></option>
      <?php endforeach ?>
    </select>

    <?php if($activeFilters): ?>
    <a href="<?=$urlPeriod($period)?>" style="padding:.38rem .75rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3);transition:all .15s" title="Réinitialiser les filtres">✕ Reset</a>
    <?php endif ?>
  </div>
</div>

<?php if($activeFilters): ?>
<div style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
  <span style="font-size:.8rem;color:var(--text3)">Filtres actifs :</span>
  <?php if($filterServiceName): ?><span style="background:var(--primary-dim);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.25rem .7rem;font-size:.8rem;color:var(--primary)">🏢 <?=h($filterServiceName)?></span><?php endif ?>
  <?php if($filterPrinterName): ?><span style="background:var(--primary-dim);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.25rem .7rem;font-size:.8rem;color:var(--primary)">🖨️ <?=h($filterPrinterName)?></span><?php endif ?>
</div>
<?php endif ?>

<!-- KPIs enrichis -->
<div class="kpi-row" style="margin-bottom:1.5rem">
  <div class="kpi-card kpi-blue" style="flex:1">
    <div class="kpi-icon">📤</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalExits,0,',',' ')?></span><span class="kpi-label">Sorties (<?=$periodLabel?>)</span></div>
    <div class="kpi-sub">~<?=$avgPerMonth?>/mois</div>
  </div>
  <?php if($filterService): ?>
  <div class="kpi-card kpi-green" style="flex:1;opacity:.6;cursor:default" title="Les entrées de stock ne sont pas filtrables par service">
    <div class="kpi-icon">📦</div>
    <div class="kpi-info">
      <span class="kpi-val" style="font-size:1.2rem">–</span>
      <span class="kpi-label">Entrées (tous services)</span>
    </div>
    <div class="kpi-sub" style="color:var(--warning)">⚠️ Non filtrable par service</div>
  </div>
  <?php else: ?>
  <div class="kpi-card kpi-green" style="flex:1">
    <div class="kpi-icon">📦</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalEntries,0,',',' ')?></span><span class="kpi-label">Entrées (<?=$periodLabel?>)</span></div>
    <div class="kpi-sub"><?=h($d['stock_total'])?> en stock</div>
  </div>
  <?php endif ?>
  <div class="kpi-card kpi-amber" style="flex:1">
    <div class="kpi-icon">💶</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalCost,0,',',' ')?>€</span><span class="kpi-label">Coût consommé</span></div>
    <div class="kpi-sub">Valeur stock : <?=number_format($stockValue,0,',',' ')?>€</div>
  </div>
  <div class="kpi-card kpi-violet" style="flex:1">
    <div class="kpi-icon">🏢</div>
    <div class="kpi-info"><span class="kpi-val"><?=count($byService)?></span><span class="kpi-label">Services actifs</span></div>
    <div class="kpi-sub"><?=count($byPrinter)?> imprimantes</div>
  </div>
</div>

<!-- Graphiques ligne 1 : Flux entrees/sorties + par service -->
<div class="stats-grid" style="margin-bottom:1.25rem">
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Flux <?=$filterService ? 'sorties' : 'entrées / sorties'?> (<?=$periodLabel?>)</span>
    </div>
    <canvas id="chartFlux" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🥧 Répartition par service</span></div>
    <canvas id="chartService" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
</div>

<!-- Graphiques ligne 2 : par couleur + stock -->
<div class="stats-grid" style="margin-bottom:1.25rem">
  <div class="card">
    <div class="card-header"><span class="card-title">🖋️ Consommation par couleur</span></div>
    <canvas id="chartColor" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Niveaux de stock (🔴 alerte)</span></div>
    <canvas id="chartStock" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
</div>

<!-- Tableau par service -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🏢 Détail par service</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byService)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée</div>
  <?php else: $maxS = max(array_column($byService,'total')); ?>
  <table class="data-table">
    <thead><tr><th>Service</th><th>Sorties</th><th>Opérations</th><th style="min-width:200px">Proportion</th></tr></thead>
    <tbody>
    <?php foreach($byService as $s): $pct = round($s['total']/$maxS*100); ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong></td>
      <td><span class="stock-pill stock-pill-out"><?=h($s['total'])?> u.</span></td>
      <td class="muted"><?=h($s['ops'])?> sorties</td>
      <td>
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden">
            <div style="height:100%;background:linear-gradient(90deg,var(--primary),#3a86ff);border-radius:99px;width:<?=$pct?>%;transition:width .8s ease"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text2);min-width:32px"><?=$pct?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Tableau par cartouche -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🖋️ Détail par cartouche</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byCartridge)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Cartouche</th><th>Couleur</th><th>Type</th><th>Consommées</th><th>Coût estimé</th><th>Stock actuel</th><th>Prévision stock</th></tr></thead>
    <tbody>
    <?php foreach($byCartridge as $c):
      $cost = $c['total'] * $c['unit_price'];
      // Consommation prévisionnelle : mois restants au rythme actuel
      $avgMonthly = $period > 0 && $period < 24 ? round($c['total'] / $period, 2) : 0;
      $monthsLeft = ($avgMonthly > 0 && $c['stock'] > 0) ? round($c['stock'] / $avgMonthly, 1) : null;
      $forecastColor = $monthsLeft === null ? 'var(--text3)' : ($monthsLeft < 1 ? 'var(--danger)' : ($monthsLeft < 2 ? '#f59e0b' : 'var(--success)'));
    ?>
    <tr>
      <td><a href="index.php?page=cartridge_history&id=<?=$c['id']??0?>" style="text-decoration:none;color:inherit;font-weight:700"><?=h($c['brand'].' '.$c['model'])?></a></td>
      <td><?=colorDot($c['color'])?></td>
      <td><span class="badge badge-muted"><?=strtoupper(h($c['type']))?></span></td>
      <td><span class="stock-pill stock-pill-out"><?=h($c['total'])?> u.</span></td>
      <td style="font-family:var(--font-mono)"><?=$cost>0?number_format($cost,2,',',' ').' €':'–'?></td>
      <td><span class="stock-pill <?=$c['stock']<=3?'stock-pill-low':'stock-pill-ok'?>"><?=h($c['stock'])?></span></td>
      <td style="font-family:var(--font-mono);color:<?=$forecastColor?>;font-weight:700">
        <?php if($monthsLeft===null): ?>
          <span style="color:var(--text3)">–</span>
        <?php elseif($c['stock']===0): ?>
          <span class="badge badge-danger">Rupture</span>
        <?php else: ?>
          <?=$monthsLeft?> mois
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Tableau par imprimante -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🖨️ Consommation par imprimante</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byPrinter)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée sur les imprimantes</div>
  <?php else: $maxP = max(array_column($byPrinter,'total')); ?>
  <table class="data-table">
    <thead><tr><th>Imprimante</th><th>Service</th><th>Emplacement</th><th>Types cart.</th><th>Consommées</th><th>Proportion</th></tr></thead>
    <tbody>
    <?php foreach($byPrinter as $p): $pct=round($p['total']/$maxP*100); ?>
    <tr>
      <td><strong><?=h($p['printer'])?></strong></td>
      <td class="muted"><?=h($p['service'])?></td>
      <td class="muted"><?=h($p['location']??'–')?></td>
      <td style="text-align:center"><span class="badge badge-info"><?=h($p['cart_types'])?></span></td>
      <td><span class="stock-pill stock-pill-out"><?=h($p['total'])?> u.</span></td>
      <td>
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden">
            <div style="height:100%;background:linear-gradient(90deg,#7b2d8b,#a855f7);border-radius:99px;width:<?=$pct?>%"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text2);min-width:32px"><?=$pct?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Ligne 3 : Commandes + Activité récente -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

  <!-- Stats commandes -->
  <div class="card">
    <div class="card-header"><span class="card-title">🛒 État des commandes</span></div>
    <?php if(empty($orderStats)): ?>
    <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune commande</div>
    <?php else: ?>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.85rem">
      <?php
      $statusLabels=['pending'=>['En attente','#f59e0b'],'partial'=>['Partielle','#38bdf8'],'received'=>['Reçue','#10b981'],'cancelled'=>['Annulée','#ef4444']];
      $totalOrders = array_sum(array_column($orderStats,'cnt'));
      foreach($orderStats as $os):
        [$lbl,$col]=$statusLabels[$os['status']]??[$os['status'],'#94a3b8'];
        $pct = $totalOrders>0?round($os['cnt']/$totalOrders*100):0;
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:.35rem;font-size:.85rem">
          <span style="display:flex;align-items:center;gap:.5rem">
            <span style="width:8px;height:8px;border-radius:50%;background:<?=$col?>;display:inline-block"></span>
            <?=h($lbl)?>
          </span>
          <span style="font-family:var(--font-mono);font-size:.8rem;color:var(--text2)"><?=$os['cnt']?> cmd · <?=number_format($os['val'],0,',',' ')?>€</span>
        </div>
        <div style="height:7px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;border-radius:99px;background:<?=$col?>;width:<?=$pct?>%"></div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <!-- Valeur stock par cartouche -->
  <div class="card">
    <div class="card-header"><span class="card-title">💶 Valeur du stock actuel</span></div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
      <?php
      $vals = array_column($stockLevels,'val');
      $maxVal = max(1, !empty($vals) ? max($vals) : 0);
      foreach($stockLevels as $sl):
        if($sl['val'] <= 0) continue;
        $pct = min(100, round($sl['val']/$maxVal*100));
        $isLow = $sl['qty'] <= $sl['alert_threshold'];
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;font-size:.82rem">
          <span><?=colorDot($sl['color'])?> <?=h($sl['name'])?><?=$isLow?' <span class="badge badge-warning" style="font-size:.65rem">⚠️</span>':''?></span>
          <span style="font-family:var(--font-mono);color:var(--text2)"><?=$sl['qty']?> u. · <?=number_format($sl['val'],0,',',' ')?>€</span>
        </div>
        <div style="height:6px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;border-radius:99px;background:<?=$isLow?'linear-gradient(90deg,#dc2626,#ef4444)':'linear-gradient(90deg,#059669,#10b981)'?>;width:<?=$pct?>%"></div>
        </div>
      </div>
      <?php endforeach ?>
      <div style="border-top:1px solid var(--border);padding-top:.75rem;display:flex;justify-content:space-between;font-size:.88rem">
        <strong>Total stock</strong>
        <span style="font-family:var(--font-mono);font-weight:700;color:var(--success)"><?=number_format($stockValue,2,',',' ')?>€</span>
      </div>
    </div>
  </div>
</div>

<!-- Activité récente -->
<div class="card">
  <div class="card-header"><span class="card-title">🕐 Dernières opérations</span><span class="badge badge-muted">15 dernières</span></div>
  <table class="data-table">
    <thead><tr><th>Type</th><th>Date</th><th>Cartouche</th><th>Qté</th><th>Service / Fournisseur</th><th>Référence</th></tr></thead>
    <tbody>
    <?php foreach($recentOps as $op): ?>
    <tr>
      <td><?=$op['type']==='sortie'?'<span class="badge badge-warning">📤 Sortie</span>':'<span class="badge badge-success">📦 Entrée</span>'?></td>
      <td><?=date('d/m/Y',strtotime($op['op_date']))?></td>
      <td><?=h($op['cart'])?></td>
      <td><span class="stock-pill <?=$op['type']==='sortie'?'stock-pill-out':'stock-pill-ok'?>"><?=$op['type']==='sortie'?'-':'+'?><?=h($op['quantity'])?></span></td>
      <td class="muted"><?=h($op['service'])?></td>
      <td class="muted" style="font-family:var(--font-mono);font-size:.78rem"><?=h($op['person_name']??'')?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<script>
const CC=['#4361ee','#10b981','#f59e0b','#ef4444','#06b6d4','#8b5cf6','#f97316','#14b8a6','#a855f7','#e11d48'];
function mkChart(id,type,labels,datasets,opts={}){
  const ctx=document.getElementById(id); if(!ctx) return;
  new Chart(ctx,{type,data:{labels,datasets},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{labels:{color:'#94a3b8',padding:14,font:{size:12}}}},scales:type==='doughnut'||type==='pie'?{}:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8',maxRotation:40,font:{size:11}}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8',font:{size:11}}}},...opts}});
}

// Flux entrées/sorties
const mLabels=<?=json_encode(array_column($monthlyExits,'m'))?>;
const mExits =<?=json_encode(array_map('intval',array_column($monthlyExits,'total')))?>;
const filterServiceActive = <?=$filterService ? 'true' : 'false'?>;

// Aligner entrées sur les mêmes mois (non affiché si filtre service actif)
const mEntMap={}; <?php foreach($monthlyEntries as $me): ?>mEntMap['<?=h($me['m'])?>']= <?=(int)$me['total']?>; <?php endforeach; ?>
const mEnts=mLabels.map(l=>mEntMap[l]||0);

const fluxDatasets = [
  {label:'Sorties',data:mExits,backgroundColor:'rgba(245,158,11,.7)',borderColor:'#f59e0b',borderWidth:2,borderRadius:4}
];
if (!filterServiceActive) {
  fluxDatasets.push({label:'Entrées',data:mEnts,backgroundColor:'rgba(16,185,129,.6)',borderColor:'#10b981',borderWidth:2,borderRadius:4});
}
mkChart('chartFlux','bar',mLabels,fluxDatasets,{plugins:{legend:{display:!filterServiceActive,labels:{color:'#94a3b8'}}}});

// Par service
const svLabels=<?=json_encode(array_column($byService,'name'))?>;
const svData  =<?=json_encode(array_map('intval',array_column($byService,'total')))?>;
if(svLabels.length) mkChart('chartService','doughnut',svLabels,[{data:svData,backgroundColor:CC,borderWidth:0,hoverOffset:8}]);

// Par couleur
const colLabels=<?=json_encode(array_column($monthByColor,'color'))?>;
const colData  =<?=json_encode(array_map('intval',array_column($monthByColor,'total')))?>;
const colColors={'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Tricolore':'#a78bfa','Bleu':'#38bdf8'};
if(colLabels.length) mkChart('chartColor','doughnut',colLabels,[{data:colData,backgroundColor:colLabels.map(c=>colColors[c]||'#94a3b8'),borderWidth:0,hoverOffset:8}]);

// Stock niveaux
const sl=<?=json_encode($stockLevels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
if(sl.length) mkChart('chartStock','bar',sl.map(x=>x.name),[{label:'Stock',data:sl.map(x=>parseInt(x.qty)),backgroundColor:sl.map(x=>parseInt(x.qty)<=parseInt(x.alert_threshold)?'rgba(239,68,68,.8)':'rgba(16,185,129,.7)'),borderRadius:4}],{indexAxis:'y',plugins:{legend:{display:false}}});

// Filtre service / imprimante : met à jour l'URL en préservant tous les params
function statsFilter(){
  const u=new URL(window.location.href);
  const sv=document.getElementById('sel-service').value;
  const pr=document.getElementById('sel-printer').value;
  if(sv==='0') u.searchParams.delete('filter_service'); else u.searchParams.set('filter_service',sv);
  if(pr==='0') u.searchParams.delete('filter_printer'); else u.searchParams.set('filter_printer',pr);
  window.location=u.toString();
}
// Filtrer les imprimantes selon le service sélectionné
document.getElementById('sel-service').addEventListener('change', function() {
  const svcId = this.value;
  const prSel = document.getElementById('sel-printer');
  const curPr = prSel.value;
  Array.from(prSel.options).forEach(function(opt) {
    if (opt.value === '0') { opt.style.display=''; return; }
    if (svcId === '0') { opt.style.display=''; }
    else { opt.style.display = (opt.dataset.svc === svcId) ? '' : 'none'; }
  });
  // Reset printer si le service a changé et que l'imprimante sélectionnée n'appartient plus
  const curOpt = prSel.options[prSel.selectedIndex];
  if (curOpt && curOpt.value !== '0' && svcId !== '0' && curOpt.dataset.svc !== svcId) {
    prSel.value = '0';
  }
});
// Appliquer au chargement si un service est déjà filtré
(function(){
  const svcId = document.getElementById('sel-service').value;
  if (svcId === '0') return;
  const prSel = document.getElementById('sel-printer');
  Array.from(prSel.options).forEach(function(opt) {
    if (opt.value === '0') return;
    opt.style.display = (opt.dataset.svc === svcId) ? '' : 'none';
  });
})();
</script>
<?php }

function pageAdmin(PDO $db): void {
    requireAdmin();
    $users = $db->query("SELECT * FROM users ORDER BY role DESC, username")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">⚙️ Administration – Utilisateurs</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Créer un compte</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Identifiant</th><th>Nom complet</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($users as $u): ?>
    <tr>
      <td><strong><?=h($u['username'])?></strong></td>
      <td><?=h($u['full_name'])?></td>
      <td><?=$u['email']?'<a href="mailto:'.h($u['email']).'">'.h($u['email']).'</a>':'–'?></td>
      <td><span class="badge <?=$u['role']==='admin'?'badge-warning':'badge-info'?>"><?=$u['role']==='admin'?'👑 Admin':'👤 Utilisateur'?></span></td>
      <td><?=$u['active']?'<span class="badge badge-success">Actif</span>':'<span class="badge badge-danger">Inactif</span>'?></td>
      <td class="muted"><?=$u['last_login']?date('d/m/Y H:i',strtotime($u['last_login'])):'Jamais'?></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"user")'>✏️</button>
        <?php if($u['id']!==(int)$GLOBALS['user']['id']):?>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$u['id']?>,"user","<?=h(addslashes($u['username']))?>")'  >🗑️</button>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Créer un compte</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="user"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Identifiant *</label><input type="text" name="username" required autocomplete="off"></div>
    <div class="form-group"><label>Mot de passe *</label><input type="password" name="password" required autocomplete="new-password" minlength="6"></div>
    <div class="form-group"><label>Nom complet</label><input type="text" name="full_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
    <div class="form-group"><label>Rôle</label><select name="role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Créer</button></div>
  </form></div>
</div>
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier l'utilisateur</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><input type="hidden" name="_entity" value="user"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group form-full"><label>Identifiant</label><input type="text" id="edit-username" disabled class="input-disabled"></div>
    <div class="form-group"><label>Nom complet</label><input type="text" name="full_name" id="edit-full_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
    <div class="form-group"><label>Rôle</label><select name="role" id="edit-role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></div>
    <div class="form-group"><label>Statut</label><select name="active" id="edit-active"><option value="1">Actif</option><option value="0">Inactif</option></select></div>
    <div class="form-group form-full"><label>Nouveau mot de passe <small class="muted">(laisser vide = inchangé)</small></label><input type="password" name="password" autocomplete="new-password" minlength="6"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('user'); }

function deleteModal(string $entity): void { ?>
<div class="modal-overlay" id="modal-delete">
  <div class="modal modal-sm"><div class="modal-header"><h3>⚠️ Confirmer la suppression</h3><button class="modal-close" onclick="closeModal('modal-delete')">✕</button></div>
  <p id="del-message" style="color:#94a3b8;margin:1rem 0"></p>
  <form method="post"><input type="hidden" name="_entity" value="<?=$entity?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" id="del-id">
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-delete')">Annuler</button><button type="submit" class="btn-danger">Supprimer définitivement</button></div>
  </form></div>
</div>
<?php }

// ─── HTML OUTPUT ─────────────────────────────────────────────
// Vider les buffers parasites sans fermer le buffer principal
while (ob_get_level() > 1) { ob_end_clean(); }
ob_clean();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pageTitle[$page]??ucfirst($page))?> – PrintManager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=DM+Sans:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Appliquer le thème AVANT le rendu pour éviter le flash
(function(){
  var t = localStorage.getItem('pm_theme');
  if (t === 'light') document.documentElement.setAttribute('data-theme','light');
})();
</script>
<style>
/* ═══════════════════════════════════════════════════════════════
   DESIGN SYSTEM – PrintManager
   Thème sombre (défaut) + thème clair Châtillon
═══════════════════════════════════════════════════════════════ */

/* ── Thème SOMBRE (défaut) ── */
:root {
  --bg:          #080b14;
  --bg2:         #0f1420;
  --bg3:         #141928;
  --card:        #111827;
  --card2:       #1a2235;
  --border:      rgba(255,255,255,.07);
  --border2:     rgba(67,97,238,.25);
  --primary:     #4361ee;
  --primary-dim: rgba(67,97,238,.15);
  --primary-glow:rgba(67,97,238,.4);
  --accent:      #f59e0b;
  --accent-dim:  rgba(245,158,11,.15);
  --success:     #10b981;
  --success-dim: rgba(16,185,129,.15);
  --danger:      #ef4444;
  --danger-dim:  rgba(239,68,68,.15);
  --warning:     #f59e0b;
  --info:        #38bdf8;
  --info-dim:    rgba(56,189,248,.15);
  --text:        #f0f4ff;
  --text2:       #94a3b8;
  --text3:       #4b5563;
  --sidebar-w:   255px;
  --topbar-h:    64px;
  --radius:      12px;
  --radius-sm:   8px;
  --shadow:      0 4px 20px rgba(0,0,0,.35);
  --shadow-lg:   0 20px 60px rgba(0,0,0,.6);
  --font:        'DM Sans', sans-serif;
  --font-display:'Outfit', sans-serif;
  --font-mono:   'JetBrains Mono', monospace;
  --font-body:   'DM Sans', sans-serif;
}

/* ── Thème CLAIR – Palette Ville de Châtillon ──
   Bleu marine institutionnel #003087
   Rouge Châtillon           #e2001a
   Fond clair, texte sombre
── */
[data-theme="light"] {
  --bg:          #f0f2f7;
  --bg2:         #ffffff;
  --bg3:         #e8ebf2;
  --card:        #ffffff;
  --card2:       #f4f6fb;
  --border:      rgba(0,0,0,.09);
  --border2:     rgba(0,48,135,.25);
  --primary:     #003087;
  --primary-dim: rgba(0,48,135,.1);
  --primary-glow:rgba(0,48,135,.3);
  --accent:      #e2001a;
  --accent-dim:  rgba(226,0,26,.12);
  --success:     #0a7c55;
  --success-dim: rgba(10,124,85,.12);
  --danger:      #c8102e;
  --danger-dim:  rgba(200,16,46,.12);
  --warning:     #d97706;
  --info:        #0077b6;
  --info-dim:    rgba(0,119,182,.12);
  --text:        #0d1b3e;
  --text2:       #3d5080;
  --text3:       #8a9abf;
  --shadow:      0 2px 12px rgba(0,48,135,.12);
  --shadow-lg:   0 10px 40px rgba(0,48,135,.18);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 15px; line-height: 1.6; min-height: 100vh; }
/* ─── SCROLLBAR ─── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg2); }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--primary); }
/* ─── LAYOUT ─── */
.app { display: flex; min-height: 100vh; }
/* ─── SIDEBAR ─── */
.sidebar {
  width: var(--sidebar-w); height: 100vh; position: fixed; left: 0; top: 0; z-index: 100;
  background: var(--bg2); border-right: 1px solid var(--border);
  display: flex; flex-direction: column; transition: transform .3s ease;
  overflow: hidden;
}
.sidebar-logo {
  padding: 1.5rem 1.5rem 1rem; display: flex; align-items: center; gap: .75rem;
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.sidebar-logo .logo-icon { font-size: 1.8rem; filter: drop-shadow(0 0 8px var(--primary-glow)); }
.sidebar-logo .logo-text { font-family: var(--font-display); font-weight: 800; font-size: 1.2rem; color: var(--text); letter-spacing: -.5px; }
.sidebar-logo .logo-ver { font-size: .65rem; color: var(--text3); font-family: var(--font-mono); }
.sidebar-section { padding: .75rem 1rem .25rem; font-size: .68rem; color: var(--text3); font-weight: 600; text-transform: uppercase; letter-spacing: .1em; flex-shrink: 0; }
.sidebar-nav { flex: 1; padding: .5rem; overflow-y: auto; min-height: 0; }
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-track { background: transparent; }
.sidebar-nav::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }
.nav-item {
  display: flex; align-items: center; gap: .75rem; padding: .65rem 1rem; border-radius: var(--radius-sm);
  color: var(--text2); text-decoration: none; font-size: .88rem; font-weight: 500;
  transition: all .2s ease; margin-bottom: 2px; position: relative;
}
.nav-item:hover { background: var(--primary-dim); color: var(--text); }
.nav-item.active { background: var(--primary-dim); color: var(--primary); }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 20px; background: var(--primary); border-radius: 0 2px 2px 0; }
.nav-icon { font-size: 1.1rem; width: 24px; text-align: center; }
.nav-label { flex: 1; }
.sidebar-user {
  padding: 1rem 1.25rem; border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
}
.user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-dim); border: 2px solid var(--border2); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; color: var(--primary); flex-shrink: 0; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-size: .85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: .7rem; color: var(--text3); }
.btn-logout { color: var(--text3); text-decoration: none; font-size: 1rem; transition: color .2s; flex-shrink: 0; }
.btn-logout:hover { color: var(--danger); }
/* ─── HAMBURGER (mobile) ─── */
.btn-hamburger {
  display: none; background: none; border: none; cursor: pointer;
  color: var(--text2); font-size: 1.4rem; padding: .25rem; line-height: 1;
  transition: color .2s;
}
.btn-hamburger:hover { color: var(--primary); }
.sidebar-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55);
  z-index: 99; backdrop-filter: blur(2px);
}
.sidebar-overlay.open { display: block; }
/* ─── MAIN ─── */
.main { margin-left: var(--sidebar-w); flex: 1; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left .3s ease; }
/* ─── TOPBAR ─── */
.topbar {
  height: var(--topbar-h); background: var(--bg2); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 1.5rem; gap: 1rem; position: sticky; top: 0; z-index: 50;
}
.topbar-title { font-family: var(--font-display); font-weight: 700; font-size: 1.1rem; flex: 1; }
.topbar-right { display: flex; align-items: center; gap: .75rem; }
.topbar-badge { font-size: .7rem; background: var(--accent-dim); color: var(--accent); padding: .2rem .6rem; border-radius: 999px; font-weight: 600; font-family: var(--font-mono); }
/* ─── THEME TOGGLE ─── */
.btn-theme {
  background: var(--card2); border: 1px solid var(--border); border-radius: var(--radius-sm);
  cursor: pointer; color: var(--text2); font-size: .88rem; padding: .3rem .65rem;
  display: flex; align-items: center; gap: .4rem; transition: all .2s; white-space: nowrap;
}
.btn-theme:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-dim); }

/* ─── RESPONSIVE ─── */
@media (max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,.4); }
  .main { margin-left: 0; }
  .btn-hamburger { display: flex; }
  .content { padding: 1rem !important; }
}
@media (max-width: 600px) {
  .topbar { padding: 0 1rem; }
  .topbar-title { font-size: .95rem; }
  .kpi-row { grid-template-columns: 1fr 1fr !important; }
  .dash-row { grid-template-columns: 1fr !important; }
  .stats-grid { grid-template-columns: 1fr !important; }
  .form-grid { grid-template-columns: 1fr !important; }
  .form-full { grid-column: 1 !important; }
  .topbar-right .user-info-name { display: none !important; }
}
/* ─── FLASH MESSAGES ─── */
.flash-container { padding: 1rem 2rem 0; }
.flash { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.25rem; border-radius: var(--radius-sm); font-size: .9rem; font-weight: 500; animation: slideDown .3s ease; }
.flash-success { background: var(--success-dim); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }
.flash-error   { background: var(--danger-dim); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
.flash-info    { background: var(--info-dim); border: 1px solid rgba(56,189,248,.3); color: #7dd3fc; }
@keyframes slideDown { from { opacity:0; transform: translateY(-10px) } to { opacity:1; transform: translateY(0) } }
/* ─── CONTENT ─── */
.content { padding: 2rem; flex: 1; max-width: 1400px; }
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
.page-title-txt { font-family: var(--font-display); font-weight: 700; font-size: 1.4rem; }
/* ─── CARDS ─── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 1.5rem; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.card-title { font-family: var(--font-display); font-weight: 600; font-size: .95rem; color: var(--text2); }
/* ─── TABLES ─── */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th { padding: .85rem 1.25rem; text-align: left; font-size: .75rem; font-weight: 600; color: var(--text3); text-transform: uppercase; letter-spacing: .08em; border-bottom: 1px solid var(--border); white-space: nowrap; }
.data-table td { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); font-size: .88rem; vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tbody tr { transition: background .15s; }
.data-table tbody tr:hover { background: rgba(255,255,255,.02); }
.data-table .muted { color: var(--text2); font-size: .82rem; }
.empty-cell { text-align: center; color: var(--text3); padding: 3rem !important; font-style: italic; }
.actions { display: flex; gap: .4rem; align-items: center; }

/* ─── OVERRIDES THÈME CLAIR ─── */
[data-theme="light"] body { background: var(--bg); }
[data-theme="light"] .badge-success { color: #065f46; }
[data-theme="light"] .badge-danger  { color: #991b1b; }
[data-theme="light"] .badge-warning { color: #92400e; }
[data-theme="light"] .badge-info    { color: #075985; }
[data-theme="light"] .badge-muted   { background: rgba(0,0,0,.07); color: var(--text2); }
[data-theme="light"] .stock-pill-ok  { color: #065f46; }
[data-theme="light"] .stock-pill-low { color: #991b1b; }
[data-theme="light"] .stock-pill-out { color: #92400e; }
[data-theme="light"] .stock-empty   { color: #991b1b; }
[data-theme="light"] .stock-low     { color: #92400e; }
[data-theme="light"] .data-table tbody tr:hover { background: rgba(0,48,135,.04); }
[data-theme="light"] .nav-item:hover { background: rgba(0,48,135,.08); color: var(--text); }
[data-theme="light"] .nav-item.active { background: rgba(0,48,135,.1); }
[data-theme="light"] code.ref { background: rgba(0,0,0,.06); color: var(--text2); }
[data-theme="light"] .modal-overlay { background: rgba(0,0,0,.45); }
[data-theme="light"] .kpi-blue   { border-color: rgba(0,48,135,.2); } [data-theme="light"] .kpi-blue::before { opacity:.03; }
[data-theme="light"] .kpi-green  { border-color: rgba(10,124,85,.2); }
[data-theme="light"] .kpi-amber  { border-color: rgba(217,119,6,.2); }
[data-theme="light"] .kpi-violet { border-color: rgba(109,40,217,.2); }
[data-theme="light"] .btn-primary { background: linear-gradient(135deg, #003087, #1a53c5); }
[data-theme="light"] .shortcut-in    { background: rgba(10,124,85,.07); border-color: rgba(10,124,85,.2); }
[data-theme="light"] .shortcut-out   { background: rgba(217,119,6,.07); border-color: rgba(217,119,6,.2); }
[data-theme="light"] .shortcut-order { background: rgba(0,48,135,.07); border-color: rgba(0,48,135,.2); }
[data-theme="light"] .shortcut-resa  { background: rgba(0,119,182,.07); border-color: rgba(0,119,182,.2); }
[data-theme="light"] .shortcut-resa-urgent { background: rgba(226,0,26,.07); border-color: rgba(226,0,26,.35); }
[data-theme="light"] .info-banner { background: rgba(0,48,135,.06); border-color: rgba(0,48,135,.15); color: #003087; }
[data-theme="light"] .muted { color: var(--text2) !important; }
[data-theme="light"] .row-cancelled { opacity: .45; }

/* ─── SEARCH BAR ─── */
.search-bar-wrap { margin-bottom:1rem; }
.search-bar {
  display:flex; align-items:center; gap:.6rem;
  background:var(--card2); border:1px solid var(--border);
  border-radius:var(--radius-sm); padding:.55rem .9rem;
  transition:border-color .2s;
}
.search-bar:focus-within { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-dim); }
.search-bar-icon { font-size:1rem; opacity:.5; flex-shrink:0; }
.search-bar input {
  flex:1; background:none; border:none; outline:none;
  color:var(--text); font-size:.9rem; font-family:var(--font-body);
}
.search-bar input::placeholder { color:var(--text3); }
.search-bar-clear {
  background:none; border:none; cursor:pointer; color:var(--text3);
  font-size:1rem; line-height:1; padding:0; display:none; transition:color .15s;
}
.search-bar-clear:hover { color:var(--text); }
.search-count { font-size:.75rem; color:var(--text3); margin-top:.3rem; min-height:1em; }
.row-cancelled { opacity: .5; }
.row-fulfilled { opacity: .65; }
.pg-btn { display:inline-block; padding:.3rem .7rem; border:1px solid var(--border); border-radius:var(--radius-sm); text-decoration:none; color:var(--text2); background:var(--card2); font-size:.82rem; transition:all .15s; }
.pg-btn:hover { border-color:var(--primary); color:var(--primary); }
.pg-btn-active { background:var(--primary); color:#fff; border-color:var(--primary); font-weight:700; pointer-events:none; }
.cart-list { max-width: 280px; font-size: .8rem; color: var(--text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* ─── BADGES ─── */
.badge { display: inline-block; padding: .2rem .65rem; border-radius: 999px; font-size: .72rem; font-weight: 600; white-space: nowrap; font-family: var(--font-mono); }
.badge-success  { background: var(--success-dim); color: #6ee7b7; }
.badge-danger   { background: var(--danger-dim); color: #fca5a5; }
.badge-warning  { background: var(--accent-dim); color: var(--accent); }
.badge-info     { background: var(--info-dim); color: var(--info); }
.badge-muted    { background: rgba(255,255,255,.06); color: var(--text2); }
/* ─── STOCK PILLS ─── */
.stock-pill { display: inline-block; padding: .2rem .75rem; border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: .82rem; font-weight: 600; }
.stock-pill-ok   { background: var(--success-dim); color: #6ee7b7; }
.stock-pill-low  { background: var(--danger-dim); color: #fca5a5; }
.stock-pill-out  { background: var(--accent-dim); color: var(--accent); }
.stock-badge { display: inline-block; padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
.stock-empty { background: var(--danger-dim); color: #fca5a5; }
.stock-low   { background: var(--accent-dim); color: var(--accent); }
/* ─── BUTTONS ─── */
.btn-primary {
  background: linear-gradient(135deg, var(--primary), #3a86ff); border: none; border-radius: var(--radius-sm);
  padding: .65rem 1.5rem; color: #fff; font-family: var(--font-display); font-weight: 600; font-size: .9rem;
  cursor: pointer; transition: all .2s; white-space: nowrap; text-decoration: none; display: inline-block;
  box-shadow: 0 4px 15px var(--primary-glow);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px var(--primary-glow); }
.btn-primary:active { transform: translateY(0); }
.btn-secondary { background: var(--card2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: .65rem 1.25rem; color: var(--text2); font-family: var(--font); font-weight: 500; font-size: .9rem; cursor: pointer; transition: all .2s; }
.btn-secondary:hover { border-color: var(--primary); color: var(--text); }
.btn-danger { background: var(--danger); border: none; border-radius: var(--radius-sm); padding: .65rem 1.25rem; color: #fff; font-weight: 600; font-size: .9rem; cursor: pointer; transition: opacity .2s; }
.btn-danger:hover { opacity: .85; }
.btn-icon { background: none; border: none; cursor: pointer; font-size: 1rem; padding: .3rem .5rem; border-radius: var(--radius-sm); transition: all .15s; }
.btn-edit:hover { background: var(--primary-dim); }
.btn-del:hover { background: var(--danger-dim); }
.btn-link { display: block; text-align: center; padding: .75rem; color: var(--primary); font-size: .85rem; text-decoration: none; border-top: 1px solid var(--border); margin-top: .5rem; transition: background .15s; }
.btn-link:hover { background: var(--primary-dim); }
/* ─── FORMS ─── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
.form-group { display: flex; flex-direction: column; gap: .4rem; }
.form-full { grid-column: 1 / -1; }
label { font-size: .78rem; font-weight: 600; color: var(--text3); text-transform: uppercase; letter-spacing: .06em; }
input, select, textarea {
  background: var(--bg3); border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: .7rem 1rem; color: var(--text); font-size: .9rem; font-family: var(--font);
  transition: border-color .2s, box-shadow .2s; width: 100%;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-dim); }
input::placeholder, textarea::placeholder { color: var(--text3); }
select option { background: var(--bg3); }
textarea { resize: vertical; min-height: 80px; }
.input-disabled { opacity: .5; cursor: not-allowed; }
code.ref { font-family: var(--font-mono); font-size: .78rem; background: rgba(255,255,255,.05); padding: .15rem .5rem; border-radius: 4px; color: var(--text2); }
.checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .5rem; background: var(--bg3); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1rem; max-height: 200px; overflow-y: auto; }
.checkbox-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; cursor: pointer; padding: .3rem; border-radius: 4px; transition: background .15s; }
.checkbox-item:hover { background: var(--primary-dim); }
.checkbox-item input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; }
/* ─── MODALS ─── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px); }
.modal-overlay.open { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity:0 } to { opacity:1 } }
.modal { background: var(--card); border: 1px solid var(--border2); border-radius: var(--radius); box-shadow: var(--shadow-lg); width: 100%; max-width: 580px; max-height: 90vh; overflow-y: auto; animation: slideUp .25s ease; }
.modal-lg { max-width: 700px; }
.modal-xl { max-width: 850px; }
.modal-sm { max-width: 420px; }
@keyframes slideUp { from { transform: translateY(20px); opacity:0 } to { transform: translateY(0); opacity:1 } }
.modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--card); z-index: 1; }
.modal-header h3 { font-family: var(--font-display); font-weight: 700; font-size: 1.1rem; }
.modal-close { background: none; border: none; color: var(--text3); font-size: 1.1rem; cursor: pointer; padding: .25rem; transition: color .15s; }
.modal-close:hover { color: var(--text); }
.modal form { padding: 1.5rem; }
.modal p { padding: 0 1.5rem; }
.modal-footer { display: flex; justify-content: flex-end; gap: .75rem; padding-top: 1.25rem; border-top: 1px solid var(--border); margin-top: 1.25rem; }
/* ─── DASHBOARD ─── */
.dashboard-grid { display: flex; flex-direction: column; gap: 1.5rem; }
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; }
.kpi-card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem; position: relative; overflow: hidden;
  transition: transform .2s, border-color .2s;
  cursor: pointer;
}
.kpi-card::before { content: ''; position: absolute; inset: 0; opacity: .04; }
.kpi-card:hover { transform: translateY(-2px); }
.kpi-blue   { border-color: rgba(67,97,238,.3); } .kpi-blue::before { background: radial-gradient(circle at 100% 0, #4361ee, transparent 60%); }
.kpi-violet { border-color: rgba(123,45,139,.3); } .kpi-violet::before { background: radial-gradient(circle at 100% 0, #7b2d8b, transparent 60%); }
.kpi-green  { border-color: rgba(16,185,129,.3); } .kpi-green::before { background: radial-gradient(circle at 100% 0, #10b981, transparent 60%); }
.kpi-amber  { border-color: rgba(245,158,11,.3); } .kpi-amber::before { background: radial-gradient(circle at 100% 0, #f59e0b, transparent 60%); }
.kpi-teal   { border-color: rgba(6,182,212,.3); }  .kpi-teal::before { background: radial-gradient(circle at 100% 0, #06b6d4, transparent 60%); }
.kpi-orange { border-color: rgba(249,115,22,.3); } .kpi-orange::before { background: radial-gradient(circle at 100% 0, #f97316, transparent 60%); }
.kpi-icon { font-size: 2rem; flex-shrink: 0; }
.kpi-info { display: flex; flex-direction: column; flex: 1; }
.kpi-val { font-family: var(--font-display); font-size: 2rem; font-weight: 800; line-height: 1.1; color: var(--text); }
.kpi-label { font-size: .78rem; color: var(--text2); text-transform: uppercase; letter-spacing: .05em; font-weight: 500; margin-top: .15rem; }
.kpi-sub { font-size: .75rem; color: var(--text3); }
.dash-row { display: grid; grid-template-columns: 1fr 340px; gap: 1.25rem; }
.dash-chart canvas { padding: 1rem 1.5rem 1.5rem; }
.dash-alerts { }
.alert-item { display: flex; align-items: center; justify-content: space-between; padding: .85rem 1.25rem; border-bottom: 1px solid var(--border); gap: .75rem; }
.alert-left { display: flex; align-items: center; gap: .75rem; }
.alert-name { font-weight: 600; font-size: .88rem; color: var(--text); }
.alert-thresh { font-size: .75rem; color: var(--text3); }
.empty-mini { text-align: center; color: var(--text3); padding: 2rem 1rem; font-style: italic; font-size: .88rem; }
.activity-list { }
.activity-item { display: flex; align-items: center; gap: .75rem; padding: .75rem 1.25rem; border-bottom: 1px solid var(--border); }
.activity-item:last-child { border-bottom: none; }
.activity-icon { font-size: 1.1rem; width: 30px; text-align: center; }
.activity-info { flex: 1; }
.activity-desc { display: block; font-size: .85rem; font-weight: 500; }
.activity-user { font-size: .75rem; color: var(--text3); }
.activity-time { font-size: .75rem; color: var(--text3); font-family: var(--font-mono); white-space: nowrap; }
/* ─── STATS ─── */
.stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
.stats-grid .card canvas { padding: 1rem 1.25rem 1.5rem; max-height: 280px; }
.bar-wrap { display: flex; align-items: center; gap: .75rem; }
.bar-fill { height: 8px; background: linear-gradient(90deg, var(--primary), #3a86ff); border-radius: 4px; min-width: 4px; transition: width .5s ease; }
.bar-wrap span { font-family: var(--font-mono); font-size: .85rem; color: var(--text2); }
/* ─── INFO BANNER ─── */
.info-banner { background: var(--info-dim); border: 1px solid rgba(56,189,248,.2); border-radius: var(--radius-sm); padding: .85rem 1.25rem; color: var(--info); font-size: .88rem; margin-bottom: 1.5rem; }
/* ─── MISC ─── */
.muted { color: var(--text2) !important; }
</style>
</head>
<body>
<div class="app">
<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <span class="logo-icon">🖨️</span>
    <div><div class="logo-text">PrintManager</div><div class="logo-ver">v1.0.0</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">Principal</div>
    <a href="index.php?page=dashboard"    class="nav-item <?=$page==='dashboard'?'active':''?>"><span class="nav-icon">🏠</span><span class="nav-label">Tableau de bord</span></a>
    <a href="index.php?page=printers"     class="nav-item <?=$page==='printers'?'active':''?>"><span class="nav-icon">🖨️</span><span class="nav-label">Imprimantes</span></a>
    <a href="index.php?page=cartridges"   class="nav-item <?=$page==='cartridges'?'active':''?>"><span class="nav-icon">🖋️</span><span class="nav-label">Cartouches</span></a>
    <div class="sidebar-section">Stock</div>
    <a href="index.php?page=stock_in"     class="nav-item <?=$page==='stock_in'?'active':''?>"><span class="nav-icon">📦</span><span class="nav-label">Entrées de stock</span></a>
    <a href="index.php?page=stock_out"    class="nav-item <?=$page==='stock_out'?'active':''?>"><span class="nav-icon">📤</span><span class="nav-label">Sorties de stock</span></a>
    <a href="index.php?page=reservations" class="nav-item <?=$page==='reservations'?'active':''?>">
      <span class="nav-icon">📋</span><span class="nav-label">Demandes</span>
      <?php if(($dashData['pending_demands']??0)>0): ?>
        <span class="badge badge-warning"><?=h($dashData['pending_demands'])?></span>
      <?php endif; ?>
    </a>
    <a href="index.php?page=orders" class="nav-item <?=$page==='orders'||$page==='order_view'?'active':''?>">
      <span class="nav-icon">🛒</span><span class="nav-label">Commandes</span>
      <?php if(($dashData['pending_orders']??0)>0): ?>
        <span class="badge badge-info"><?=h($dashData['pending_orders'])?></span>
      <?php endif; ?>
    </a>
    <div class="sidebar-section">Référentiels</div>
    <a href="index.php?page=services"     class="nav-item <?=$page==='services'?'active':''?>"><span class="nav-icon">🏢</span><span class="nav-label">Services</span></a>
    <a href="index.php?page=suppliers"    class="nav-item <?=$page==='suppliers'?'active':''?>"><span class="nav-icon">🏭</span><span class="nav-label">Fournisseurs</span></a>
    <div class="sidebar-section">Analyse</div>
    <a href="index.php?page=stats"       class="nav-item <?=$page==='stats'?'active':''?>"><span class="nav-icon">📊</span><span class="nav-label">Statistiques</span></a>
    <a href="index.php?page=ink_levels"  class="nav-item <?=$page==='ink_levels'?'active':''?>"><span class="nav-icon">🖨️</span><span class="nav-label">Niveaux d'encre</span></a>
    <?php if(isAdmin()): ?>
    <div class="sidebar-section">Admin</div>
    <a href="index.php?page=admin"        class="nav-item <?=$page==='admin'?'active':''?>"><span class="nav-icon">⚙️</span><span class="nav-label">Administration</span></a>
    <?php endif; ?>
  </nav>
</aside>

<!-- ═══ MAIN ═══ -->
<main class="main">
  <div class="topbar">
    <button class="btn-hamburger" onclick="openSidebar()" title="Menu">☰</button>
    <span class="topbar-title"><?=h($pageTitle[$page]??ucfirst($page))?></span>
    <div class="topbar-right">
      <button class="btn-theme" id="btn-theme" onclick="toggleTheme()" title="Changer de thème">
        <span id="theme-icon">🌙</span>
        <span id="theme-label">Sombre</span>
      </button>
      <div style="display:flex;align-items:center;gap:.6rem;border-left:1px solid var(--border);padding-left:.75rem;margin-left:.25rem">
        <div class="user-avatar"><?=strtoupper(substr($user['username'],0,1))?></div>
        <div class="user-info-name" style="line-height:1.3">
          <div style="font-size:.85rem;font-weight:700;color:var(--text);white-space:nowrap"><?=h($user['full_name']??$user['username'])?></div>
          <div style="font-size:.7rem;color:var(--text3)"><?=$user['role']==='admin'?'👑 Administrateur':'👤 Utilisateur'?></div>
        </div>
        <a href="index.php?page=logout" class="btn-logout" title="Se déconnecter">⏏️</a>
      </div>
    </div>
  </div>

  <!-- Flash messages -->
  <?php $flashes = getFlashes(); if($flashes): ?>
  <div class="flash-container">
    <?php foreach($flashes as $f): ?>
    <div class="flash flash-<?=h($f['type'])?>"><?=h($f['msg'])?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="content">
    <?=$content?>
  </div>
</main>
</div>

<script>
// ═══ TABLE SEARCH (réutilisable) ═══
function tableSearch(inp, tbodyId, countId) {
  const q     = inp.value.trim().toLowerCase();
  const tbody = document.getElementById(tbodyId);
  const count = document.getElementById(countId);
  const clear = inp.nextElementSibling;
  if (!tbody) return;
  if (clear) clear.style.display = q ? 'block' : 'none';
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const words = q.split(/\s+/).filter(Boolean);
  let visible = 0;
  rows.forEach(function(tr) {
    if (tr.querySelector('td[colspan]')) { return; } // empty-cell row
    const txt = tr.textContent.toLowerCase();
    const match = !words.length || words.every(function(w) { return txt.includes(w); });
    tr.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  if (count) {
    if (!q)           count.textContent = '';
    else if (!visible) count.textContent = 'Aucun résultat.';
    else              count.textContent  = visible + ' résultat(s)';
  }
}
function clearSearch(inpId, tbodyId, countId, clearId) {
  const inp = document.getElementById(inpId);
  if (inp) { inp.value = ''; tableSearch(inp, tbodyId, countId); inp.focus(); }
  const cl = document.getElementById(clearId);
  if (cl) cl.style.display = 'none';
}

// ═══ THEME TOGGLE ═══
(function() {
  const saved = localStorage.getItem('pm_theme') || 'dark';
  applyTheme(saved);
})();

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : '');
  const icon  = document.getElementById('theme-icon');
  const label = document.getElementById('theme-label');
  if (icon)  icon.textContent  = theme === 'light' ? '☀️' : '🌙';
  if (label) label.textContent = theme === 'light' ? 'Clair' : 'Sombre';
  localStorage.setItem('pm_theme', theme);
}

function toggleTheme() {
  const current = localStorage.getItem('pm_theme') || 'dark';
  applyTheme(current === 'dark' ? 'light' : 'dark');
}

// ═══ SIDEBAR RESPONSIVE ═══
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebar-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
// Close sidebar on nav click (mobile)
document.querySelectorAll('.nav-item').forEach(function(a) {
  a.addEventListener('click', function() {
    if (window.innerWidth <= 900) closeSidebar();
  });
});

// ═══ PRINTER MODEL AUTO-FILL ═══
// Les cartouches sont héritées du modèle (non modifiables en ajout)
// PM_CART_LABELS est injecté dans pagePrinters

async function fillFromPrinterModel(act) {
  var sel = document.getElementById(act+'-model-select');
  if (!sel) return;
  var mid = parseInt(sel.value);
  var readonlyDiv = document.getElementById(act+'-cart-readonly');
  var hiddenDiv   = document.getElementById(act+'-cart-hidden');
  var sourceLabel = document.getElementById(act+'-cart-source');

  if (!mid) {
    if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.85rem">Sélectionnez un modèle ci-dessus pour voir les cartouches associées.</span>';
    if (hiddenDiv)   hiddenDiv.innerHTML = '';
    return;
  }

  // Pré-remplir marque / modèle (hidden inputs + display div)
  var opt = sel.options[sel.selectedIndex];
  var brandEl = document.getElementById(act+'-brand');
  var modelEl = document.getElementById(act+'-model');
  var brandDisplay = document.getElementById(act+'-brand-display');
  var modelDisplay = document.getElementById(act+'-model-display');
  var brandVal = opt ? (opt.dataset.brand || '') : '';
  var modelVal = opt ? (opt.dataset.model || '') : '';
  if (brandEl) brandEl.value = brandVal;
  if (modelEl) modelEl.value = modelVal;
  if (brandDisplay) { brandDisplay.textContent = brandVal || '—'; brandDisplay.style.color = brandVal ? 'var(--text)' : 'var(--text3)'; brandDisplay.style.fontStyle = brandVal ? 'normal' : 'italic'; }
  if (modelDisplay) { modelDisplay.textContent = modelVal || '—'; modelDisplay.style.color = modelVal ? 'var(--text)' : 'var(--text3)'; modelDisplay.style.fontStyle = modelVal ? 'normal' : 'italic'; }

  if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--text3);font-size:.82rem">⏳ Chargement…</span>';

  try {
    var resp = await fetch('index.php?ajax_printer_model_cids=1&model_id='+mid);
    var cids = await resp.json();

    // Affichage lecture seule
    if (readonlyDiv) {
      if (!cids.length) {
        readonlyDiv.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.82rem">Aucune cartouche définie sur ce modèle.</span>';
      } else {
        var colorMap = {'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Bleu':'#38bdf8','Rouge':'#ef4444'};
        readonlyDiv.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:.5rem">' +
          cids.map(function(id) {
            var info = PM_CART_LABELS[id] || {label:'#'+id, color:''};
            var dot = colorMap[info.color] || '#94a3b8';
            return '<span style="display:inline-flex;align-items:center;gap:.35rem;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:.25rem .65rem;font-size:.82rem">'
              +'<span style="width:8px;height:8px;border-radius:50%;background:'+dot+';flex-shrink:0"></span>'
              + info.label + '</span>';
          }).join('') + '</div>';
      }
    }

    // Inputs cachés pour la soumission
    if (hiddenDiv) {
      hiddenDiv.innerHTML = cids.map(function(id) {
        return '<input type="hidden" name="cartridge_ids[]" value="'+id+'">';
      }).join('');
    }
  } catch(e) {
    if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--danger);font-size:.82rem">Erreur de chargement.</span>';
  }
}

// ═══ MODAL SYSTEM ═══
function openModal(id){
  const el=document.getElementById(id);
  if(el){ el.classList.add('open'); document.body.style.overflow='hidden'; }
}
function closeModal(id){
  const el=document.getElementById(id);
  if(el){ el.classList.remove('open'); document.body.style.overflow=''; }
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(ov=>{
  ov.addEventListener('click',e=>{ if(e.target===ov) closeModal(ov.id); });
});
// Close on Escape
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>closeModal(m.id)); });

// ═══ EDIT MODAL GENERIC ═══
function openEditModal(data, entity){
  // Set ID
  const idField=document.getElementById('edit-id');
  if(idField) idField.value=data.id||data.id===0?data.id:'';
  // Populate fields
  Object.keys(data).forEach(k=>{
    const el=document.getElementById('edit-'+k);
    if(!el) return;
    if(el.tagName==='SELECT'){ el.value=data[k]||''; }
    else if(el.tagName==='TEXTAREA'){ el.value=data[k]||''; }
    else { el.value=data[k]||''; }
  });
  openModal('modal-edit');
}

// ═══ DELETE CONFIRMATION ═══
function confirmDel(id, entity, name){
  const msg=document.getElementById('del-message');
  const did=document.getElementById('del-id');
  // Find entity input in delete form
  const form=document.querySelector('#modal-delete form');
  if(form){
    const ent=form.querySelector('input[name="_entity"]');
    if(ent) ent.value=entity;
  }
  if(msg) msg.innerHTML='Voulez-vous vraiment supprimer <strong>"'+name+'"</strong> ?<br><small style="color:#ef4444">Cette action est irréversible.</small>';
  if(did) did.value=id;
  openModal('modal-delete');
}

// ═══ PRINTER EDIT MODAL ═══
// Lit les données depuis data-printer et data-cids du bouton cliqué
function openPrinterEdit(btn) {
  try {
    var p    = JSON.parse(btn.getAttribute('data-printer'));
    var cids = JSON.parse(btn.getAttribute('data-cids') || '[]');
  } catch(e) { console.error('openPrinterEdit parse error', e); return; }

  var idEl = document.getElementById('edit-id');
  if (idEl) idEl.value = p.id;

  ['serial_number','ip_address','location','purchase_date','warranty_end','notes'].forEach(function(k) {
    var el = document.getElementById('edit-' + k);
    if (el) el.value = p[k] || '';
  });
  // Marque et modèle en lecture seule — affichage + hidden input
  var brandDisplay = document.getElementById('edit-brand-display');
  var modelDisplay = document.getElementById('edit-model-display');
  var brandHidden  = document.getElementById('edit-brand');
  var modelHidden  = document.getElementById('edit-model');
  if (brandDisplay) brandDisplay.textContent = p.brand || '–';
  if (modelDisplay) modelDisplay.textContent = p.model || '–';
  if (brandHidden)  brandHidden.value  = p.brand || '';
  if (modelHidden)  modelHidden.value  = p.model || '';
  var ss = document.getElementById('edit-service_id');
  if (ss) ss.value = (p.service_id !== null && p.service_id !== undefined) ? p.service_id : '';
  var st = document.getElementById('edit-status');
  if (st) st.value = p.status || 'active';

  // Afficher les cartouches compatibles (lecture seule)
  var cartList = document.getElementById('edit-cart-list');
  if (cartList) {
    var colorMap = {'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Bleu':'#38bdf8','Rouge':'#ef4444','Vert':'#10b981'};
    if (!cids.length) {
      cartList.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.82rem">Aucune cartouche associée</span>';
    } else {
      cartList.innerHTML = cids.map(function(id) {
        var info = (typeof PM_CART_LABELS !== 'undefined' && PM_CART_LABELS[id]) ? PM_CART_LABELS[id] : {label: '#'+id, color: ''};
        var dot  = colorMap[info.color] || '#94a3b8';
        return '<span style="display:inline-flex;align-items:center;gap:.35rem;background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:.3rem .65rem;font-size:.8rem;font-weight:600">'
          + '<span style="width:8px;height:8px;border-radius:50%;background:'+dot+';flex-shrink:0"></span>'
          + info.label
          + '</span>';
      }).join('');
    }
  }

  document.querySelectorAll('#modal-edit .cart-check').forEach(function(cb) {
    cb.checked = cids.indexOf(parseInt(cb.value)) !== -1;
  });
  openModal('modal-edit');
}

// ═══ AUTO HIDE FLASH ═══
setTimeout(()=>{ document.querySelectorAll('.flash').forEach(f=>{ f.style.transition='opacity .5s'; f.style.opacity='0'; setTimeout(()=>f.remove(),500); }); }, 5000);

// ═══ AUTO OPEN MODAL (depuis raccourci) ═══
<?php if($autoOpen): ?>
window.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('<?=h($autoOpen)?>');
  if (el) { el.classList.add('open'); document.body.style.overflow='hidden'; }
});
<?php endif; ?>
</script>

<!-- ═══ MODAL SCANNER QR ═══ -->
<div class="modal-overlay" id="modal-qr-scan">
  <div class="modal modal-sm"><div class="modal-header"><h3>📷 Scanner un QR Code</h3><button class="modal-close" onclick="closeQrScanner()">✕</button></div>
  <div style="padding:1.5rem;text-align:center">
    <div id="qr-scan-reader" style="width:100%;border-radius:8px;overflow:hidden;background:#000;min-height:260px;display:flex;align-items:center;justify-content:center">
      <span style="color:#fff;opacity:.5;font-size:.88rem">Démarrage de la caméra…</span>
    </div>
    <div id="qr-scan-status" style="margin-top:.75rem;font-size:.85rem;color:var(--text3)">Pointez vers un QR Code de cartouche</div>
    <button onclick="closeQrScanner()" class="btn-secondary" style="margin-top:.75rem;font-size:.85rem">Annuler</button>
  </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
var _qrVideo = null, _qrCanvas = null, _qrCtx = null, _qrRunning = false, _qrAnim = null;
var _qrTargetSelect = null, _qrContext = null;

function openQrScanner(selectId, ctx) {
    _qrTargetSelect = selectId;
    _qrContext = ctx;
    openModal('modal-qr-scan');
    document.getElementById('qr-scan-status').textContent = 'Démarrage de la caméra…';

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(function(stream) {
        _qrVideo = document.createElement('video');
        _qrVideo.srcObject = stream;
        _qrVideo.setAttribute('playsinline', true);
        _qrVideo.play();
        _qrCanvas = document.createElement('canvas');
        _qrCtx = _qrCanvas.getContext('2d');
        var reader = document.getElementById('qr-scan-reader');
        reader.innerHTML = '';
        _qrVideo.style.cssText = 'width:100%;display:block';
        reader.appendChild(_qrVideo);
        _qrRunning = true;
        document.getElementById('qr-scan-status').textContent = '🔍 Pointez vers un QR Code de cartouche…';
        requestAnimationFrame(_qrTick);
    })
    .catch(function(err) {
        document.getElementById('qr-scan-status').textContent = '⚠️ Caméra inaccessible. Saisissez la référence manuellement.';
    });
}

function _qrTick() {
    if (!_qrRunning || !_qrVideo || _qrVideo.readyState !== _qrVideo.HAVE_ENOUGH_DATA) {
        if (_qrRunning) _qrAnim = requestAnimationFrame(_qrTick);
        return;
    }
    _qrCanvas.width = _qrVideo.videoWidth;
    _qrCanvas.height = _qrVideo.videoHeight;
    _qrCtx.drawImage(_qrVideo, 0, 0);
    var img = _qrCtx.getImageData(0, 0, _qrCanvas.width, _qrCanvas.height);
    var code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
    if (code) {
        _qrApply(code.data);
    } else {
        _qrAnim = requestAnimationFrame(_qrTick);
    }
}

function _qrApply(data) {
    closeQrScanner();
    // Contexte cartouche : enregistrer le code brut comme code-barres
    if (_qrContext === 'cart-add' || _qrContext === 'cart-edit') {
        var barcodeField = document.getElementById(
            _qrContext === 'cart-add' ? 'cart-add-barcode' : 'edit-barcode'
        );
        if (barcodeField) {
            barcodeField.value = data;
            barcodeField.style.borderColor = 'var(--success)';
            setTimeout(function() { barcodeField.style.borderColor = ''; }, 2000);
        }
        return;
    }
    // Contexte entrée/sortie : retrouver la cartouche par barcode ou URL
    var cidMatch = data.match(/prefill_cid=(\d+)/);
    if (cidMatch) {
        var cid = cidMatch[1];
        if (_qrTargetSelect) {
            var sel = document.getElementById(_qrTargetSelect);
            if (sel) { sel.value = cid; sel.dispatchEvent(new Event('change')); }
        }
    } else {
        fetch('index.php?ajax_find_cartridge=1&q=' + encodeURIComponent(data))
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.id && _qrTargetSelect) {
                var sel = document.getElementById(_qrTargetSelect);
                if (sel) {
                    sel.value = res.id;
                    sel.dispatchEvent(new Event('change'));
                    sel.style.borderColor = 'var(--success)';
                    setTimeout(function() { sel.style.borderColor = ''; }, 2000);
                }
            } else {
                var st = document.getElementById('qr-scan-status');
                if (st) st.textContent = '\u26a0\ufe0f Code non reconnu : ' + data;
            }
        });
    }
}
function closeQrScanner() {
    _qrRunning = false;
    if (_qrAnim) cancelAnimationFrame(_qrAnim);
    if (_qrVideo && _qrVideo.srcObject) {
        _qrVideo.srcObject.getTracks().forEach(function(t) { t.stop(); });
    }
    _qrVideo = null;
    closeModal('modal-qr-scan');
}
</script>
</body>
</html>
<?php

// ─── PAGE : HISTORIQUE CARTOUCHE ─────────────────────────────────────────────
function pageCartridgeHistory(PDO $db, int $id): void {
    if (!$id) { header('Location: index.php?page=cartridges'); exit; }
    $cm = $db->prepare("SELECT cm.*, COALESCE(s.quantity_available,0) as qty FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE cm.id=?");
    $cm->execute([$id]); $cart = $cm->fetch();
    if (!$cart) { header('Location: index.php?page=cartridges'); exit; }

    // Entrées
    $stIn = $db->prepare("SELECT se.entry_date as op_date, se.quantity, se.unit_price, se.invoice_ref, sp.name as supplier_name, u.full_name as user_name, se.notes FROM stock_entries se LEFT JOIN suppliers sp ON se.supplier_id=sp.id LEFT JOIN users u ON se.created_by=u.id WHERE se.cartridge_model_id=? ORDER BY se.entry_date DESC");
    $stIn->execute([$id]); $entries = $stIn->fetchAll();

    // Sorties
    $stOut = $db->prepare("SELECT se.exit_date as op_date, se.quantity, se.person_name, sv.name as service_name, CONCAT(p.brand,' ',p.model) as printer_label, p.location, u.full_name as user_name, se.notes FROM stock_exits se LEFT JOIN services sv ON se.service_id=sv.id LEFT JOIN printers p ON se.printer_id=p.id LEFT JOIN users u ON se.created_by=u.id WHERE se.cartridge_model_id=? ORDER BY se.exit_date DESC");
    $stOut->execute([$id]); $exits = $stOut->fetchAll();

    // Demandes
    $stRes = $db->prepare("SELECT r.requested_date as op_date, r.quantity_requested, r.quantity_fulfilled, r.status, sv.name as service_name, r.notes FROM reservations r LEFT JOIN services sv ON r.service_id=sv.id WHERE r.cartridge_model_id=? ORDER BY r.requested_date DESC");
    $stRes->execute([$id]); $reservations = $stRes->fetchAll();

    // Stats consommation
    $stAvg = $db->prepare("SELECT COALESCE(AVG(monthly),0) FROM (SELECT DATE_FORMAT(exit_date,'%Y-%m') as mo, SUM(quantity) as monthly FROM stock_exits WHERE cartridge_model_id=? GROUP BY mo) t");
    $stAvg->execute([$id]); $avgMonth = round((float)$stAvg->fetchColumn(),1);
    $monthsLeft = $avgMonth > 0 ? round($cart['qty'] / $avgMonth, 1) : null;
?>
<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1.5rem;font-size:.85rem;color:var(--text3)">
  <a href="index.php?page=cartridges" style="color:var(--text3);text-decoration:none" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text3)'">← Cartouches</a>
  <span>/</span>
  <span style="color:var(--text2)"><?=h($cart['brand'].' '.$cart['model'])?></span>
</div>

<!-- Header -->
<div style="display:flex;align-items:center;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <div style="width:56px;height:56px;background:var(--primary-dim);border:2px solid var(--border2);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.8rem">🖋️</div>
  <div>
    <h1 style="font-family:var(--font-display);font-size:1.5rem;font-weight:800"><?=h($cart['brand'].' '.$cart['model'])?></h1>
    <div style="display:flex;gap:.65rem;margin-top:.3rem;flex-wrap:wrap">
      <?=colorDot($cart['color'])?>
      <span class="badge badge-muted"><?=strtoupper(h($cart['type']))?></span>
      <span style="font-size:.82rem;color:var(--text2)">Réf. <?=h($cart['reference'])?></span>
      <span style="font-size:.82rem;color:var(--text2)">Seuil alerte : <?=$cart['alert_threshold']?></span>
    </div>
  </div>
</div>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem">
  <div class="card" style="padding:1rem 1.25rem">
    <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;margin-bottom:.3rem">En stock</div>
    <div style="font-size:2rem;font-weight:800;color:<?=$cart['qty']<=$cart['alert_threshold']?'var(--danger)':'var(--primary)'?>;font-family:var(--font-display)"><?=$cart['qty']?></div>
  </div>
  <div class="card" style="padding:1rem 1.25rem">
    <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;margin-bottom:.3rem">Total sorties</div>
    <div style="font-size:2rem;font-weight:800;color:var(--primary);font-family:var(--font-display)"><?=array_sum(array_column($exits,'quantity'))?></div>
  </div>
  <div class="card" style="padding:1rem 1.25rem">
    <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;margin-bottom:.3rem">Moy. mensuelle</div>
    <div style="font-size:2rem;font-weight:800;color:var(--accent);font-family:var(--font-display)"><?=$avgMonth?></div>
    <div style="font-size:.75rem;color:var(--text3)">cartouches/mois</div>
  </div>
  <div class="card" style="padding:1rem 1.25rem">
    <div style="font-size:.72rem;color:var(--text3);font-weight:600;text-transform:uppercase;margin-bottom:.3rem">Stock prévu</div>
    <div style="font-size:2rem;font-weight:800;color:<?=$monthsLeft!==null&&$monthsLeft<2?'var(--danger)':'var(--success)'?>;font-family:var(--font-display)"><?=$monthsLeft!==null?$monthsLeft.'m':'∞'?></div>
    <div style="font-size:.75rem;color:var(--text3)">mois restants</div>
  </div>
</div>

<!-- Timeline sorties -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">📤 Sorties (<?=count($exits)?>)</span></div>
  <?php if(empty($exits)): ?><div class="empty-mini">Aucune sortie</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Date</th><th>Qté</th><th>Service</th><th>Imprimante</th><th>Emplacement</th><th>Récupérée par</th><th>Délivré par</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($exits as $e): ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:.82rem"><?=date('d/m/Y',strtotime($e['op_date']))?></td>
      <td><span class="stock-pill stock-pill-out">-<?=$e['quantity']?></span></td>
      <td><?=h($e['service_name']??'–')?></td>
      <td><?=h($e['printer_label']??'–')?></td>
      <td class="muted"><?=h($e['location']??'–')?></td>
      <td><?=h($e['person_name']??'–')?></td>
      <td class="muted"><?=h($e['user_name']??'–')?></td>
      <td class="muted"><?=h($e['notes']??'')?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Entrées -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">📦 Entrées (<?=count($entries)?>)</span></div>
  <?php if(empty($entries)): ?><div class="empty-mini">Aucune entrée</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Date</th><th>Qté</th><th>Prix unit.</th><th>Fournisseur</th><th>Réf. facture</th><th>Enregistré par</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($entries as $e): ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:.82rem"><?=date('d/m/Y',strtotime($e['op_date']))?></td>
      <td><span class="stock-pill stock-pill-ok">+<?=$e['quantity']?></span></td>
      <td style="font-family:var(--font-mono)"><?=$e['unit_price']?number_format($e['unit_price'],2,',',' ').' €':'–'?></td>
      <td><?=h($e['supplier_name']??'–')?></td>
      <td><code class="ref"><?=h($e['invoice_ref']??'–')?></code></td>
      <td class="muted"><?=h($e['user_name']??'–')?></td>
      <td class="muted"><?=h($e['notes']??'')?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Demandes -->
<div class="card">
  <div class="card-header"><span class="card-title">📋 Demandes (<?=count($reservations)?>)</span></div>
  <?php if(empty($reservations)): ?><div class="empty-mini">Aucune demande</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Date</th><th>Service</th><th>Demandée</th><th>Traitée</th><th>Statut</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($reservations as $r): ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:.82rem"><?=date('d/m/Y',strtotime($r['op_date']))?></td>
      <td><?=h($r['service_name']??'–')?></td>
      <td><?=h($r['quantity_requested'])?></td>
      <td><?=h($r['quantity_fulfilled'])?></td>
      <td><?=statusBadge($r['status'])?></td>
      <td class="muted"><?=h($r['notes']??'')?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
<?php }

// ─── PAGE : EXPORT SORTIES (CSV/XLSX simple) ─────────────────────────────────
function pageExportExits(PDO $db): void {
    $filterSvc  = (int)($_GET['fsvc'] ?? 0);
    $filterFrom = $_GET['from'] ?? '';
    $filterTo   = $_GET['to']   ?? '';
    $whereClause = '1=1';
    if ($filterSvc)  $whereClause .= " AND se.service_id = $filterSvc";
    if ($filterFrom) $whereClause .= " AND se.exit_date >= ".($db->quote($filterFrom));
    if ($filterTo)   $whereClause .= " AND se.exit_date <= ".($db->quote($filterTo));

    $exits = $db->query("SELECT se.exit_date, se.quantity, cm.brand, cm.model, cm.color, sv.name as service_name, CONCAT(p.brand,' ',p.model) as printer_label, p.location, se.person_name, u.full_name as user_name, se.notes FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN services sv ON se.service_id=sv.id LEFT JOIN printers p ON se.printer_id=p.id LEFT JOIN users u ON se.created_by=u.id WHERE $whereClause ORDER BY se.exit_date DESC, se.id DESC")->fetchAll();

    $filename = 'sorties_'.($filterSvc?'svc'.$filterSvc.'_':'').date('Y-m-d').'.csv';
    // Flush output buffer and send CSV headers
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 pour Excel
    fwrite($out, "ï»¿");
    fputcsv($out, ['Date','Cartouche','Couleur','Quantité','Service','Imprimante','Emplacement','Récupérée par','Délivré par','Notes'], ';');
    foreach ($exits as $e) {
        fputcsv($out, [
            date('d/m/Y', strtotime($e['exit_date'])),
            $e['brand'].' '.$e['model'],
            $e['color'],
            $e['quantity'],
            $e['service_name'] ?? '–',
            $e['printer_label'] ?? '–',
            $e['location'] ?? '–',
            $e['person_name'] ?? '–',
            $e['user_name'] ?? '–',
            $e['notes'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ─── LOGIN PAGE ──────────────────────────────────────────────
function renderLogin(): void {
    $flashes = getFlashes();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion – PrintManager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#080b14;display:flex;align-items:center;justify-content:center;font-family:'DM Sans',sans-serif;color:#f0f4ff;padding:1rem;position:relative;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% -10%,rgba(67,97,238,.25),transparent),radial-gradient(ellipse 50% 40% at 80% 110%,rgba(123,45,139,.2),transparent);pointer-events:none}
.login-wrap{width:100%;max-width:440px;position:relative;z-index:1}
.login-card{background:rgba(17,24,39,.9);border:1px solid rgba(67,97,238,.2);border-radius:20px;padding:2.5rem;backdrop-filter:blur(10px);box-shadow:0 25px 80px rgba(0,0,0,.6)}
.logo{text-align:center;margin-bottom:2.5rem}
.logo-icon{font-size:3.5rem;display:block;margin-bottom:.75rem;filter:drop-shadow(0 0 20px rgba(67,97,238,.6))}
.logo h1{font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:800;letter-spacing:-1px;background:linear-gradient(135deg,#f0f4ff,#a5b4fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo p{color:#4b5563;font-size:.85rem;margin-top:.3rem}
.form-group{margin-bottom:1.25rem}
label{display:block;font-size:.75rem;font-weight:600;color:#4b5563;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.5rem}
input{width:100%;background:rgba(8,11,20,.8);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:.85rem 1.1rem;color:#f0f4ff;font-size:.95rem;transition:all .2s;font-family:'DM Sans',sans-serif}
input:focus{outline:none;border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.2)}
.btn-submit{width:100%;background:linear-gradient(135deg,#4361ee,#3a86ff);border:none;border-radius:10px;padding:1rem;color:#fff;font-size:1rem;font-weight:700;cursor:pointer;font-family:'Outfit',sans-serif;letter-spacing:.02em;transition:all .25s;box-shadow:0 4px 20px rgba(67,97,238,.4);margin-top:.5rem}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(67,97,238,.5)}
.flash{padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1.25rem;font-size:.88rem;font-weight:500}
.flash-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.footer-note{text-align:center;color:#1f2937;font-size:.75rem;margin-top:1.5rem}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="logo">
      <span class="logo-icon">🖨️</span>
      <h1>PrintManager</h1>
      <p>Gestion des imprimantes & cartouches</p>
    </div>
    <?php foreach($flashes as $f): ?>
    <div class="flash flash-<?=htmlspecialchars($f['type'])?>"><?=htmlspecialchars($f['msg'])?></div>
    <?php endforeach; ?>
    <form method="post" action="index.php?page=login">
      <div class="form-group"><label>Identifiant</label><input type="text" name="username" required autofocus autocomplete="username" placeholder="votre identifiant"></div>
      <div class="form-group"><label>Mot de passe</label><input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></div>
      <button type="submit" class="btn-submit">Se connecter →</button>
    </form>
  </div>
  <div class="footer-note">Première utilisation ? Lancez <strong>install.php</strong></div>
</div>
</body>
</html>
<?php
}