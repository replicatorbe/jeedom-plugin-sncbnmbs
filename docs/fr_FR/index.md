# Plugin SNCB/NMBS

Ce plugin surveille les trains belges pour les navetteurs, à partir des données
ouvertes d'iRail. Un équipement représente un trajet : une gare de départ, une
gare d'arrivée, un créneau horaire et les jours de la semaine concernés. Le
plugin liste les trains du créneau, puis vérifie leur état pendant le créneau
surveillé — retard, suppression, changement de voie, perturbation du réseau — et
expose le tout en commandes Jeedom.

Il ne remplace pas un planificateur d'itinéraire. Il ne cherche pas le meilleur
chemin, ne réserve rien, n'achète pas de billet et ne vous propose pas
d'alternative quand votre train est supprimé. Il répond à une seule question,
celle qu'on se pose la tasse de café à la main : est-ce que je pars maintenant,
et sur quelle voie ?

Aucune dépendance, aucun démon, aucune clé d'API. Le cron du coeur passe chaque
minute ; chaque trajet décide seul s'il interroge iRail ou s'il se contente de
recalculer ses commandes.

## Installation

1. Plugins → Gestion des plugins → Ajouter → Github.
2. Renseignez le dépôt (voir le README), branche `master`.
3. Activez le plugin.

Aucune dépendance n'est à installer.

## Créer un trajet

Plugins → Organisation → SNCB/NMBS → **Ajouter un trajet**. La page du trajet a
quatre onglets : Équipement, Trains, Réseau et Commandes. Toute la configuration
tient dans le premier.

Bloc **Trajet** :

| Champ | Valeur |
|---|---|
| Gare de départ | tapez au moins deux lettres, cliquez sur la loupe, choisissez dans la liste déroulante |
| Gare d'arrivée | idem |
| Inverser le trajet | échange les deux gares |
| Trajet retenu | rappelle, en clair, les deux gares telles qu'elles seront enregistrées |

La recherche ignore les accents et les tirets : `bruxelles midi` trouve
« Bruxelles-Midi ». Les deux gares doivent être **choisies dans la liste**, pas
seulement tapées : le plugin travaille avec les identifiants d'iRail, pas avec
des noms.

Bloc **Créneau** :

| Champ | Valeur |
|---|---|
| Heure de début, Fin | les heures entre lesquelles vous prenez habituellement le train. `07:00` → `09:00` par défaut |
| Jours | les cases Lu à Di |

Bloc **Surveillance** :

| Champ | Valeur |
|---|---|
| Seuil de retard | à partir de combien de minutes un train est « en retard ». 5 par défaut |
| Surveillance à la minute | décochée, le trajet n'est plus relu qu'au quart d'heure pendant le créneau et son avance, une fois par heure en dehors |
| Minutes d'avance | combien de minutes avant le créneau la surveillance à la minute démarre. 60 par défaut, 240 au maximum |
| Temps jusqu'à la gare | minutes pour rejoindre la gare de départ, de 0 à 120. Sert au calcul de « Partir dans ». 0 par défaut |
| Nombre de trains | combien de départs suivre par créneau, de 1 à 6. 6 par défaut |
| Commande à déclencher | la commande d'action Jeedom appelée dès qu'un problème apparaît. La croix la retire |

Deux boutons complètent ce bloc : **Rafraîchir maintenant**, qui interroge iRail
sans attendre le cron — inutile juste après une sauvegarde, qui le fait déjà —
et **Acquitter l'alerte**. Le résultat s'affiche juste en dessous.

> Une gare de départ identique à la gare d'arrivée n'est pas seulement absurde :
> iRail part en timeout et répond au bout de trente secondes. Le plugin refuse
> donc ce trajet avant tout appel, et le signale au centre de messages.

Un trajet représente **un sens de circulation**. Le retour se crée en second
équipement : dupliquez le trajet du matin, cliquez sur **Inverser le trajet**,
décalez le créneau. Rien n'oblige l'aller et le retour à partager le seuil ou les
jours.

