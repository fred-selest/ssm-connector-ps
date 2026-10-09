# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions sémantiques.

## [0.8.0] - 2026-10-09

### Ajouté
- **Adresse réelle du back-office** (`admin_url`) envoyée à SSM. Le dossier d'administration étant
  renommé à l'installation, SSM affichait un lien inventé (`/admin-dev/`). Le module la lit dans le
  back-office ou la retrouve à la racine, et n'envoie rien s'il y a un doute (plusieurs candidats).
- **Connexion directe au back-office depuis SSM**, fermée par défaut. Un super-administrateur l'ouvre
  dans la page du module (case à cocher et choix de l'employé, jeton anti-CSRF) ; SSM ne peut pas l'ouvrir
  à distance. La constante `SSM_CONNECTOR_ALLOW_LOGIN` de `config/defines_custom.inc.php` l'emporte
  (`false` la verrouille fermée), comme `SSM_CONNECTOR_LOGIN_EMPLOYEE` pour l'employé. La clé remise par SSM est stockée chiffrée (libsodium). Le lien signé
  HMAC-SHA256 est valable 60 s et une seule fois, et vérifié pour le bon site. La session est ouverte
  comme par la page de connexion de PrestaShop (cookie `psAdmin`, session employé), y compris en mode
  maintenance. Chaque connexion est journalisée. Testée sur un PrestaShop 8.2 réel : refus (fermée, clé
  absente, autre site, mauvaise clé, lien rejoué), puis ouverture du tableau de bord.
- Page du module : panneau « Connexion directe depuis SSM » (case, employé, état de la clé) et adresse du
  back-office transmise. Vérifié dans le back-office réel : formulaire enregistré, lien accepté avec la
  seule case cochée, refusé quand la constante vaut `false`.

## [0.7.0] - 2026-10-09

### Ajouté
- **Contrat 3 de SSM Core (2.13).** Le module annonce ce qu'il sait faire (`capabilities`) ; SSM ne
  lui envoie rien d'autre. Un SSM plus ancien ignore les nouveaux champs.
- **Activer et désactiver un module** à la demande de SSM (jamais le connecteur lui-même), avec un
  compte rendu par action (`command_results`).
- **Erreurs PHP** : erreurs fatales relevées en fin de requête, lecture incrémentale du journal de
  PHP (`error_log`, 512 Ko au plus par envoi), chemins rendus relatifs à la boutique.
- **Sauvegarde de la boutique** (`backup_site`) : base exportée en SQL et fichiers (sans caches ni
  journaux, images en option) dans une archive zip déposée sur l'URL pré-signée fournie par SSM.

### Corrigé
- `upgrade-0.6.0.php` ne définissait pas `upgrade_module_0_6_0()`, la fonction que PrestaShop
  appelle pour appliquer un script de mise à niveau.

### Limite connue, désormais dite
- **La mise à jour de modules ne fonctionne pas sur un vrai PrestaShop** : elle appelle
  `$module->upgrade()`, qui n'existe pas dans la classe `Module` (seulement dans le faux module des
  tests), et ne télécharge pas la nouvelle version. Elle échouait donc toujours, avec le message
  « Ce module ne sait pas se mettre à jour lui-même ». `update_extension` n'est plus annoncé :
  SSM n'envoie plus ces commandes. Reste à écrire : téléchargement depuis PrestaShop Addons.

## [0.6.0] - 2026-10-08

### Ajouté
- **Exécution des mises à jour de modules demandées par SSM Core.** SSM Core n'a pas accès au
  système de fichiers d'une boutique : il envoie une instruction dans la réponse du heartbeat, ce
  module l'exécute, et renvoie le compte rendu au heartbeat suivant. C'est la seule chose qui
  autorise SSM à écrire « appliquée » — et la seule source honnête de ce fait.
- **Une sauvegarde avant chaque modification**, dans `ssm-backups/` à la racine de la boutique,
  avec trois générations conservées par module. En cas d'échec, le dossier est restauré et le
  compte rendu dit explicitement ce qu'est devenu le module.
- **Rien n'est exécuté sans demande.** Après coup, la version installée est relue : une commande
  annoncée comme faite sans que la version ait bougé est rapportée en échec.

### Sécurité
- Le nom de module venu de SSM est contrôlé avant tout usage : un identifiant contenant `../` ne
  peut plus désigner un chemin hors du répertoire des modules.
