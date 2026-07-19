<?php
// ============================================================
//  PrintManager – Page : statistiques
// ============================================================

function pageStats(PDO $db, array $d): void {

    // ── Filtre période : 0 = toutes les données ─────────────
    $period = (int)($_GET['period'] ?? 12);
    if (!in_array($period,[0,1,3,6,12,24])) $period = 12;

    // ── Filtres service / imprimante ─────────────────────────
    $filterService = (int)($_GET['filter_service'] ?? 0);
    $filterPrinter = (int)($_GET['filter_printer'] ?? 0);

    // Listes pour les selects
    $servicesList = $db->query("SELECT id, name FROM services ORDER BY name")->fetchAll();
    $printersList = $db->query("SELECT p.id, CONCAT(p.brand,' ',p.model) as label, COALESCE(sv.name,'') as service, p.service_id FROM printers p LEFT JOIN services sv ON p.service_id=sv.id ORDER BY sv.name, p.brand, p.model")->fetchAll();

    // ── Construction des clauses WHERE dynamiques ────────────
    // Période
    if ($period === 0) {
        $dateWhereExit  = '';           // pas de filtre date
        $dateWhereEntry = '';
        $periodLabel    = 'Tout';
    } else {
        $dateWhereExit  = "AND se.exit_date  >= DATE_SUB(NOW(), INTERVAL $period MONTH)";
        $dateWhereEntry = "AND en.entry_date >= DATE_SUB(NOW(), INTERVAL $period MONTH)";
        $periodLabel    = "$period mois";
    }
    // Service
    $svcWhere = $filterService ? "AND se.service_id = $filterService" : '';
    // Imprimante
    $prnWhere = $filterPrinter ? "AND se.printer_id = $filterPrinter" : '';

    // Clause combinée pour toutes les requêtes stock_exits
    $exitWhere = "1=1 $dateWhereExit $svcWhere $prnWhere";

    // Pour le graphique mensuel entrées : pas de filtre service/printer (entrées indépendantes)
    $monthlyExitDateWhere  = $period > 0 ? "exit_date  >= DATE_SUB(NOW(), INTERVAL $period MONTH)" : '1=1';
    $monthlyEntryDateWhere = $period > 0 ? "entry_date >= DATE_SUB(NOW(), INTERVAL $period MONTH)" : '1=1';

    // Helper URL : préserve tous les params actifs
    $urlBase = 'index.php?page=stats';
    $urlPeriod   = function(int $p) use ($filterService,$filterPrinter): string {
        $q = ['page'=>'stats','period'=>$p];
        if ($filterService) $q['filter_service'] = $filterService;
        if ($filterPrinter) $q['filter_printer'] = $filterPrinter;
        return 'index.php?'.http_build_query($q);
    };

    // ── Requêtes ────────────────────────────────────────────
    $monthlyExits = $monthlyEntries = $byService = $byCartridge = [];
    $byPrinter = $stockLevels = $orderStats = $monthByColor = $recentOps = [];
    $stockValue = $totalExits = $totalEntries = $totalCost = 0;
    $avgPerMonth = 0; $statsError = null;

    try {
        $svcWhereRaw = $filterService ? "AND service_id = $filterService" : '';
        $prnWhereRaw = $filterPrinter ? "AND printer_id = $filterPrinter" : '';
        $monthlyExitsWhere = $period > 0
            ? "exit_date >= DATE_SUB(NOW(), INTERVAL $period MONTH) $svcWhereRaw $prnWhereRaw"
            : "1=1 $svcWhereRaw $prnWhereRaw";
        $monthlyExits  = $db->query("SELECT DATE_FORMAT(exit_date,'%b %Y') as m, DATE_FORMAT(exit_date,'%Y-%m') as ym, SUM(quantity) as total FROM stock_exits WHERE $monthlyExitsWhere GROUP BY DATE_FORMAT(exit_date,'%Y-%m'), DATE_FORMAT(exit_date,'%b %Y') ORDER BY ym")->fetchAll();
        $monthlyEntries= $db->query("SELECT DATE_FORMAT(entry_date,'%b %Y') as m, DATE_FORMAT(entry_date,'%Y-%m') as ym, SUM(quantity) as total FROM stock_entries WHERE $monthlyEntryDateWhere GROUP BY DATE_FORMAT(entry_date,'%Y-%m'), DATE_FORMAT(entry_date,'%b %Y') ORDER BY ym")->fetchAll();

        $byService   = $db->query("SELECT COALESCE(sv.name,'Sans service') as name, SUM(se.quantity) as total, COUNT(DISTINCT se.id) as ops FROM stock_exits se LEFT JOIN services sv ON se.service_id=sv.id WHERE $exitWhere GROUP BY sv.id, sv.name ORDER BY total DESC LIMIT 12")->fetchAll();
        $byCartridge = $db->query("SELECT cm.brand, cm.model, cm.color, cm.type, COALESCE(cm.unit_price,0) as unit_price, SUM(se.quantity) as total, COALESCE(MAX(s.quantity_available),0) as stock FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN stock s ON s.cartridge_model_id=cm.id WHERE $exitWhere GROUP BY cm.id, cm.brand, cm.model, cm.color, cm.type, cm.unit_price ORDER BY total DESC LIMIT 12")->fetchAll();
        $byPrinter   = $db->query("SELECT CONCAT(p.brand,' ',p.model) as printer, COALESCE(sv.name,'-') as service, SUM(se.quantity) as total, COUNT(DISTINCT se.cartridge_model_id) as cart_types, p.location FROM stock_exits se JOIN printers p ON se.printer_id=p.id LEFT JOIN services sv ON p.service_id=sv.id WHERE $exitWhere GROUP BY p.id, p.brand, p.model, p.location, sv.name ORDER BY total DESC LIMIT 10")->fetchAll();
        $stockLevels = $db->query("SELECT CONCAT(cm.brand,' ',cm.model) as name, cm.color, COALESCE(s.quantity_available,0) as qty, cm.alert_threshold, COALESCE(cm.unit_price,0)*COALESCE(s.quantity_available,0) as val FROM cartridge_models cm LEFT JOIN stock s ON s.cartridge_model_id=cm.id ORDER BY qty ASC LIMIT 15")->fetchAll();
        $stockValue  = $db->query("SELECT COALESCE(SUM(cm.unit_price * s.quantity_available),0) as val FROM stock s JOIN cartridge_models cm ON s.cartridge_model_id=cm.id")->fetchColumn();

        $totalExits  = $db->query("SELECT COALESCE(SUM(se.quantity),0) FROM stock_exits se WHERE $exitWhere")->fetchColumn();
        $totalEntries= $db->query("SELECT COALESCE(SUM(quantity),0) FROM stock_entries WHERE $monthlyEntryDateWhere")->fetchColumn();
        $totalCost   = $db->query("SELECT COALESCE(SUM(se.quantity*cm.unit_price),0) FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id WHERE $exitWhere")->fetchColumn();

        if ($period === 0) {
            // Calcul du vrai nombre de mois couverts
            $firstExit = $db->query("SELECT MIN(exit_date) FROM stock_exits")->fetchColumn();
            $spanMonths = $firstExit ? max(1, (int)round((time()-strtotime($firstExit))/2592000)) : 1;
            $avgPerMonth = round($totalExits / $spanMonths, 1);
        } else {
            $avgPerMonth = $period > 0 ? round($totalExits / $period, 1) : 0;
        }

        try { $orderStats = $db->query("SELECT status, COUNT(*) as cnt, COALESCE(SUM(pol.quantity_ordered*pol.unit_price),0) as val FROM purchase_orders po LEFT JOIN purchase_order_lines pol ON pol.order_id=po.id GROUP BY po.status")->fetchAll(); } catch(Exception $e){}
        $monthByColor = $db->query("SELECT cm.color, SUM(se.quantity) as total FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id WHERE $exitWhere GROUP BY cm.color ORDER BY total DESC")->fetchAll();
        $recentOps    = $db->query("SELECT 'sortie' as type, se.exit_date as op_date, se.quantity, CONCAT(cm.brand,' ',cm.model) as cart, COALESCE(sv.name,'-') as service, se.person_name FROM stock_exits se JOIN cartridge_models cm ON se.cartridge_model_id=cm.id LEFT JOIN services sv ON se.service_id=sv.id WHERE $exitWhere UNION ALL SELECT 'entree' as type, en.entry_date, en.quantity, CONCAT(cm.brand,' ',cm.model), COALESCE(sp.name,'-'), en.invoice_ref FROM stock_entries en JOIN cartridge_models cm ON en.cartridge_model_id=cm.id LEFT JOIN suppliers sp ON en.supplier_id=sp.id WHERE $monthlyEntryDateWhere ORDER BY op_date DESC LIMIT 15")->fetchAll();
    } catch (Exception $e) {
        $statsError = $e->getMessage();
    }

    $activeFilters = ($filterService || $filterPrinter);
    $filterServiceName = '';
    $filterPrinterName = '';
    if ($filterService) { foreach($servicesList as $s) { if ($s['id']==$filterService) { $filterServiceName=$s['name']; break; } } }
    if ($filterPrinter) { foreach($printersList as $p) { if ($p['id']==$filterPrinter) { $filterPrinterName=$p['label']; break; } } }
?>

<?php if ($statsError): ?>
<div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:var(--radius);padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:flex-start">
  <span style="font-size:1.5rem;flex-shrink:0">⚠️</span>
  <div><strong style="color:#fca5a5">Erreur de chargement des statistiques</strong><br>
  <code style="font-size:.82rem;color:var(--text2)"><?=h($statsError)?></code></div>
</div>
<?php endif ?>

<!-- TOOLBAR : titre + période + filtres -->
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
  <span class="page-title-txt">📊 Statistiques & Rapports</span>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">

    <!-- Période -->
    <span style="font-size:.8rem;color:var(--text3)">Période :</span>
    <?php foreach([0=>'Tout',1=>'1 mois',3=>'3 mois',6=>'6 mois',12=>'12 mois',24=>'24 mois'] as $v=>$l): ?>
    <a href="<?=$urlPeriod($v)?>" style="padding:.4rem .85rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .15s;<?=$period===$v?'background:var(--primary);color:#fff;':'background:var(--card2);color:var(--text2);border:1px solid var(--border)'?>"><?=h($l)?></a>
    <?php endforeach ?>

    <span style="width:1px;height:20px;background:var(--border);margin:0 .25rem"></span>

    <!-- Filtre service -->
    <select id="sel-service" onchange="statsFilter()" style="background:var(--card2);border:1px solid <?=$filterService?'var(--primary)':'var(--border)'?>;border-radius:var(--radius-sm);padding:.38rem .75rem;color:var(--text<?=$filterService?'':'2'?>);font-size:.82rem;cursor:pointer;min-width:130px">
      <option value="0"<?=$filterService===0?' selected':''?>>🏢 Tous les services</option>
      <?php foreach($servicesList as $s): ?>
      <option value="<?=$s['id']?>"<?=$filterService===$s['id']?' selected':''?>><?=h($s['name'])?></option>
      <?php endforeach ?>
    </select>

    <!-- Filtre imprimante (filtré dynamiquement selon le service sélectionné) -->
    <select id="sel-printer" onchange="statsFilter()" style="background:var(--card2);border:1px solid <?=$filterPrinter?'var(--primary)':'var(--border)'?>;border-radius:var(--radius-sm);padding:.38rem .75rem;color:var(--text<?=$filterPrinter?'':'2'?>);font-size:.82rem;cursor:pointer;min-width:150px">
      <option value="0"<?=$filterPrinter===0?' selected':''?>>🖨️ Toutes les imprimantes</option>
      <?php foreach($printersList as $p): ?>
      <option value="<?=$p['id']?>"
        data-svc="<?=(int)($p['service_id'] ?? 0)?>"
        <?=$filterPrinter===$p['id']?' selected':''?>><?=h($p['label'])?><?=$p['service']?' ('.$p['service'].')':''?></option>
      <?php endforeach ?>
    </select>

    <?php if($activeFilters): ?>
    <a href="<?=$urlPeriod($period)?>" style="padding:.38rem .75rem;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;text-decoration:none;background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3);transition:all .15s" title="Réinitialiser les filtres">✕ Reset</a>
    <?php endif ?>
  </div>
</div>

<?php if($activeFilters): ?>
<div style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
  <span style="font-size:.8rem;color:var(--text3)">Filtres actifs :</span>
  <?php if($filterServiceName): ?><span style="background:var(--primary-dim);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.25rem .7rem;font-size:.8rem;color:var(--primary)">🏢 <?=h($filterServiceName)?></span><?php endif ?>
  <?php if($filterPrinterName): ?><span style="background:var(--primary-dim);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:.25rem .7rem;font-size:.8rem;color:var(--primary)">🖨️ <?=h($filterPrinterName)?></span><?php endif ?>
</div>
<?php endif ?>

<!-- KPIs enrichis -->
<div class="kpi-row" style="margin-bottom:1.5rem">
  <div class="kpi-card kpi-blue" style="flex:1">
    <div class="kpi-icon">📤</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalExits,0,',',' ')?></span><span class="kpi-label">Sorties (<?=$periodLabel?>)</span></div>
    <div class="kpi-sub">~<?=$avgPerMonth?>/mois</div>
  </div>
  <?php if($filterService): ?>
  <div class="kpi-card kpi-green" style="flex:1;opacity:.6;cursor:default" title="Les entrées de stock ne sont pas filtrables par service">
    <div class="kpi-icon">📦</div>
    <div class="kpi-info">
      <span class="kpi-val" style="font-size:1.2rem">–</span>
      <span class="kpi-label">Entrées (tous services)</span>
    </div>
    <div class="kpi-sub" style="color:var(--warning)">⚠️ Non filtrable par service</div>
  </div>
  <?php else: ?>
  <div class="kpi-card kpi-green" style="flex:1">
    <div class="kpi-icon">📦</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalEntries,0,',',' ')?></span><span class="kpi-label">Entrées (<?=$periodLabel?>)</span></div>
    <div class="kpi-sub"><?=h($d['stock_total'])?> en stock</div>
  </div>
  <?php endif ?>
  <div class="kpi-card kpi-amber" style="flex:1">
    <div class="kpi-icon">💶</div>
    <div class="kpi-info"><span class="kpi-val"><?=number_format($totalCost,0,',',' ')?>€</span><span class="kpi-label">Coût consommé</span></div>
    <div class="kpi-sub">Valeur stock : <?=number_format($stockValue,0,',',' ')?>€</div>
  </div>
  <div class="kpi-card kpi-violet" style="flex:1">
    <div class="kpi-icon">🏢</div>
    <div class="kpi-info"><span class="kpi-val"><?=count($byService)?></span><span class="kpi-label">Services actifs</span></div>
    <div class="kpi-sub"><?=count($byPrinter)?> imprimantes</div>
  </div>