Les trois autres onglets ne se configurent pas :

- **Trains** liste les trains retenus — le jour, le départ, le retard, le train,
  la direction, la voie, l'arrivée, la durée, les correspondances, l'occupation —
  avec l'heure de la dernière vérification et l'état de la surveillance. Il lit ce que
  le plugin a déjà récupéré : l'ouvrir n'interroge pas iRail et ne consomme rien.
- **Réseau** montre les perturbations publiées pour l'ensemble du réseau, votre
  trajet ou non, avec un bouton Actualiser. Celles qui citent une de vos deux
  gares sont reprises dans l'onglet Trains.
- **Commandes** est le tableau habituel des commandes de l'équipement.

## Le créneau et les jours

Le créneau borne les deux extrémités : iRail rend les trains qui suivent l'heure
demandée, sans jamais s'arrêter de lui-même. Sans borne haute, un créneau de 7 h
à 9 h remonterait aussi le train de 11 h, et vous réveillerait pour un retard
qui ne vous concerne pas.

Les jours actifs se cochent de lundi à dimanche. Un trajet neuf est créé avec
**lundi à vendredi cochés** : c'est le trajet de bureau, et cela évite qu'un
nouveau trajet n'interroge le réseau le dimanche. Décochez-les tous et la règle
s'inverse : **aucune case cochée vaut tous les jours**, pour un trajet sans
horaire fixe.

Le plugin suit deux créneaux à la fois : celui d'aujourd'hui et celui du
prochain jour actif. Une fois le créneau du jour terminé, l'affichage bascule
tout seul sur le suivant — il n'y a plus rien à surveiller aujourd'hui. Les
trains du lendemain ne sont relus que toutes les trente minutes : un horaire de
demain matin ne bouge pas à la minute, et chaque lecture coûte un appel.

> « Demain » veut dire le prochain jour actif, pas le lendemain du calendrier.
> Un trajet coché du lundi au vendredi affiche le lundi dès le vendredi soir.

Un créneau dont l'heure de fin précède l'heure de début est compris comme un
créneau de nuit : `22:00` → `01:00` se termine le lendemain matin, et reste suivi
après minuit — c'est très exactement l'heure à laquelle on le consulte. Un créneau
dont le début et la fin sont identiques dure une heure, et non vingt-quatre : on a
voulu un instant, pas une journée entière.

## La surveillance à la minute

Dans la fenêtre de surveillance, le plugin interroge iRail **chaque minute**. En
dehors, il se contente d'un appel **par heure**, et d'aucun entre 1 h et 5 h du
matin : la SNCB ne fait plus guère circuler de trains, et personne ne regarde.

La fenêtre de surveillance, c'est le créneau élargi en amont des « Minutes
d'avance ». Avec un créneau de 7 h à 9 h et 60 minutes en amont,
le plugin surveille de 6 h à 9 h. L'amont compte autant que le créneau : un
retard appris à 6 h 20 vous laisse le temps de prendre le train suivant ou de
partir en voiture ; le même retard appris sur le quai ne sert plus à rien.

En dehors de la fenêtre, les commandes continuent d'être recalculées chaque
minute, sans appel réseau : le compte à rebours et le prochain train changent
avec l'heure qui passe, pas avec les réponses d'iRail.

Cette politique n'est pas une limitation arbitraire. iRail est un service
gratuit, communautaire, sans clé d'API et sans facture : surveiller un trajet de
7 h du matin vingt-quatre heures sur vingt-quatre ferait 1440 requêtes par jour
pour en utiliser 120. Le plugin s'impose donc ses propres bornes :

- le nombre de trains suivis est plafonné à 6. Ce n'est pas seulement de la
  sobriété : iRail ignore silencieusement toute demande supérieure et rend six
  connexions de toute façon ;
