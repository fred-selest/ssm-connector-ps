# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions sémantiques.

## [0.4.2] - 2026-10-05

Le module envoie enfin l'inventaire que SSM Core lit, et ne montre plus jamais le token.

### Corrigé
- **Aucun module n'arrivait dans SSM Core** : l'inventaire utilisait la clé `modules`, SSM Core lit `extensions`. La boutique passait « en ligne » mais SSM n'avait aucun module (et effaçait ceux qu'il aurait connus). La version de la base (`mysql_version`) n'était pas lue non plus : elle part maintenant sous `db_version`, avec le nom de la machine, le chemin d'installation et le serveur web.
- **Refus de tout l'inventaire (422) avec une version de PHP longue** (ex. Debian, plus de 20 caractères) : la version part au format `X.Y.Z`, et tous les champs sont tronqués aux limites de SSM Core (modules plafonnés à 1000, thèmes à 200). Une erreur 422 est maintenant expliquée (champ refusé) au lieu de « réponse inattendue ».
- **Token de 16 à 31 caractères accepté par le module mais refusé par SSM Core** (minimum 32), avec un message trompeur : le module exige 32 à 256 caractères et signale un ancien token trop court.
- **Crochets inexistants** : `actionCustomerLoginBefore`, `actionCustomerLoginAfter`, `actionEmployeeLoginAfter` et `actionOrderCreated` n'existent pas dans PrestaShop (vérifié dans la liste officielle et le code source du cœur) : les événements de connexion et de commande ne partaient jamais. Remplacés par `actionAuthenticationBefore`, `actionAuthentication` et `actionValidateOrder` ; les anciens sont détachés à la mise à jour. Pas de crochet fiable pour la connexion des employés : cet événement est retiré.
- Le numéro de version d'une release doit être strictement `X.Y.Z`.

### Sécurité
- **Le token n'est plus écrit dans la page de configuration** (il l'était dans un champ prérempli et dans la commande cron) : champ vide, 4 derniers caractères en repère, commande cron avec `VOTRE_TOKEN`.
- Le module n'invente plus de token à l'installation (il n'était connu que de la boutique) ni ne propose d'en générer un : le token vient de SSM Core. Un token déjà enregistré est conservé.
- La fausse signature `X-SSM-Signature` est retirée : sa clé était le token envoyé dans la même requête et SSM Core ne la vérifie pas.
- File d'événements : plus d'empreinte d'adresse e-mail, et les répétitions rapprochées sont regroupées (une attaque par force brute ou un import de produits n'écrit plus en base à chaque requête).
- Envoi automatique : délai limité à 5 secondes.

### Ajouté
- Le token se colle tel quel (espaces, retours à la ligne, guillemets, `Bearer` retirés).
- Guide en trois étapes tant que la boutique n'est pas connectée (plus de faux « Non connecté » avant toute configuration), case « Token renseigné » dans la liste de contrôle.
- Tests (`tests/`, 200 vérifications) avec un faux PrestaShop et un faux SSM Core local, CI sur PHP 8.1 et 8.3, générateur des aperçus du README, `CHANGELOG.md`.

### Modifié
- README : suppression des affirmations inexactes (signature HMAC, « affiché masqué ») et ajout des limites connues.

## [0.4.1]
Saisie du token fourni par SSM Core. Les versions antérieures sont décrites sur la page des [releases](../../releases).
