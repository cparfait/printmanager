<?php
// ============================================================
//  PrintManager – Page : services (référentiel, admin)
// ============================================================

function pageServices(PDO $db): void {
    requireAdmin(); // référentiel : gestion réservée aux administrateurs
    $services = $db->query("SELECT s.*, COUNT(DISTINCT p.id) as printer_count FROM services s LEFT JOIN printers p ON p.service_id=s.id GROUP BY s.id ORDER BY s.name")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">🏢 Gestion des Services</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Nouveau service</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Nom du service</th><th>Direction</th><th>Contact</th><th>Email / Téléphone</th><th>Imprimantes</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($services)): ?><tr><td colspan="6" class="empty-cell">Aucun service enregistré</td></tr>
    <?php else: foreach($services as $s): ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong></td>
      <td><?=h($s['direction'])?></td>
      <td><?=h($s['contact_name'])?></td>
      <td><?=$s['contact_email']?'<a href="mailto:'.h($s['contact_email']).'">'.h($s['contact_email']).'</a>':''?>
          <?=$s['phone']?' <span class="muted">'.h($s['phone']).'</span>':''?></td>
      <td><span class="badge badge-info"><?=h($s['printer_count'])?> 🖨️</span></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"service")'title="Modifier">✏️</button>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$s['id']?>,"service","<?=h(addslashes($s['name']))?>")'title="Supprimer">🗑️</button>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<!-- Add Modal -->
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouveau service</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="service"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Nom du service *</label><input type="text" name="name" required placeholder="ex: Direction des Finances"></div>
    <div class="form-group"><label>Direction / Pôle</label><input type="text" name="direction" placeholder="ex: Pôle Ressources"></div>
    <div class="form-group"><label>Nom du contact</label><input type="text" name="contact_name" placeholder="Jean Dupont"></div>
    <div class="form-group"><label>Email</label><input type="email" name="contact_email" placeholder="jean.dupont@collectivite.fr"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" placeholder="01 23 45 67 89"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Informations complémentaires..."></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<!-- Edit Modal -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier le service</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="service"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" id="edit-name" required></div>
    <div class="form-group"><label>Direction</label><input type="text" name="direction" id="edit-direction"></div>
    <div class="form-group"><label>Contact</label><input type="text" name="contact_name" id="edit-contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="contact_email" id="edit-contact_email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" id="edit-phone"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('service'); }