</div>

<!-- Graphiques ligne 1 : Flux entrees/sorties + par service -->
<div class="stats-grid" style="margin-bottom:1.25rem">
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Flux <?=$filterService ? 'sorties' : 'entrées / sorties'?> (<?=$periodLabel?>)</span>
    </div>
    <canvas id="chartFlux" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🥧 Répartition par service</span></div>
    <canvas id="chartService" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
</div>

<!-- Graphiques ligne 2 : par couleur + stock -->
<div class="stats-grid" style="margin-bottom:1.25rem">
  <div class="card">
    <div class="card-header"><span class="card-title">🖋️ Consommation par couleur</span></div>
    <canvas id="chartColor" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Niveaux de stock (🔴 alerte)</span></div>
    <canvas id="chartStock" style="padding:1rem 1.25rem 1.5rem;max-height:260px"></canvas>
  </div>
</div>

<!-- Tableau par service -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🏢 Détail par service</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byService)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée</div>
  <?php else: $maxS = max(array_column($byService,'total')); ?>
  <table class="data-table">
    <thead><tr><th>Service</th><th>Sorties</th><th>Opérations</th><th style="min-width:200px">Proportion</th></tr></thead>
    <tbody>
    <?php foreach($byService as $s): $pct = round($s['total']/$maxS*100); ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong></td>
      <td><span class="stock-pill stock-pill-out"><?=h($s['total'])?> u.</span></td>
      <td class="muted"><?=h($s['ops'])?> sorties</td>
      <td>
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden">
            <div style="height:100%;background:linear-gradient(90deg,var(--primary),#3a86ff);border-radius:99px;width:<?=$pct?>%;transition:width .8s ease"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text2);min-width:32px"><?=$pct?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Tableau par cartouche -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🖋️ Détail par cartouche</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byCartridge)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Cartouche</th><th>Couleur</th><th>Type</th><th>Consommées</th><th>Coût estimé</th><th>Stock actuel</th><th>Prévision stock</th></tr></thead>
    <tbody>
    <?php foreach($byCartridge as $c):
      $cost = $c['total'] * $c['unit_price'];
      // Consommation prévisionnelle : mois restants au rythme actuel
      $avgMonthly = $period > 0 && $period < 24 ? round($c['total'] / $period, 2) : 0;
      $monthsLeft = ($avgMonthly > 0 && $c['stock'] > 0) ? round($c['stock'] / $avgMonthly, 1) : null;
      $forecastColor = $monthsLeft === null ? 'var(--text3)' : ($monthsLeft < 1 ? 'var(--danger)' : ($monthsLeft < 2 ? '#f59e0b' : 'var(--success)'));
    ?>
    <tr>
      <td><a href="index.php?page=cartridge_history&id=<?=$c['id']??0?>" style="text-decoration:none;color:inherit;font-weight:700"><?=h($c['brand'].' '.$c['model'])?></a></td>
      <td><?=colorDot($c['color'])?></td>
      <td><span class="badge badge-muted"><?=strtoupper(h($c['type']))?></span></td>
      <td><span class="stock-pill stock-pill-out"><?=h($c['total'])?> u.</span></td>
      <td style="font-family:var(--font-mono)"><?=$cost>0?number_format($cost,2,',',' ').' €':'–'?></td>
      <td><span class="stock-pill <?=$c['stock']<=3?'stock-pill-low':'stock-pill-ok'?>"><?=h($c['stock'])?></span></td>
      <td style="font-family:var(--font-mono);color:<?=$forecastColor?>;font-weight:700">
        <?php if($monthsLeft===null): ?>
          <span style="color:var(--text3)">–</span>
        <?php elseif($c['stock']===0): ?>
          <span class="badge badge-danger">Rupture</span>
        <?php else: ?>
          <?=$monthsLeft?> mois
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Tableau par imprimante -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><span class="card-title">🖨️ Consommation par imprimante</span><span class="badge badge-muted"><?=$periodLabel?></span></div>
  <?php if(empty($byPrinter)): ?>
  <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune donnée sur les imprimantes</div>
  <?php else: $maxP = max(array_column($byPrinter,'total')); ?>
  <table class="data-table">
    <thead><tr><th>Imprimante</th><th>Service</th><th>Emplacement</th><th>Types cart.</th><th>Consommées</th><th>Proportion</th></tr></thead>
    <tbody>
    <?php foreach($byPrinter as $p): $pct=round($p['total']/$maxP*100); ?>
    <tr>
      <td><strong><?=h($p['printer'])?></strong></td>
      <td class="muted"><?=h($p['service'])?></td>
      <td class="muted"><?=h($p['location']??'–')?></td>
      <td style="text-align:center"><span class="badge badge-info"><?=h($p['cart_types'])?></span></td>
      <td><span class="stock-pill stock-pill-out"><?=h($p['total'])?> u.</span></td>
      <td>
        <div style="display:flex;align-items:center;gap:.6rem">
          <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden">
            <div style="height:100%;background:linear-gradient(90deg,#7b2d8b,#a855f7);border-radius:99px;width:<?=$pct?>%"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text2);min-width:32px"><?=$pct?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<!-- Ligne 3 : Commandes + Activité récente -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

  <!-- Stats commandes -->
  <div class="card">
    <div class="card-header"><span class="card-title">🛒 État des commandes</span></div>
    <?php if(empty($orderStats)): ?>
    <div style="padding:2rem;text-align:center;color:var(--text3)">Aucune commande</div>
    <?php else: ?>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.85rem">
      <?php
      $statusLabels=['pending'=>['En attente','#f59e0b'],'partial'=>['Partielle','#38bdf8'],'received'=>['Reçue','#10b981'],'cancelled'=>['Annulée','#ef4444']];
      $totalOrders = array_sum(array_column($orderStats,'cnt'));
      foreach($orderStats as $os):
        [$lbl,$col]=$statusLabels[$os['status']]??[$os['status'],'#94a3b8'];
        $pct = $totalOrders>0?round($os['cnt']/$totalOrders*100):0;
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:.35rem;font-size:.85rem">
          <span style="display:flex;align-items:center;gap:.5rem">
            <span style="width:8px;height:8px;border-radius:50%;background:<?=$col?>;display:inline-block"></span>
            <?=h($lbl)?>
          </span>
          <span style="font-family:var(--font-mono);font-size:.8rem;color:var(--text2)"><?=$os['cnt']?> cmd · <?=number_format($os['val'],0,',',' ')?>€</span>
        </div>
        <div style="height:7px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;border-radius:99px;background:<?=$col?>;width:<?=$pct?>%"></div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

  <!-- Valeur stock par cartouche -->
  <div class="card">
    <div class="card-header"><span class="card-title">💶 Valeur du stock actuel</span></div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
      <?php
      $vals = array_column($stockLevels,'val');
      $maxVal = max(1, !empty($vals) ? max($vals) : 0);
      foreach($stockLevels as $sl):
        if($sl['val'] <= 0) continue;
        $pct = min(100, round($sl['val']/$maxVal*100));
        $isLow = $sl['qty'] <= $sl['alert_threshold'];
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;font-size:.82rem">
          <span><?=colorDot($sl['color'])?> <?=h($sl['name'])?><?=$isLow?' <span class="badge badge-warning" style="font-size:.65rem">⚠️</span>':''?></span>
          <span style="font-family:var(--font-mono);color:var(--text2)"><?=$sl['qty']?> u. · <?=number_format($sl['val'],0,',',' ')?>€</span>
        </div>
        <div style="height:6px;background:var(--bg3);border-radius:99px;overflow:hidden">
          <div style="height:100%;border-radius:99px;background:<?=$isLow?'linear-gradient(90deg,#dc2626,#ef4444)':'linear-gradient(90deg,#059669,#10b981)'?>;width:<?=$pct?>%"></div>
        </div>
      </div>
      <?php endforeach ?>
      <div style="border-top:1px solid var(--border);padding-top:.75rem;display:flex;justify-content:space-between;font-size:.88rem">
        <strong>Total stock</strong>
        <span style="font-family:var(--font-mono);font-weight:700;color:var(--success)"><?=number_format($stockValue,2,',',' ')?>€</span>
      </div>
    </div>
  </div>
