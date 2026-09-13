# Plugin Jeedom SNCB/NMBS

Surveillance des trains belges pour navetteurs, à partir des données ouvertes
[iRail](https://docs.irail.be/).

Un équipement représente un trajet : une gare de départ, une gare d'arrivée, un
créneau horaire et les jours de la semaine concernés. Le plugin liste les trains
du jour et du prochain jour actif, puis vérifie leur état : retard, suppression,
changement de voie, perturbation du réseau. Chaque minute pendant le créneau
surveillé et l'heure qui le précède, toutes les quinze minutes en dehors — iRail
est gratuit, le plugin s'interdit d'en abuser.

## Ce que le plugin expose

- le prochain train du créneau : heure théorique, heure réelle, retard, quai,
  direction, durée, correspondances et compte à rebours avant le départ ;
- l'état global du trajet : nombre de trains, trains retardés, trains supprimés,
  retard maximum, message de perturbation ;
- une commande d'action de rafraîchissement et une commande d'acquittement.

Toutes ces valeurs sont des commandes info : elles déclenchent vos scénarios par
événement. Un trajet peut en plus appeler directement une commande d'action de
votre choix (notification, message, lampe) dès qu'un retard dépasse le seuil
configuré, qu'un train est supprimé ou que sa voie change.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, dépôt
`replicatorbe/jeedom-plugin-sncbnmbs`, branche `master` (stable) ou `beta`.

Aucune dépendance, aucune clé d'API : iRail est ouvert et gratuit.

## Documentation

- [Documentation](docs/fr_FR/index.md)
- [Changelog](docs/fr_FR/changelog.md)

## Licence

AGPL v3 — voir [LICENSE](LICENSE).

Les données proviennent d'iRail (opendata SNCB/NMBS), sous licence CC0.
