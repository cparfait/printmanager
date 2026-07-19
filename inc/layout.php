<?php
// ============================================================
//  PrintManager – Gabarit HTML (head, sidebar, topbar, JS global)
//  Variables attendues : $page, $pageTitle, $user, $dashData, $content, $autoOpen
// ============================================================
// ─── HTML OUTPUT ─────────────────────────────────────────────
// Vider les buffers parasites sans fermer le buffer principal
while (ob_get_level() > 1) { ob_end_clean(); }
ob_clean();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pageTitle[$page]??ucfirst($page))?> – PrintManager</title>
<!-- Polices : pile système (pas d'appel à Google Fonts — RGPD + fonctionnement intranet) -->
<!-- Chart.js : version locale prioritaire (voir assets/README.md), CDN en secours -->
<script src="assets/chart.umd.min.js"></script>
<script>if (typeof Chart === 'undefined') document.write('<script src="https:\/\/cdn.jsdelivr.net\/npm\/chart.js@4.4.0\/dist\/chart.umd.min.js"><\/script>');</script>
<script>
// Appliquer le thème AVANT le rendu pour éviter le flash
(function(){
  var t = localStorage.getItem('pm_theme');
  if (t === 'light') document.documentElement.setAttribute('data-theme','light');
})();
</script>
<link rel="stylesheet" href="assets/app.css?v=<?=APP_VERSION?>">
</head>
<body>
<div class="app">
<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <span class="logo-icon">🖨️</span>
    <div><div class="logo-text">PrintManager</div><div class="logo-ver">v1.0.0</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">Principal</div>
    <a href="index.php?page=dashboard"    class="nav-item <?=$page==='dashboard'?'active':''?>"><span class="nav-icon">🏠</span><span class="nav-label">Tableau de bord</span></a>
    <a href="index.php?page=printers"     class="nav-item <?=$page==='printers'?'active':''?>"><span class="nav-icon">🖨️</span><span class="nav-label">Imprimantes</span></a>
    <a href="index.php?page=cartridges"   class="nav-item <?=$page==='cartridges'?'active':''?>"><span class="nav-icon">🖋️</span><span class="nav-label">Cartouches</span></a>
    <div class="sidebar-section">Stock</div>
    <a href="index.php?page=stock_in"     class="nav-item <?=$page==='stock_in'?'active':''?>"><span class="nav-icon">📦</span><span class="nav-label">Entrées de stock</span></a>
    <a href="index.php?page=stock_out"    class="nav-item <?=$page==='stock_out'?'active':''?>"><span class="nav-icon">📤</span><span class="nav-label">Sorties de stock</span></a>
    <a href="index.php?page=reservations" class="nav-item <?=$page==='reservations'?'active':''?>">
      <span class="nav-icon">📋</span><span class="nav-label">Demandes</span>
      <?php if(($dashData['pending_demands']??0)>0): ?>
        <span class="badge badge-warning"><?=h($dashData['pending_demands'])?></span>
      <?php endif; ?>
    </a>
    <a href="index.php?page=orders" class="nav-item <?=$page==='orders'||$page==='order_view'?'active':''?>">
      <span class="nav-icon">🛒</span><span class="nav-label">Commandes</span>
      <?php if(($dashData['pending_orders']??0)>0): ?>
        <span class="badge badge-info"><?=h($dashData['pending_orders'])?></span>
      <?php endif; ?>
    </a>
    <?php if(isAdmin()): ?>
    <div class="sidebar-section">Référentiels</div>
    <a href="index.php?page=services"     class="nav-item <?=$page==='services'?'active':''?>"><span class="nav-icon">🏢</span><span class="nav-label">Services</span></a>
    <a href="index.php?page=suppliers"    class="nav-item <?=$page==='suppliers'?'active':''?>"><span class="nav-icon">🏭</span><span class="nav-label">Fournisseurs</span></a>
    <?php endif; ?>
    <div class="sidebar-section">Analyse</div>
    <a href="index.php?page=stats"       class="nav-item <?=$page==='stats'?'active':''?>"><span class="nav-icon">📊</span><span class="nav-label">Statistiques</span></a>
    <a href="index.php?page=ink_levels"  class="nav-item <?=$page==='ink_levels'?'active':''?>"><span class="nav-icon">🖨️</span><span class="nav-label">Niveaux d'encre</span></a>
    <?php if(isAdmin()): ?>
    <div class="sidebar-section">Admin</div>
    <a href="index.php?page=admin"        class="nav-item <?=$page==='admin'?'active':''?>"><span class="nav-icon">⚙️</span><span class="nav-label">Administration</span></a>
    <?php endif; ?>
  </nav>
</aside>

<!-- ═══ MAIN ═══ -->
<main class="main">
  <div class="topbar">
    <button class="btn-hamburger" onclick="openSidebar()" title="Menu">☰</button>
    <span class="topbar-title"><?=h($pageTitle[$page]??ucfirst($page))?></span>
    <div class="topbar-right">
      <button class="btn-theme" id="btn-theme" onclick="toggleTheme()" title="Changer de thème">
        <span id="theme-icon">🌙</span>
        <span id="theme-label">Sombre</span>
      </button>
      <div style="display:flex;align-items:center;gap:.6rem;border-left:1px solid var(--border);padding-left:.75rem;margin-left:.25rem">
        <div class="user-avatar"><?=strtoupper(substr($user['username'],0,1))?></div>
        <div class="user-info-name" style="line-height:1.3">
          <div style="font-size:.85rem;font-weight:700;color:var(--text);white-space:nowrap"><?=h($user['full_name']??$user['username'])?></div>
          <div style="font-size:.7rem;color:var(--text3)"><?=$user['role']==='admin'?'👑 Administrateur':'👤 Utilisateur'?></div>
        </div>
        <a href="index.php?page=logout" class="btn-logout" title="Se déconnecter">⏏️</a>
      </div>
    </div>
  </div>

  <!-- Flash messages -->
  <?php $flashes = getFlashes(); if($flashes): ?>
  <div class="flash-container">
    <?php foreach($flashes as $f): ?>
    <div class="flash flash-<?=h($f['type'])?>"><?=h($f['msg'])?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="content">
    <?=$content?>
  </div>
</main>
</div>

<script>
// ═══ TABLE SEARCH (réutilisable) ═══
function tableSearch(inp, tbodyId, countId) {
  const q     = inp.value.trim().toLowerCase();
  const tbody = document.getElementById(tbodyId);
  const count = document.getElementById(countId);
  const clear = inp.nextElementSibling;
  if (!tbody) return;
  if (clear) clear.style.display = q ? 'block' : 'none';
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const words = q.split(/\s+/).filter(Boolean);
  let visible = 0;
  rows.forEach(function(tr) {
    if (tr.querySelector('td[colspan]')) { return; } // empty-cell row
    const txt = tr.textContent.toLowerCase();
    const match = !words.length || words.every(function(w) { return txt.includes(w); });
    tr.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  if (count) {
    if (!q)           count.textContent = '';
    else if (!visible) count.textContent = 'Aucun résultat.';
    else              count.textContent  = visible + ' résultat(s)';
  }
}
function clearSearch(inpId, tbodyId, countId, clearId) {
  const inp = document.getElementById(inpId);
  if (inp) { inp.value = ''; tableSearch(inp, tbodyId, countId); inp.focus(); }
  const cl = document.getElementById(clearId);
  if (cl) cl.style.display = 'none';
}

// ═══ THEME TOGGLE ═══
(function() {
  const saved = localStorage.getItem('pm_theme') || 'dark';
  applyTheme(saved);
})();

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : '');
  const icon  = document.getElementById('theme-icon');
  const label = document.getElementById('theme-label');
  if (icon)  icon.textContent  = theme === 'light' ? '☀️' : '🌙';
  if (label) label.textContent = theme === 'light' ? 'Clair' : 'Sombre';
  localStorage.setItem('pm_theme', theme);
}

function toggleTheme() {
  const current = localStorage.getItem('pm_theme') || 'dark';
  applyTheme(current === 'dark' ? 'light' : 'dark');
}

// ═══ SIDEBAR RESPONSIVE ═══
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebar-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
// Close sidebar on nav click (mobile)
document.querySelectorAll('.nav-item').forEach(function(a) {
  a.addEventListener('click', function() {
    if (window.innerWidth <= 900) closeSidebar();
  });
});

// ═══ PRINTER MODEL AUTO-FILL ═══
// Les cartouches sont héritées du modèle (non modifiables en ajout)
// PM_CART_LABELS est injecté dans pagePrinters

async function fillFromPrinterModel(act) {
  var sel = document.getElementById(act+'-model-select');
  if (!sel) return;
  var mid = parseInt(sel.value);
  var readonlyDiv = document.getElementById(act+'-cart-readonly');
  var hiddenDiv   = document.getElementById(act+'-cart-hidden');
  var sourceLabel = document.getElementById(act+'-cart-source');

  if (!mid) {
    if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.85rem">Sélectionnez un modèle ci-dessus pour voir les cartouches associées.</span>';
    if (hiddenDiv)   hiddenDiv.innerHTML = '';
    return;
  }

  // Pré-remplir marque / modèle (hidden inputs + display div)
  var opt = sel.options[sel.selectedIndex];
  var brandEl = document.getElementById(act+'-brand');
  var modelEl = document.getElementById(act+'-model');
  var brandDisplay = document.getElementById(act+'-brand-display');
  var modelDisplay = document.getElementById(act+'-model-display');
  var brandVal = opt ? (opt.dataset.brand || '') : '';
  var modelVal = opt ? (opt.dataset.model || '') : '';
  if (brandEl) brandEl.value = brandVal;
  if (modelEl) modelEl.value = modelVal;
  if (brandDisplay) { brandDisplay.textContent = brandVal || '—'; brandDisplay.style.color = brandVal ? 'var(--text)' : 'var(--text3)'; brandDisplay.style.fontStyle = brandVal ? 'normal' : 'italic'; }
  if (modelDisplay) { modelDisplay.textContent = modelVal || '—'; modelDisplay.style.color = modelVal ? 'var(--text)' : 'var(--text3)'; modelDisplay.style.fontStyle = modelVal ? 'normal' : 'italic'; }

  if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--text3);font-size:.82rem">⏳ Chargement…</span>';

  try {
    var resp = await fetch('index.php?ajax_printer_model_cids=1&model_id='+mid);
    var cids = await resp.json();

    // Affichage lecture seule
    if (readonlyDiv) {
      if (!cids.length) {
        readonlyDiv.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.82rem">Aucune cartouche définie sur ce modèle.</span>';
      } else {
        var colorMap = {'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Bleu':'#38bdf8','Rouge':'#ef4444'};
        readonlyDiv.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:.5rem">' +
          cids.map(function(id) {
            var info = PM_CART_LABELS[id] || {label:'#'+id, color:''};
            var dot = colorMap[info.color] || '#94a3b8';
            return '<span style="display:inline-flex;align-items:center;gap:.35rem;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:.25rem .65rem;font-size:.82rem">'
              +'<span style="width:8px;height:8px;border-radius:50%;background:'+dot+';flex-shrink:0"></span>'
              + info.label + '</span>';
          }).join('') + '</div>';
      }
    }

    // Inputs cachés pour la soumission
    if (hiddenDiv) {
      hiddenDiv.innerHTML = cids.map(function(id) {
        return '<input type="hidden" name="cartridge_ids[]" value="'+id+'">';
      }).join('');
    }
  } catch(e) {
    if (readonlyDiv) readonlyDiv.innerHTML = '<span style="color:var(--danger);font-size:.82rem">Erreur de chargement.</span>';
  }
}