</div>

<!-- Activité récente -->
<div class="card">
  <div class="card-header"><span class="card-title">🕐 Dernières opérations</span><span class="badge badge-muted">15 dernières</span></div>
  <table class="data-table">
    <thead><tr><th>Type</th><th>Date</th><th>Cartouche</th><th>Qté</th><th>Service / Fournisseur</th><th>Référence</th></tr></thead>
    <tbody>
    <?php foreach($recentOps as $op): ?>
    <tr>
      <td><?=$op['type']==='sortie'?'<span class="badge badge-warning">📤 Sortie</span>':'<span class="badge badge-success">📦 Entrée</span>'?></td>
      <td><?=date('d/m/Y',strtotime($op['op_date']))?></td>
      <td><?=h($op['cart'])?></td>
      <td><span class="stock-pill <?=$op['type']==='sortie'?'stock-pill-out':'stock-pill-ok'?>"><?=$op['type']==='sortie'?'-':'+'?><?=h($op['quantity'])?></span></td>
      <td class="muted"><?=h($op['service'])?></td>
      <td class="muted" style="font-family:var(--font-mono);font-size:.78rem"><?=h($op['person_name']??'')?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<script>
const CC=['#4361ee','#10b981','#f59e0b','#ef4444','#06b6d4','#8b5cf6','#f97316','#14b8a6','#a855f7','#e11d48'];
function mkChart(id,type,labels,datasets,opts={}){
  const ctx=document.getElementById(id); if(!ctx) return;
  new Chart(ctx,{type,data:{labels,datasets},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{labels:{color:'#94a3b8',padding:14,font:{size:12}}}},scales:type==='doughnut'||type==='pie'?{}:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8',maxRotation:40,font:{size:11}}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8',font:{size:11}}}},...opts}});
}

