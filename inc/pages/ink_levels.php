<?php
// ============================================================
//  PrintManager – Page : niveaux d'encre (SNMP)
// ============================================================

function pageInkLevels(PDO $db): void {
    $printers = $db->query(
        "SELECT p.*, s.name as service_name FROM printers p
         LEFT JOIN services s ON p.service_id=s.id
         WHERE p.ip_address != '' AND p.ip_address IS NOT NULL AND p.status='active'
         ORDER BY s.name, p.brand, p.model"
    )->fetchAll();
    $snmpOk = function_exists('snmpget');
?>
<?php if(!$snmpOk): ?>
<div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:var(--radius);padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:flex-start;font-size:.88rem">
  <span style="font-size:1.5rem;flex-shrink:0">⚠️</span>
  <div>
    <strong style="color:var(--warning)">Extension PHP SNMP non activée — monitoring des niveaux d'encre indisponible</strong><br>
    <span style="color:var(--text2)">Laragon : Menu → PHP → <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">php.ini</code> → chercher <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">;extension=snmp</code> → supprimer le <code style="font-family:var(--font-mono);background:rgba(255,255,255,.07);padding:.1rem .35rem;border-radius:4px">;</code> → Redémarrer Apache.</span>
  </div>
</div>
<?php endif; ?>

<?php if(empty($printers)): ?>
<div style="text-align:center;padding:4rem;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);color:var(--text3)">
  <div style="font-size:3rem;margin-bottom:.75rem">🖨️</div>
  <p>Aucune imprimante active avec adresse IP configurée.<br>
  <a href="index.php?page=printers" style="color:var(--primary)">Gérer le parc →</a></p>
</div>
<?php else: ?>

<!-- TOOLBAR -->
<div style="display:flex;align-items:center;gap:1rem;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <span style="font-size:.75rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">Communauté SNMP</span>
  <input type="text" id="snmp-community" value="public" style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.45rem .85rem;color:var(--text);font-family:var(--font-mono);font-size:.85rem;width:130px">
  <button class="btn-primary" id="btn-scan-all" onclick="inkScanAll()" style="padding:.5rem 1.1rem;font-size:.85rem">↺ Scanner toutes</button>
  <button class="btn-secondary" onclick="inkResetAll()" style="padding:.5rem 1rem;font-size:.85rem">✕ Réinitialiser</button>
  <div style="flex:1"></div>
  <span id="ink-scan-status" style="font-size:.78rem;color:var(--text3);font-family:var(--font-mono)"></span>
</div>

<!-- LÉGENDE -->
<div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:center;margin-bottom:1.25rem;font-size:.78rem;color:var(--text2)">
  <span style="color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;font-size:.72rem">Niveaux :</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#059669,#10b981);display:inline-block"></span>OK (&gt; 25%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#d97706,#f59e0b);display:inline-block"></span>Faible (10–25%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:linear-gradient(90deg,#dc2626,#ef4444);display:inline-block"></span>Critique (&lt; 10%)</span>
  <span style="display:flex;align-items:center;gap:.4rem"><span style="width:22px;height:6px;border-radius:3px;background:var(--text3);display:inline-block"></span>Inconnu</span>
</div>

<!-- TABLE -->
<div class="card">
<table class="data-table">
  <thead>
    <tr>
      <th style="width:14px"></th>
      <th>Imprimante</th>
      <th>Service</th>
      <th>Emplacement</th>
      <th>Adresse IP</th>
      <th>Pages imprimées</th>
      <th>Niveaux d'encre</th>
      <th style="text-align:center;width:50px">↺</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($printers as $p): ?>
  <tr>
    <td><span id="inkdot-<?=$p['id']?>" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3)"></span></td>
    <td>
      <strong><?=h($p['brand'].' '.$p['model'])?></strong>
      <a href="index.php?page=printer_view&id=<?=$p['id']?>" style="margin-left:.4rem;font-size:.75rem;color:var(--text3);text-decoration:none;transition:color .15s" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text3)'" title="Voir la fiche">↗</a>
    </td>
    <td class="muted"><?=h($p['service_name']??'–')?></td>
    <td class="muted"><?=h($p['location']??'–')?></td>
    <td><code class="ref"><?=h($p['ip_address'])?></code></td>
    <td id="inkpages-<?=$p['id']?>" style="color:var(--text3);font-family:var(--font-mono);font-size:.78rem">–</td>
    <td id="inkcell-<?=$p['id']?>" style="min-width:200px">
      <span style="font-size:.78rem;color:var(--text3);display:flex;align-items:center;gap:.4rem">💤 non scanné</span>
    </td>
    <td style="text-align:center">
      <button id="inkbtn-<?=$p['id']?>" onclick="inkScanOne(<?=$p['id']?>)" title="Scanner" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:1rem;padding:.25rem .4rem;border-radius:4px;transition:all .15s" onmouseover="this.style.background='var(--primary-dim)';this.style.color='var(--primary)'" onmouseout="this.style.background='none';this.style.color='var(--text3)'">↺</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>

