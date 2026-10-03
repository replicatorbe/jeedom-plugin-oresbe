# jeedom-plugin-oresbe

Plugin Jeedom qui surveille les pannes et interruptions planifiées du
réseau électrique **ORES** (Opérateur des Réseaux d'Electricité en
Wallonie) pour une adresse précise (code postal + rue + numéro).

## Électricité uniquement — le gaz n'est pas couvert

ORES ne publie pas d'API ni de carte temps réel pour les coupures gaz.
Ce plugin surveille uniquement l'**électricité**. Pour une fuite ou
odeur de gaz, appelez le **0800/87.087** — numéro d'urgence 24h/24.

## En bref

Pas d'API publique documentée côté ORES, mais l'endpoint Azure
`https://ores-breakdownmapapi-prd.azurewebsites.net/Breakdown` renvoie
l'ensemble des pannes actives en Wallonie en JSON structuré. Le plugin
l'interroge toutes les 15 min et filtre côté client pour l'adresse
exacte configurée (match strict après normalisation du nom de rue,
parité du numéro honorée contre les `streetNumberRanges` publiés).

Un équipement = une adresse. Les commandes exposées (état binaire,
compteur, titre, type panne/interruption, dates, retard, clients
impactés, rue, groupe électrogène de secours, URL, JSON) sont
exploitables en scénario. Les **alertes intégrées** permettent de
notifier à l'apparition et à la disparition d'une panne sans écrire de
scénario : notification mobile, SMS, TTS, affichage matrix WLED, lampe —
tout ce que le sélecteur d'actions Jeedom propose.

Voir [`docs/fr_FR/index.md`](docs/fr_FR/index.md) pour la documentation
complète.