// Flux entrées/sorties
const mLabels=<?=json_encode(array_column($monthlyExits,'m'))?>;
const mExits =<?=json_encode(array_map('intval',array_column($monthlyExits,'total')))?>;
const filterServiceActive = <?=$filterService ? 'true' : 'false'?>;

// Aligner entrées sur les mêmes mois (non affiché si filtre service actif)
const mEntMap={}; <?php foreach($monthlyEntries as $me): ?>mEntMap['<?=h($me['m'])?>']= <?=(int)$me['total']?>; <?php endforeach; ?>
const mEnts=mLabels.map(l=>mEntMap[l]||0);

const fluxDatasets = [
  {label:'Sorties',data:mExits,backgroundColor:'rgba(245,158,11,.7)',borderColor:'#f59e0b',borderWidth:2,borderRadius:4}
];
if (!filterServiceActive) {
  fluxDatasets.push({label:'Entrées',data:mEnts,backgroundColor:'rgba(16,185,129,.6)',borderColor:'#10b981',borderWidth:2,borderRadius:4});
}
mkChart('chartFlux','bar',mLabels,fluxDatasets,{plugins:{legend:{display:!filterServiceActive,labels:{color:'#94a3b8'}}}});

// Par service
const svLabels=<?=json_encode(array_column($byService,'name'))?>;
const svData  =<?=json_encode(array_map('intval',array_column($byService,'total')))?>;
if(svLabels.length) mkChart('chartService','doughnut',svLabels,[{data:svData,backgroundColor:CC,borderWidth:0,hoverOffset:8}]);