- le délai de surveillance en amont est plafonné à 240 minutes ;
- la commande « Rafraîchir » ne relit rien si la dernière lecture a moins de
  20 secondes, même appelée en boucle par un scénario ;
- enregistrer l'équipement ne relit iRail que si le trajet a réellement changé —
  les gares, le créneau, les jours ou le nombre de trains. Renommer l'équipement
  ou changer son icône ne coûte aucun appel ;
- les perturbations du réseau sont mutualisées entre tous les trajets et
  relues toutes les dix minutes, pendant la surveillance seulement, quel que
  soit le nombre d'équipements.

La case « Surveillance à la minute » espace cette surveillance sans supprimer le
trajet : pendant le créneau et son avance, le trajet n'est plus relu qu'au
**quart d'heure** — nuit comprise pour un créneau de nuit —, et une fois par
heure en dehors. Les alertes partent toujours, avec jusqu'à quinze minutes de
retard ; les horaires restent consultables.

Après un échec, le trajet **attend avant de réessayer**, et l'attente double à
chaque nouvel échec : une minute, deux, quatre, jusqu'à une heure. **Jamais plus
de deux minutes pendant la surveillance**, cependant : un train peut y être
supprimé à tout instant, et un service qui hoquette ne doit pas nous rendre
aveugles au moment précis où le plugin sert à quelque chose. Un service qui
retombe en marche est donc retrouvé en une minute, mais une gare définitivement
fausse ne coûte plus que vingt-quatre requêtes par jour au lieu de mille deux
cents. Le premier succès remet le compteur à zéro.

Quand iRail ne répond pas, rien n'est effacé. Les derniers horaires connus
restent affichés, la commande « Dernière vérification » garde l'heure de la
dernière lecture réussie. Une gare inconnue ou une demande refusée est signalée
aussitôt au centre de messages. Une panne passagère d'iRail — service saturé
(erreur 5xx), délai dépassé, réponse illisible — n'y apparaît qu'à partir du
**troisième échec consécutif** : iRail rend certains matins une dizaine d'erreurs
504 isolées, qui se résorbent seules la minute suivante. D'ici là, elle n'est
notée qu'en avertissement dans le journal du plugin.

## Agir sur un retard

Toutes les commandes info déclenchent vos scénarios sur événement. Mais le
plugin sait aussi agir de lui-même : la **commande à déclencher**, choisie dans
le bloc Surveillance, est une commande d'action de votre choix — une
notification, un message parlé, une lampe — appelée dès qu'un problème est
constaté sur un train qui n'est pas encore parti.

Elle est appelée avec deux paramètres, ceux qu'attendent les commandes de
message de Jeedom :

| Paramètre | Contenu |
|---|---|
| `title` | `Train — <nom de l'équipement>` |
| `message` | le trajet, puis les problèmes constatés, au plus trois |

Trois situations, et trois seulement, comptent pour un problème :

- une suppression, au départ ou à l'arrivée ;
- un retard au départ égal ou supérieur au seuil ;
- un changement de voie, quand iRail signale que la voie n'est pas l'habituelle.

Les alertes iRail rattachées aux trains et les perturbations du réseau
**n'appellent jamais cette commande**. Elles alimentent la commande « Message de
perturbation » et peuvent allumer « Trajet perturbé », rien de plus : ce sont des
textes libres, souvent des travaux ou des informations commerciales, qu'on lit
mais qui ne justifient pas de réveiller quelqu'un.

Un train déjà parti n'est plus signalé : continuer à alerter dessus ne ferait que
retarder l'alerte sur le suivant.

**Une même situation n'est signalée qu'une fois.** Sans cette mémoire, un train
retardé de vingt minutes enverrait vingt notifications, une par passage du cron.
Une exception : un retard qui s'aggrave d'une tranche de dix minutes est
re-signalé. Un train annoncé à +5 puis passé à +25 mérite une seconde alerte,
parce que vous avez peut-être déjà décidé de partir sur la foi de la première.