// ═══ MODAL SYSTEM ═══
function openModal(id){
  const el=document.getElementById(id);
  if(el){ el.classList.add('open'); document.body.style.overflow='hidden'; }
}
function closeModal(id){
  const el=document.getElementById(id);
  if(el){ el.classList.remove('open'); document.body.style.overflow=''; }
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(ov=>{
  ov.addEventListener('click',e=>{ if(e.target===ov) closeModal(ov.id); });
});
// Close on Escape
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>closeModal(m.id)); });

// ═══ EDIT MODAL GENERIC ═══
function openEditModal(data, entity){
  // Set ID
  const idField=document.getElementById('edit-id');
  if(idField) idField.value=data.id||data.id===0?data.id:'';
  // Populate fields
  Object.keys(data).forEach(k=>{
    const el=document.getElementById('edit-'+k);
    if(!el) return;
    if(el.tagName==='SELECT'){ el.value=data[k]||''; }
    else if(el.tagName==='TEXTAREA'){ el.value=data[k]||''; }
    else { el.value=data[k]||''; }
  });
  openModal('modal-edit');
}

// ═══ DELETE CONFIRMATION ═══
function confirmDel(id, entity, name){
  const msg=document.getElementById('del-message');
  const did=document.getElementById('del-id');
  // Find entity input in delete form
  const form=document.querySelector('#modal-delete form');
  if(form){
    const ent=form.querySelector('input[name="_entity"]');
    if(ent) ent.value=entity;
  }
  if(msg) msg.innerHTML='Voulez-vous vraiment supprimer <strong>"'+name+'"</strong> ?<br><small style="color:#ef4444">Cette action est irréversible.</small>';
  if(did) did.value=id;
  openModal('modal-delete');
}

