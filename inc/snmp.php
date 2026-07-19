<?php
// ============================================================
//  PrintManager – Monitoring SNMP (OIDs + interrogation des imprimantes)
// ============================================================

if (!defined('OID_SUPPLY_DESC')) {
    define('OID_SUPPLY_DESC',    '1.3.6.1.2.1.43.11.1.1.6.1');
    define('OID_SUPPLY_MAX',     '1.3.6.1.2.1.43.11.1.1.8.1');
    define('OID_SUPPLY_CURRENT', '1.3.6.1.2.1.43.11.1.1.9.1');
    define('OID_PRINTER_STATUS', '1.3.6.1.2.1.25.3.5.1.1.1');
    define('OID_PRINTER_NAME',   '1.3.6.1.2.1.43.5.1.1.16.1');
    define('OID_PAGES_TOTAL',    '1.3.6.1.2.1.43.10.2.1.4.1.1');
}
function snmpQueryPrinter(string $ip, string $community='public', int $timeout=2): array {
    $r = ['ip'=>$ip,'reachable'=>false,'status'=>0,'pages_total'=>null,'supplies'=>[],'error'=>'','queried_at'=>date('H:i:s')];
    if (!function_exists('snmpget')) { $r['error']='extension_missing'; return $r; }
    set_error_handler(function(){});
    try {
        if (@snmpget($ip,$community,'1.3.6.1.2.1.1.1.0',$timeout*1000000,2)===false) { $r['error']='unreachable'; restore_error_handler(); return $r; }
        $r['reachable']=true;
        $st=@snmpget($ip,$community,OID_PRINTER_STATUS.'.0',$timeout*1000000,2); $r['status']=$st?(int)snmpClean($st):3;
        $pg=@snmpget($ip,$community,OID_PAGES_TOTAL,$timeout*1000000,2); $r['pages_total']=$pg?(int)snmpClean($pg):null;
        for($i=1;$i<=10;$i++){
            $d=@snmpget($ip,$community,OID_SUPPLY_DESC.'.'.$i,$timeout*1000000,2);
            $m=@snmpget($ip,$community,OID_SUPPLY_MAX.'.'.$i,$timeout*1000000,2);
            $c=@snmpget($ip,$community,OID_SUPPLY_CURRENT.'.'.$i,$timeout*1000000,2);
            if($d===false&&$m===false&&$c===false) break;
            if($d===false||$m===false||$c===false) continue;
            $dc=snmpClean($d); $mv=(int)snmpClean($m); $cv=(int)snmpClean($c);
            if(empty($dc)||$mv===0) continue;
            $pct=match(true){$cv===-3=>-1,$cv===-2=>100,$mv>0=>(int)round($cv/$mv*100),default=>-1};
            $r['supplies'][]=['description'=>$dc,'percent'=>$pct,'color'=>snmpColor($dc)];
        }
    } catch(Exception $e){ $r['error']=$e->getMessage(); }
    restore_error_handler();
    return $r;
}
function snmpClean(string $v): string {
    $v = trim($v);
    // Gérer le format Hex-STRING : "Hex-STRING: 43 61 72 74..."
    if (preg_match('/^Hex-STRING:\s*([0-9A-Fa-f\s]+)$/', $v, $m)) {
        $hex = preg_replace('/\s+/', '', trim($m[1]));
        // Supprimer les zéros de fin (octet null)
        $hex = rtrim($hex, '0');
        if (strlen($hex) % 2 !== 0) $hex .= '0';
        $decoded = '';
        foreach (str_split($hex, 2) as $byte) {
            $char = chr(hexdec($byte));
            if ($char !== "\x00") $decoded .= $char;
        }
        return trim($decoded);
    }
    // Format standard STRING, INTEGER, etc.
    return preg_match('/^(STRING|INTEGER|OID|Gauge32|Counter32|Timeticks):\s*"?(.+?)"?$/', $v, $m)
        ? trim($m[2])
        : $v;
}
function snmpColor(string $d): array {
    $d=strtolower($d);
    if(str_contains($d,'black')||str_contains($d,'noir')||str_contains($d,'bk')) return ['','#e2e8f0','Noir'];
    if(str_contains($d,'cyan'))    return ['','#67e8f9','Cyan'];
    if(str_contains($d,'magenta')) return ['','#f0abfc','Magenta'];
    if(str_contains($d,'yellow')||str_contains($d,'jaune')) return ['','#fde68a','Jaune'];
    if(str_contains($d,'waste')||str_contains($d,'maintenance')) return ['','#94a3b8','Maintenance'];
    if(str_contains($d,'drum')||str_contains($d,'tambour')) return ['','#a78bfa','Tambour'];
    return ['','#94a3b8','Inconnu'];
}
