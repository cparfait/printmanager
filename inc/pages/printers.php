<?php
// ============================================================
//  PrintManager – Pages : parc d'imprimantes + fiche
// ============================================================

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
    // Recherche côté serveur (couvre toutes les pages, pas seulement celle affichée)
    $q = trim($_GET['q'] ?? '');
    $where = ''; $params = [];
    if ($q !== '') {
        $where  = "WHERE (p.brand LIKE ? OR p.model LIKE ? OR p.serial_number LIKE ? OR p.ip_address LIKE ? OR p.location LIKE ? OR s.name LIKE ?)";
        $params = array_fill(0, 6, '%'.$q.'%');
    }
    $stP = $db->prepare("SELECT p.*, s.name as service_name, pm.brand as model_brand, pm.model as model_name, GROUP_CONCAT(DISTINCT CONCAT(cm.id,'|',cm.brand,'|',cm.model,'|',cm.color) ORDER BY cm.brand,cm.model SEPARATOR ';;') as cartridges_raw FROM printers p LEFT JOIN services s ON p.service_id=s.id LEFT JOIN printer_models pm ON p.printer_model_id=pm.id LEFT JOIN printer_cartridges pc ON pc.printer_id=p.id LEFT JOIN cartridge_models cm ON pc.cartridge_model_id=cm.id $where GROUP BY p.id ORDER BY $orderSql");
    $stP->execute($params);
    $printersAll = $stP->fetchAll();
    $pgPrinters = paginate($printersAll, 20);
    $printers   = $pgPrinters['items'];
    // Cartouches compatibles par imprimante : une requête pour la page au lieu d'une par ligne
    $cidsByPrinter = [];
    if ($printers) {
        $pids = array_map('intval', array_column($printers, 'id'));
        $ph = implode(',', array_fill(0, count($pids), '?'));
        try {
            $stCids = $db->prepare("SELECT printer_id, cartridge_model_id FROM printer_cartridges WHERE printer_id IN ($ph)");
            $stCids->execute($pids);
            foreach ($stCids as $row) $cidsByPrinter[(int)$row['printer_id']][] = (int)$row['cartridge_model_id'];
        } catch(Exception $e) {}
    }
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
    <?php elseif(isAdmin()): ?>
    <button class="btn-primary" onclick="openModal('modal-model-add')">+ Nouveau modèle</button>
    <?php endif ?>
  </div>
</div>

<!-- Modales : toujours présentes (utilisées par les deux onglets) -->
<!-- Modal Nouveau modèle -->
<div class="modal-overlay" id="modal-model-add">
  <div class="modal modal-lg"><div class="modal-header"><h3>📋 Nouveau modèle d'imprimante</h3><button class="modal-close" onclick="closeModal('modal-model-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="add">
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
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="pme-id"><input type="hidden" name="cartridge_ids_sent" value="1">
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
<form method="get" class="search-bar-wrap">
  <input type="hidden" name="page" value="printers"><input type="hidden" name="tab" value="parc">
  <input type="hidden" name="sort" value="<?=h($sortBy)?>"><input type="hidden" name="dir" value="<?=h($sortDir)?>">
  <div class="search-bar">
    <span class="search-bar-icon">🔍</span>
    <input type="text" id="printer-search" name="q" value="<?=h($q)?>" placeholder="Rechercher par marque, modèle, service, emplacement, IP, N° série… (Entrée : recherche globale)" oninput="tableSearch(this,'printer-tbody','printer-count')" autocomplete="off">
    <?php if($q !== ''): ?>
    <a class="search-bar-clear" style="display:block;text-decoration:none" href="?<?=h(http_build_query(array_diff_key($_GET,['q'=>1,'p'=>1])))?>" title="Effacer la recherche">✕</a>
    <?php else: ?>
    <button type="button" class="search-bar-clear" id="printer-clear" onclick="clearSearch('printer-search','printer-tbody','printer-count','printer-clear')">✕</button>
    <?php endif ?>
  </div>
  <div class="search-count" id="printer-count"><?=$q !== '' ? $pgPrinters['total'].' résultat(s) pour « '.h($q).' »' : ''?></div>
