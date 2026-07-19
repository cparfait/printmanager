<?php
// ============================================================
//  PrintManager – Page : tableau de bord
// ============================================================

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

