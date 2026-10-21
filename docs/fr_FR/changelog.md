# Journal des versions

## 0.1.1

- Nouvelles commandes **Dernière vérification**, **Erreur API** et **Dernière
  erreur** : quand l'API ORES ne répond plus, l'ancien état reste affiché mais
  n'est plus muet — « 0 panne » porte la date de la dernière lecture réussie
  et l'erreur reste levée jusqu'à la suivante, backoff compris.
- Pendant une erreur, l'état republié ne compte plus comme une
  communication : le timeout de l'équipement, posé à 60 minutes s'il était
  vide, signale une lecture bloquée.
- Page Santé : âge de la dernière lecture réussie et dernière erreur de
  chaque équipement.

## 0.1 — version initiale

- Surveillance des pannes et interruptions ORES pour une adresse précise
  (code postal + rue + numéro avec parité).
- Collecte via l'API Azure non documentée mais publique
  `/Breakdown`, filtrage côté client avec translittération et matching
  strict.
- Commandes exposées : état binaire, compteur, titre, type (panne /
  interruption), dates de début et de fin, retard, clients impactés, rue
  impactée, groupe électrogène de secours, URL, détails JSON, bouton
  rafraîchir.
- Alertes intégrées complètes : actions configurables à l'apparition
  d'une nouvelle panne (notification mobile, SMS, TTS, affichage
  matrix…), actions miroirs à la disparition, bouton Tester, mémoire
  par `businessId` pour éviter les re-déclenchements.
- Jetons : `#titre#`, `#type#`, `#debut#`, `#fin#`, `#retard#`,
  `#clients#`, `#rue#`, `#generateur#`, `#url#`, `#equipement#`, `#cp#`.
- Note explicite dans l'UI et la doc : le gaz n'est pas couvert, ORES ne
  publie pas d'API pour les coupures gaz.
- cron15 min, cache 5 min partagé entre équipements, backoff 1 h après échec HTTP.
