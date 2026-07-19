<?php
// ============================================================
//  PrintManager – Pages : commandes fournisseurs
// ============================================================

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
    // Nb de cartouches avec demandes en attente, par commande (une requête pour toute la liste)
    $demandsByOrder = [];
    try {
        foreach ($db->query(
            "SELECT pol.order_id, COUNT(DISTINCT r.cartridge_model_id) as cnt
             FROM purchase_order_lines pol
             JOIN reservations r ON r.cartridge_model_id = pol.cartridge_model_id AND r.status IN ('pending','partial')
             GROUP BY pol.order_id"
        ) as $row) $demandsByOrder[(int)$row['order_id']] = (int)$row['cnt'];
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
    <td><?php $oDemands = $demandsByOrder[(int)$o['id']] ?? 0;
    ?><?=$oDemands>0?"<span title='$oDemands cartouche(s) avec demandes' style='background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);border-radius:6px;padding:.15rem .5rem;font-size:.72rem;font-weight:700;color:#f59e0b'>📌 $oDemands</span>":'<span style="color:var(--text3);font-size:.78rem">–</span>'?></td>
    <td><?=orderStatusBadge($o['status'])?></td>
    <td class="actions">
      <a href="index.php?page=order_view&id=<?=$o['id']?>" class="btn-icon" title="Voir / Réceptionner">📬</a>
      <?php if(in_array($o['status'],['pending','partial'])): ?>
      <button class="btn-icon btn-edit" title="Modifier" onclick="openEditOrder(<?=htmlspecialchars(json_encode($o, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),ENT_QUOTES)?>)">✏️</button>
      <form method="post" style="display:inline"><?=csrfField()?><input type="hidden" name="_entity" value="order"><input type="hidden" name="_action" value="cancel"><input type="hidden" name="_id" value="<?=$o['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Annuler cette commande ?')" title="Annuler">✕</button></form>
      <?php endif ?>
      <?php if(in_array($o['status'],['received','cancelled'])): ?>
      <?php if(isAdmin()): ?><form method="post" style="display:inline"><?=csrfField()?><input type="hidden" name="_entity" value="order"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$o['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Supprimer cette commande ?')" title="Supprimer">🗑️</button></form><?php endif ?>
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
    <form method="post"><?=csrfField()?>
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
    <form method="post" id="form-edit-order"><?=csrfField()?>
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
        $st2->execute([$id]); $lines=$st2->fetchAll();
    } catch(Exception $e){}

    // Demandes en attente par cartouche de la commande (une seule requête pour la page)
    $lineDemands = [];
    if ($lines) {
        $cidList = array_values(array_unique(array_map(fn($l)=>(int)$l['cartridge_model_id'], $lines)));
        $ph = implode(',', array_fill(0, count($cidList), '?'));
        try {
            $ldq = $db->prepare(
                "SELECT r.cartridge_model_id,
                 COALESCE(SUM(r.quantity_requested-r.quantity_fulfilled),0) as need,
                 GROUP_CONCAT(DISTINCT COALESCE(sv.name,'?') SEPARATOR ', ') as svcs
                 FROM reservations r LEFT JOIN services sv ON r.service_id=sv.id
                 WHERE r.cartridge_model_id IN ($ph) AND r.status IN ('pending','partial')
                 GROUP BY r.cartridge_model_id"
            );
            $ldq->execute($cidList);
            foreach ($ldq as $row) $lineDemands[(int)$row['cartridge_model_id']] = ['need'=>(int)$row['need'],'svcs'=>$row['svcs']??''];
        } catch(Exception $e){}
    }

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
        $lineDem=(int)($lineDemands[(int)$l['cartridge_model_id']]['need'] ?? 0);
        $lineSvc=$lineDemands[(int)$l['cartridge_model_id']]['svcs'] ?? '';
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
    <form method="post"><?=csrfField()?>
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
            $recvDem=(int)($lineDemands[(int)$l['cartridge_model_id']]['need'] ?? 0);
            $recvSvc=$lineDemands[(int)$l['cartridge_model_id']]['svcs'] ?? '';
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

