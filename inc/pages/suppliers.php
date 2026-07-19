<?php
// ============================================================
//  PrintManager – Page : fournisseurs (référentiel, admin)
// ============================================================

function pageSuppliers(PDO $db): void {
    requireAdmin(); // référentiel : gestion réservée aux administrateurs
    $suppliers = $db->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">🏭 Fournisseurs</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Nouveau fournisseur</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Fournisseur</th><th>Contact</th><th>Email</th><th>Téléphone</th><th>Site web</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($suppliers)): ?><tr><td colspan="6" class="empty-cell">Aucun fournisseur</td></tr>
    <?php else: foreach($suppliers as $s): ?>
    <tr>
      <td><strong><?=h($s['name'])?></strong><?=$s['address']?'<br><small class="muted">'.h($s['address']).'</small>':''?></td>
      <td><?=h($s['contact_name'])?></td>
      <td><?=$s['email']?'<a href="mailto:'.h($s['email']).'">'.h($s['email']).'</a>':'-'?></td>
      <td><?=h($s['phone'])?:'-'?></td>
      <td><?=$s['website']?'<a href="'.h($s['website']).'" target="_blank">🔗 Voir</a>':'-'?></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"supplier")'>✏️</button>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$s['id']?>,"supplier","<?=h(addslashes($s['name']))?>")'  >🗑️</button>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Nouveau fournisseur</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="supplier"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" required></div>
    <div class="form-group"><label>Nom du contact</label><input type="text" name="contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone"></div>
    <div class="form-group form-full"><label>Adresse</label><input type="text" name="address"></div>
    <div class="form-group form-full"><label>Site web</label><input type="url" name="website" placeholder="https://"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier le fournisseur</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="supplier"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group"><label>Nom *</label><input type="text" name="name" id="edit-name" required></div>
    <div class="form-group"><label>Contact</label><input type="text" name="contact_name" id="edit-contact_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
    <div class="form-group"><label>Téléphone</label><input type="tel" name="phone" id="edit-phone"></div>
    <div class="form-group form-full"><label>Adresse</label><input type="text" name="address" id="edit-address"></div>
    <div class="form-group form-full"><label>Site web</label><input type="url" name="website" id="edit-website"></div>
    <div class="form-group form-full"><label>Notes</label><textarea name="notes" id="edit-notes" rows="2"></textarea></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('supplier'); }

