# Plugin Izypower pour Jeedom

Suivi de vos centrales photovoltaïques **Izypower** (Materfrance) dans Jeedom : puissance, énergie, onduleurs, compteurs — en lecture seule, via le cloud Izypower.

> ⚠️ Ce plugin n'est ni développé, ni affilié, ni soutenu par Materfrance / Izypower. Toutes les données transitent par leur cloud (aucun accès local à l'installation).

## Fonctionnalités

- **Synchronisation automatique** de toutes les centrales du compte Izypower, avec création automatique d'un équipement Jeedom par centrale, par onduleur et par compteur.
- **Rafraîchissement périodique** (toutes les 3 minutes via cron) des valeurs :
  - Puissances instantanées : production PV, réseau, consommation.
  - Énergie : consommation, consommation depuis PV, import/export réseau — sur les périodes jour / mois / année / total.
  - Infos de diagnostic centrale : capacité installée, nombre d'équipements, statut, dernière mise à jour.
  - Par onduleur : état en ligne, version logicielle, Wi-Fi (réseau, signal, IP), puissance par chaîne PV (PV1, PV2...).
  - Par compteur : tension, fréquence, énergie importée/exportée, puissance réseau instantanée.
- **Widgets dashboard dédiés** pour centrale, onduleur et compteur.
- Compatibilité avec les widgets génériques Jeedom (`POWER`, `CONSO_TOTAL`, `PRESENCE`) pour s'intégrer au tableau de bord énergie.
- Plugin **lecture seule** : aucune commande exécutable n'est proposée depuis Jeedom.

## Installation

1. Installer le plugin depuis le Market Jeedom (ou en manuel via ce dépôt).
2. Aller dans **Plugins > Izypower > Configuration**.
3. Renseigner l'identifiant (email) et le mot de passe de votre compte Izypower.
4. Cliquer sur **Synchroniser les centrales** : un équipement Jeedom est créé pour chaque centrale du compte, avec ses onduleurs et compteurs associés.

Les valeurs se rafraîchissent ensuite automatiquement toutes les 3 minutes. Un renommage manuel d'un équipement n'est jamais écrasé par les synchronisations suivantes.

## Prérequis

- Jeedom ≥ 4.2, sur OS 10 à 12.99.
- Un compte Izypower valide (mêmes identifiants que l'application/le portail officiel).
- Aucune dépendance système, aucun démon : le plugin communique directement en PHP (cURL) avec l'API cloud Izypower.

## Architecture

| Fichier | Rôle |
|---|---|
| [core/class/izypower.class.php](core/class/izypower.class.php) | `eqLogic` du plugin : cron, synchronisation des centrales/onduleurs/compteurs, création des commandes, rendu des widgets dashboard. |
| [core/class/IzypowerApi.class.php](core/class/IzypowerApi.class.php) | Client HTTP pour l'API cloud Izypower : authentification (JWT), récupération des centrales, rapports d'énergie, équipements, Wi-Fi. Reconstruit à partir de la logique de l'intégration Home Assistant `izypower_cloud`. |
| [core/ajax/izypower.ajax.php](core/ajax/izypower.ajax.php) | Point d'entrée AJAX (bouton "Synchroniser les centrales"). |
| [core/template/dashboard/](core/template/dashboard/) | Templates HTML des widgets (station / inverter / meter). |
| [plugin_info/configuration.php](plugin_info/configuration.php) | Page de configuration du plugin (identifiants, bouton de synchronisation). |

## Limitations connues

- Lecture seule : impossible de piloter une centrale/un onduleur depuis Jeedom.
- Fréquence de rafraîchissement limitée à celle du cloud Izypower (~3 minutes), pas de flux temps réel local.
- Le suivi batterie n'est pas intégré au plugin, faute de matériel disponible pour le développer et le tester.

## Licence

AGPL — voir [LICENSE](LICENSE).