// Par couleur
const colLabels=<?=json_encode(array_column($monthByColor,'color'))?>;
const colData  =<?=json_encode(array_map('intval',array_column($monthByColor,'total')))?>;
const colColors={'Noir':'#e2e8f0','Cyan':'#67e8f9','Magenta':'#f0abfc','Jaune':'#fde68a','Tricolore':'#a78bfa','Bleu':'#38bdf8'};
if(colLabels.length) mkChart('chartColor','doughnut',colLabels,[{data:colData,backgroundColor:colLabels.map(c=>colColors[c]||'#94a3b8'),borderWidth:0,hoverOffset:8}]);

// Stock niveaux
const sl=<?=json_encode($stockLevels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
if(sl.length) mkChart('chartStock','bar',sl.map(x=>x.name),[{label:'Stock',data:sl.map(x=>parseInt(x.qty)),backgroundColor:sl.map(x=>parseInt(x.qty)<=parseInt(x.alert_threshold)?'rgba(239,68,68,.8)':'rgba(16,185,129,.7)'),borderRadius:4}],{indexAxis:'y',plugins:{legend:{display:false}}});

// Filtre service / imprimante : met à jour l'URL en préservant tous les params
function statsFilter(){
  const u=new URL(window.location.href);
  const sv=document.getElementById('sel-service').value;
  const pr=document.getElementById('sel-printer').value;
  if(sv==='0') u.searchParams.delete('filter_service'); else u.searchParams.set('filter_service',sv);
  if(pr==='0') u.searchParams.delete('filter_printer'); else u.searchParams.set('filter_printer',pr);
  window.location=u.toString();
}
// Filtrer les imprimantes selon le service sélectionné
document.getElementById('sel-service').addEventListener('change', function() {
  const svcId = this.value;
  const prSel = document.getElementById('sel-printer');
  const curPr = prSel.value;
  Array.from(prSel.options).forEach(function(opt) {
    if (opt.value === '0') { opt.style.display=''; return; }
    if (svcId === '0') { opt.style.display=''; }
    else { opt.style.display = (opt.dataset.svc === svcId) ? '' : 'none'; }
  });
  // Reset printer si le service a changé et que l'imprimante sélectionnée n'appartient plus
  const curOpt = prSel.options[prSel.selectedIndex];
  if (curOpt && curOpt.value !== '0' && svcId !== '0' && curOpt.dataset.svc !== svcId) {
    prSel.value = '0';
  }
});
// Appliquer au chargement si un service est déjà filtré
(function(){
  const svcId = document.getElementById('sel-service').value;
  if (svcId === '0') return;
  const prSel = document.getElementById('sel-printer');
  Array.from(prSel.options).forEach(function(opt) {
    if (opt.value === '0') return;
    opt.style.display = (opt.dataset.svc === svcId) ? '' : 'none';
  });
})();
</script>
<?php }

