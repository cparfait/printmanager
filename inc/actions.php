<?php
// ============================================================
//  PrintManager – Traitement des formulaires (doPost) et helpers de saisie
// ============================================================

function doPost(PDO $db, string $ent, string $act, array $d, int $id): void {
    global $user;
    // Contrôle d'accès serveur : référentiels, comptes et suppressions réservés aux administrateurs
    $adminOnly = [
        'service'       => ['add','edit','delete'],
        'supplier'      => ['add','edit','delete'],
        'printer_model' => ['add','edit','delete'],
        'printer'       => ['delete'],
        'cartridge'     => ['delete'],
        'order'         => ['delete'],
        'reservation'   => ['delete'],
        'stock_in'      => ['delete'],
        'stock_out'     => ['delete'],
        'user'          => ['add','edit','delete'],
    ];
    if (isset($adminOnly[$ent]) && in_array($act, $adminOnly[$ent], true) && !isAdmin()) {
        flash('error', '⛔ Action réservée aux administrateurs.');
        return;
    }
    switch ($ent) {
        case 'service':
            if ($act==='add')
                $db->prepare("INSERT INTO services(name,direction,contact_name,contact_email,phone,notes)VALUES(?,?,?,?,?,?)")
                   ->execute([S($d,'name'),S($d,'direction'),S($d,'contact_name'),S($d,'contact_email'),S($d,'phone'),S($d,'notes')]);
            elseif ($act==='edit')
                $db->prepare("UPDATE services SET name=?,direction=?,contact_name=?,contact_email=?,phone=?,notes=? WHERE id=?")
                   ->execute([S($d,'name'),S($d,'direction'),S($d,'contact_name'),S($d,'contact_email'),S($d,'phone'),S($d,'notes'),$id]);
            elseif ($act==='delete')
                $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
            flash('success', msg($act,'service'));
            break;
        case 'supplier':
            if ($act==='add')
                $db->prepare("INSERT INTO suppliers(name,contact_name,email,phone,address,website,notes)VALUES(?,?,?,?,?,?,?)")
                   ->execute([S($d,'name'),S($d,'contact_name'),S($d,'email'),S($d,'phone'),S($d,'address'),S($d,'website'),S($d,'notes')]);
            elseif ($act==='edit')
                $db->prepare("UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,address=?,website=?,notes=? WHERE id=?")
                   ->execute([S($d,'name'),S($d,'contact_name'),S($d,'email'),S($d,'phone'),S($d,'address'),S($d,'website'),S($d,'notes'),$id]);
            elseif ($act==='delete')
                $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
            flash('success', msg($act,'fournisseur'));
            break;
        case 'cartridge':
            if ($act==='add') {
                $db->prepare("INSERT INTO cartridge_models(brand,model,reference,color,type,page_yield,unit_price,alert_threshold,notes,barcode)VALUES(?,?,?,?,?,?,?,?,?,?)")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'reference'),S($d,'color','Noir'),S($d,'type','laser'),(int)IV($d,'page_yield'),(float)IV($d,'unit_price'),(int)IV($d,'alert_threshold',3),S($d,'notes'),S($d,'barcode')?:null]);
                $cid=$db->lastInsertId();
                $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available,quantity_reserved)VALUES(?,0,0)")->execute([$cid]);
            } elseif ($act==='edit')
                $db->prepare("UPDATE cartridge_models SET brand=?,model=?,reference=?,color=?,type=?,page_yield=?,unit_price=?,alert_threshold=?,notes=?,barcode=? WHERE id=?")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'reference'),S($d,'color','Noir'),S($d,'type','laser'),(int)IV($d,'page_yield'),(float)IV($d,'unit_price'),(int)IV($d,'alert_threshold',3),S($d,'notes'),S($d,'barcode')?:null,$id]);
            elseif ($act==='archive') {
                $db->prepare("UPDATE cartridge_models SET active=0 WHERE id=?")->execute([$id]);
                flash('success','🗄️ Cartouche archivée. Elle reste visible dans l\'historique.');
                break;
            } elseif ($act==='restore') {
                $db->prepare("UPDATE cartridge_models SET active=1 WHERE id=?")->execute([$id]);
                flash('success','✅ Cartouche restaurée.');
                break;
            } elseif ($act==='archive_orphans') {
                // Archiver toutes les cartouches actives non rattachées à une imprimante
                $orphans = $db->query(
                    "SELECT cm.id FROM cartridge_models cm
                     LEFT JOIN printer_cartridges pc ON pc.cartridge_model_id = cm.id
                     WHERE pc.printer_id IS NULL AND (cm.active = 1 OR cm.active IS NULL)"
                )->fetchAll(PDO::FETCH_COLUMN);
                if (empty($orphans)) {
                    flash('info','ℹ️ Aucune cartouche orpheline à archiver.');
                } else {
                    $ph = implode(',', array_fill(0, count($orphans), '?'));
                    $db->prepare("UPDATE cartridge_models SET active=0 WHERE id IN ($ph)")->execute($orphans);
                    flash('success','🗄️ '.count($orphans).' cartouche(s) non rattachée(s) à une imprimante ont été archivées.');
                }
                break;
            } elseif ($act==='delete') {
                // Vérifier si la cartouche a un historique (entrées, sorties, commandes, demandes)
                $hasHistory = false;
                $checks = [
                    "SELECT COUNT(*) FROM stock_entries WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM stock_exits WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM purchase_order_lines WHERE cartridge_model_id=?",
                    "SELECT COUNT(*) FROM reservations WHERE cartridge_model_id=?",
                ];
                foreach ($checks as $sql) {
                    $st = $db->prepare($sql); $st->execute([$id]);
                    if ((int)$st->fetchColumn() > 0) { $hasHistory = true; break; }
                }
                if ($hasHistory) {
                    // Archiver plutôt que supprimer
                    $db->prepare("UPDATE cartridge_models SET active=0 WHERE id=?")->execute([$id]);
                    flash('warning','⚠️ Cette cartouche a un historique et ne peut pas être supprimée. Elle a été archivée à la place.');
                    break;
                }
                $db->prepare("DELETE FROM cartridge_models WHERE id=?")->execute([$id]);
            }
            flash('success', msg($act,'cartouche'));
            break;
        case 'printer_model':
            if ($act==='add') {
                $db->prepare("INSERT INTO printer_models(brand,model,notes)VALUES(?,?,?)")
                   ->execute([S($d,'brand'),S($d,'model'),S($d,'notes')]);
                $pmid = $db->lastInsertId();
                assocCartridgesModel($db, $pmid, $d['cartridge_ids']??[]);
                flash('success','✅ Modèle d\'imprimante créé.');
            } elseif ($act==='edit') {
                $db->prepare("UPDATE printer_models SET brand=?,model=?,notes=? WHERE id=?")->execute([S($d,'brand'),S($d,'model'),S($d,'notes'),$id]);
                // Les cartridge_ids peuvent être absent si aucune case cochée — sentinel "cartridge_ids_sent" confirme soumission intentionnelle
                $newCids = array_map('intval', $d['cartridge_ids'] ?? []);
                if (!isset($d['cartridge_ids_sent'])) break; // ne devrait pas arriver
                // Màj cartouches du modèle
                $db->prepare("DELETE FROM printer_model_cartridges WHERE printer_model_id=?")->execute([$id]);
                if (!empty($newCids)) {
                    assocCartridgesModel($db, $id, $newCids);
                }
                // Récupérer les imprimantes liées AVANT de faire d'autres requêtes
                $stLinked = $db->prepare("SELECT id FROM printers WHERE printer_model_id=?");
                $stLinked->execute([$id]);
                $linkedIds = $stLinked->fetchAll(PDO::FETCH_COLUMN);
                // Propager aux imprimantes rattachées à ce modèle
                foreach ($linkedIds as $pid2) {
                    $db->prepare("DELETE FROM printer_cartridges WHERE printer_id=?")->execute([$pid2]);
                    if (!empty($newCids)) {
                        assocCartridges($db, $pid2, $newCids);
                    }
                }
                $nb = count($newCids);
                $linkedCount = count($linkedIds);
                flash('success','✅ Modèle mis à jour avec '.$nb.' cartouche(s). '.$linkedCount.' imprimante(s) liée(s) synchronisée(s).');
            } elseif ($act==='delete') {
                // Délier les imprimantes avant suppression
                $db->prepare("UPDATE printers SET printer_model_id=NULL WHERE printer_model_id=?")->execute([$id]);
                $db->prepare("DELETE FROM printer_models WHERE id=?")->execute([$id]);
                flash('success','Modèle supprimé.');
            }
            break;
        case 'printer':
            $svc = (int)IV($d,'service_id') ?: null;
            $pd  = NV($d,'purchase_date'); $wd = NV($d,'warranty_end');
            $pmid2 = (int)IV($d,'printer_model_id') ?: null;
            if ($act==='add') {
                $db->prepare("INSERT INTO printers(service_id,brand,model,serial_number,ip_address,location,status,purchase_date,warranty_end,notes,printer_model_id)VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$svc,S($d,'brand'),S($d,'model'),S($d,'serial_number'),S($d,'ip_address'),S($d,'location'),S($d,'status','active'),$pd,$wd,S($d,'notes'),$pmid2]);
                $pid2=$db->lastInsertId();
                // Cartouches héritées du modèle
                if ($pmid2) {
                    $stCids = $db->prepare("SELECT cartridge_model_id FROM printer_model_cartridges WHERE printer_model_id=?");
                    $stCids->execute([$pmid2]);
                    $cidsFromModel = $stCids->fetchAll(PDO::FETCH_COLUMN);
                    assocCartridges($db, $pid2, $cidsFromModel);
                } else {
                    assocCartridges($db,$pid2,$d['cartridge_ids']??[]);
                }
            } elseif ($act==='edit') {
                $newStatus = S($d,'status','active');
                // Récupérer le printer_model_id actuel pour ne pas l'écraser
                $stPmid = $db->prepare("SELECT printer_model_id FROM printers WHERE id=?");
                $stPmid->execute([$id]); $currentPmid = (int)$stPmid->fetchColumn() ?: null;
                $db->prepare("UPDATE printers SET service_id=?,brand=?,model=?,serial_number=?,ip_address=?,location=?,status=?,purchase_date=?,warranty_end=?,notes=? WHERE id=?")
                   ->execute([$svc,S($d,'brand'),S($d,'model'),S($d,'serial_number'),S($d,'ip_address'),S($d,'location'),$newStatus,$pd,$wd,S($d,'notes'),$id]);
                // Les cartouches sont gérées par le modèle — pas de mise à jour manuelle
                if ($newStatus === 'inactive') {
                    flash('warning','⚠️ Imprimante mise en Inactif.');
                }
            } elseif ($act==='delete')
                $db->prepare("DELETE FROM printers WHERE id=?")->execute([$id]);
            flash('success', msg($act,'imprimante'));
            break;
        case 'stock_in':
            if ($act==='add') {
                $cid=(int)$d['cartridge_model_id']; $qty=(int)$d['quantity'];
                if ($qty<1) { flash('error','Quantité invalide.'); break; }
                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("INSERT INTO stock_entries(cartridge_model_id,supplier_id,quantity,unit_price,entry_date,invoice_ref,created_by,notes)VALUES(?,?,?,?,?,?,?,?)")
                   ->execute([$cid,$sup,$qty,(float)IV($d,'unit_price'),S($d,'entry_date'),S($d,'invoice_ref'),$user['id'],S($d,'notes')]);
                $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available)VALUES(?,?)ON DUPLICATE KEY UPDATE quantity_available=quantity_available+?")
                   ->execute([$cid,$qty,$qty]);
                flash('success',"✅ Entrée de $qty unité(s) enregistrée.");
                logAct($db,$user['id'],'stock_in','cartridge',$cid,"Entrée $qty ".($qty>1?'cartouches':'cartouche'));
            } elseif ($act==='delete') {
                // Annulation d'une entrée erronée : retire la quantité du stock puis supprime la ligne
                $st=$db->prepare("SELECT * FROM stock_entries WHERE id=?"); $st->execute([$id]); $en=$st->fetch();
                if (!$en) { flash('error','Entrée introuvable.'); break; }
                $upd=$db->prepare("UPDATE stock SET quantity_available=quantity_available-? WHERE cartridge_model_id=? AND quantity_available>=?");
                $upd->execute([$en['quantity'],$en['cartridge_model_id'],$en['quantity']]);
                if ($upd->rowCount()===0) {
                    flash('error','⛔ Annulation impossible : le stock restant est inférieur à la quantité de cette entrée (des cartouches sont déjà sorties).');
                    break;
                }
                $db->prepare("DELETE FROM stock_entries WHERE id=?")->execute([$id]);
                logAct($db,$user['id'],'stock_in_cancel','cartridge',(int)$en['cartridge_model_id'],"Annulation entrée #$id ({$en['quantity']} u.)");
                flash('success','↩️ Entrée annulée, stock ajusté.');
            }
            break;
        case 'stock_out':
            if ($act==='add') {
                $cid=(int)$d['cartridge_model_id']; $qty=(int)$d['quantity'];
                if ($qty<1) { flash('error','Quantité invalide.'); break; }

                $st2=$db->prepare("SELECT quantity_available FROM stock WHERE cartridge_model_id=?");
                $st2->execute([$cid]); $avail=(int)$st2->fetchColumn();
                if ($avail<$qty) { flash('error',"Stock insuffisant. Disponible : $avail u."); break; }

                $rid=(int)IV($d,'reservation_id')?:null;
                $svc=(int)IV($d,'service_id')?:null;

                // ── Contrôle seuil de réservation ──────────────────
                // Calculer la quantité totale réservée par d'autres demandes (hors celle liée à cette sortie)
                $reservedByOthers = 0;
                $stRes=$db->prepare(
                    "SELECT COALESCE(SUM(quantity_requested - quantity_fulfilled),0)
                     FROM reservations
                     WHERE cartridge_model_id=? AND status IN ('pending','partial')
                     AND id != COALESCE(?,0)"
                );
                $stRes->execute([$cid, $rid]);
                $reservedByOthers = (int)$stRes->fetchColumn();

                // Si la sortie n'est pas liée à une demande ET qu'il n'y a pas assez de stock libre
                $freeStock = $avail - $reservedByOthers;
                if (!$rid && $freeStock < $qty) {
                    flash('error',
                        "⛔ Sortie bloquée : $reservedByOthers u. sont réservées pour des demandes en attente. "
                        . "Stock libre disponible : $freeStock u. "
                        . "Liez cette sortie à une demande existante, ou réduisez la quantité à $freeStock u."
                    );
                    break;
                }

                $prn=(int)IV($d,'printer_id')?:null;
                $db->prepare("INSERT INTO stock_exits(cartridge_model_id,service_id,printer_id,quantity,exit_date,person_name,reservation_id,notes,created_by)VALUES(?,?,?,?,?,?,?,?,?)")
                   ->execute([$cid,$svc,$prn,$qty,S($d,'exit_date'),S($d,'person_name'),$rid,S($d,'notes'),$user['id']]);
                // UPDATE atomique : décrémente uniquement si le stock est suffisant (protection race condition)
                $stUpd = $db->prepare("UPDATE stock SET quantity_available = quantity_available - ? WHERE cartridge_model_id = ? AND quantity_available >= ?");
                $stUpd->execute([$qty,$cid,$qty]);
                if ($stUpd->rowCount() === 0) {
                    // Le stock a été pris par une autre session entre le check et l'UPDATE — annuler la sortie
                    $db->prepare("DELETE FROM stock_exits WHERE id=?")->execute([$db->lastInsertId()]);
                    flash('error','⛔ Stock insuffisant au moment de la validation (modifié simultanément). Veuillez réessayer.');
                    break;
                }
                if ($rid) {
                    // Incrémenter quantity_fulfilled
                    $db->prepare("UPDATE reservations SET quantity_fulfilled = quantity_fulfilled + ? WHERE id=?")->execute([$qty, $rid]);
                    // Recalculer le statut APRÈS la mise à jour : fulfilled seulement si tout a été livré
                    $rSt = $db->prepare("SELECT quantity_requested, quantity_fulfilled FROM reservations WHERE id=?");
                    $rSt->execute([$rid]); $rRow = $rSt->fetch();
                    if ($rRow) {
                        $newStatus = ($rRow['quantity_fulfilled'] >= $rRow['quantity_requested']) ? 'fulfilled' : 'partial';
                        $db->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$newStatus, $rid]);
                    }
                }
                flash('success',"✅ Sortie de $qty unité(s) enregistrée.");
                logAct($db,$user['id'],'stock_out','cartridge',$cid,"Sortie $qty ".($qty>1?'cartouches':'cartouche')." - ".S($d,'person_name'));
            } elseif ($act==='delete') {
                // Annulation d'une sortie erronée : réintègre le stock et recale la demande liée
                $st=$db->prepare("SELECT * FROM stock_exits WHERE id=?"); $st->execute([$id]); $ex=$st->fetch();
                if (!$ex) { flash('error','Sortie introuvable.'); break; }
                $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available)VALUES(?,?)ON DUPLICATE KEY UPDATE quantity_available=quantity_available+?")
                   ->execute([$ex['cartridge_model_id'],$ex['quantity'],$ex['quantity']]);
                if ($ex['reservation_id']) {
                    $db->prepare("UPDATE reservations SET quantity_fulfilled=GREATEST(0,quantity_fulfilled-?) WHERE id=?")->execute([$ex['quantity'],$ex['reservation_id']]);
                    $rSt=$db->prepare("SELECT quantity_requested, quantity_fulfilled, status FROM reservations WHERE id=?");
                    $rSt->execute([$ex['reservation_id']]); $rRow=$rSt->fetch();
                    if ($rRow && $rRow['status']!=='cancelled') {
                        $ns = $rRow['quantity_fulfilled']>=$rRow['quantity_requested']?'fulfilled':($rRow['quantity_fulfilled']>0?'partial':'pending');
                        $db->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$ns,$ex['reservation_id']]);
                    }
                }
                $db->prepare("DELETE FROM stock_exits WHERE id=?")->execute([$id]);
                logAct($db,$user['id'],'stock_out_cancel','cartridge',(int)$ex['cartridge_model_id'],"Annulation sortie #$id ({$ex['quantity']} u.)");
                flash('success','↩️ Sortie annulée, stock réintégré.');
            }
            break;
        case 'reservation':
            if ($act==='add') {
                $db->prepare("INSERT INTO reservations(cartridge_model_id,service_id,printer_id,quantity_requested,requested_date,notes,created_by)VALUES(?,?,?,?,?,?,?)")
                   ->execute([(int)$d['cartridge_model_id'],(int)IV($d,'service_id')?:null,(int)IV($d,'printer_id')?:null,(int)$d['quantity_requested'],S($d,'requested_date'),S($d,'notes'),$user['id']]);
                flash('success','Demande créée.');
            } elseif ($act==='edit') {
                $newQty = (int)$d['quantity_requested'];
                $db->prepare("UPDATE reservations SET cartridge_model_id=?,service_id=?,printer_id=?,quantity_requested=?,requested_date=?,notes=? WHERE id=?")
                   ->execute([(int)$d['cartridge_model_id'],(int)IV($d,'service_id')?:null,(int)IV($d,'printer_id')?:null,$newQty,S($d,'requested_date'),S($d,'notes'),$id]);
                // Recalculer le statut selon la nouvelle quantité demandée vs quantité déjà traitée
                $rChk = $db->prepare("SELECT quantity_requested, quantity_fulfilled, status FROM reservations WHERE id=?");
                $rChk->execute([$id]); $rRow = $rChk->fetch();
                if ($rRow && in_array($rRow['status'], ['pending','partial','fulfilled'])) {
                    $fulfilled = (int)$rRow['quantity_fulfilled'];
                    $requested = (int)$rRow['quantity_requested'];
                    if ($fulfilled >= $requested && $requested > 0) $newRStatus = 'fulfilled';
                    elseif ($fulfilled > 0) $newRStatus = 'partial';
                    else $newRStatus = 'pending';
                    $db->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$newRStatus,$id]);
                }
                flash('success','Demande modifiée.');
            } elseif ($act==='cancel') {
                $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
                flash('success','Demande annulée.');
            } elseif ($act==='delete') {
                $chk=$db->prepare("SELECT quantity_fulfilled FROM reservations WHERE id=?");
                $chk->execute([$id]);
                if ((int)$chk->fetchColumn() > 0) {
                    flash('error','⛔ Cette demande a déjà donné lieu à des sorties de stock : annulez-la plutôt que de la supprimer (traçabilité).');
                    break;
                }
                $db->prepare("DELETE FROM reservations WHERE id=?")->execute([$id]);
                flash('success','Demande supprimée.');
            }
            break;
        case 'order':
            if ($act==='edit') {
                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,notes=? WHERE id=?")
                   ->execute([$sup,S($d,'order_date'),NV($d,'expected_date'),S($d,'notes'),$id]);
                // màj des lignes existantes
                $lineIds=$d['line_id']??[]; $qtys=$d['line_qty']??[]; $pxs=$d['line_price']??[];
                foreach($lineIds as $k=>$lid){
                    $lid=(int)$lid; $qty=(int)($qtys[$k]??0);
                    if($qty>0)
                        $db->prepare("UPDATE purchase_order_lines SET quantity_ordered=?,unit_price=? WHERE id=?")
                           ->execute([$qty,(float)($pxs[$k]??0),$lid]);
                    else
                        $db->prepare("DELETE FROM purchase_order_lines WHERE id=? AND quantity_received=0")->execute([$lid]);
                }
                // nouvelles lignes
                $cids=$d['cart_id']??[]; $nqtys=$d['cart_qty']??[]; $npxs=$d['cart_price']??[];
                $ins=$db->prepare("INSERT INTO purchase_order_lines(order_id,cartridge_model_id,quantity_ordered,unit_price)VALUES(?,?,?,?)");
                foreach($cids as $k=>$cid){ if((int)$cid>0&&(int)($nqtys[$k]??0)>0) $ins->execute([$id,(int)$cid,(int)$nqtys[$k],(float)($npxs[$k]??0)]); }
                flash('success','✅ Commande modifiée.');
            } elseif ($act==='add') {                $sup=(int)IV($d,'supplier_id')?:null;
                $db->prepare("INSERT INTO purchase_orders(supplier_id,order_date,expected_date,notes,status,created_by)VALUES(?,?,?,?,'pending',?)")
                   ->execute([$sup,S($d,'order_date'),NV($d,'expected_date'),S($d,'notes'),$user['id']]);
                $oid=$db->lastInsertId();
                // lignes de commande
                $cids=$d['cart_id']??[]; $qtys=$d['cart_qty']??[]; $pxs=$d['cart_price']??[];
                $ins=$db->prepare("INSERT INTO purchase_order_lines(order_id,cartridge_model_id,quantity_ordered,unit_price)VALUES(?,?,?,?)");
                foreach($cids as $k=>$cid){ if((int)$cid>0&&(int)($qtys[$k]??0)>0) $ins->execute([$oid,(int)$cid,(int)$qtys[$k],(float)($pxs[$k]??0)]); }
                flash('success','✅ Commande créée.');
                logAct($db,$user['id'],'order_create','order',$oid,'Nouvelle commande');
            } elseif ($act==='cancel') {
                $db->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]);
                flash('success','Commande annulée.');
            } elseif ($act==='delete') {
                $chk=$db->prepare("SELECT status FROM purchase_orders WHERE id=?");
                $chk->execute([$id]); $oStatus=$chk->fetchColumn();
                if (in_array($oStatus, ['partial','received'], true)) {
                    flash('error','⛔ Cette commande a des réceptions enregistrées : elle ne peut plus être supprimée (traçabilité).');
                    break;
                }
                $db->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);
                flash('success','Commande supprimée.');
            }
            break;
        case 'order_receive':
            if ($act==='receive') {
                $oid=(int)($d['order_id']??0);
                if (!$oid) { flash('error','Commande invalide.'); break; }
                // Récupérer le fournisseur de la commande pour l'associer aux entrées
                $orderInfo = $db->prepare("SELECT supplier_id FROM purchase_orders WHERE id=?");
                $orderInfo->execute([$oid]); $orderSupId = (int)$orderInfo->fetchColumn() ?: null;
                $lineIds=$d['line_id']??[]; $recvQtys=$d['recv_qty']??[]; $prices=$d['unit_price']??[];
                $anyReceived=false;
                foreach($lineIds as $k=>$lid){
                    $lid=(int)$lid; $rqty=(int)($recvQtys[$k]??0);
                    if($rqty<=0) continue;
                    // récupérer la ligne
                    $ln=$db->prepare("SELECT * FROM purchase_order_lines WHERE id=? AND order_id=?");
                    $ln->execute([$lid,$oid]); $line=$ln->fetch();
                    if(!$line) continue;
                    // Plafonner à la quantité restant à recevoir (pas de sur-réception)
                    $remaining=(int)$line['quantity_ordered']-(int)$line['quantity_received'];
                    if($remaining<=0) continue;
                    if($rqty>$remaining) $rqty=$remaining;
                    $newRecv=$line['quantity_received']+$rqty;
                    $db->prepare("UPDATE purchase_order_lines SET quantity_received=? WHERE id=?")->execute([$newRecv,$lid]);
                    // entrée stock avec le fournisseur de la commande
                    $up=(float)($prices[$k]??$line['unit_price']??0);
                    $db->prepare("INSERT INTO stock_entries(cartridge_model_id,supplier_id,quantity,unit_price,entry_date,invoice_ref,created_by,notes)VALUES(?,?,?,?,?,?,?,?)")
                       ->execute([$line['cartridge_model_id'],$orderSupId,$rqty,$up,date('Y-m-d'),'CMD-'.$oid,$user['id'],'Réception commande #'.$oid]);
                    $db->prepare("INSERT INTO stock(cartridge_model_id,quantity_available)VALUES(?,?)ON DUPLICATE KEY UPDATE quantity_available=quantity_available+?")
                       ->execute([$line['cartridge_model_id'],$rqty,$rqty]);
                    logAct($db,$user['id'],'order_receive','order',$oid,"Réception $rqty ".($rqty>1?'cartouches':'cartouche')." cart #{$line['cartridge_model_id']}");
                    $anyReceived=true;
                }
                if($anyReceived){
                    // recalc statut commande
                    $chk=$db->prepare("SELECT SUM(quantity_ordered) as tot, SUM(quantity_received) as rec FROM purchase_order_lines WHERE order_id=?");
                    $chk->execute([$oid]); $chkr=$chk->fetch();
                    $newStatus=$chkr['rec']>=$chkr['tot']?'received':($chkr['rec']>0?'partial':'pending');
                    $db->prepare("UPDATE purchase_orders SET status=?, received_date=IF(?='received',NOW(),received_date) WHERE id=?")->execute([$newStatus,$newStatus,$oid]);
                    if ($newStatus === 'received') {
                        flash('success','✅ Réception enregistrée. Commande complète — elle a été archivée.');
                    } else {
                        flash('success','✅ Réception enregistrée et stock mis à jour.');
                    }
                } else { flash('error','Aucune quantité saisie.'); }
            }
            break;
        case 'user':
            requireAdmin();
            if ($act==='add') {
                // Mot de passe utilisé tel quel (pas de sanitize : il n'est jamais affiché
                // et strip_tags/trim modifierait la valeur comparée au login)
                $pass = (string)($d['password'] ?? '');
                if (S($d,'username')==='') { flash('error','Identifiant obligatoire.'); break; }
                if (mb_strlen($pass) < MIN_PASSWORD_LEN) { flash('error','Le mot de passe doit contenir au moins '.MIN_PASSWORD_LEN.' caractères.'); break; }
                $hash=password_hash($pass,PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO users(username,password_hash,full_name,email,role)VALUES(?,?,?,?,?)")
                   ->execute([S($d,'username'),$hash,S($d,'full_name'),S($d,'email'),S($d,'role','user')]);
                flash('success','Utilisateur créé.');
            } elseif ($act==='edit') {
                if ($id===(int)$user['id'] && (S($d,'role','user')!=='admin' || !(int)IV($d,'active',1))) {
                    flash('error','⛔ Impossible de rétrograder ou désactiver son propre compte.'); break;
                }
                if (!empty($d['password'])) {
                    $pass = (string)$d['password'];
                    if (mb_strlen($pass) < MIN_PASSWORD_LEN) { flash('error','Le mot de passe doit contenir au moins '.MIN_PASSWORD_LEN.' caractères.'); break; }
                    $hash=password_hash($pass,PASSWORD_BCRYPT);
                    $db->prepare("UPDATE users SET full_name=?,email=?,role=?,active=?,password_hash=? WHERE id=?")
                       ->execute([S($d,'full_name'),S($d,'email'),S($d,'role','user'),(int)IV($d,'active',1),$hash,$id]);
                } else {
                    $db->prepare("UPDATE users SET full_name=?,email=?,role=?,active=? WHERE id=?")
                       ->execute([S($d,'full_name'),S($d,'email'),S($d,'role','user'),(int)IV($d,'active',1),$id]);
                }
                flash('success','Utilisateur modifié.');
            } elseif ($act==='delete') {
                if ($id===(int)$user['id']) { flash('error','Impossible de supprimer son propre compte.'); break; }
                $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
                flash('success','Utilisateur supprimé.');
            }
            break;
    }
}

