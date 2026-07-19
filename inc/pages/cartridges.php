<?php
// ============================================================
//  PrintManager – Page : modèles de cartouches
// ============================================================

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
    // Recherche côté serveur (couvre toutes les pages, pas seulement celle affichée)
    $q = trim($_GET['q'] ?? '');
    $where = ''; $params = [];
    if ($q !== '') {
        $where  = "WHERE (cm.brand LIKE ? OR cm.model LIKE ? OR cm.reference LIKE ? OR cm.color LIKE ? OR cm.type LIKE ? OR cm.barcode LIKE ?)";
        $params = array_fill(0, 6, '%'.$q.'%');
    }
    $stC = $db->prepare(
        "SELECT cm.*,
         COALESCE(s.quantity_available,0) as qty_avail,
         (SELECT COALESCE(SUM(r.quantity_requested - r.quantity_fulfilled),0)
          FROM reservations r
          WHERE r.cartridge_model_id = cm.id AND r.status IN ('pending','partial')) as qty_res,
         COUNT(DISTINCT pc.printer_id) as printer_count,
         GROUP_CONCAT(DISTINCT CONCAT(p.brand,' ',p.model) ORDER BY p.brand,p.model SEPARATOR '|') as printer_list,
         GROUP_CONCAT(DISTINCT p.id ORDER BY p.brand,p.model SEPARATOR ',') as printer_ids
         FROM cartridge_models cm
         LEFT JOIN stock s ON s.cartridge_model_id=cm.id
         LEFT JOIN printer_cartridges pc ON pc.cartridge_model_id=cm.id
         LEFT JOIN printers p ON p.id=pc.printer_id
         $where
         GROUP BY cm.id ORDER BY cm.active DESC, $orderSql"
    );
    $stC->execute($params);
    $cartridges = $stC->fetchAll();
    $archivedCount = count(array_filter($cartridges, fn($c) => !($c['active'] ?? 1)));
    $displayed = $showArchived ? $cartridges : array_values(array_filter($cartridges, fn($c) => ($c['active'] ?? 1)));
    $pgCarts  = paginate($displayed, 25);
    $displayed = $pgCarts['items'];
?>
<div class="page-header">
  <span class="page-title-txt">🖋️ Modèles de Cartouches</span>
  <div style="display:flex;gap:.6rem;align-items:center">
    <?php if($archivedCount > 0): ?>
    <a href="?page=cartridges<?=$showArchived?'':'&archived=1'?>"
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
    <form method="post" style="display:inline" onsubmit="return confirm('Archiver les cartouches non rattachées à une imprimante ?')"><?=csrfField()?>
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

<form method="get" class="search-bar-wrap">
  <input type="hidden" name="page" value="cartridges">
  <?php if($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif ?>
  <input type="hidden" name="sort" value="<?=h($sortBy)?>"><input type="hidden" name="dir" value="<?=h($sortDir)?>">
  <div class="search-bar">
    <span class="search-bar-icon">🔍</span>
    <input type="text" id="cart-search" name="q" value="<?=h($q)?>" placeholder="Rechercher par marque, modèle, couleur, type, référence… (Entrée : recherche globale)" oninput="tableSearch(this,'cart-tbody','cart-count')" autocomplete="off">
    <?php if($q !== ''): ?>
    <a class="search-bar-clear" style="display:block;text-decoration:none" href="?<?=h(http_build_query(array_diff_key($_GET,['q'=>1,'p'=>1])))?>" title="Effacer la recherche">✕</a>
    <?php else: ?>
    <button type="button" class="search-bar-clear" id="cart-clear" onclick="clearSearch('cart-search','cart-tbody','cart-count','cart-clear')">✕</button>
    <?php endif ?>
  </div>
  <div class="search-count" id="cart-count"><?=$q !== '' ? $pgCarts['total'].' résultat(s) pour « '.h($q).' »' : ''?></div>
</form>

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
          // Quantité réellement réservée (demandes en attente), calculée dans la requête principale
          $pendDem = (int)($c['qty_res'] ?? 0);
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
        <form method="post" style="display:inline" title="Archiver"><?=csrfField()?>
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="archive">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Archiver" onclick="return confirm('Archiver cette cartouche ?\nElle restera dans l\'historique mais ne sera plus proposée.')">🗄️</button>
        </form>
        <?php if(isAdmin()): ?>
        <form method="post" style="display:inline"><?=csrfField()?>
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement ?\nSi des données sont liées, la cartouche sera archivée automatiquement.')">🗑️</button>
        </form>
        <?php endif ?>
        <?php else: ?>
        <form method="post" style="display:inline"><?=csrfField()?>
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="restore">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon" title="Restaurer" style="color:var(--success)">♻️</button>
        </form>
        <?php if(isAdmin()): ?>
        <form method="post" style="display:inline"><?=csrfField()?>
          <input type="hidden" name="_entity" value="cartridge">
          <input type="hidden" name="_action" value="delete">
          <input type="hidden" name="_id" value="<?=$c['id']?>">
          <button type="submit" class="btn-icon btn-del" title="Supprimer définitivement" onclick="return confirm('Supprimer définitivement cette cartouche archivée ?\nSi des données sont liées, elle sera conservée.')">🗑️</button>
        </form>
        <?php endif ?>
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
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="cartridge"><input type="hidden" name="_action" value="add">
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
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="cartridge"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
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

