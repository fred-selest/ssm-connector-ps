<p align="center"><img src="logo.png" alt="SSM Connector" width="96" height="96"></p>

# SSM Connector — PrestaShop

Module natif PrestaShop (8.x, 9.x visé) qui relie la boutique à **SSM Core** (Selest Site Manager) : inventaire (modules, thèmes, versions), statistiques de base, journal d'événements et suivi de l'état de la boutique.

Version actuelle : **0.4.0**

## Aperçu

Page de configuration du module : trois champs, un test de connexion intégré et un état clair de la connexion.

![Première configuration : trois étapes et liste de contrôle](docs/screenshots/configuration-initiale.png)

![Connexion établie avec SSM Core](docs/screenshots/connecte.png)

![Erreur expliquée en clair, avec l'action à mener](docs/screenshots/erreur-token.png)

> Ces aperçus sont le rendu du HTML produit par le module, hors back-office PrestaShop : l'habillage exact dépend du thème d'administration de votre boutique.

## Configuration en 3 étapes

1. **Installer** le module : Back-office → Modules → Gestionnaire de modules → *Téléverser un module*, avec `ssmconnector.zip` de la [dernière release](../../releases/latest).
2. **Ouvrir la configuration** du module, coller l'**adresse de SSM Core**, cliquer sur **Enregistrer et tester la connexion**.
3. **Copier le token** affiché (bouton *Copier*) et ajouter la boutique dans le tableau de bord SSM avec ce token.

C'est tout : l'envoi est automatique, sans tâche cron à configurer. La page indique l'état de la connexion ; en cas d'échec, elle explique la cause (adresse introuvable, token refusé, certificat invalide…) et ce qu'il faut corriger.

## Fonctionnement

Le module envoie périodiquement un *heartbeat* (`POST {adresse SSM}/api/v1/heartbeat`, JSON) contenant :

- version de PrestaShop, de PHP et de MySQL, mode debug, mode maintenance, SSL, multiboutique ;
- liste des modules et thèmes (version, actif / inactif) ;
- compteurs (clients, produits, commandes, employés) ;
- les événements survenus depuis le dernier envoi (connexions, commandes, mises à jour produit, installation / désinstallation de module). La file est limitée à 100 événements et n'est vidée qu'après un envoi réussi.

Un badge **SSM: OK / KO** est affiché dans l'en-tête du back-office.

### Envoi automatique

L'intervalle se règle dans la configuration (5 minutes à 24 heures, 30 minutes par défaut). L'envoi est déclenché par les pages vues, sans cron :

- côté **boutique**, l'envoi a lieu *après* la réponse au visiteur (PHP-FPM), donc sans jamais le ralentir ; sans PHP-FPM, la boutique ne déclenche rien ;
- côté **back-office**, l'envoi est déclenché à l'ouverture d'une page d'administration ;
- un verrou évite les envois simultanés ; après un échec, un nouvel essai a lieu au plus tard 5 minutes plus tard.

### Tâche cron (facultatif)

Pour un envoi à heure fixe, même sans visite, planifier toutes les 5 minutes (la commande exacte, avec le token, est affichée dans la configuration, bouton *Copier la commande*) :

```bash
*/5 * * * * curl -fsS -H "X-SSM-Token: <token>" "https://votre-boutique.tld/module/ssmconnector/cron" >/dev/null
```

Le heartbeat n'est envoyé que si l'intervalle configuré est écoulé ; ajouter `?force=1` pour l'envoyer immédiatement. Le token se passe **uniquement dans l'en-tête** : il n'est plus accepté dans l'adresse (changement depuis la 0.3.0).

## Sécurité

- **HTTPS obligatoire** : l'adresse de SSM Core doit commencer par `https://` (`http://` n'est toléré que pour `localhost`). Le certificat est vérifié et les redirections ne sont **pas** suivies, pour que le token ne parte jamais vers une autre adresse.
- **Token** de 256 bits tiré au hasard à l'installation, affiché masqué, **régénérable** en un clic (l'ancien est alors refusé).
- **Chaque envoi** porte le token (`X-SSM-Token`) et une signature HMAC-SHA256 du corps (`X-SSM-Signature`, clé = token). Le corps contient un horodatage, donc signé.
- **Point d'entrée cron** : token en en-tête uniquement, comparaison à temps constant, **blocage 15 minutes après 10 échecs** depuis la même adresse IP (réponse `429`). L'adresse IP n'est jamais stockée en clair, seulement son condensat.
- **Back-office** : chaque action de la page de configuration est protégée par un jeton anti-CSRF propre à l'employé, en plus de celui de PrestaShop ; toutes les sorties sont échappées. L'accès à la page reste régi par les droits PrestaShop sur les modules.
- **Données envoyées** : voir la liste ci-dessus. Aucun mot de passe, aucun contenu de commande ni donnée client : seulement des identifiants (n° de client, de commande, de produit) et, pour les tentatives de connexion, un condensat tronqué de l'adresse e-mail.

Limites à connaître : le token est stocké en clair dans la table de configuration de PrestaShop (il sert de clé de signature) ; protégez l'accès à la base de données. Il n'y a ni OAuth2 ni liste blanche d'adresses IP.

## Compatibilité

- PrestaShop 8.0 minimum (9.x visé)
- PHP 8.1 minimum, extension cURL
- Multiboutique : la configuration suit le contexte de boutique

## Mise à jour du connecteur

**Détection.** Le module compare sa version à la dernière release GitHub de ce dépôt (au plus une vérification toutes les 12 h, ou à la demande via « Vérifier les mises à jour »). Quand une version plus récente existe, la page de configuration affiche un bandeau avec le lien de téléchargement et les notes de version, et le heartbeat transmet `latest_connector_version` et `connector_update_available` à SSM Core. La vérification est une simple requête publique vers `api.github.com` : aucune donnée de la boutique n'est envoyée. Une indisponibilité de GitHub n'a aucun effet sur le fonctionnement du module.

**Installation de la mise à jour.** Elle reste manuelle : remplacer le dossier `modules/ssmconnector/` par le contenu de `ssmconnector.zip`, puis lancer la mise à jour du module dans le gestionnaire de modules. Les scripts du dossier `upgrade/` s'exécutent alors (ils ajoutent les réglages manquants, enregistrent les hooks et réparent la file d'événements). Le token et la configuration sont conservés.

**Publier une version** (mainteneurs) : mettre à jour la version dans `ssmconnector.php` et `config.xml`, ajouter si besoin `upgrade/upgrade-X.Y.Z.php`, fusionner dans `main`, puis au choix :

- en ligne de commande : `git tag vX.Y.Z && git push origin vX.Y.Z` ;
- depuis l'interface GitHub : *Releases → Draft a new release*, créer le tag `vX.Y.Z` sur `main`, puis *Publish release*.

Dans les deux cas, le workflow `.github/workflows/release.yml` vérifie la syntaxe PHP, contrôle que le tag correspond à la version du module, construit `ssmconnector.zip` (dossier racine `ssmconnector/`) et le joint à la release (qu'il crée si elle n'existe pas). Une release publiée sans archive après un échec du workflow se corrige en relançant le job depuis l'onglet Actions.

## Développement

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Licence

[MIT](LICENSE) © Selest Informatique
