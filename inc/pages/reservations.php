<?php
// ============================================================
//  PrintManager – Page : demandes / réservations
// ============================================================

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
          <form method="post" style="display:inline"><?=csrfField()?><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="cancel"><input type="hidden" name="_id" value="<?=$r['id']?>"><button type="submit" class="btn-icon btn-del" title="Annuler" onclick="return confirm('Annuler cette demande ?')">✕</button></form>
        <?php endif;?>
        <?php if($r['status']==='cancelled' || $r['status']==='fulfilled'):?>
          <?php if(isAdmin()): ?><form method="post" style="display:inline"><?=csrfField()?><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$r['id']?>"><button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement ?')">🗑️</button></form><?php endif ?>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouvelle demande</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="reservation"><input type="hidden" name="_action" value="add">
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
  <form method="post"><?=csrfField()?>
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

