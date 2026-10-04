# SSM Connector — PrestaShop

Module natif PrestaShop (8.x, 9.x visé) qui relie la boutique à **SSM Core** (Selest Site Manager) : inventaire (modules, thèmes, versions), statistiques de base, journal d'événements et suivi de l'état de la boutique.

Version actuelle : **0.3.0**

## Fonctionnement

Le module envoie périodiquement un *heartbeat* (`POST {URL SSM}/api/v1/heartbeat`, JSON) contenant :

- version de PrestaShop, de PHP et de MySQL, mode debug, mode maintenance, SSL, multiboutique ;
- liste des modules et thèmes (version, actif / inactif) ;
- compteurs (clients, produits, commandes, employés) ;
- les événements survenus depuis le dernier envoi (connexions, commandes, mises à jour produit, installation / désinstallation de module). La file est limitée à 100 événements et n'est vidée qu'après un envoi réussi.

Un badge **SSM: OK / KO** est affiché dans l'en-tête du back-office.

## Installation

1. Télécharger `ssmconnector.zip` depuis la [dernière release](../../releases/latest), ou le construire (le dossier racine doit s'appeler `ssmconnector`) :
   ```bash
   git archive --prefix=ssmconnector/ -o ssmconnector.zip HEAD
   ```
2. Back-office PrestaShop → Modules → Gestionnaire de modules → Téléverser un module.
3. Ouvrir la configuration du module : renseigner l'**URL de SSM Core** et noter le **token** (généré à l'installation).
4. Déclarer la boutique dans SSM Core avec ce token.

## Planification de l'envoi

L'intervalle (300 s à 86 400 s, 1 800 s par défaut) est appliqué par un point d'entrée cron. Ajouter une tâche cron système, par exemple toutes les 5 minutes :

```bash
*/5 * * * * curl -fsS -H "X-SSM-Token: <token>" "https://votre-boutique.tld/module/ssmconnector/cron" >/dev/null
```

Le heartbeat n'est envoyé que si l'intervalle configuré est écoulé ; ajouter `?force=1` pour l'envoyer immédiatement. Le bouton « Heartbeat maintenant » de la page de configuration fait la même chose depuis le back-office.

## Sécurité

- Chaque requête sortante porte le token du module (`X-SSM-Token`) et une signature HMAC-SHA256 du corps (`X-SSM-Signature`, clé = token).
- Le point d'entrée cron exige le token (comparaison à temps constant) et répond `403` sinon.
- Utiliser une URL SSM Core en **HTTPS** : le token transite dans les en-têtes.
- Les adresses e-mail des tentatives de connexion client ne sont jamais envoyées : seul un hash tronqué l'est.

## Compatibilité

- PrestaShop 8.0 minimum (9.x visé)
- PHP 8.1 minimum, extension cURL
- Multiboutique : la configuration suit le contexte de boutique

## Mise à jour du connecteur

**Détection.** Le module compare sa version à la dernière release GitHub de ce dépôt (au plus une vérification toutes les 12 h, ou à la demande via « Vérifier les mises à jour »). Quand une version plus récente existe, la page de configuration affiche un bandeau avec le lien de téléchargement et les notes de version, et le heartbeat transmet `latest_connector_version` et `connector_update_available` à SSM Core. La vérification est une simple requête publique vers `api.github.com` : aucune donnée de la boutique n'est envoyée. Une indisponibilité de GitHub n'a aucun effet sur le fonctionnement du module.

**Installation de la mise à jour.** Elle reste manuelle : remplacer le dossier `modules/ssmconnector/` par le contenu de `ssmconnector.zip`, puis lancer la mise à jour du module dans le gestionnaire de modules. Les scripts du dossier `upgrade/` s'exécutent alors (le script `upgrade-0.3.0.php` ajoute les réglages manquants, enregistre les hooks et répare la file d'événements). Le token et la configuration sont conservés.

**Publier une version** (mainteneurs) : mettre à jour la version dans `ssmconnector.php` et `config.xml`, ajouter si besoin `upgrade/upgrade-X.Y.Z.php`, puis :

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

Le workflow `.github/workflows/release.yml` vérifie la syntaxe PHP, contrôle que le tag correspond à la version du module, construit `ssmconnector.zip` (dossier racine `ssmconnector/`) et crée la release GitHub.

## Développement

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Licence

[MIT](LICENSE) © Selest Informatique
