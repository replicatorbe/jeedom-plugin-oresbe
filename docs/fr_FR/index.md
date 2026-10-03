# Plugin ORES

Surveille les pannes et interruptions planifiées du réseau **ORES**
(Opérateur des Réseaux d'Electricité en Wallonie) pour une adresse précise,
et expose l'état en commandes Jeedom.

## À quoi ça sert

ORES publie en temps réel la carte des pannes et interruptions de son
réseau électrique via une API JSON Azure. Ce plugin interroge l'API toutes
les 15 min, filtre pour votre adresse exacte (code postal, rue, numéro
avec sa parité) et expose les détails en commandes exploitables en
scénario.

## Important : le gaz n'est pas couvert

ORES **ne publie pas d'API** ni de carte temps réel pour les coupures gaz.
Ce plugin surveille uniquement l'**électricité**. Pour une fuite ou odeur
de gaz, appelez le **0800/87.087** — numéro d'urgence 24h/24. C'est une
limitation côté distributeur, pas du plugin.

## Installation

1. **Plugins → Gestion des plugins → Ajouter → Github** : utilisateur
   `replicatorbe`, dépôt `jeedom-plugin-oresbe`, branche `beta` ou `master`.
2. Activer le plugin.
3. Pas de dépendance : tout est en PHP natif Jeedom.

## Configuration

### Paramètres du plugin

- **Délai d'attente des requêtes** : secondes avant d'abandonner un appel
  à l'API ORES (défaut 10).
- **Fréquence de rafraîchissement** : minutes entre deux lectures
  (défaut 15, minimum 15 — plancher imposé par `cron15`).

### Ajouter une adresse

1. Depuis la page du plugin, cliquer sur **Ajouter une adresse**.
2. Donner un nom à l'équipement (par exemple « ORES Maison »).
3. Saisir **code postal**, **nom de rue** (accents et casse indifférents)
   et **numéro** (ex. `3A`).
4. **Tester l'adresse** interroge l'API sans enregistrer : affiche s'il
   y a une panne active maintenant, ou si le code postal est reconnu.
5. **Sauvegarder** : les commandes sont créées, le premier état est
   stocké au prochain cron15.

## Commandes exposées

| Commande | Type | Contenu |
|---|---|---|
| **Panne en cours** | info / binary | 1 si une panne touche l'adresse, 0 sinon |
| **Nombre de pannes** | info / numeric | compteur (habituellement 0 ou 1) |
| **Titre** | info / string | « Panne électrique » ou « Interruption planifiée » |
| **Type** | info / string | `panne` ou `interruption` (clé brute pour scénarios) |
| **Date de début** | info / string | ISO 8601, ex. `2026-10-03T09:00:00+00:00` |
| **Date de fin prévue** | info / string | ISO 8601 |
| **En retard** | info / binary | 1 si ORES signale un retard sur la fin prévue |
| **Clients impactés** | info / numeric | nombre de clients coupés par la panne |
| **Rue impactée** | info / string | nom de la rue tel qu'ORES l'écrit (en MAJUSCULES) |
| **Groupe électrogène prévu** | info / binary | 1 si un générateur de secours est planifié |
| **Page ORES** | info / string | lien vers la page générique ores.be |
| **Détails (JSON)** | info / string | état complet pour scénarios avancés |
| **Rafraîchir** | action | force une relecture immédiate |

## Alertes intégrées

L'onglet **Alertes** permet d'être prévenu dès qu'une nouvelle panne
apparaît sans écrire de scénario. Chaque alerte est une liste d'actions —
notification mobile, SMS, message vocal, allumage lampe — jouées à
l'instant où ORES publie une panne touchant votre adresse. Un deuxième
groupe d'actions miroirs peut être configuré pour **la fin de la panne**
(ex. effacer un affichage matrix une fois le courant rétabli).

### Jetons disponibles dans le titre et le message

| Jeton | Contenu |
|---|---|
| `#titre#` | « Panne électrique » ou « Interruption planifiée » |
| `#type#` | `panne` ou `interruption` |
| `#debut#` | Date et heure de début, format FR : `sam 03/10/2026 09:00` |
| `#fin#` | Date et heure de fin prévue |
| `#retard#` | `oui` si ORES a signalé un retard, `non` sinon |
| `#clients#` | Nombre de clients impactés par la panne |
| `#rue#` | Rue impactée |
| `#generateur#` | `oui` si un groupe électrogène de secours est prévu, `non` sinon |
| `#url#` | Lien vers la page générique ores.be |
| `#equipement#` | Nom de l'équipement Jeedom |
| `#cp#` | Code postal surveillé |

Les jetons Jeedom restent utilisables par-dessus :
`#[Objet][Équipement][Commande]#`, `variable()`, `date()`, etc.

### Détection d'une « nouvelle panne »

Le plugin garde en mémoire la liste des `businessId` ORES déjà vus pour
cet équipement. Chaque passage du cron compare la liste courante à la
mémoire : les `businessId` qui n'y figuraient pas déclenchent les
alertes `start`. Un même incident ne re-déclenche jamais tant que son
identifiant est stable côté ORES.

**Premier cycle après installation** : la mémoire s'amorce sans alerter.
Les pannes déjà actives au moment de l'installation ne génèrent pas
d'alerte, sinon chaque mise en route provoquerait un pic de notifications
pour des situations déjà connues de l'utilisateur.

### Bouton Tester

Chaque alerte expose un bouton **Tester** qui joue les actions sur la
dernière panne connue (ou sur un incident fictif si aucune). Permet de
valider le canal — notification mobile, TTS, lampe — sans attendre une
vraie coupure.

## Fonctionnement de l'API

Le plugin utilise l'endpoint non documenté mais public
`https://ores-breakdownmapapi-prd.azurewebsites.net/Breakdown` qui renvoie
l'ensemble des pannes actives en Wallonie (~350 pannes = ~300 Ko).
Le filtrage par adresse se fait côté plugin :

1. Normalisation du nom de rue : translittération accents, uppercase,
   ponctuation remplacée par des espaces.
2. Égalité stricte après normalisation (pas de match partiel pour éviter
   d'alerter un voisin de la rue voisine).
3. Numéro dans l'un des `streetNumberRanges` publiés par ORES, en
   respectant la parité (`odd` / `even`).

## Note sur l'historisation

La commande **Détails (JSON)** peut dépasser 127 caractères (longueur de
la colonne `history.value` dans Jeedom). **Ne pas l'historiser** : SQL
tronquerait silencieusement les lignes passées cette limite. Les
commandes utiles pour l'historisation sont `Panne en cours`,
`Nombre de pannes`, `Clients impactés`.

## Limites connues

- **API non documentée** : si ORES refond son architecture, le plugin
  devra être adapté. L'endpoint actuel est stable depuis plusieurs
  années, mais rien ne le garantit.
- **Pas de filtrage côté serveur** : l'API renvoie l'ensemble des pannes
  à chaque appel, le plugin filtre ensuite. C'est l'origine du cran
  minimum à 15 min entre les lectures.
- **Électricité seulement** : voir note en haut de ce document.
- **Pas de fiche panne publique** : la commande `url` pointe vers la page
  générique `/pannes-et-interruptions/pannes-et-interruptions-en-cours`,
  pas vers une page spécifique à l'incident.
