# Ressources statiques locales

- `app.css` — feuille de style principale de l'application (thèmes sombre/clair).
- `chart.umd.min.js` et `jsQR.min.js` — bibliothèques JavaScript, **déjà présentes** :
  l'application fonctionne donc 100 % en local (intranet sans internet, conformité RGPD).

L'application charge en priorité les bibliothèques depuis ce dossier, et ne bascule
sur le CDN (jsdelivr) **qu'en secours** si un fichier local venait à manquer.

Pour les re-télécharger ou les mettre à jour :

| Fichier attendu      | Bibliothèque | Téléchargement                                                        |
|----------------------|--------------|-----------------------------------------------------------------------|
| `chart.umd.min.js`   | Chart.js 4.4 | <https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js>   |
| `jsQR.min.js`        | jsQR 1.4     | <https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js>            |

Exemple (PowerShell) :

```powershell
Invoke-WebRequest https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js -OutFile chart.umd.min.js
Invoke-WebRequest https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js -OutFile jsQR.min.js
```

Les polices Google Fonts ont été retirées : l'interface utilise la pile de polices
système (Segoe UI, system-ui…), sans aucun appel externe.