</form>
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
          data-cids='<?=htmlspecialchars(json_encode($cidsByPrinter[(int)$p["id"]] ?? []),ENT_QUOTES)?>'>✏️</button>
        <?php if(isAdmin()): ?><button class="btn-icon btn-del" onclick='confirmDel(<?=$p['id']?>,"printer","<?=h(addslashes($p['brand'].' '.$p['model']))?>")'  >🗑️</button><?php endif ?>
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
        <?php if(isAdmin()): ?>
        <button class="btn-icon btn-edit" onclick="openPrinterModelEdit(this)"
          data-pm='<?=htmlspecialchars(json_encode(['id'=>(int)$pm['id'],'brand'=>(string)$pm['brand'],'model'=>(string)$pm['model'],'notes'=>(string)($pm['notes']??'')],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'
          data-cids='<?=htmlspecialchars(json_encode(array_column($pmCartList,'id')),ENT_QUOTES)?>'>✏️</button>
        <form method="post" style="display:inline"><?=csrfField()?><input type="hidden" name="_entity" value="printer_model"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" value="<?=$pm['id']?>"><button type="submit" class="btn-icon btn-del" onclick="return confirm('Supprimer ce modèle ?')">🗑️</button></form>
        <?php else: ?>
        <span style="color:var(--text3);font-size:.78rem">–</span>
        <?php endif ?>
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

function renderInkRow(pid, supplies) {
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

  cell.innerHTML = `<div style="display:flex;flex-direction:column;gap:4px">${html}</div>`;

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

  // Sans extension SNMP, pas de fausses données : on l'indique clairement
  if (!SNMP_OK) {
    cell.innerHTML = `<span style="font-size:.72rem;color:var(--warning)">⚠️ SNMP indisponible</span>`;
    if (btn) { btn.disabled = false; btn.textContent = origTxt; }
    return;
  }
  let data;
  try {
    const r = await fetch(`index.php?ajax_snmp=1&printer_id=${pid}&community=public`);
    data = await r.json();
  } catch(e) {
    cell.innerHTML = `<span style="font-size:.72rem;color:var(--danger)">❌ erreur réseau</span>`;
    if (btn) { btn.disabled = false; btn.textContent = origTxt; }
    return;
  }

  if (!data.reachable || data.error === 'unreachable') {
    cell.innerHTML = `<span style="font-size:.72rem;color:var(--danger)" title="${printerIPs[pid]}">🔴 inaccessible</span>`;
    if (btn) { btn.disabled = false; btn.title = 'Réessayer'; }
    return;
  }

  renderInkRow(pid, data.supplies);
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

// Auto-scan si SNMP dispo et IP présente
<?php if($hasIP && function_exists('snmpget')): ?>
window.addEventListener('DOMContentLoaded', () => setTimeout(scanInk, 400));
<?php elseif($hasIP && !function_exists('snmpget')): ?>
window.addEventListener('DOMContentLoaded', () => setTimeout(scanInk, 400)); // affichera « SNMP indisponible »
<?php endif ?>

function showSnmpHelp() { openModal('modal-snmp-help'); }

async function scanInk() {
  const btn = document.getElementById('btn-scan');
  const status = document.getElementById('ink-status');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Scan...'; }
  status.textContent = 'Interrogation en cours...';

  // Sans extension SNMP, pas de fausses données : on l'indique clairement
  if (!SNMP_AVAIL) {
    const container = document.getElementById('ink-supplies');
    if (container) container.innerHTML = `
      <div style="text-align:center;padding:2rem;color:var(--text3);font-size:.88rem">
        ⚠️ Extension PHP SNMP non disponible — niveaux d'encre indisponibles.<br>
        <a href="#" onclick="showSnmpHelp();return false" style="color:var(--primary)">Comment l'activer ?</a>
      </div>`;
    status.textContent = 'SNMP indisponible';
    if (btn) { btn.disabled = false; btn.textContent = '🔍 Scanner'; }
    return;
  }
  let data;
  try {
    const community = 'public';
    const r = await fetch(`index.php?ajax_snmp=1&printer_id=${PRINTER_ID}&community=${community}`);
    data = await r.json();
  } catch(e) {
    status.textContent = '❌ Erreur réseau';
    if (btn) { btn.disabled = false; btn.textContent = '🔍 Scanner'; }
    return;
  }

  renderInk(data);
  if (btn) { btn.disabled = false; btn.textContent = '🔄 Actualiser'; }
  status.textContent = '✅ ' + now();
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
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="printer"><input type="hidden" name="_action" value="<?=$act?>">
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

