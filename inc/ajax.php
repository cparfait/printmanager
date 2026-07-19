<?php
// ============================================================
//  PrintManager – Points d'entrée AJAX (SNMP, recherches, lignes de commande)
// ============================================================

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
