<?php
// ============================================================
//  PrintManager – Page : historique d'une cartouche
// ============================================================


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

