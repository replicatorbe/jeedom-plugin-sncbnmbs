# Changelog du plugin SNCB/NMBS

## 1.3

**Corrections**

- Sur un axe très fréquenté (Bruxelles-Central → Bruxelles-Nord, par exemple),
  le plugin annonçait « Aucun train dans le créneau » en pleine heure de
  pointe : iRail ne rend que six trains par demande, et les six de la
  demi-heure de recul étaient déjà partis. Le plugin lit désormais les pages
  suivantes, quatre au plus, jusqu'à trouver les trains encore à prendre.
- Les appels à iRail sont espacés d'au moins 0,35 seconde, pour rester sous la
  limite de trois requêtes par seconde même avec plusieurs trajets.
- Un trajet dont la gare de départ et la gare d'arrivée sont identiques est
  signalé au centre de messages dès l'enregistrement. Avant, il était ignoré
  sans rien dire.

**Documentation**

- Installation depuis le Market, et mention claire : plugin non officiel, sans
  lien avec la SNCB/NMBS.
- Corrigé plusieurs passages qui ne correspondaient plus au plugin : le train de
  repli, le nombre de commandes, les compteurs limités au créneau du prochain
  train, la coche verte de la tuile, le seuil rouge qui suit celui du trajet,
  les perturbations du réseau qui n'allument pas « Trajet perturbé ».
- Deux comportements désormais expliqués : les correspondances ne sont pas
  contrôlées, et un train du lendemain supprimé est signalé dès la veille.

## 1.2

**Partir à temps**

- Nouveau réglage « Temps jusqu'à la gare » et nouvelle commande « Partir dans »
  (`leave_countdown`) : les minutes avant de devoir quitter la maison, retard
  compris. Si le prochain train est supprimé, le calcul porte sur le train de
  repli.

**Corrections**

- Surveillance à la minute décochée : le trajet n'était plus relu qu'une fois
  par heure, même pendant le créneau. Il l'est de nouveau au quart d'heure,
  comme l'annonce l'aide.
- Les pannes passagères d'iRail (HTTP 5xx, délai dépassé) ne vont plus au
  centre de messages qu'à partir du troisième échec consécutif ; avant, elles
  sont notées en avertissement dans le journal.
- « Trajet perturbé », « Trains du créneau », « Trains en retard », « Trains
  supprimés » et « Retard maximum » ne comptent plus que les trains du créneau
  en cours encore à venir : un train parti en retard ou un train du lendemain
  ne maintient plus le trajet « perturbé ».
- Le seuil de couleur rouge de « Retard du prochain train » suit désormais le
  seuil du trajet quand il est modifié, sauf s'il a été personnalisé.

## 1.1

**Le train de repli**

- Quatre commandes exposent le train d'après : `next2_summary`, `next2_time`,
  `next2_vehicle`, `next2_countdown`. Le plugin le connaissait déjà, il le
  gardait pour lui.
- La notification le propose d'elle-même : « IC 1706 de 07:38 supprimé — repli :
  P 7802 à 07:54, voie 1 ». Le repli ne franchit jamais le jour.

**Moins de sollicitation d'iRail, sans perdre le temps réel**

- Un trajet dont la gare est introuvable relançait 1 200 requêtes par jour,
  indéfiniment. Un recul après échec y met fin, doublant l'attente à chaque
  nouvel essai.
- Ce recul est plafonné à deux minutes pendant la surveillance : un train peut
  y être supprimé à tout instant, et une panne passagère du service ne doit pas
  nous rendre aveugles au moment où le plugin sert.
- Les perturbations du réseau ne sont plus relues que pendant la surveillance,
  et leur cache passe de trois à dix minutes.
- Hors créneau surveillé, une lecture par heure au lieu d'une par quart d'heure,
  et aucune entre 1 h et 5 h du matin.
- Un créneau distant de plus de six heures n'est relu que toutes les trois
  heures.
- La cadence à la minute pendant le créneau n'a pas bougé.

**Corrections**

- Créer un équipement était impossible : une propriété d'objet sans souligné
  était prise pour une colonne de la base.
- Enregistrer un trajet ne gardait rien : une méthode `setCmd()` privée entrait
  en collision avec le mécanisme d'enregistrement du cœur.
- Un trajet neuf naissait désactivé et invisible, donc ignoré par le cron.
- Une recherche de gare sans résultat effaçait la gare déjà enregistrée.
- Les jours et les catégories du trajet précédent restaient cochés sur le
  suivant.
- iRail rend parfois deux fois le même départ : le train était compté et
  affiché en double.
- Un créneau de nuit disparaissait au passage de minuit ; un créneau dont le
  début égale la fin durait vingt-quatre heures.
- En plein créneau, un train prévu avant l'heure courante mais retardé n'était
  plus proposé.
- Une voie qui change sur un train déjà en retard n'était jamais signalée ; un
  retard qui diminuait déclenchait une alerte.
- Le texte venu d'iRail ne peut plus porter de chevrons jusqu'aux widgets.
- Le nombre de trains par créneau est ramené à six, la limite réelle d'iRail.

## 1.0

- Première version.
- Équipement « trajet » : gare de départ, gare d'arrivée, créneau horaire, jours actifs.
- Trains du jour et du lendemain, issus de l'API iRail.
- Contrôle chaque minute des retards, suppressions, changements de quai et perturbations.
- Commandes info du prochain train et de l'état global du trajet.
- Commandes d'action « Rafraîchir » et « Acquitter ».
- Déclenchement automatique d'une commande d'action Jeedom au-delà d'un seuil de retard.
