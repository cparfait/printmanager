<?php
// ============================================================
//  PrintManager – Page : entrées de stock
// ============================================================

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
    <thead><tr><th>Date</th><th>Cartouche</th><th>Quantité</th><th>Fournisseur</th><th>Prix unit.</th><th>Réf. facture</th><th>Enregistré par</th><th>Notes</th><?php if(isAdmin()): ?><th>Actions</th><?php endif ?></tr></thead>
    <tbody>
    <?php if(empty($entries)): ?><tr><td colspan="<?=isAdmin()?9:8?>" class="empty-cell">Aucune entrée de stock</td></tr>
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
      <?php if(isAdmin()): ?>
      <td class="actions">
        <form method="post" style="display:inline" onsubmit="return confirm('Annuler cette entrée ?\nLe stock sera réduit de la quantité correspondante.')"><?=csrfField()?>
          <input type="hidden" name="_entity" value="stock_in"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$e['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Annuler cette entrée (ajuste le stock)">↩️</button>
        </form>
      </td>
      <?php endif ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?=paginationHtml($pgIn)?>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📦 Nouvelle entrée de stock</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="stock_in"><input type="hidden" name="_action" value="add">

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