// ═══ PRINTER EDIT MODAL ═══
// Lit les données depuis data-printer et data-cids du bouton cliqué
function openPrinterEdit(btn) {
  try {
    var p    = JSON.parse(btn.getAttribute('data-printer'));
    var cids = JSON.parse(btn.getAttribute('data-cids') || '[]');
  } catch(e) { console.error('openPrinterEdit parse error', e); return; }

  var idEl = document.getElementById('edit-id');
  if (idEl) idEl.value = p.id;

  ['serial_number','ip_address','location','purchase_date','warranty_end','notes'].forEach(function(k) {
    var el = document.getElementById('edit-' + k);
    if (el) el.value = p[k] || '';
  });
  // Marque et modèle en lecture seule — affichage + hidden input
  var brandDisplay = document.getElementById('edit-brand-display');
  var modelDisplay = document.getElementById('edit-model-display');
  var brandHidden  = document.getElementById('edit-brand');
  var modelHidden  = document.getElementById('edit-model');
  if (brandDisplay) brandDisplay.textContent = p.brand || '–';
  if (modelDisplay) modelDisplay.textContent = p.model || '–';
  if (brandHidden)  brandHidden.value  = p.brand || '';
  if (modelHidden)  modelHidden.value  = p.model || '';
  var ss = document.getElementById('edit-service_id');
  if (ss) ss.value = (p.service_id !== null && p.service_id !== undefined) ? p.service_id : '';
  var st = document.getElementById('edit-status');
  if (st) st.value = p.status || 'active';

  // Afficher les cartouches compatibles (lecture seule)
  var cartList = document.getElementById('edit-cart-list');
  if (cartList) {
    var colorMap = {'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Bleu':'#38bdf8','Rouge':'#ef4444','Vert':'#10b981'};
    if (!cids.length) {
      cartList.innerHTML = '<span style="color:var(--text3);font-style:italic;font-size:.82rem">Aucune cartouche associée</span>';
    } else {
      cartList.innerHTML = cids.map(function(id) {
        var info = (typeof PM_CART_LABELS !== 'undefined' && PM_CART_LABELS[id]) ? PM_CART_LABELS[id] : {label: '#'+id, color: ''};
        var dot  = colorMap[info.color] || '#94a3b8';
        return '<span style="display:inline-flex;align-items:center;gap:.35rem;background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:.3rem .65rem;font-size:.8rem;font-weight:600">'
          + '<span style="width:8px;height:8px;border-radius:50%;background:'+dot+';flex-shrink:0"></span>'
          + info.label
          + '</span>';
      }).join('');
    }
  }

  document.querySelectorAll('#modal-edit .cart-check').forEach(function(cb) {
    cb.checked = cids.indexOf(parseInt(cb.value)) !== -1;
  });
  openModal('modal-edit');
}