<style>
.ink-list{display:flex;flex-direction:column;gap:5px}
.ink-row{display:flex;align-items:center;gap:7px}
.ink-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.ink-track{flex:1;height:6px;background:var(--bg3);border-radius:99px;overflow:hidden;min-width:80px}
.ink-fill{height:100%;border-radius:99px;transition:width .9s cubic-bezier(.4,0,.2,1)}
.ink-pct{font-family:var(--font-mono);font-size:.72rem;font-weight:600;min-width:32px;text-align:right}
.spin-xs{width:12px;height:12px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<script>
const INK_DEMO = <?=$snmpOk?'false':'true'?>;
const INK_IDS  = <?=json_encode(array_column($printers,'id'))?>;
let   inkBusy  = false;

async function inkScanOne(pid) {
  const btn  = document.getElementById('inkbtn-'+pid);
  const dot  = document.getElementById('inkdot-'+pid);
  const cell = document.getElementById('inkcell-'+pid);
  const pages= document.getElementById('inkpages-'+pid);

  if(btn){btn.disabled=true;btn.textContent='…';}
  dot.style.cssText='display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3);animation:pulse 1s infinite';
  cell.innerHTML='<span style="font-size:.78rem;color:var(--text2);display:flex;align-items:center;gap:.4rem"><span class="spin-xs"></span>scan…</span>';

  // Sans extension SNMP, pas de fausses données : on l'indique clairement
  if(INK_DEMO){
    dot.style.cssText='display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3)';
    cell.innerHTML='<span style="font-size:.78rem;color:var(--warning)">⚠️ SNMP indisponible</span>';
    pages.textContent='–';
    if(btn){btn.disabled=false;btn.textContent='↺';}
    return;
  }
  let data;
  {
    const com=document.getElementById('snmp-community').value||'public';
    try{
      const r=await fetch(`index.php?ajax_snmp=1&printer_id=${pid}&community=${encodeURIComponent(com)}`);
      data=await r.json();
    }catch(e){
      cell.innerHTML='<span style="font-size:.78rem;color:var(--danger)">❌ erreur réseau</span>';
      dot.style.background='var(--danger)';dot.style.animation='';
      if(btn){btn.disabled=false;btn.textContent='↺';}
      return;
    }
  }

  // Dot statut
  if(!data.reachable||data.error==='unreachable'){
    dot.style.background='var(--danger)';dot.style.animation='';
    cell.innerHTML='<span style="font-size:.78rem;color:var(--danger)">🔴 inaccessible</span>';
    pages.textContent='–';
    if(btn){btn.disabled=false;btn.textContent='↺';}
    return;
  }
  const dotColors={3:'#10b981',4:'#94a3b8',5:'#f59e0b',6:'#ef4444'};
  dot.style.background=dotColors[data.status]||'#94a3b8';
  dot.style.animation='';
  dot.style.boxShadow=data.status===3?'0 0 6px rgba(16,185,129,.5)':'';

  if(data.pages_total) pages.textContent=data.pages_total.toLocaleString('fr-FR');
  else pages.textContent='–';

  if(!data.supplies||!data.supplies.length){
    cell.innerHTML='<span style="font-size:.78rem;color:var(--text3)">Aucune donnée SNMP</span>';
    if(btn){btn.disabled=false;btn.textContent='↺';}
    return;
  }

  const bars=data.supplies.map(s=>{
    const pct=s.percent, fg=s.color[1]||'#94a3b8';
    let grad,col,lbl;
    if(pct<0)      {grad='var(--text3)';                           col='var(--text3)';lbl='?';}
    else if(pct<10){grad='linear-gradient(90deg,#dc2626,#ef4444)'; col='#ef4444';    lbl=pct+'%';}
    else if(pct<25){grad='linear-gradient(90deg,#d97706,#f59e0b)'; col='#f59e0b';    lbl=pct+'%';}
    else           {grad='linear-gradient(90deg,#059669,#10b981)'; col='#10b981';    lbl=pct+'%';}
    const w=pct<0?2:Math.max(2,pct);
    return `<div class="ink-row"><div class="ink-dot" style="background:${fg}"></div><div class="ink-track"><div class="ink-fill" data-w="${w}%" style="width:0%;background:${grad}"></div></div><span class="ink-pct" style="color:${col}">${lbl}</span></div>`;
  }).join('');

  cell.innerHTML=`<div class="ink-list">${bars}</div>`;
  requestAnimationFrame(()=>cell.querySelectorAll('[data-w]').forEach(el=>el.style.width=el.dataset.w));
  if(btn){btn.disabled=false;btn.textContent='↺';btn.title='Actualiser';}
}

async function inkScanAll(){
  if(inkBusy)return; inkBusy=true;
  const btn=document.getElementById('btn-scan-all');
  const status=document.getElementById('ink-scan-status');
  btn.disabled=true; let done=0;
  for(let i=0;i<INK_IDS.length;i+=3){
    await Promise.all(INK_IDS.slice(i,i+3).map(id=>inkScanOne(id)));
    done+=Math.min(3,INK_IDS.length-i);
    status.textContent=`${Math.min(done,INK_IDS.length)} / ${INK_IDS.length}`;
  }
  btn.disabled=false; btn.textContent='↺ Tout rescanner';
  status.textContent=`✅ terminé · ${new Date().toLocaleTimeString('fr-FR')}`;
  inkBusy=false;
}

function inkResetAll(){
  INK_IDS.forEach(pid=>{
    document.getElementById('inkdot-'+pid).style.cssText='display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text3)';
    document.getElementById('inkcell-'+pid).innerHTML='<span style="font-size:.78rem;color:var(--text3);display:flex;align-items:center;gap:.4rem">💤 non scanné</span>';
    document.getElementById('inkpages-'+pid).textContent='–';
    const b=document.getElementById('inkbtn-'+pid); if(b){b.disabled=false;b.textContent='↺';}
  });
  document.getElementById('ink-scan-status').textContent='';
  document.getElementById('btn-scan-all').textContent='↺ Scanner toutes';
}

<?php if($snmpOk && !empty($printers)): ?>
window.addEventListener('DOMContentLoaded',()=>setTimeout(inkScanAll,400));
<?php endif; ?>
</script>
<?php
}

