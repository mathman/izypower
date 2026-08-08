# Plugin Izypower pour Jeedom

## ⚠️ Avertissement

Ce plugin est une reconstruction **non officielle**, inspirée de l'intégration Home Assistant
[`izypower_cloud`](https://github.com/StefanPlizga/izypower_cloud) (StefanPlizga). Il n'est ni
développé ni maintenu par Materfrance / Izypower.

Toutes les données transitent par le **cloud Izypower** — il n'existe pas d'API locale ni de MQTT
pour les onduleurs Izypower à ce jour. Le rafraîchissement n'est donc jamais plus rapide que
3 minutes (c'est la fréquence à laquelle le cloud Izypower met lui-même ses données à jour, comme
dans l'application officielle).

Le plugin est **lecture seule** : aucune action (pilotage, réglage) n'est possible depuis Jeedom.

## Prérequis

- Un compte Izypower Cloud valide (le même que celui utilisé dans l'application mobile)
- Un ou plusieurs onduleurs/centrales Izypower déjà appairés via l'app officielle
- Aucune dépendance système particulière : le plugin communique en PHP (cURL) directement avec le
  cloud Izypower

## Installation

1. Installer le plugin depuis le Market Jeedom (ou en manuel via le zip)
2. Activer le plugin
3. Aller dans **Plugins > Énergie > Izypower > Configuration**
4. Renseigner l'identifiant (email) et le mot de passe du compte Izypower
5. Cliquer sur **Synchroniser les centrales**

Un équipement Jeedom est créé automatiquement pour chaque centrale détectée sur le compte, ainsi
qu'un équipement par onduleur et par compteur qui lui sont rattachés. Un renommage manuel d'un
équipement n'est jamais écrasé par les synchronisations suivantes.

## Capteurs disponibles

### Équipement "Centrale"

**Puissance instantanée (W)**
- Puissance Production PV
- Puissance Réseau
- Puissance Consommation

**Énergie (kWh), par période Jour / Mois / Année / Total**
- Consommation
- Consommation depuis PV
- Import Réseau
- Export Réseau

**Diagnostic**
- Capacité Installée (W)
- Nombre d'Équipements
- Statut
- Dernière Mise à Jour

### Équipement "Onduleur"

- État en ligne
- Version Logicielle
- Signal Wi-Fi (dBm), Réseau Wi-Fi, Adresse IP
- Puissance par chaîne PV, détectée dynamiquement (PV1, PV2, ...)

### Équipement "Compteur"

- État en ligne
- Version Logicielle
- Signal Wi-Fi (dBm), Réseau Wi-Fi, Adresse IP
- Tension Réseau (V)
- Fréquence Réseau (Hz)
- Énergie Importée / Exportée (kWh)
- Puissance Réseau (W)

## Widgets dashboard

Chaque type d'équipement (centrale / onduleur / compteur) dispose de son propre widget dédié sur
le dashboard Jeedom, avec un rendu adapté (ratio d'autoconsommation et sens du flux réseau pour une
centrale, état en ligne et détail par chaîne PV pour un onduleur, etc.). Les commandes principales
sont aussi compatibles avec les widgets génériques Jeedom (`POWER`, `CONSO_TOTAL`, `PRESENCE`) pour
s'intégrer au tableau de bord énergie.

## Limites connues

- Pas de suivi batterie : non intégré, faute de matériel disponible pour le développer et le
  tester.
- Pas de contrôle à distance (lecture seule) : aucune commande exécutable n'est proposée depuis
  Jeedom.
- Rafraîchissement fixe à 3 minutes (aligné sur le cron du plugin et sur la fréquence de mise à
  jour du cloud Izypower).

## Dépannage

- **"Identifiants Izypower non configurés"** : vérifier la configuration du plugin
- **Capteurs à 0 ou vides après synchro** : vérifier dans les logs du plugin
  (`Plugins > Izypower > Logs`) si l'API renvoie une erreur 401/403 (identifiants invalides)
  ou 5xx (cloud Izypower temporairement indisponible)
- **Aucune centrale trouvée** : vérifier que le compte utilisé est bien celui associé aux
  onduleurs dans l'application mobile IZYPOWER Cloud
- **Un onduleur ou un compteur n'apparaît pas** : relancer une synchronisation manuelle depuis la
  configuration du plugin ; ces équipements sont créés/mis à jour en cascade depuis leur centrale