La commande d'action **Acquitter l'alerte**, et le bouton du même nom sur la page
du trajet, mémorisent **les trains actuellement en défaut**. Ceux-là ne
déclenchent plus rien, même si leur retard s'aggrave, même s'ils passent en
supprimés. Acquitter dit « j'ai vu ce retard-là », pas « ne me préviens
plus de rien » :

- un problème sur **un autre** train du créneau alerte toujours ;
- l'acquittement disparaît avec le train. Une fois celui-ci parti, il quitte le
  tableau et son acquittement avec lui : le lendemain, le même IC alerte de
  nouveau ;
- le bouton annonce combien de trains ont été acquittés, ou qu'il n'y avait
  aucune alerte en cours.

## Le train de repli

Quand le plugin vous réveille pour vous dire que votre train est supprimé, il
connaît déjà le suivant : il est dans la même lecture. Quatre commandes
l'exposent, et la notification le propose d'elle-même :

> Soignies → Bruxelles-Central — IC 1706 de 07:38 supprimé — repli : P 7802 à
> 07:54, voie 1

Le repli est le premier train **du même jour** qui part après le prochain, qui
n'est ni supprimé ni déjà parti. Deux limites à connaître :

- il n'y a pas de repli au-delà du dernier train du créneau. Élargissez la
  fenêtre si vous voulez qu'on vous en propose un après votre dernier train
  habituel ;
- le repli ne franchit jamais le jour. À 8 h 50 sur un créneau qui finit à 9 h,
  « Repli dans » vaut `-1` : vous proposer le premier train de demain matin
  n'aiderait personne.

## Commandes disponibles

Vingt-sept commandes, dont quatre visibles par défaut — trois tuiles et un
bouton.

| Commande | Type | Description |
|---|---|---|
| Prochain train (`summary`) | info / string | la ligne de résumé : `IC 2137 · 07:42 · +5 min · voie 3`, ou `Aucun train dans le créneau` |
| Retard du prochain train (`next_delay`) | info / numeric, min | historisée. `0` quand le train est à l'heure |
| Trajet perturbé (`disturbed`) | info / binary | `1` dès qu'un train du créneau en cours, pas encore parti, est supprimé, qu'une alerte est rattachée au prochain train, que sa voie change, ou qu'un retard atteint le seuil |
| Départ prévu (`next_time`) | info / string | l'heure théorique, `HH:MM` |
| Départ réel (`next_real`) | info / string | l'heure théorique augmentée du retard |
| Départ dans (`next_countdown`) | info / numeric, min | minutes avant le départ réel, jamais négatif. `-1` uniquement quand il n'y a aucun train |
| Partir dans (`leave_countdown`) | info / numeric, min | minutes avant de devoir quitter la maison : départ réel moins le temps jusqu'à la gare, jamais négatif (`0` = partez maintenant). Si le prochain train est supprimé, calculé sur le train de repli. `-1` quand il n'y a aucun train à prendre |
| Train (`next_vehicle`) | info / string | `IC 2137`, `S13424`... |
| Direction (`next_direction`) | info / string | la destination affichée du train, pas votre gare d'arrivée |
| Voie (`next_platform`) | info / string | vide quand iRail ne l'a pas encore publiée |
| Changement de voie (`next_platform_changed`) | info / binary | `1` quand la voie n'est pas l'habituelle |
| Prochain train supprimé (`next_canceled`) | info / binary | suppression au départ ou à l'arrivée |
| Arrivée réelle (`next_arrival`) | info / string | l'heure d'arrivée, retard d'arrivée compris |
| Durée du trajet (`next_duration`) | info / numeric, min | |
| Correspondances (`next_transfers`) | info / numeric | `0` pour un train direct |
| Occupation (`next_occupancy`) | info / string | `Faible`, `Moyenne`, `Forte`, ou vide |
| Train de repli (`next2_summary`) | info / string | la même ligne de résumé, pour le train d'après |
| Départ du repli (`next2_time`) | info / string | son heure théorique, `HH:MM` |
| Train de repli (numéro) (`next2_vehicle`) | info / string | `P 7800`, `IC 3706`… |
| Repli dans (`next2_countdown`) | info / numeric, min | minutes avant son départ réel. `-1` quand il n'y a pas de repli |
| Trains du créneau (`trains_count`) | info / numeric | nombre de trains du créneau du prochain train pas encore partis. Les trains déjà partis et ceux du créneau suivant ne sont pas comptés, pas plus que dans les trois commandes suivantes ; `0` quand plus aucun train n'est attendu |
| Trains en retard (`trains_delayed`) | info / numeric | historisée. Tout retard, même d'une minute |
| Trains supprimés (`trains_canceled`) | info / numeric | historisée |
| Retard maximum (`delay_max`) | info / numeric, min | le pire retard parmi les trains du créneau pas encore partis |
| Message de perturbation (`alert_message`) | info / string | les alertes iRail des trains du créneau pas encore partis et les perturbations du réseau, au plus trois, séparées par des tirets |
| Dernière vérification (`last_update`) | info / string | l'heure de la dernière lecture **réussie**, `JJ/MM/AAAA HH:MM` |
| Rafraîchir (`refresh`) | action | force une lecture d'iRail, pas plus d'une toutes les 20 secondes |
| Acquitter l'alerte (`acknowledge`) | action | fait taire les alertes des trains actuellement en défaut ; les autres restent surveillés |

