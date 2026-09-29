---
name: ssm-connector-ps
description: "SSM Connector PrestaShop — Module natif PS8/PS9. Inventaire, MAJ, logs, sécurité. Livré en ZIP installable manuellement."
---

# SSM Connector — PrestaShop

Module natif PrestaShop (PS8 / PS9) qui expose la boutique au SSM Core (inventaire, MAJ, logs, sécurité).

## Installation (manuelle côté client)

1. Télécharger `ssm-connector.zip` depuis la release GitHub
2. Dézipper dans `modules/ssmconnector/`
3. Back-office PS → Modules → Chercher "SSM Connector" → Installer
4. Configurer l'URL du serveur SSM + token (généré auto)
5. Ajouter la boutique dans le dashboard SSM avec ce token

## API utilisée

- **Admin API OAuth2** (canonique PS9) pour inventaire
- **Webservice legacy** (compat PS8) pour update modules
- Routes custom exposées côté serveur PS

## Compatibilité

- PrestaShop 8.0 minimum (recommandé 9.x)
- PHP 8.1 minimum
- Multiboutique : oui (config par shop)

## Sécurité

- OAuth2 client credentials + secret
- HMAC signature sur webhooks sortants
- IP allowlist + rate limiting

## Roadmap

- [x] Sprint 0 — Spike (1 semaine)
- [ ] Sprint 2 — Full MVP (3 semaines)