// ═══ AUTO HIDE FLASH ═══
setTimeout(()=>{ document.querySelectorAll('.flash').forEach(f=>{ f.style.transition='opacity .5s'; f.style.opacity='0'; setTimeout(()=>f.remove(),500); }); }, 5000);

// ═══ AUTO OPEN MODAL (depuis raccourci) ═══
<?php if($autoOpen): ?>
window.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('<?=h($autoOpen)?>');
  if (el) { el.classList.add('open'); document.body.style.overflow='hidden'; }
});
<?php endif; ?>
</script>

<!-- ═══ MODAL SCANNER QR ═══ -->
<div class="modal-overlay" id="modal-qr-scan">
  <div class="modal modal-sm"><div class="modal-header"><h3>📷 Scanner un QR Code</h3><button class="modal-close" onclick="closeQrScanner()">✕</button></div>
  <div style="padding:1.5rem;text-align:center">
    <div id="qr-scan-reader" style="width:100%;border-radius:8px;overflow:hidden;background:#000;min-height:260px;display:flex;align-items:center;justify-content:center">
      <span style="color:#fff;opacity:.5;font-size:.88rem">Démarrage de la caméra…</span>
    </div>
    <div id="qr-scan-status" style="margin-top:.75rem;font-size:.85rem;color:var(--text3)">Pointez vers un QR Code de cartouche</div>
    <button onclick="closeQrScanner()" class="btn-secondary" style="margin-top:.75rem;font-size:.85rem">Annuler</button>
  </div>
  </div>