Quelques précisions qui évitent des scénarios faux :

- « Trains en retard » compte tout retard, même d'une minute ; c'est « Retard
  maximum » et « Trajet perturbé » qui tiennent compte de votre seuil ;
- le prochain train est le premier qui n'est pas encore parti, avec une minute
  de battement après son heure réelle : un train qu'on vient de rater n'est plus
  le prochain ;
- « Départ dans » ne descend jamais sous `0` tant qu'un train est connu, et vaut
  `-1` quand il n'y en a aucun. Testez sur `>= 0` avant de comparer à une durée ;
- « Trains du créneau », « Trains en retard », « Trains supprimés » et « Retard
  maximum » portent sur **les deux créneaux connus** : celui d'aujourd'hui et
  celui du prochain jour actif. Un train de demain matin annoncé à +30 gonfle
  donc ces compteurs ce soir, et allume « Trajet perturbé » alors que plus rien
  ne circule. Pour ne parler que du train qui vous concerne, servez-vous des
  commandes du prochain train ;
- « Occupation » est très souvent vide. iRail la publie à partir des retours des
  voyageurs de l'application SNCB : la plupart des trains n'en ont aucun. Un
  scénario ne doit pas dépendre de cette valeur.

## Sur le dashboard

Quatre commandes sont visibles par défaut : les tuiles **Prochain train**,
**Retard du prochain train** et **Trajet perturbé**, et le bouton **Rafraîchir**.
Les autres existent pour les scénarios et les graphiques, et resteraient sans cela
empilées en une colonne de vingt tuiles pour un seul trajet. Pour en afficher
une autre, rendez-la visible depuis l'onglet Commandes.

La tuile « Prochain train » est un widget du plugin. Elle montre un pictogramme
de train, le nom du train et son heure de départ en gros caractères, puis une
étiquette de retard et une étiquette de voie. L'étiquette de retard est verte et
dit « sans retard » quand tout va bien, orange et dit `+7 min` en cas de retard,
rouge et dit `SUPPRIMÉ` quand le train ne partira pas. L'heure est alors barrée,
et le pictogramme prend la même couleur : la tuile reste lisible en noir et
blanc, ou pour qui ne distingue pas le rouge. Quand il n'y a rien à afficher, la
tuile reprend la phrase telle quelle : `Aucun train dans le créneau`.

Trois réglages facultatifs, dans la configuration de la commande, onglet
Affichage, bloc « Paramètres optionnels widget » :

