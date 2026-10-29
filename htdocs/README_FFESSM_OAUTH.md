# Connexion FFESSM — configuration

1. Demandez à la FFESSM l’enregistrement d’une application OAuth/OIDC et les valeurs `client_id`, `client_secret`, les scopes autorisés et les URL d’autorisation/token confirmées.
2. Renseignez `FFESSM_CLIENT_ID`, `FFESSM_CLIENT_SECRET` et `FFESSM_REDIRECT_URI` dans `.env`. L’URL de retour doit être exactement celle enregistrée côté FFESSM.
3. Les routes prévues sont `?action=ffessm_login`, `?action=ffessm_callback` et `?action=ffessm_logout`.

**Limite importante :** cette modification ajoute le squelette de connexion OAuth/OIDC. Elle ne remplace pas encore l’ancienne récupération des performances dans `SyncController.php`. Le serveur d’identité ne fournit pas, à lui seul, la preuve qu’une API de performances est accessible. Les endpoints et scopes doivent être confirmés par la FFESSM avant mise en production.