</div>

<!-- jsQR : version locale prioritaire (voir assets/README.md), CDN en secours -->
<script src="assets/jsQR.min.js"></script>
<script>if (typeof jsQR === 'undefined') document.write('<script src="https:\/\/cdn.jsdelivr.net\/npm\/jsqr@1.4.0\/dist\/jsQR.min.js"><\/script>');</script>
<script>
var _qrVideo = null, _qrCanvas = null, _qrCtx = null, _qrRunning = false, _qrAnim = null;
var _qrTargetSelect = null, _qrContext = null;

function openQrScanner(selectId, ctx) {
    _qrTargetSelect = selectId;
    _qrContext = ctx;
    openModal('modal-qr-scan');
    document.getElementById('qr-scan-status').textContent = 'Démarrage de la caméra…';

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(function(stream) {
        _qrVideo = document.createElement('video');
        _qrVideo.srcObject = stream;
        _qrVideo.setAttribute('playsinline', true);
        _qrVideo.play();
        _qrCanvas = document.createElement('canvas');
        _qrCtx = _qrCanvas.getContext('2d');
        var reader = document.getElementById('qr-scan-reader');
        reader.innerHTML = '';
        _qrVideo.style.cssText = 'width:100%;display:block';
        reader.appendChild(_qrVideo);
        _qrRunning = true;
        document.getElementById('qr-scan-status').textContent = '🔍 Pointez vers un QR Code de cartouche…';
        requestAnimationFrame(_qrTick);
    })
    .catch(function(err) {
        document.getElementById('qr-scan-status').textContent = '⚠️ Caméra inaccessible. Saisissez la référence manuellement.';
    });
}

function _qrTick() {
    if (!_qrRunning || !_qrVideo || _qrVideo.readyState !== _qrVideo.HAVE_ENOUGH_DATA) {
        if (_qrRunning) _qrAnim = requestAnimationFrame(_qrTick);
        return;
    }
    _qrCanvas.width = _qrVideo.videoWidth;
    _qrCanvas.height = _qrVideo.videoHeight;
    _qrCtx.drawImage(_qrVideo, 0, 0);
    var img = _qrCtx.getImageData(0, 0, _qrCanvas.width, _qrCanvas.height);
    var code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
    if (code) {
        _qrApply(code.data);
    } else {
        _qrAnim = requestAnimationFrame(_qrTick);
    }
}

function _qrApply(data) {
    closeQrScanner();
    // Contexte cartouche : enregistrer le code brut comme code-barres
    if (_qrContext === 'cart-add' || _qrContext === 'cart-edit') {
        var barcodeField = document.getElementById(
            _qrContext === 'cart-add' ? 'cart-add-barcode' : 'edit-barcode'
        );
        if (barcodeField) {
            barcodeField.value = data;
            barcodeField.style.borderColor = 'var(--success)';
            setTimeout(function() { barcodeField.style.borderColor = ''; }, 2000);
        }
        return;
    }
    // Contexte entrée/sortie : retrouver la cartouche par barcode ou URL
    var cidMatch = data.match(/prefill_cid=(\d+)/);
    if (cidMatch) {
        var cid = cidMatch[1];
        if (_qrTargetSelect) {
            var sel = document.getElementById(_qrTargetSelect);
            if (sel) { sel.value = cid; sel.dispatchEvent(new Event('change')); }
        }
    } else {
        fetch('index.php?ajax_find_cartridge=1&q=' + encodeURIComponent(data))
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.id && _qrTargetSelect) {
                var sel = document.getElementById(_qrTargetSelect);
                if (sel) {
                    sel.value = res.id;
                    sel.dispatchEvent(new Event('change'));
                    sel.style.borderColor = 'var(--success)';
                    setTimeout(function() { sel.style.borderColor = ''; }, 2000);
                }
            } else {
                var st = document.getElementById('qr-scan-status');
                if (st) st.textContent = '\u26a0\ufe0f Code non reconnu : ' + data;
            }
        });
    }
}
function closeQrScanner() {
    _qrRunning = false;
    if (_qrAnim) cancelAnimationFrame(_qrAnim);
    if (_qrVideo && _qrVideo.srcObject) {
        _qrVideo.srcObject.getTracks().forEach(function(t) { t.stop(); });
    }
    _qrVideo = null;
    closeModal('modal-qr-scan');
}
</script>
</body>
</html>
