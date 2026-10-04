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

1. Créer l'archive (le dossier du module doit s'appeler `ssmconnector`) :
   ```bash
   git archive --prefix=ssmconnector/ -o ssmconnector.zip HEAD
   ```
   ou télécharger `ssmconnector.zip` depuis les releases.
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

## Mise à jour depuis une version précédente

Remplacer le dossier `modules/ssmconnector/` puis, dans le gestionnaire de modules, lancer la mise à jour : le script `upgrade/upgrade-0.3.0.php` ajoute les réglages manquants, enregistre les hooks et répare la file d'événements.

## Développement

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Licence

À définir.
