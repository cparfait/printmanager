<?php
// ============================================================
//  PrintManager – Export CSV des sorties
// ============================================================

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
    // BOM UTF-8 pour Excel (octets bruts EF BB BF)
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date','Cartouche','Couleur','Quantité','Service','Imprimante','Emplacement','Récupérée par','Délivré par','Notes'], ';');
    foreach ($exits as $e) {
        // csvSafe : neutralise l'injection de formule Excel (=, +, -, @)
        fputcsv($out, array_map('csvSafe', [
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
        ]), ';');
    }
    fclose($out);
    exit;
}

