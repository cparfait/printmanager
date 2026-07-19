<?php
// ============================================================
//  PrintManager – Page : administration des comptes
// ============================================================

function pageAdmin(PDO $db): void {
    requireAdmin();
    // Jamais de SELECT * ici : le hash du mot de passe ne doit pas se retrouver dans le HTML
    $users = $db->query("SELECT id, username, full_name, email, role, active, last_login, created_at FROM users ORDER BY role DESC, username")->fetchAll();
?>
<div class="page-header"><span class="page-title-txt">⚙️ Administration – Utilisateurs</span>
  <button class="btn-primary" onclick="openModal('modal-add')">+ Créer un compte</button>
</div>
<div class="card">
  <table class="data-table">
    <thead><tr><th>Identifiant</th><th>Nom complet</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($users as $u): ?>
    <tr>
      <td><strong><?=h($u['username'])?></strong></td>
      <td><?=h($u['full_name'])?></td>
      <td><?=$u['email']?'<a href="mailto:'.h($u['email']).'">'.h($u['email']).'</a>':'–'?></td>
      <td><span class="badge <?=$u['role']==='admin'?'badge-warning':'badge-info'?>"><?=$u['role']==='admin'?'👑 Admin':'👤 Utilisateur'?></span></td>
      <td><?=$u['active']?'<span class="badge badge-success">Actif</span>':'<span class="badge badge-danger">Inactif</span>'?></td>
      <td class="muted"><?=$u['last_login']?date('d/m/Y H:i',strtotime($u['last_login'])):'Jamais'?></td>
      <td class="actions">
        <button class="btn-icon btn-edit" onclick='openEditModal(<?=json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,"user")'>✏️</button>
        <?php if($u['id']!==(int)$GLOBALS['user']['id']):?>
        <button class="btn-icon btn-del" onclick='confirmDel(<?=$u['id']?>,"user","<?=h(addslashes($u['username']))?>")'  >🗑️</button>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="modal-overlay" id="modal-add">
  <div class="modal"><div class="modal-header"><h3>Créer un compte</h3><button class="modal-close" onclick="closeModal('modal-add')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="user"><input type="hidden" name="_action" value="add">
  <div class="form-grid">
    <div class="form-group"><label>Identifiant *</label><input type="text" name="username" required autocomplete="off"></div>
    <div class="form-group"><label>Mot de passe * <small class="muted">(min. <?=MIN_PASSWORD_LEN?> caractères)</small></label><input type="password" name="password" required autocomplete="new-password" minlength="<?=MIN_PASSWORD_LEN?>"></div>
    <div class="form-group"><label>Nom complet</label><input type="text" name="full_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
    <div class="form-group"><label>Rôle</label><select name="role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-add')">Annuler</button><button type="submit" class="btn-primary">Créer</button></div>
  </form></div>
</div>
<div class="modal-overlay" id="modal-edit">
  <div class="modal"><div class="modal-header"><h3>Modifier l'utilisateur</h3><button class="modal-close" onclick="closeModal('modal-edit')">✕</button></div>
  <form method="post"><?=csrfField()?><input type="hidden" name="_entity" value="user"><input type="hidden" name="_action" value="edit"><input type="hidden" name="_id" id="edit-id">
  <div class="form-grid">
    <div class="form-group form-full"><label>Identifiant</label><input type="text" id="edit-username" disabled class="input-disabled"></div>
    <div class="form-group"><label>Nom complet</label><input type="text" name="full_name" id="edit-full_name"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
    <div class="form-group"><label>Rôle</label><select name="role" id="edit-role"><option value="user">Utilisateur</option><option value="admin">Administrateur</option></select></div>
    <div class="form-group"><label>Statut</label><select name="active" id="edit-active"><option value="1">Actif</option><option value="0">Inactif</option></select></div>
    <div class="form-group form-full"><label>Nouveau mot de passe <small class="muted">(laisser vide = inchangé · min. <?=MIN_PASSWORD_LEN?> caractères)</small></label><input type="password" name="password" autocomplete="new-password" minlength="<?=MIN_PASSWORD_LEN?>"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button><button type="submit" class="btn-primary">Enregistrer</button></div>
  </form></div>
</div>
<?php deleteModal('user'); }