// Helpers
function S(array $d, string $k, string $def=''): string { return sanitize($d[$k] ?? $def); }
function IV(array $d, string $k, $def=0) { return $d[$k] ?? $def; }
function NV(array $d, string $k): ?string { $v=trim($d[$k]??''); return $v?:null; }
function msg(string $act, string $e): string {
    return match($act){
        'add'=>"✅ ".ucfirst($e)." ajouté(e) avec succès.",
        'edit'=>"✅ ".ucfirst($e)." modifié(e).",
        'delete'=>"🗑️ ".ucfirst($e)." supprimé(e).",
        default=>"OK"
    };
}
function assocCartridgesModel(PDO $db, int $pmid, array $cids): void {
    if (!$cids) return;
    $ins = $db->prepare("INSERT IGNORE INTO printer_model_cartridges(printer_model_id,cartridge_model_id)VALUES(?,?)");
    foreach ($cids as $c) { if ((int)$c>0) $ins->execute([$pmid,(int)$c]); }
}
function assocCartridges(PDO $db, int $pid, array $cids): void {
    if (!$cids) return;
    $ins=$db->prepare("INSERT IGNORE INTO printer_cartridges(printer_id,cartridge_model_id)VALUES(?,?)");
    foreach ($cids as $c) { if ((int)$c>0) $ins->execute([$pid,(int)$c]); }
}