- Les comptes rendus n'emportent que l'identifiant de mise à jour, l'état, la version et la
  raison. Aucune donnée personnelle de la boutique.

## [0.5.0]

Aligné sur SSM Core 2.7.0, qui lit enfin l'état de la boutique et les compteurs que le module envoyait déjà. Trois défauts d'alignement corrigés au passage.

### Corrigé

- **Une URL de boutique trop longue faisait refuser tout l'inventaire (422).** `shop_url` partait sans coupure alors que SSM Core l'accepte sur 255 caractères au maximum. Toutes les autres valeurs étaient tronquées ; celle-ci seule avait été oubliée — il suffisait d'un nom de domaine inhabituellement long pour que la boutique passe « hors ligne » dans SSM. Elle est maintenant tronquée comme les autres.
- **Un module au nom vide faisait de même.** SSM Core exige un `slug` non vide (`min_length=1`) : une ligne illisible dans la table des modules suffisait à faire refuser l'inventaire complet. Le module au nom vide — et le thème au nom vide — sont écartés de l'envoi, pas transmis.
- **Les compteurs métier étaient transmis sans borne.** SSM Core les accepte entre 0 et 2 000 000 000 ; hors de ces bornes, c'est tout l'inventaire qui est refusé, pas seulement le compteur. Les valeurs sont maintenant bornées, et un compteur négatif ne peut plus Injecter une valeur absurde.
- **Les messages d'erreur ne reconnaissaient pas deux réponses possibles de SSM Core** : `409` (deux envois simultanés pour la même boutique — rien n'est perdu, le prochain envoi repart) et `413` (inventaire trop volumineux). Les deux tombaient sur « réponse inattendue ».

### Sécurité

- **Le détail d'erreur renvoyé par SSM Core était rendu sans échappement dans le back-office.** Sur un `422`, le champ `msg` du serveur était recopié tel quel dans le message affiché, sans passer par l'échappement que subit tout le reste de la page. SSM Core étant auto-hébergé, une instance hostile ou compromise pouvait exécuter du script dans le back-office PrestaShop. Le balisage est maintenant retiré du texte avant affichage.
- **Décocher « envoyer les événements » n'arrêtait pas l'envoi.** Le réglage ne gouvernait que la mise en file : une file déjà pleine continuait d'être transmise, et repartait à chaque envoi. C'était le cas le plus probable en pratique — une file pleine au moment du décochement. Le drapeau gouverne désormais aussi l'envoi, et le décochement vide la file.
- **Un réglage absent n'est plus rapporté « désactivé ».** Quand la clé `PS_SSL_ENABLED` n'existe pas encore (installation fraîche), le module annonçait « SSL désactivé » alors que personne n'avait rien déclaré : il ne rapporte plus rien du tout, ce qu'SSM Core 2.7.0 sait distinguer des autres connecteurs.

### Modifié

- **Les événements sont désactivés par défaut.** SSM Core ne les exploite pas : son schéma les déclare volontairement absents, et le connecteur WordPress avait supprimé sa file pour la même raison. Le module, lui, écrivait en base à chaque mise à jour produit et envoyait des numéros de client, de commande et de tentatives de connexion — des identifiants de personnes, pour un service qui les jette. Une case « Envoyer les événements » permet de les réactiver si SSM Core vient à les consommer. À l'installation comme à la mise à jour, la file existante est vidée.
- Le module lit le `site_id` renvoyé par SSM Core et l'affiche dans la page de configuration : c'est la confirmation immédiate que le token collé est bien celui de **cette** boutique, et non celui d'une autre collé par erreur.
- Le README ne décrit plus comme « non lus par SSM Core » l'état de la boutique et les compteurs, qu'il lit depuis la 2.7.0.

### Ajouté

- Tests pour chacun de ces points (URL trop longue, nom vide, compteurs hors bornes, 409/413, site reconnu, événements désactivés) et pour chacun des correctifs de sécurité : **303 vérifications**. Chaque test a été validé en réintroduisant le bug qu'il couvre, pour vérifier qu'il échoue vraiment.
- Un test exécute réellement la mise à jour `0.4.2 → 0.5.0` sur une boutique existante (deux passes) : c'est l'opération la plus risquée pour un marchand, elle ne dépend pas d'une simple vérification de présence de fichier.

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