| Paramètre | Valeur | Effet |
|---|---|---|
| `time` | `duration` ou `date` | affiche l'ancienneté de la valeur sous la tuile |
| `platform` | `0` | masque l'étiquette de voie |
| `timeonly` | `1` | n'affiche que l'heure, sans le nom du train |

« Retard du prochain train » reçoit deux seuils d'alerte à la création de
l'équipement : orange dès la première minute de retard, rouge à partir de votre
seuil. La couleur de la tuile et la notification disent ainsi la même chose. Ces
seuils ne sont posés qu'une fois : si vous les modifiez, le plugin ne les touche
plus.

Les commandes binaires du plugin — « Trajet perturbé », « Changement de voie »,
« Prochain train supprimé » — utilisent un widget qui montre un triangle rouge
quand c'est vrai, une coche verte sinon. Un trajet sans problème doit se lire
d'un coup d'oeil, sans avoir à distinguer un `0` d'un `1`.

## Utilisation dans un scénario

Les exemples qui suivent sont du pseudo-code : ils montrent le déclencheur, la
condition et l'idée de l'action, à traduire dans le bloc scénario de votre choix.
`message::notification` y désigne la commande d'action de votre outil de
notification — celle-là même que vous choisiriez comme commande à déclencher.

Être prévenu d'un retard sérieux, sur événement :

```
Déclencheur : #[Maison][Train du matin][Retard du prochain train]#
Si : #[Maison][Train du matin][Retard du prochain train]# >= 10
Alors : message::notification avec
        "Train " + #[Maison][Train du matin][Train]# + " retardé de "
        + #[Maison][Train du matin][Retard du prochain train]# + " min"
```

Savoir tout de suite qu'un train ne partira pas :

```
Déclencheur : #[Maison][Train du matin][Prochain train supprimé]#
Si : #[Maison][Train du matin][Prochain train supprimé]# == 1
Alors : message::notification avec
        "Supprimé : " + #[Maison][Train du matin][Train]#
        + " de " + #[Maison][Train du matin][Départ prévu]#
```

> « Départ prévu » est l'heure du prochain train, supprimé compris : un train
> supprimé reste le prochain tant qu'il n'a pas passé son heure. Ces commandes
> décrivent ce train-là, pas celui d'après.

Annoncer la voie au moment de sortir, à 7 h 10 :

```
Déclencheur : programmation, 10 7 * * 1-5
Alors : message::notification avec
        #[Maison][Train du matin][Prochain train]#
```

Allumer une lampe rouge tant que le trajet est perturbé :

```
Déclencheur : #[Maison][Train du matin][Trajet perturbé]#
Si : #[Maison][Train du matin][Trajet perturbé]# == 1
Alors : #[Salon][Lampe][Rouge]#
Sinon : #[Salon][Lampe][Off]#
```

Ne rien annoncer si le départ est encore loin :

```
Si : #[Maison][Train du matin][Départ dans]# >= 0
     ET #[Maison][Train du matin][Départ dans]# <= 20
```

## Configuration du plugin

Plugins → Organisation → SNCB/NMBS → **Configuration**, bloc « Service iRail ».

| Réglage | Rôle |
|---|---|
| Délai d'attente des requêtes | secondes avant d'abandonner un appel iRail. 8 par défaut, 3 au minimum. Le trajet étant relu chaque minute pendant la surveillance, une valeur haute ferait attendre le cron de toute la box pour un horaire qui sera repris une minute plus tard |
| Langue des libellés | langue dans laquelle iRail rend les noms de gares, les directions et les perturbations : français, néerlandais, allemand ou anglais. Par défaut, celle de Jeedom |

La langue mérite un mot en Belgique : iRail renvoie les noms de gares dans la
langue demandée, et une gare bruxelloise n'a pas le même nom en français et en
néerlandais. Changer ce réglage change donc les noms affichés dans les listes,
mais pas les identifiants déjà enregistrés dans vos trajets.

## Les données

