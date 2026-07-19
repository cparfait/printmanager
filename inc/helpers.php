<?php
// ============================================================
//  PrintManager – Helpers d'affichage (pagination, journal, badges)
// ============================================================

// ─── PAGINATION HELPER ───────────────────────────────────────────────────
function paginate(array $items, int $perPage=25): array {
    $page   = max(1, (int)($_GET['p'] ?? 1));
    $total  = count($items);
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    return ['items'=>array_slice($items,$offset,$perPage),'page'=>$page,'pages'=>$pages,
            'total'=>$total,'perPage'=>$perPage,'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)];
}
function paginationHtml(array $pg): string {
    if ($pg['pages'] <= 1) return '';
    $q = $_GET; unset($q['p']); unset($q['open']); // exclure 'open' pour ne pas rouvrir le modal
    $base = '?' . http_build_query($q); $sep = $base==='?'?'':'&';
    $out = '<div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-top:1px solid var(--border);font-size:.82rem;color:var(--text3)">';
    $out .= '<span>'.$pg['from'].'–'.$pg['to'].' sur '.$pg['total'].'</span><div style="display:flex;gap:.35rem">';
    if ($pg['page'] > 1)
        $out .= '<a href="'.$base.$sep.'p='.($pg['page']-1).'" class="pg-btn">← Préc.</a>';
    for ($i=1; $i<=$pg['pages']; $i++) {
        if ($pg['pages']>7 && abs($i-$pg['page'])>2 && $i!==1 && $i!==$pg['pages']) {
            if ($i===2||$i===$pg['pages']-1) $out .= '<span style="padding:.3rem .4rem;color:var(--text3)">…</span>';
            continue;
        }
        $a = $i===$pg['page'];
        $out .= '<a href="'.$base.$sep.'p='.$i.'" class="pg-btn'.($a?' pg-btn-active':'').'">'.$i.'</a>';
    }
    if ($pg['page'] < $pg['pages'])
        $out .= '<a href="'.$base.$sep.'p='.($pg['page']+1).'" class="pg-btn">Suiv. →</a>';
    return $out.'</div></div>';
}

function logAct(PDO $db,int $uid,string $action,string $etype,int $eid,string $desc): void {
    try { $db->prepare("INSERT INTO activity_log(user_id,action,entity_type,entity_id,description,ip_address)VALUES(?,?,?,?,?,?)")
              ->execute([$uid,$action,$etype,$eid,$desc,$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
}
function statusBadge(string $s): string {
    $map=['active'=>['Actif','badge-success'],'inactive'=>['Inactif','badge-danger'],'maintenance'=>['Maintenance','badge-warning'],
          'pending'=>['En attente','badge-warning'],'partial'=>['Partiel','badge-info'],'fulfilled'=>['Traité','badge-success'],'cancelled'=>['Annulé','badge-danger']];
    [$label,$cls]=$map[$s]??[$s,'badge-muted'];
    return "<span class='badge $cls'>$label</span>";
}
function colorDot(string $color): string {
    $map=['Noir'=>'#111','Cyan'=>'#06b6d4','Magenta'=>'#d946ef','Jaune'=>'#eab308','Bleu'=>'#3b82f6','Rouge'=>'#ef4444','Vert'=>'#10b981'];
    $c=$map[$color]??'#888';
    return "<span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:$c;border:1px solid rgba(255,255,255,.2);vertical-align:middle;margin-right:5px'></span>$color";
}


function deleteModal(string $entity): void { ?>
<div class="modal-overlay" id="modal-delete">
  <div class="modal modal-sm"><div class="modal-header"><h3>⚠️ Confirmer la suppression</h3><button class="modal-close" onclick="closeModal('modal-delete')">✕</button></div>
  <p id="del-message" style="color:#94a3b8;margin:1rem 0"></p>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="<?=$entity?>"><input type="hidden" name="_action" value="delete"><input type="hidden" name="_id" id="del-id">
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-delete')">Annuler</button><button type="submit" class="btn-danger">Supprimer définitivement</button></div>
  </form></div>
</div>
<?php }

