<?php
// ============================================================
//  PrintManager – Page de connexion
// ============================================================

// ─── LOGIN PAGE ──────────────────────────────────────────────
function renderLogin(): void {
    $flashes = getFlashes();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion – PrintManager</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#080b14;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#f0f4ff;padding:1rem;position:relative;overflow:hidden}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% -10%,rgba(67,97,238,.25),transparent),radial-gradient(ellipse 50% 40% at 80% 110%,rgba(123,45,139,.2),transparent);pointer-events:none}
.login-wrap{width:100%;max-width:440px;position:relative;z-index:1}
.login-card{background:rgba(17,24,39,.9);border:1px solid rgba(67,97,238,.2);border-radius:20px;padding:2.5rem;backdrop-filter:blur(10px);box-shadow:0 25px 80px rgba(0,0,0,.6)}
.logo{text-align:center;margin-bottom:2.5rem}
.logo-icon{font-size:3.5rem;display:block;margin-bottom:.75rem;filter:drop-shadow(0 0 20px rgba(67,97,238,.6))}
.logo h1{font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:800;letter-spacing:-1px;background:linear-gradient(135deg,#f0f4ff,#a5b4fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo p{color:#4b5563;font-size:.85rem;margin-top:.3rem}
.form-group{margin-bottom:1.25rem}
label{display:block;font-size:.75rem;font-weight:600;color:#4b5563;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.5rem}
input{width:100%;background:rgba(8,11,20,.8);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:.85rem 1.1rem;color:#f0f4ff;font-size:.95rem;transition:all .2s;font-family:'DM Sans',sans-serif}
input:focus{outline:none;border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.2)}
.btn-submit{width:100%;background:linear-gradient(135deg,#4361ee,#3a86ff);border:none;border-radius:10px;padding:1rem;color:#fff;font-size:1rem;font-weight:700;cursor:pointer;font-family:'Outfit',sans-serif;letter-spacing:.02em;transition:all .25s;box-shadow:0 4px 20px rgba(67,97,238,.4);margin-top:.5rem}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(67,97,238,.5)}
.flash{padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1.25rem;font-size:.88rem;font-weight:500}
.flash-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.footer-note{text-align:center;color:#1f2937;font-size:.75rem;margin-top:1.5rem}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="logo">
      <span class="logo-icon">🖨️</span>
      <h1>PrintManager</h1>
      <p>Gestion des imprimantes & cartouches</p>
    </div>
    <?php foreach($flashes as $f): ?>
    <div class="flash flash-<?=htmlspecialchars($f['type'])?>"><?=htmlspecialchars($f['msg'])?></div>
    <?php endforeach; ?>
    <form method="post" action="index.php?page=login">
      <?=csrfField()?>
      <div class="form-group"><label>Identifiant</label><input type="text" name="username" required autofocus autocomplete="username" placeholder="votre identifiant"></div>
      <div class="form-group"><label>Mot de passe</label><input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></div>
      <button type="submit" class="btn-submit">Se connecter →</button>
    </form>
  </div>
  <div class="footer-note">Première utilisation ? Lancez <strong>install.php</strong></div>
</div>
</body>
</html>
<?php
}