Tout vient d'[iRail](https://docs.irail.be/), qui republie les données ouvertes
de la SNCB/NMBS : horaires théoriques, temps réel, suppressions, voies,
perturbations du réseau. Les données sont sous licence CC0.

Il n'y a **aucune clé d'API à demander** et aucun compte à créer. iRail demande
en revanche que chaque client s'identifie par son User-Agent : le plugin le fait,
avec son nom, sa version et l'adresse de son dépôt. C'est la contrepartie d'un
service gratuit, hébergé par une association.

C'est aussi la raison de toute la politique d'appels décrite plus haut : la
surveillance à la minute réservée à la fenêtre utile, une lecture par heure le reste
du temps, les perturbations mutualisées, la liste des gares gardée une semaine.
Un plugin qui appellerait sans retenue ne se ferait pas seulement bloquer : il
dégraderait le service pour tout le monde.

> Le rapprochement des perturbations du réseau se fait sur les **noms** des deux
> gares du trajet, cherchés dans le titre et la description de la perturbation.
> iRail ne publie pas la liste des gares concernées : c'est tout ce que la source
> permet. Le nom doit être trouvé entier, entre deux limites de mot — « Mol » ne
> s'accroche donc plus à « Molenbeek » — et les noms de moins de quatre lettres
> sont ignorés, faute de quoi « Ans » se reconnaîtrait dans « dans ». Il reste
> qu'une perturbation « Malines - Termonde » remonte sur tous les trajets citant
> l'une de ces deux gares, même sans rapport avec la portion coupée, et qu'une
> gare au nom trop court ne remonte aucune perturbation. Le plugin préfère ce
> faux positif — qui informe — au faux négatif, qui laisse sur le quai.

Seuls les incidents en cours sont retenus. iRail mêle dans la même liste les
incidents et les travaux programmés, ces derniers largement majoritaires : un
navetteur veut savoir ce qui se passe ce matin, pas ce qui est prévu dans trois
semaines. Les alertes rattachées aux trains sont filtrées de la même façon, sur
leur période de validité.

## En cas de problème

Les journaux sont dans Analyse → Logs, log `sncbnmbs`.

| Symptôme | Cause probable |
|---|---|
| « Trajet incomplet : choisissez une gare de départ et une gare d'arrivée » | une gare a été tapée mais pas choisie dans la liste : le plugin travaille avec les identifiants d'iRail |
| « La gare de départ et la gare d'arrivée sont les mêmes » | deux fois la même gare ; iRail n'y répond que par un timeout, le plugin refuse avant l'appel |
| « Aucun trajet trouvé : vérifiez les gares et le créneau » | iRail ne connaît pas cette liaison, ou aucun train ne circule à cette heure-là |
| « iRail ne répond pas » | panne, coupure réseau ou délai d'attente trop court ; le plugin réessaie au prochain passage |
| « iRail a refusé la demande : HTTP ... » | erreur du service ; les horaires connus restent affichés |
| « Aucun train dans le créneau » | le créneau est déjà passé pour aujourd'hui et aucun jour actif n'est proche, ou il est trop étroit pour contenir un train |
| Le créneau ne remonte qu'un ou deux trains | le nombre de trains demandé est faible, ou la borne haute du créneau coupe les suivants |
| « Occupation » toujours vide | normal : iRail ne publie l'occupation que pour les trains commentés par les voyageurs |
| Une perturbation sans rapport avec votre ligne | le rapprochement se fait sur les noms des gares ; voir l'avertissement plus haut |
| Aucune notification malgré un retard | le retard n'atteint pas le seuil, le problème a déjà été signalé, le train a été acquitté, ou aucune commande à déclencher n'est choisie |
| « Commande d'alerte introuvable » | la commande d'action choisie a été supprimée depuis ; choisissez-en une autre |
| Les commandes ne bougent plus | le cron du coeur, partagé par tous les plugins, ne passe plus ; vérifiez Analyse → Moteur de tâches |
