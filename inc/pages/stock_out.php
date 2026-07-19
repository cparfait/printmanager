<?php
// ============================================================
//  PrintManager – Page : sorties de stock
// ============================================================

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
    <thead><tr><th>Date</th><th>Cartouche</th><th>Qté</th><th>Service</th><th>Imprimante</th><th>Récupérée par</th><th>Délivré par</th><th>Notes</th><?php if(isAdmin()): ?><th>Actions</th><?php endif ?></tr></thead>
    <tbody>
    <?php if(empty($exits)): ?><tr><td colspan="<?=isAdmin()?9:8?>" class="empty-cell">Aucune sortie enregistrée</td></tr>
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
      <?php if(isAdmin()): ?>
      <td class="actions">
        <form method="post" style="display:inline" onsubmit="return confirm('Annuler cette sortie ?\nLe stock sera réintégré et la demande liée recalculée.')"><?=csrfField()?>
          <input type="hidden" name="_entity" value="stock_out"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$e['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Annuler cette sortie (réintègre le stock)">↩️</button>
        </form>
      </td>
      <?php endif ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgOut)?>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📤 Enregistrer une sortie</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post" id="form-stock-out"><?=csrfField()?><input type="hidden" name="_entity" value="stock_out"><input type="hidden" name="_action" value="add">

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

