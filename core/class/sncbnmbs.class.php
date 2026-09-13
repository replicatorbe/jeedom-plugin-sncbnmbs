<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class sncbnmbs extends eqLogic {

    /*
     * iRail publie les données temps réel de la SNCB. Aucune clé n'est
     * nécessaire, mais le service demande que chaque appelant s'identifie par
     * son User-Agent : une requête anonyme peut être refusée sans préavis.
     *
     * L'ancienne racine (api.irail.be/connections/) répond aujourd'hui par une
     * redirection 303 vers /v1/connections. L'appeler directement économise un
     * aller-retour par requête — et il y en a une par minute et par trajet.
     */
    const API_BASE = 'https://api.irail.be/v1';

    /*
     * La liste des gares ne bouge que quelques fois par an, et pèse 120 ko :
     * la relire à chaque frappe de l'autocomplete serait absurde.
     */
    const STATIONS_TTL = 604800;

    /*
     * Les perturbations sont mutualisées entre tous les trajets : elles
     * décrivent le réseau, pas un voyage. Dix minutes, et non trois : à trois,
     * ce cache coûtait 480 requêtes par jour — plus qu'un trajet entier — pour
     * une information qui n'apparaît ni ne disparaît en si peu de temps. Les
     * alertes, elles, ne dépendent pas de ce cache.
     */
    const DISTURBANCES_TTL = 600;

    /* Les trains du lendemain ne bougent pas à la minute : inutile de les
     * relire aussi souvent que ceux du jour. */
    const TOMORROW_TTL = 1800;

    /*
     * Hors fenêtre de surveillance, une lecture par heure suffit : personne ne
     * regarde, et tout est relu dès que la fenêtre s'ouvre. Au quart d'heure,
     * c'était un tiers des requêtes d'une journée dépensé pour rien.
     */
    const IDLE_INTERVAL = 3600;

    /*
     * Et la nuit, rien du tout : la SNCB ne fait plus circuler grand-chose entre
     * une heure et cinq heures, et le navetteur dort. La fenêtre de surveillance,
     * elle, reste prioritaire — un créneau de nuit continue d'être suivi.
     */
    const QUIET_FROM = 1;
    const QUIET_TO   = 5;

    /*
     * Recul après échec. Sans lui, un trajet dont la gare est introuvable
     * relançait 1 200 requêtes par jour, indéfiniment : l'appel échoue, rien
     * n'est mis en cache, l'âge reste infini et le cron réessaie à la minute
     * suivante. C'est très exactement le client abusif que ce plugin s'interdit
     * d'être — et il frappait le plus fort quand iRail était déjà en peine.
     */
    const BACKOFF_BASE = 60;
    const BACKOFF_MAX  = 3600;

    /* Garde-fou contre un scénario qui appellerait « Rafraîchir » en boucle. */
    const FORCE_MIN_INTERVAL = 20;

    /*
     * Budget de temps d'un passage du cron. plugin::cron() dispose de deux
     * minutes pour TOUS les plugins de la box : un utilisateur à sept trajets
     * les consommerait à lui seul si chacun attendait son délai d'expiration.
     * Au-delà du budget, les trajets restants sont simplement recomposés depuis
     * le cache et leur appel réseau attend la minute suivante.
     */
    const CRON_BUDGET = 45;

    /*
     * En plein créneau, on demande les trains à partir d'une demi-heure en
     * arrière : un train prévu avant l'heure courante mais retardé part encore
     * dans le futur, et c'est précisément celui-là qu'il faut proposer.
     */
    const LOOKBACK = 1800;

    /* Valeurs par défaut d'un trajet neuf. */
    const DEFAULT_SLOT_START = '07:00';
    const DEFAULT_SLOT_END   = '09:00';
    const DEFAULT_MAX_TRAINS = 6;
    const DEFAULT_THRESHOLD  = 5;
    const DEFAULT_WATCH_BEFORE = 60;

    /*
     * Bornes de sécurité : iRail est un service gratuit et communautaire.
     * Six trains, et pas douze : au-delà, iRail ignore le paramètre `results`
     * sans le dire et rend six connexions quand même — vérifié en demandant 12
     * sur Namur → Bruxelles-Central, réponse identique à celle de 6.
     */
    const MAX_TRAINS = 6;
    const MAX_WATCH_BEFORE = 240;

    /* Types de problème, par ordre de gravité croissante. */
    const PROBLEM_DELAY    = 'delay';
    const PROBLEM_PLATFORM = 'platform';
    const PROBLEM_CANCELED = 'canceled';

    /*
     * Message de rafraîchissement raté, transmis à l'appelant sans lever
     * d'exception : le cron ne doit pas s'arrêter au premier trajet en panne.
     *
     * Le souligné n'est pas décoratif : DB::save() traite toute propriété qui
     * n'en porte pas comme une colonne de la table (DB.class.php, « if ('_' !==
     * $name[0]) »). Sans lui, la création d'un équipement échoue sur un
     * « Unknown column 'refreshError' ».
     */
    private $_refreshError = '';

    /* ================================================================ WIDGETS */

    /*
     * Deux gabarits repris du coeur, aux seules icônes changées. « Trajet
     * perturbé » doit se lire comme une alerte et non comme une coche verte
     * quand tout va bien. Le coeur remplace les guillemets doubles par des
     * apostrophes (cmd::getWidgetTemplateCode) : écrire directement en
     * apostrophes.
     */
    public static function templateWidget() {
        $icons = array(
            '#_icon_on_#'  => "<i class='icon_red fas fa-exclamation-triangle'></i>",
            '#_icon_off_#' => "<i class='icon_green fas fa-check'></i>",
        );
        return array(
            'info' => array(
                'binary' => array(
                    'trouble'     => array('template' => 'tmplicon',     'replace' => $icons),
                    'troubleLine' => array('template' => 'tmpliconline', 'replace' => $icons),
                ),
            ),
        );
    }

    /* ==================================================================== CRON */

    /*
     * Appelé chaque minute par plugin::cron(). Chaque trajet décide lui-même
     * s'il doit interroger iRail : surveiller à la minute 24 h sur 24 un trajet
     * de 7 h du matin ferait 1440 requêtes par jour pour en utiliser 120.
     */
    public static function cron() {
        $deadline = microtime(true) + self::CRON_BUDGET;

        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                if (!$eqLogic->shouldPoll() || microtime(true) > $deadline) {
                    /*
                     * Même sans nouvel appel, les commandes sont recomposées :
                     * le compte à rebours et « prochain train » changent à
                     * chaque minute qui passe, pas à chaque réponse d'iRail.
                     */
                    $eqLogic->refreshFromCache();
                    continue;
                }
                $eqLogic->update();
            } catch (Throwable $e) {
                // Un trajet en échec ne doit pas priver les autres de leur tour.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* Faut-il interroger iRail pour ce trajet, maintenant ? */
    public function shouldPoll($_now = null) {
        if (!$this->isConfigured()) {
            return false;
        }
        $now = ($_now === null) ? time() : $_now;

        /* Un trajet en échec attend son tour : voir BACKOFF_BASE. */
        $backoff = $this->getBackoff();
        if (isset($backoff['until']) && $now < $backoff['until']) {
            return false;
        }

        $cache = $this->getJourneys();
        $age = $now - (isset($cache['fetchedAt']) ? $cache['fetchedAt'] : 0);

        if ($this->isWatching($now)) {
            return $age >= 55;
        }
        $heure = (int) date('G', $now);
        if ($heure >= self::QUIET_FROM && $heure < self::QUIET_TO) {
            return false;
        }
        return $age >= self::IDLE_INTERVAL;
    }

    /*
     * Dans la fenêtre de surveillance : le créneau du jour, élargi en amont de
     * watch_before minutes pour que l'utilisateur soit prévenu avant de partir
     * de chez lui, et non sur le quai.
     */
    public function isWatching($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        if ($this->getConfiguration('watch_enabled', 1) == 0) {
            return false;
        }
        foreach ($this->slots($now) as $slot) {
            $before = $slot['start'] - ($this->watchBefore() * 60);
            if ($now >= $before && $now <= $slot['end']) {
                return true;
            }
        }
        return false;
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /*
         * Aucune exception ici : le coeur crée l'équipement avec son seul nom.
         * Toute validation rendrait le bouton « Ajouter » définitivement
         * inopérant. Un trajet incomplet est signalé au centre de messages au
         * moment du rafraîchissement.
         */

        /*
         * Un trajet neuf naît actif et visible. « Ajouter » n'envoie que le nom
         * (plugin.template.js, addEqLogic) : les cases « Activer » et « Visible »
         * du formulaire, pourtant cochées dans le HTML, ne sont jamais lues à ce
         * moment-là, et le trajet se retrouvait désactivé — absent du dashboard,
         * ignoré par le cron, sans que rien ne le signale.
         */
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        foreach (array(
            'slot_start'   => self::DEFAULT_SLOT_START,
            'slot_end'     => self::DEFAULT_SLOT_END,
            'max_trains'   => self::DEFAULT_MAX_TRAINS,
            'threshold'    => self::DEFAULT_THRESHOLD,
            'watch_before' => self::DEFAULT_WATCH_BEFORE,
            'watch_enabled' => 1,
        ) as $key => $default) {
            if ($this->getConfiguration($key, '') === '') {
                $this->setConfiguration($key, $default);
            }
        }

        /*
         * Du lundi au vendredi à la création. « Aucune case cochée vaut tous les
         * jours » reste vrai si l'utilisateur les décoche toutes, mais un trajet
         * neuf ne doit pas interroger le réseau le dimanche pour un trajet de
         * bureau : c'est ce que conseille l'aide du formulaire, autant le faire.
         */
        if ($this->getConfiguration('days_initialized', '') === '') {
            for ($day = 1; $day <= 5; $day++) {
                $this->setConfiguration('day_' . $day, 1);
            }
            $this->setConfiguration('days_initialized', 1);
        }
    }

    public function postSave() {
        $this->createCommands();

        if (!$this->isConfigured()) {
            return;
        }
        try {
            /*
             * Relire iRail à chaque enregistrement ferait un appel pour un
             * simple changement de nom ou d'icône. Seul un trajet réellement
             * différent justifie de tout reprendre — et il faut alors oublier
             * les alertes déjà envoyées, qui parlaient d'un autre voyage.
             */
            $journeys = $this->getJourneys();
            $changed = !isset($journeys['signature']) || $journeys['signature'] !== $this->signature();
            if ($changed) {
                $this->clearAlertState();
            }
            $this->update($changed);
        } catch (Throwable $e) {
            // L'enregistrement ne doit pas échouer parce qu'iRail est
            // indisponible : le trajet est valide, le cron réessaiera.
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        /*
         * DB::remove() met l'id à null avant postRemove : les caches doivent
         * être nettoyés tant que l'identifiant est encore lisible.
         */
        $this->clearJourneys();
        $this->clearAlertState();
        // Sans cela le message reste au centre de messages avec un identifiant
        // qu'aucun code ne pourra plus faire correspondre.
        $this->clearProblem();
        return true;
    }

    /* Le trajet est-il exploitable ? */
    public function isConfigured() {
        $from = trim($this->getConfiguration('from_id', ''));
        $to = trim($this->getConfiguration('to_id', ''));
        /*
         * Deux fois la même gare n'est pas seulement absurde : iRail part alors
         * en timeout et répond 504 au bout de trente secondes. À raison d'une
         * requête par minute, cela bloquerait le cron de tous les plugins.
         */
        return $from != '' && $to != '' && $from !== $to;
    }

    /* La raison pour laquelle le trajet n'est pas exploitable, en clair. */
    public function configurationError() {
        $from = trim($this->getConfiguration('from_id', ''));
        $to = trim($this->getConfiguration('to_id', ''));
        if ($from == '' || $to == '') {
            return __('Trajet incomplet : choisissez une gare de départ et une gare d\'arrivée.', __FILE__);
        }
        if ($from === $to) {
            return __('La gare de départ et la gare d\'arrivée sont les mêmes.', __FILE__);
        }
        return '';
    }

    /* ================================================================ COMMANDES */

    /* Crée les commandes manquantes sans jamais écraser la personnalisation. */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new sncbnmbsCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /*
         * La table cmd impose l'unicité du couple (eqLogic_id, name) : un nom
         * déjà pris ferait échouer l'enregistrement de tout l'équipement. On
         * suffixe plutôt que de laisser planter.
         */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        if (isset($_options['unite'])) {
            $cmd->setUnite($_options['unite']);
        }
        if (isset($_options['generic'])) {
            $cmd->setGeneric_type($_options['generic']);
        }
        if (isset($_options['icon'])) {
            $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>');
        }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    /*
     * Vingt-trois commandes, dont trois visibles seulement. Un navetteur veut
     * voir « son » train sur le dashboard, pas une colonne de vingt tuiles ;
     * les autres restent disponibles pour les scénarios et se réaffichent d'un
     * clic dans la configuration avancée.
     */
    private function createCommands() {
        $order = 0;

        $this->addCmdIfMissing('summary', 'Prochain train', 'info', 'string', array(
            'order' => $order++, 'isVisible' => 1,
            'template' => 'sncbnmbs::sncbnmbs',
        ));
        $delay = $this->addCmdIfMissing('next_delay', 'Retard du prochain train', 'info', 'numeric', array(
            'order' => $order++, 'isVisible' => 1, 'isHistorized' => 1, 'unite' => 'min',
            'icon' => 'fas fa-hourglass-half',
        ));
        /*
         * Les seuils ne sont posés qu'à la création : ils colorent la tuile sans
         * aucun réglage, mais l'utilisateur qui les change ensuite doit garder
         * la main. Le seuil « danger » suit celui du trajet, pour que la couleur
         * et la notification disent la même chose.
         */
        if ($delay->getAlert('warningif') == '' && $delay->getAlert('dangerif') == '') {
            $delay->setAlert('warningif', '#value# >= 1');
            $delay->setAlert('dangerif', '#value# >= ' . $this->threshold());
            $delay->save();
        }
        $this->addCmdIfMissing('disturbed', 'Trajet perturbé', 'info', 'binary', array(
            'order' => $order++, 'isVisible' => 1,
            'template' => 'sncbnmbs::troubleLine',
        ));

        /* ------------------------------------------------- le prochain train */
        $this->addCmdIfMissing('next_time', 'Départ prévu', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_real', 'Départ réel', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_countdown', 'Départ dans', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min',
        ));
        $this->addCmdIfMissing('next_vehicle', 'Train', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_direction', 'Direction', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_platform', 'Voie', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_platform_changed', 'Changement de voie', 'info', 'binary', array(
            'order' => $order++, 'template' => 'sncbnmbs::troubleLine',
        ));
        $this->addCmdIfMissing('next_canceled', 'Prochain train supprimé', 'info', 'binary', array(
            'order' => $order++, 'template' => 'sncbnmbs::troubleLine',
        ));
        /* Heure réelle, retard compris : « prévue » aurait laissé croire à
         * l'horaire théorique et tout calcul d'écart aurait rendu zéro. */
        $this->addCmdIfMissing('next_arrival', 'Arrivée réelle', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_duration', 'Durée du trajet', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min',
        ));
        $this->addCmdIfMissing('next_transfers', 'Correspondances', 'info', 'numeric', array('order' => $order++));
        $this->addCmdIfMissing('next_occupancy', 'Occupation', 'info', 'string', array('order' => $order++));

        /* ------------------------------------------------------- le repli */
        /*
         * Quand le plugin réveille quelqu'un pour lui dire que son train est
         * supprimé, il a déjà le suivant en mémoire. Le garder pour lui obligeait
         * l'utilisateur à sortir son téléphone au pire moment.
         */
        $this->addCmdIfMissing('next2_summary', 'Train de repli', 'info', 'string', array(
            'order' => $order++, 'template' => 'sncbnmbs::sncbnmbs',
        ));
        $this->addCmdIfMissing('next2_time', 'Départ du repli', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next2_vehicle', 'Train de repli (numéro)', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next2_countdown', 'Repli dans', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min',
        ));

        /* ------------------------------------------------- l'état du créneau */
        $this->addCmdIfMissing('trains_count', 'Trains du créneau', 'info', 'numeric', array('order' => $order++));
        $this->addCmdIfMissing('trains_delayed', 'Trains en retard', 'info', 'numeric', array(
            'order' => $order++, 'isHistorized' => 1,
        ));
        $this->addCmdIfMissing('trains_canceled', 'Trains supprimés', 'info', 'numeric', array(
            'order' => $order++, 'isHistorized' => 1,
        ));
        $this->addCmdIfMissing('delay_max', 'Retard maximum', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min',
        ));
        $this->addCmdIfMissing('alert_message', 'Message de perturbation', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('last_update', 'Dernière vérification', 'info', 'string', array('order' => $order++));

        /* ------------------------------------------------------------ actions */
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array(
            'order' => $order++, 'isVisible' => 1, 'icon' => 'fas fa-sync',
        ));
        $this->addCmdIfMissing('acknowledge', 'Acquitter l\'alerte', 'action', 'other', array(
            'order' => $order++, 'icon' => 'fas fa-bell-slash',
        ));
    }

    /*
     * Surtout pas nommée setCmd() : en enregistrant un équipement, le coeur
     * passe le formulaire à utils::a2o(), qui transforme chaque clé envoyée en
     * un appel « set » + clé (utils.class.php, vers la ligne 132). La page
     * envoie une clé « cmd » pour le tableau des commandes : une méthode
     * setCmd() est donc appelée par le coeur, et si elle est privée
     * l'enregistrement meurt sur une erreur fatale — l'équipement reste vide et
     * l'utilisateur ne voit qu'une page qui se rafraîchit sans rien garder.
     *
     * checkAndUpdateCmd() n'écrit que si la valeur change, et c'est ce qui
     * déclenche les scénarios sur événement. On ne remplace jamais une valeur
     * connue par du vide sur un simple raté réseau : le dashboard se viderait
     * à la première coupure.
     */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === '' || $_value === null) {
            $cmd = $this->getCmd(null, $_logicalId);
            if (is_object($cmd) && $cmd->execCmd() === '') {
                return;
            }
        }
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* =============================================================== LECTURE */

    /*
     * Interroge iRail et recompose tout. $_force ignore l'âge du cache, sans
     * jamais descendre sous FORCE_MIN_INTERVAL : un scénario en boucle ne doit
     * pas faire de ce plugin un client abusif.
     */
    public function update($_force = false) {
        $this->_refreshError = '';
        if (!$this->isConfigured()) {
            $this->_refreshError = $this->configurationError();
            $this->reportProblem($this->_refreshError);
            return array();
        }

        $previous = $this->getJourneys();
        $now = time();
        /*
         * Un scénario qui appelle « Rafraîchir » en boucle ne doit pas devenir
         * un client abusif d'un service gratuit : sous FORCE_MIN_INTERVAL, on
         * recompose depuis le cache sans toucher au réseau. Se contenter de
         * retomber sur un rafraîchissement normal ne suffisait pas — le créneau
         * en cours est justement celui qu'on relit à chaque passage.
         */
        if ($_force && !empty($previous)
            && ($now - (isset($previous['fetchedAt']) ? $previous['fetchedAt'] : 0)) < self::FORCE_MIN_INTERVAL) {
            $this->refreshCommands($previous);
            $this->checkAlerts($previous);
            return $previous;
        }

        try {
            $journeys = $this->fetchJourneys($previous, $_force);
        } catch (Throwable $e) {
            /*
             * Une panne d'iRail ne doit pas effacer ce qu'on sait déjà : les
             * commandes sont recomposées depuis le cache, avec l'heure de la
             * dernière lecture réussie. L'erreur remonte à l'appelant.
             */
            $this->noteFailure($now);
            $this->_refreshError = $e->getMessage();
            $this->reportProblem($e->getMessage());
            $this->refreshFromCache();
            return $previous;
        }

        $this->clearProblem();
        $this->clearBackoff();
        $this->saveJourneys($journeys);
        $this->refreshCommands($journeys);
        $this->checkAlerts($journeys);
        return $journeys;
    }

    /* Recompose les commandes sans appeler iRail. */
    public function refreshFromCache() {
        $journeys = $this->getJourneys();
        if (empty($journeys)) {
            return;
        }
        $this->refreshCommands($journeys);
        $this->checkAlerts($journeys);
    }

    /*
     * Les trains du jour et ceux du lendemain viennent de deux requêtes
     * distinctes : iRail ne répond que pour une date à la fois. Celle du
     * lendemain n'est refaite que toutes les demi-heures, son contenu ne
     * changeant pas à la minute.
     */
    private function fetchJourneys($_previous, $_force = false) {
        $now = time();
        $previousTrains = isset($_previous['trains']) ? $_previous['trains'] : array();
        $previousAt = isset($_previous['fetchedDates']) ? $_previous['fetchedDates'] : array();
        $trains = array();
        $fetchedDates = array();
        $failures = array();
        $nearest = true;

        foreach ($this->slots($now, 2) as $slot) {
            $date = $slot['date'];
            /*
             * Un créneau est « vivant » quand on est dedans ou dans son avance :
             * c'est celui dont les retards changent à la minute. Les autres,
             * fussent-ils rendus en premier parce que le créneau du jour est
             * terminé, ne bougent pas et se contentent d'une relecture par
             * demi-heure. Raisonner sur le rang, et non sur l'heure, faisait
             * relire un lundi matin lointain à chaque passage.
             */
            $live = ($now >= $slot['start'] - ($this->watchBefore() * 60) && $now <= $slot['end']);
            $age = $now - (isset($previousAt[$date]) ? $previousAt[$date] : 0);
            /*
             * Un créneau à plus de six heures n'a rien à dire de neuf : les
             * retards ne s'annoncent pas la veille. Trois heures d'intervalle
             * suffisent, contre une demi-heure quand il approche.
             */
            $ttl = (($slot['start'] - $now) > 21600) ? 10800 : self::TOMORROW_TTL;
            $stale = ($_force || $live || $age >= $ttl);

            if (!$stale) {
                // Réutiliser tels quels les trains déjà connus pour cette date.
                foreach ($previousTrains as $train) {
                    if (isset($train['date']) && $train['date'] === $date) {
                        $trains[] = $train;
                    }
                }
                $fetchedDates[$date] = isset($previousAt[$date]) ? $previousAt[$date] : 0;
                $nearest = false;
                continue;
            }

            try {
                $trains = array_merge($trains, $this->fetchSlot($slot, $now));
                $fetchedDates[$date] = $now;
            } catch (Throwable $e) {
                /*
                 * Le créneau le plus proche prime : si c'est lui qui échoue, il
                 * n'y a rien à afficher et l'erreur doit remonter. Un échec sur
                 * le suivant laisse le trajet parfaitement utilisable.
                 */
                if ($nearest) {
                    throw $e;
                }
                $failures[] = $e->getMessage();
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
            }
            $nearest = false;
        }

        usort($trains, array(__CLASS__, 'compareTrains'));

        return array(
            'fetchedAt'    => $now,
            'fetchedDates' => $fetchedDates,
            'signature'    => $this->signature(),
            'trains'       => $trains,
            'warnings'     => $failures,
        );
    }

    /* Les trains d'un créneau, filtrés sur l'heure de départ prévue. */
    private function fetchSlot($_slot, $_now = null) {
        $now = ($_now === null) ? time() : $_now;

        /*
         * En plein créneau, on ne repart pas de l'heure courante mais d'une
         * demi-heure en arrière : un train prévu à 08:25 et retardé de quinze
         * minutes part réellement à 08:40, il est encore prenable à 08:30, et
         * demander « les trains à partir de 08:30 » le laissait justement de
         * côté — le seul qu'on aurait pu avoir. Avant le créneau, la borne
         * reste son vrai début.
         */
        $from = max($_slot['start'], min($now - self::LOOKBACK, $_slot['end']));

        $params = array(
            'from'    => $this->getConfiguration('from_id'),
            'to'      => $this->getConfiguration('to_id'),
            // DDMMYY : tout autre format est accepté sans erreur par iRail, qui
            // retombe alors silencieusement sur aujourd'hui.
            'date'    => date('dmy', $from),
            'time'    => date('Hi', $from),
            'timesel' => 'departure',
            'alerts'  => 'true',
            'results' => $this->maxTrains(),
        );
        $data = static::call('connections', $params);

        $trains = array();
        $connections = isset($data['connection']) ? $data['connection'] : array();
        if (is_array($connections) && isset($connections['id'])) {
            // iRail rend un objet nu quand il n'y a qu'un seul résultat.
            $connections = array($connections);
        }
        if (!is_array($connections)) {
            // Réponse de forme inattendue : un foreach sur une chaîne lèverait
            // un avertissement à chaque minute dans les journaux.
            $connections = array();
        }
        $vus = array();
        foreach ($connections as $connection) {
            $train = self::parseConnection($connection, $_slot['date']);
            if ($train === null) {
                continue;
            }
            /*
             * iRail rend parfois deux fois le même départ, avec deux acheminements
             * différents — observé sur Soignies → Bruxelles-Central, l'IC 3706 de
             * 06:37 listé deux fois. La clé (train + heure de départ) est alors
             * identique : sans ce filtre, le tableau affiche le train en double,
             * « trains du créneau » le compte deux fois, et un acquittement porte
             * sur les deux. On garde le trajet le plus court.
             */
            if (isset($vus[$train['key']])) {
                $ancien = $vus[$train['key']];
                if ($train['duration'] >= $trains[$ancien]['duration']) {
                    continue;
                }
                $trains[$ancien] = $train;
                continue;
            }
            /*
             * iRail rend les N connexions suivant l'heure demandée, sans borne
             * haute : sans ce filtre, un créneau de 7 h à 9 h remonterait aussi
             * le train de 11 h et déclencherait des alertes hors sujet.
             */
            if ($train['depTs'] > $_slot['end'] || $train['depTs'] < $_slot['start']) {
                continue;
            }
            $vus[$train['key']] = count($trains);
            $trains[] = $train;
        }
        return $trains;
    }

    /*
     * Tout arrive en chaînes chez iRail, y compris les nombres et les booléens.
     * Les délais sont en secondes, les heures en timestamps Unix.
     */
    public static function parseConnection($_connection, $_date = '') {
        if (!isset($_connection['departure']['time'])) {
            return null;
        }
        $departure = $_connection['departure'];
        /*
         * L'heure de départ fait foi : sans elle, rien n'est exploitable. Un
         * champ présent mais illisible ("", "abc") donnait un train fantôme daté
         * du 1er janvier 1970, compté dans « trains du créneau » et affiché à
         * 01:00 au tableau.
         */
        if ((int) $departure['time'] <= 0) {
            return null;
        }
        $arrival = isset($_connection['arrival']) ? $_connection['arrival'] : array();

        /*
         * Les alertes d'iRail portent une période de validité : beaucoup
         * concernent des travaux programmés des semaines plus tard. Les
         * afficher toutes ferait du champ « perturbation » un bruit permanent
         * que plus personne ne lirait.
         */
        $now = time();
        $alerts = array();
        foreach (self::itemsOf($_connection, 'alerts', 'alert') as $alert) {
            if (isset($alert['startTime']) && (int) $alert['startTime'] > $now) {
                continue;
            }
            if (isset($alert['endTime']) && (int) $alert['endTime'] > 0 && (int) $alert['endTime'] < $now) {
                continue;
            }
            $text = self::sanitizeText(isset($alert['header']) ? $alert['header'] : (isset($alert['description']) ? $alert['description'] : ''));
            if ($text != '') {
                $alerts[] = $text;
            }
        }
        foreach (self::itemsOf($_connection, 'remarks', 'remark') as $remark) {
            $text = self::sanitizeText(isset($remark['header']) ? $remark['header'] : (isset($remark['description']) ? $remark['description'] : ''));
            if ($text != '') {
                $alerts[] = $text;
            }
        }

        $depTs = (int) $departure['time'];
        $vehicle = self::sanitizeText(isset($departure['vehicleinfo']['shortname']) ? $departure['vehicleinfo']['shortname']
            : (isset($departure['vehicle']) ? str_replace('BE.NMBS.', '', $departure['vehicle']) : '?'));
        if ($vehicle === '') {
            $vehicle = '?';
        }

        return array(
            /*
             * La clé identifie un train dans le temps : le même IC 2137 revient
             * tous les jours, et une alerte d'hier ne doit pas éteindre celle
             * d'aujourd'hui.
             */
            'key'        => $vehicle . '@' . $depTs,
            'date'       => ($_date !== '') ? $_date : date('Ymd', $depTs),
            'vehicle'    => $vehicle,
            'vehicleId'  => isset($departure['vehicle']) ? $departure['vehicle'] : '',
            'depTs'      => $depTs,
            'depDelay'   => self::sanitizeDelay(isset($departure['delay']) ? $departure['delay'] : 0),
            'depPlatform' => self::sanitizeText(isset($departure['platform']) ? $departure['platform'] : ''),
            'depPlatformNormal' => !isset($departure['platforminfo']['normal']) || $departure['platforminfo']['normal'] == '1',
            'depCanceled' => isset($departure['canceled']) && $departure['canceled'] == '1',
            'left'       => isset($departure['left']) && $departure['left'] == '1',
            'direction'  => self::sanitizeText(isset($departure['direction']['name']) ? $departure['direction']['name'] : ''),
            'occupancy'  => isset($departure['occupancy']['name']) ? $departure['occupancy']['name'] : '',
            'arrTs'      => isset($arrival['time']) ? (int) $arrival['time'] : 0,
            'arrDelay'   => self::sanitizeDelay(isset($arrival['delay']) ? $arrival['delay'] : 0),
            'arrPlatform' => self::sanitizeText(isset($arrival['platform']) ? $arrival['platform'] : ''),
            'arrCanceled' => isset($arrival['canceled']) && $arrival['canceled'] == '1',
            'arrived'    => isset($arrival['arrived']) && $arrival['arrived'] == '1',
            'duration'   => isset($_connection['duration']) ? (int) $_connection['duration'] : 0,
            'transfers'  => isset($_connection['vias']['number']) ? (int) $_connection['vias']['number'] : 0,
            'alerts'     => $alerts,
        );
    }

    /*
     * iRail emballe ses listes dans { number: "2", <singulier>: [...] } et rend
     * l'objet nu quand il n'y en a qu'un. Sans cette normalisation, un foreach
     * parcourrait les clés d'un seul élément.
     */
    private static function itemsOf($_parent, $_plural, $_singular) {
        if (!isset($_parent[$_plural][$_singular])) {
            return array();
        }
        $items = $_parent[$_plural][$_singular];
        if (!is_array($items)) {
            return array();
        }
        if (isset($items['id']) || isset($items['header']) || isset($items['description'])) {
            return array($items);
        }
        return $items;
    }

    private static function compareTrains($_a, $_b) {
        if ($_a['depTs'] == $_b['depTs']) {
            return 0;
        }
        return ($_a['depTs'] < $_b['depTs']) ? -1 : 1;
    }

    /* ============================================================= COMMANDES */

    private function refreshCommands($_journeys) {
        $now = time();
        $trains = isset($_journeys['trains']) ? $_journeys['trains'] : array();
        $next = $this->nextTrain($trains, $now);

        $count = count($trains);
        $delayed = 0;
        $canceled = 0;
        $maxDelay = 0;
        $messages = array();

        foreach ($trains as $train) {
            if ($train['depCanceled'] || $train['arrCanceled']) {
                $canceled++;
            }
            $delay = self::minutes($train['depDelay']);
            if ($delay > 0) {
                $delayed++;
            }
            if ($delay > $maxDelay) {
                $maxDelay = $delay;
            }
            foreach ($train['alerts'] as $alert) {
                $messages[] = $train['vehicle'] . ' : ' . $alert;
            }
        }

        /*
         * Les perturbations réseau complètent les alertes attachées aux trains :
         * une ligne coupée est annoncée là avant que les trains ne soient
         * marqués supprimés.
         */
        /*
         * Hors surveillance, on se contente de ce qui est déjà en cache : cette
         * boucle tourne à chaque minute et pour chaque trajet, et elle relisait
         * les perturbations nuit et week-end compris, pour un écran que
         * personne ne regarde.
         */
        foreach ($this->matchingDisturbances($this->isWatching($now)) as $disturbance) {
            $messages[] = $disturbance;
        }

        $this->publishCmd('trains_count', $count);
        $this->publishCmd('trains_delayed', $delayed);
        $this->publishCmd('trains_canceled', $canceled);
        $this->publishCmd('delay_max', $maxDelay);
        $this->publishCmd('alert_message', implode(' — ', array_slice(array_unique($messages), 0, 3)));
        $this->publishCmd('last_update', date('d/m/Y H:i', isset($_journeys['fetchedAt']) ? $_journeys['fetchedAt'] : $now));

        $fallback = $this->fallbackTrain($trains, $next, $now);
        if ($fallback === null) {
            $this->publishCmd('next2_summary', '');
            $this->publishCmd('next2_time', '');
            $this->publishCmd('next2_vehicle', '');
            $this->publishCmd('next2_countdown', -1);
        } else {
            $this->publishCmd('next2_summary', $this->summaryOf($fallback));
            $this->publishCmd('next2_time', date('H:i', $fallback['depTs']));
            $this->publishCmd('next2_vehicle', $fallback['vehicle']);
            $this->publishCmd('next2_countdown', max(0, (int) floor((($fallback['depTs'] + $fallback['depDelay']) - $now) / 60)));
        }

        if ($next === null) {
            $this->publishCmd('summary', __('Aucun train dans le créneau', __FILE__));
            $this->publishCmd('next_time', '');
            $this->publishCmd('next_real', '');
            $this->publishCmd('next_delay', 0);
            $this->publishCmd('next_countdown', -1);
            $this->publishCmd('next_vehicle', '');
            $this->publishCmd('next_direction', '');
            $this->publishCmd('next_platform', '');
            $this->publishCmd('next_platform_changed', 0);
            $this->publishCmd('next_canceled', 0);
            $this->publishCmd('next_arrival', '');
            $this->publishCmd('next_duration', 0);
            $this->publishCmd('next_transfers', 0);
            $this->publishCmd('next_occupancy', '');
            $this->publishCmd('disturbed', ($canceled > 0 || $maxDelay >= $this->threshold()) ? 1 : 0);
            return;
        }

        $delay = self::minutes($next['depDelay']);
        $realTs = $next['depTs'] + $next['depDelay'];

        $this->publishCmd('next_time', date('H:i', $next['depTs']));
        $this->publishCmd('next_real', date('H:i', $realTs));
        $this->publishCmd('next_delay', $delay);
        /*
         * Plancher à zéro : un train qui part à l'instant reste « le prochain »
         * pendant une minute, et un compte à rebours négatif se confondrait avec
         * le -1 qui signifie « aucun train ». Les scénarios testent sur >= 0.
         */
        $this->publishCmd('next_countdown', max(0, (int) floor(($realTs - $now) / 60)));
        $this->publishCmd('next_vehicle', $next['vehicle']);
        $this->publishCmd('next_direction', $next['direction']);
        $this->publishCmd('next_platform', ($next['depPlatform'] == '?') ? '' : $next['depPlatform']);
        $this->publishCmd('next_platform_changed', $next['depPlatformNormal'] ? 0 : 1);
        $this->publishCmd('next_canceled', ($next['depCanceled'] || $next['arrCanceled']) ? 1 : 0);
        $this->publishCmd('next_arrival', ($next['arrTs'] > 0) ? date('H:i', $next['arrTs'] + $next['arrDelay']) : '');
        $this->publishCmd('next_duration', self::minutes($next['duration']));
        $this->publishCmd('next_transfers', $next['transfers']);
        $this->publishCmd('next_occupancy', self::occupancyLabel($next['occupancy']));
        $this->publishCmd('summary', $this->summaryOf($next));
        $this->publishCmd('disturbed', $this->isDisturbed($next, $canceled, $maxDelay) ? 1 : 0);
    }

    /*
     * Le prochain train est le premier qui n'est pas encore parti. Une minute de
     * battement après l'heure réelle : un train qu'on vient de rater n'est plus
     * « le prochain », et iRail ne lève pas toujours son drapeau « left ».
     */
    private function nextTrain($_trains, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        foreach ($_trains as $train) {
            if ($train['left']) {
                continue;
            }
            if (($train['depTs'] + $train['depDelay']) < ($now - 60)) {
                continue;
            }
            return $train;
        }
        return null;
    }

    /*
     * Le train d'après, celui qu'on prendra si le prochain est supprimé. Il doit
     * partir le MÊME jour que le prochain : sans ce contrôle, un trajet consulté
     * à 8 h 50 proposerait comme repli le premier train de demain matin, ce qui
     * n'aide personne. Un train lui-même supprimé n'est évidemment pas un repli.
     */
    private function fallbackTrain($_trains, $_next, $_now = null) {
        if ($_next === null) {
            return null;
        }
        $now = ($_now === null) ? time() : $_now;
        $vu = false;
        foreach ($_trains as $train) {
            if (!$vu) {
                if ($train['key'] === $_next['key']) { $vu = true; }
                continue;
            }
            if ($train['date'] !== $_next['date']) {
                break;
            }
            if ($train['depCanceled'] || $train['arrCanceled'] || $train['left']) {
                continue;
            }
            if (($train['depTs'] + $train['depDelay']) < $now) {
                continue;
            }
            return $train;
        }
        return null;
    }

    private function isDisturbed($_train, $_canceled, $_maxDelay) {
        if ($_train['depCanceled'] || $_train['arrCanceled'] || !$_train['depPlatformNormal']) {
            return true;
        }
        if (count($_train['alerts']) > 0) {
            return true;
        }
        $threshold = $this->threshold();
        return (self::minutes($_train['depDelay']) >= $threshold) || ($_canceled > 0) || ($_maxDelay >= $threshold);
    }

    /* Une ligne lisible d'un coup d'oeil sur le dashboard. */
    private function summaryOf($_train) {
        $parts = array($_train['vehicle'], date('H:i', $_train['depTs']));
        $delay = self::minutes($_train['depDelay']);
        if ($_train['depCanceled'] || $_train['arrCanceled']) {
            $parts[] = __('SUPPRIMÉ', __FILE__);
        } elseif ($delay > 0) {
            $parts[] = '+' . $delay . ' ' . __('min', __FILE__);
        }
        if ($_train['depPlatform'] != '' && $_train['depPlatform'] != '?') {
            $parts[] = __('voie', __FILE__) . ' ' . $_train['depPlatform'];
        }
        return implode(' · ', $parts);
    }

    /* ================================================================ ALERTES */

    /*
     * Compare l'état courant à ce qui a déjà été signalé, et n'agit que sur la
     * nouveauté. Sans cette mémoire, un train en retard de vingt minutes
     * enverrait vingt notifications, une par passage du cron.
     */
    private function checkAlerts($_journeys) {
        $state = $this->getAlertState();
        $trains = isset($_journeys['trains']) ? $_journeys['trains'] : array();
        $threshold = $this->threshold();
        $now = time();
        $notified = isset($state['notified']) ? $state['notified'] : array();
        $acknowledged = isset($state['acknowledged']) ? $state['acknowledged'] : array();
        $fresh = array();
        $stillAcknowledged = array();
        $events = array();

        foreach ($trains as $train) {
            /*
             * Un train déjà parti ne peut plus être manqué : continuer à alerter
             * dessus ne ferait que retarder l'alerte sur le suivant.
             */
            if ($train['left'] || ($train['depTs'] + $train['depDelay']) < $now) {
                continue;
            }
            $problem = $this->problemOf($train, $threshold);
            if ($problem === null) {
                continue;
            }
            $fresh[$train['key']] = array('flags' => $problem['flags'], 'band' => $problem['band']);

            /*
             * Un train acquitté le reste tant qu'il est au tableau. C'est bien
             * ce qu'on attend d'un acquittement : « j'ai vu, ne me préviens plus
             * pour celui-là » — y compris si son retard s'aggrave encore. Le
             * train disparaît du tableau une fois parti, et l'acquittement avec
             * lui : le lendemain, le même IC alerte de nouveau.
             */
            if (isset($acknowledged[$train['key']])) {
                $stillAcknowledged[$train['key']] = true;
                continue;
            }

            $previous = isset($notified[$train['key']]) ? $notified[$train['key']] : null;
            if ($previous === null) {
                $events[] = $problem;
                continue;
            }
            /* Les états mémorisés par une version antérieure sont des chaînes. */
            if (!is_array($previous)) {
                $previous = array('flags' => (string) $previous, 'band' => 0);
            }
            /*
             * On ne re-signale que ce qui s'aggrave : une tranche de retard plus
             * haute, ou un ennui d'une autre nature (suppression, voie changée).
             * Comparer les signatures à l'identique faisait re-notifier un train
             * qui passait de +25 à +5 — une bonne nouvelle annoncée comme un
             * incident.
             */
            if ($problem['band'] > $previous['band'] || $problem['flags'] !== $previous['flags']) {
                $events[] = $problem;
            }
        }

        $state['notified'] = $fresh;
        $state['acknowledged'] = $stillAcknowledged;
        $this->saveAlertState($state);

        if (empty($events)) {
            return;
        }

        $next = $this->nextTrain($trains, $now);
        $message = $this->alertMessage($events, $this->fallbackTrain($trains, $next, $now));
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $message);
        $this->runAlertCmd($message);
    }

    /*
     * TOUS les ennuis d'un train, et non le premier rencontré. Un train déjà
     * retardé au-delà du seuil peut ensuite changer de voie : ne rendre que le
     * retard laissait l'utilisateur sur le quai habituel, sans rien lui dire.
     *
     * Le retard est rendu séparément, en tranches de dix minutes : c'est lui
     * seul qui peut s'aggraver progressivement, et il faut pouvoir comparer deux
     * relevés pour distinguer une aggravation d'une amélioration.
     */
    private function problemOf($_train, $_threshold) {
        $flags = array();
        $texts = array();
        $band = 0;
        $hour = date('H:i', $_train['depTs']);

        if ($_train['depCanceled'] || $_train['arrCanceled']) {
            $flags[] = self::PROBLEM_CANCELED;
            $texts[] = sprintf(__('%s de %s supprimé', __FILE__), $_train['vehicle'], $hour);
        } else {
            $delay = self::minutes($_train['depDelay']);
            if ($delay >= $_threshold) {
                $band = 10 * (int) floor($delay / 10);
                $texts[] = sprintf(__('%s de %s : +%s min', __FILE__), $_train['vehicle'], $hour, $delay);
            }
            /* Une voie changée sur un train supprimé n'apprend rien. */
            if (!$_train['depPlatformNormal']) {
                $flags[] = self::PROBLEM_PLATFORM . ':' . $_train['depPlatform'];
                $texts[] = sprintf(__('%s de %s : voie %s au lieu de la voie habituelle', __FILE__), $_train['vehicle'], $hour, $_train['depPlatform']);
            }
        }

        if (empty($texts)) {
            return null;
        }
        return array(
            'flags' => implode('|', $flags),
            'band'  => $band,
            'train' => $_train,
            'text'  => implode(' — ', $texts),
        );
    }

    private function alertMessage($_events, $_fallback = null) {
        $texts = array();
        foreach ($_events as $event) {
            $texts[] = $event['text'];
        }
        $message = $this->routeLabel() . ' — ' . implode(', ', array_slice($texts, 0, 3));

        /*
         * Dire ce qu'il faut faire, et pas seulement ce qui ne va pas : c'est
         * toute la différence entre une alerte qui réveille et une alerte qui
         * sert. Le train de repli est déjà en mémoire.
         */
        if ($_fallback !== null) {
            $repli = $_fallback['vehicle'] . ' ' . __('à', __FILE__) . ' '
                . date('H:i', $_fallback['depTs'] + $_fallback['depDelay']);
            if ($_fallback['depPlatform'] != '' && $_fallback['depPlatform'] != '?') {
                $repli .= ', ' . __('voie', __FILE__) . ' ' . $_fallback['depPlatform'];
            }
            $message .= ' — ' . __('repli :', __FILE__) . ' ' . $repli;
        }
        return $message;
    }

    /*
     * La commande d'action choisie par l'utilisateur : une notification, un
     * message, une lampe. C'est le « faire quelque chose » du plugin ; tout le
     * reste n'est que valeurs exposées aux scénarios.
     */
    private function runAlertCmd($_message) {
        $cmdId = trim($this->getConfiguration('alert_cmd', ''));
        if ($cmdId == '') {
            return;
        }
        try {
            $cmd = cmd::byId(str_replace('#', '', $cmdId));
            if (!is_object($cmd)) {
                throw new Exception(__('Commande d\'alerte introuvable :', __FILE__) . ' ' . $cmdId);
            }
            $cmd->execCmd(array(
                'title'   => __('Train', __FILE__) . ' — ' . $this->getName(),
                'message' => $_message,
            ));
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /*
     * Fait taire les alertes des trains actuellement en défaut. Un problème sur
     * un autre train, lui, alerte toujours : acquitter dit « j'ai vu ce
     * retard-là », pas « ne me préviens plus de rien ».
     */
    public function acknowledge() {
        $state = $this->getAlertState();
        $acknowledged = isset($state['acknowledged']) ? $state['acknowledged'] : array();
        $journeys = $this->getJourneys();
        $threshold = $this->threshold();
        $now = time();

        foreach (isset($journeys['trains']) ? $journeys['trains'] : array() as $train) {
            if ($train['left'] || ($train['depTs'] + $train['depDelay']) < $now) {
                continue;
            }
            if ($this->problemOf($train, $threshold) === null) {
                continue;
            }
            $acknowledged[$train['key']] = true;
        }

        $state['acknowledged'] = $acknowledged;
        $this->saveAlertState($state);
        return count($acknowledged);
    }

    /* ============================================================ PERTURBATIONS */

    /*
     * Rapproche les perturbations du réseau des deux gares du trajet. iRail ne
     * publie pas la liste des gares concernées : le titre, lui, porte presque
     * toujours les noms des extrémités de la portion coupée (« Malines -
     * Termonde : ... »). C'est approximatif, et c'est tout ce que la source
     * permet — un faux positif informe, un faux négatif laisse sur le quai.
     */
    public function matchingDisturbances($_allowFetch = true) {
        $names = array();
        foreach (array('from_label', 'to_label') as $key) {
            $label = self::normalize($this->getConfiguration($key, ''));
            /*
             * Les noms de moins de quatre lettres sont écartés : « Ans » se
             * retrouve dans « dans », et le trajet Ans → Namur remontait huit
             * perturbations sans rapport, dont une sur Brussels Airport.
             */
            if (strlen($label) >= 4) {
                $names[] = $label;
            }
        }
        if (empty($names)) {
            return array();
        }

        $matches = array();
        foreach (self::disturbances($_allowFetch) as $disturbance) {
            $haystack = self::normalize(
                (isset($disturbance['title']) ? $disturbance['title'] : '') . ' ' .
                (isset($disturbance['description']) ? $disturbance['description'] : '')
            );
            foreach ($names as $name) {
                // Limite de mot : « Mol » ne doit pas s'accrocher à « Molenbeek ».
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $haystack) === 1) {
                    $matches[] = self::sanitizeText(isset($disturbance['title']) ? $disturbance['title'] : '');
                    break;
                }
            }
        }
        return array_values(array_filter(array_unique($matches)));
    }

    /* =================================================================== CACHE */

    private function journeyKey() {
        return __CLASS__ . '::journeys::' . $this->getId();
    }

    private function alertKey() {
        return __CLASS__ . '::alerts::' . $this->getId();
    }

    public function getJourneys() {
        $cache = cache::byKey($this->journeyKey());
        $value = $cache->getValue();
        if (!is_array($value)) {
            return array();
        }
        return $value;
    }

    private function saveJourneys($_journeys) {
        // Deux jours de durée de vie : au-delà, les horaires mémorisés sont
        // ceux d'avant-hier et ne valent plus rien.
        cache::set($this->journeyKey(), $_journeys, 172800);
    }

    private function clearJourneys() {
        $cache = cache::byKey($this->journeyKey());
        if (is_object($cache)) {
            $cache->remove();
        }
    }

    /* ================================================================= RECUL */

    private function backoffKey() {
        return __CLASS__ . '::backoff::' . $this->getId();
    }

    private function getBackoff() {
        $cache = cache::byKey($this->backoffKey());
        $value = $cache->getValue();
        return is_array($value) ? $value : array();
    }

    /*
     * Chaque échec double l'attente : une minute, deux, quatre… jusqu'à une
     * heure. Un service qui retombe en marche est donc retrouvé en une minute,
     * mais une gare définitivement fausse ne coûte plus que vingt-quatre
     * requêtes par jour au lieu de mille deux cents.
     */
    private function noteFailure($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $state = $this->getBackoff();
        $fails = isset($state['fails']) ? ((int) $state['fails'] + 1) : 1;
        $attente = min(self::BACKOFF_MAX, self::BACKOFF_BASE * pow(2, $fails - 1));
        cache::set($this->backoffKey(), array(
            'fails' => $fails,
            'until' => $now + $attente,
        ), 86400);
    }

    private function clearBackoff() {
        $cache = cache::byKey($this->backoffKey());
        if (is_object($cache)) {
            $cache->remove();
        }
    }

    private function getAlertState() {
        $cache = cache::byKey($this->alertKey());
        $value = $cache->getValue();
        if (!is_array($value)) {
            return array('notified' => array(), 'acknowledged' => array());
        }
        return $value;
    }

    private function saveAlertState($_state) {
        cache::set($this->alertKey(), $_state, 172800);
    }

    private function clearAlertState() {
        $cache = cache::byKey($this->alertKey());
        if (is_object($cache)) {
            $cache->remove();
        }
    }

    /* ================================================================ MESSAGES */

    private function reportProblem($_text) {
        $text = $this->getHumanName() . ' ' . $_text;
        log::add(__CLASS__, 'error', $text);
        /*
         * message::save() ne met à jour que la date et le compteur d'un message
         * existant, jamais son texte : sans cet effacement préalable, la
         * première cause resterait affichée pour toujours.
         */
        message::removeAll(__CLASS__, 'journey' . $this->getId());
        message::add(__CLASS__, $text, '', 'journey' . $this->getId());
    }

    private function clearProblem() {
        message::removeAll(__CLASS__, 'journey' . $this->getId());
    }

    public function getRefreshError() {
        return $this->_refreshError;
    }

    /* ================================================================= RÉGLAGES */

    public function routeLabel() {
        $from = self::sanitizeText($this->getConfiguration('from_label', $this->getConfiguration('from_id', '?')));
        $to = self::sanitizeText($this->getConfiguration('to_label', $this->getConfiguration('to_id', '?')));
        return $from . ' → ' . $to;
    }

    public function threshold() {
        return max(1, (int) $this->getConfiguration('threshold', self::DEFAULT_THRESHOLD));
    }

    public function maxTrains() {
        return min(self::MAX_TRAINS, max(1, (int) $this->getConfiguration('max_trains', self::DEFAULT_MAX_TRAINS)));
    }

    public function watchBefore() {
        return min(self::MAX_WATCH_BEFORE, max(0, (int) $this->getConfiguration('watch_before', self::DEFAULT_WATCH_BEFORE)));
    }

    /* Les jours retenus, en numérotation ISO (1 = lundi). Aucun coché = tous. */
    public function activeDays() {
        $days = array();
        for ($day = 1; $day <= 7; $day++) {
            if ($this->getConfiguration('day_' . $day, 0) == 1) {
                $days[] = $day;
            }
        }
        return empty($days) ? array(1, 2, 3, 4, 5, 6, 7) : $days;
    }

    /*
     * Les créneaux encore utiles, limités aux jours retenus : celui en cours
     * s'il n'est pas fini, puis les suivants. Les bornes rendues sont les vraies
     * bornes du créneau, jamais rabotées sur l'heure courante — c'est fetchSlot()
     * qui décide à partir de quand interroger iRail, et isWatching() a besoin du
     * vrai début pour placer sa fenêtre d'avance.
     */
    public function slots($_now = null, $_count = 2) {
        $now = ($_now === null) ? time() : $_now;
        $active = $this->activeDays();
        $slots = array();

        /*
         * La veille est examinée elle aussi : un créneau de nuit (22:00 → 01:00)
         * ouvert hier soir court encore à 00:30, et c'est très exactement
         * l'heure à laquelle on le consulte. Ne partir que d'aujourd'hui le
         * faisait disparaître au passage de minuit, en pleine surveillance.
         */
        for ($offset = -1; $offset < 8 && count($slots) < $_count; $offset++) {
            $day = strtotime(sprintf('%+d day', $offset), $now);
            if (!in_array((int) date('N', $day), $active)) {
                continue;
            }
            $start = self::atTime($day, $this->getConfiguration('slot_start', self::DEFAULT_SLOT_START));
            $end = self::atTime($day, $this->getConfiguration('slot_end', self::DEFAULT_SLOT_END));
            if ($end < $start) {
                // Un créneau de nuit (22:00 → 01:00) finit le lendemain.
                $end = strtotime('+1 day', $end);
            } elseif ($end == $start) {
                /*
                 * Début et fin identiques : l'utilisateur a voulu un instant,
                 * pas vingt-quatre heures. Reporter la fin au lendemain faisait
                 * surveiller le trajet nuit comprise, à la minute.
                 */
                $end = $start + 3600;
            }
            if ($end < $now) {
                continue;
            }
            $slots[] = array(
                'start' => $start,
                'end'   => $end,
                /*
                 * La date du créneau, et non son rang : une fois le créneau du
                 * jour passé, le premier créneau rendu est un jour futur. S'en
                 * remettre au rang faisait étiqueter « Aujourd'hui » des trains
                 * de lundi prochain.
                 */
                'date'  => date('Ymd', $start),
            );
        }
        return $slots;
    }

    /*
     * Les bornes sont bridées à des heures réelles : « 25:99 » saisi à la main
     * produisait un créneau dont la fin précédait le début, et le trajet
     * répondait « aucun train » pour toujours, sans le moindre message.
     */
    private static function atTime($_day, $_time) {
        $parts = explode(':', trim((string) $_time));
        $hour = isset($parts[0]) ? min(23, max(0, (int) $parts[0])) : 0;
        $minute = isset($parts[1]) ? min(59, max(0, (int) $parts[1])) : 0;
        return mktime($hour, $minute, 0, (int) date('n', $_day), (int) date('j', $_day), (int) date('Y', $_day));
    }

    /* Ce qui, changé, oblige à tout relire. */
    public function signature() {
        return md5(implode('|', array(
            $this->getConfiguration('from_id', ''),
            $this->getConfiguration('to_id', ''),
            $this->getConfiguration('slot_start', ''),
            $this->getConfiguration('slot_end', ''),
            implode(',', $this->activeDays()),
            $this->maxTrains(),
        )));
    }

    /* =================================================================== GARES */

    /* La liste complète des gares desservies, telle qu'iRail la publie. */
    public static function stations($_refresh = false) {
        $key = __CLASS__ . '::stations::' . self::language();
        if (!$_refresh) {
            $cache = cache::byKey($key);
            $value = $cache->getValue();
            if (is_array($value) && !empty($value)) {
                return $value;
            }
        }

        $data = self::call('stations', array());
        $stations = array();
        foreach (isset($data['station']) ? $data['station'] : array() as $station) {
            if (!isset($station['id'])) {
                continue;
            }
            $stations[] = array(
                'id'   => self::sanitizeText($station['id']),
                'name' => self::sanitizeText(isset($station['name']) ? $station['name'] : $station['id']),
                'standardname' => self::sanitizeText(isset($station['standardname']) ? $station['standardname'] : ''),
            );
        }
        if (!empty($stations)) {
            cache::set($key, $stations, self::STATIONS_TTL);
        }
        return $stations;
    }

    /* Les gares dont le nom contient la recherche, au plus vingt. */
    public static function searchStations($_query) {
        $needle = self::normalize($_query);
        if (strlen($needle) < 2) {
            return array();
        }

        /*
         * Trois paniers plutôt qu'un tri : le nom exact d'abord, puis les gares
         * dont le nom commence par la recherche, enfin celles qui la contiennent
         * ailleurs. Chaque panier garde l'ordre d'iRail, alphabétique, pour que
         * deux frappes successives ne réordonnent pas la liste sous le curseur.
         */
        $exact = array();
        $starts = array();
        $contains = array();
        foreach (self::stations() as $station) {
            $name = self::normalize($station['name']);
            $standard = self::normalize($station['standardname']);
            if ($name === $needle || $standard === $needle) {
                $exact[] = $station;
            } elseif (strpos($name, $needle) === 0 || strpos($standard, $needle) === 0) {
                $starts[] = $station;
            } elseif (strpos($name, $needle) !== false || strpos($standard, $needle) !== false) {
                $contains[] = $station;
            }
        }
        return array_slice(array_merge($exact, $starts, $contains), 0, 20);
    }

    public static function stationById($_id) {
        foreach (self::stations() as $station) {
            if ($station['id'] === $_id) {
                return $station;
            }
        }
        return null;
    }

    /* Les perturbations du réseau, mutualisées entre tous les trajets. */
    public static function disturbances($_allowFetch = true) {
        $key = __CLASS__ . '::disturbances::' . self::language();
        $cache = cache::byKey($key);
        $value = $cache->getValue();
        if (is_array($value)) {
            return $value;
        }
        /*
         * Consulter un écran ne doit jamais déclencher d'appel réseau : sans
         * cette porte, ouvrir l'onglet « Trains » après trois minutes bloquait
         * l'interface le temps d'un aller-retour vers iRail, contredisant ce que
         * le bandeau de l'onglet promet.
         */
        if (!$_allowFetch) {
            return array();
        }

        try {
            $data = self::call('disturbances', array());
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Perturbations indisponibles :', __FILE__) . ' ' . $e->getMessage());
            /*
             * Cinq minutes, et non soixante secondes : à une minute, l'entrée
             * expirait pile à chaque passage du cron et l'échec triplait la
             * charge au lieu de la réduire.
             */
            cache::set($key, array(), 300);
            return array();
        }

        $disturbances = isset($data['disturbance']) ? $data['disturbance'] : array();
        if (isset($disturbances['id'])) {
            $disturbances = array($disturbances);
        }

        /*
         * iRail mélange dans la même liste les incidents en cours (type
         * « disturbance ») et les travaux programmés (type « planned »), ces
         * derniers très largement majoritaires. Un navetteur veut savoir ce qui
         * se passe ce matin, pas ce qui est prévu dans trois semaines.
         */
        $current = array();
        foreach ($disturbances as $disturbance) {
            if (isset($disturbance['type']) && $disturbance['type'] != 'disturbance') {
                continue;
            }
            $current[] = $disturbance;
        }

        cache::set($key, $current, self::DISTURBANCES_TTL);
        return $current;
    }

    /* ==================================================================== HTTP */

    /* Un appel à iRail, avec sa réponse décodée. Lève en cas d'échec. */
    public static function call($_path, $_params = array()) {
        $params = array_merge(array('format' => 'json', 'lang' => self::language()), $_params);
        $url = self::API_BASE . '/' . ltrim($_path, '/') . '?' . http_build_query($params);

        $code = 0;
        $body = self::httpGet($url, $code);

        if ($body === false) {
            throw new Exception(__('iRail ne répond pas.', __FILE__));
        }
        if ($code == 400 || $code == 404) {
            /*
             * 400 pour une gare inconnue, 404 pour un train ou un trajet
             * introuvable : dans les deux cas la demande est mal formée ou sans
             * réponse, ce n'est pas une panne du service. Le message d'iRail
             * expose des noms de classes Java, inutiles à l'utilisateur.
             */
            throw new Exception(__('Aucun trajet trouvé : vérifiez les gares et le créneau.', __FILE__));
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception(__('iRail a refusé la demande :', __FILE__) . ' HTTP ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception(__('Réponse illisible d\'iRail.', __FILE__));
        }
        return $data;
    }

    private static function httpGet($_url, &$_code = null) {
        $timeout = max(3, (int) config::byKey('api_timeout', __CLASS__, 10));
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL            => $_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            /*
             * iRail redirige encore ses anciennes adresses en 303 : suivre les
             * redirections évite qu'un changement de racine ne casse le plugin
             * du jour au lendemain.
             */
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
            /*
             * iRail demande explicitement que chaque client s'identifie, avec
             * un moyen de le contacter : c'est la contrepartie d'un service
             * gratuit et sans clé.
             */
            CURLOPT_USERAGENT      => 'JeedomSNCB/' . self::pluginVersion() . ' (+https://github.com/replicatorbe/jeedom-plugin-sncbnmbs)',
        ));
        $body = curl_exec($curl);
        $_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            log::add(__CLASS__, 'debug', __('Requête en échec :', __FILE__) . ' ' . $_url . ' (' . $error . ')');
            return false;
        }
        return $body;
    }

    /* ================================================================== OUTILS */

    public static function timezone() {
        return new DateTimeZone(config::byKey('timezone', 'core', 'Europe/Brussels'));
    }

    /* La langue des libellés iRail : fr, nl, de ou en. */
    public static function language() {
        $lang = config::byKey('lang', __CLASS__, '');
        if ($lang != '') {
            return $lang;
        }
        $core = substr(config::byKey('language', 'core', 'fr_FR'), 0, 2);
        return in_array($core, array('fr', 'nl', 'de', 'en')) ? $core : 'fr';
    }

    /*
     * Le numéro de version sert à s'identifier auprès d'iRail. Il est appelé à
     * chaque requête, donc à chaque minute : un fichier illisible ne doit ni
     * lever, ni remplir les journaux d'avertissements PHP — file_get_contents
     * émet un warning que catch(Throwable) ne rattrape pas.
     */
    public static function pluginVersion() {
        $path = __DIR__ . '/../../plugin_info/info.json';
        if (!is_readable($path)) {
            return '1.0';
        }
        $info = json_decode((string) file_get_contents($path), true);
        return (is_array($info) && isset($info['pluginVersion'])) ? $info['pluginVersion'] : '1.0';
    }

    /*
     * « Aujourd'hui », « Demain », sinon la date. Une fois le créneau du jour
     * terminé, le premier créneau suivi peut être lundi prochain : l'étiqueter
     * « Aujourd'hui » parce qu'il arrive en tête de liste serait un mensonge.
     */
    public static function dayLabel($_date, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        if ($_date === '') {
            return '';
        }
        if ($_date === date('Ymd', $now)) {
            return __('Aujourd\'hui', __FILE__);
        }
        if ($_date === date('Ymd', strtotime('+1 day', $now))) {
            return __('Demain', __FILE__);
        }
        $ts = strtotime($_date);
        return ($ts === false) ? $_date : date('d/m', $ts);
    }

    /*
     * Neutralise le texte venu d'iRail avant qu'il ne devienne la valeur d'une
     * commande. Les gabarits de widget de Jeedom placent la valeur dans un
     * littéral JavaScript, échappée par addslashes() — ce qui protège des
     * apostrophes mais pas d'un « </script> », qui referme le bloc et rend
     * actif le HTML qui suit. Le plugin ne peut pas corriger le coeur ; il peut
     * s'interdire d'y verser des chevrons.
     */
    public static function sanitizeText($_text) {
        if (!is_scalar($_text)) {
            return '';
        }
        $text = str_replace(array('<', '>'), '', (string) $_text);
        // Les caractères de contrôle ne servent à rien et cassent le JSON.
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        return trim($text);
    }

    /*
     * Un retard est borné à vingt-quatre heures. Au-delà, ce n'est plus un
     * retard mais une donnée corrompue : un champ à 10^21 débordait l'entier,
     * et date() refusant un flottant, le rafraîchissement s'arrêtait sur une
     * erreur fatale sans écrire la moindre commande.
     */
    public static function sanitizeDelay($_seconds) {
        if (!is_scalar($_seconds) || !is_numeric($_seconds)) {
            return 0;
        }
        return (int) min(86400, max(-3600, (float) $_seconds));
    }

    /* Les retards d'iRail sont en secondes ; personne ne parle ainsi. */
    public static function minutes($_seconds) {
        return (int) round(((int) $_seconds) / 60);
    }

    public static function occupancyLabel($_occupancy) {
        switch ($_occupancy) {
            case 'low':    return __('Faible', __FILE__);
            case 'medium': return __('Moyenne', __FILE__);
            case 'high':   return __('Forte', __FILE__);
        }
        return '';
    }

    /* Minuscules, sans accents ni ponctuation : « Bruxelles-Midi » se cherche
     * aussi bien en tapant « bruxelles midi ». */
    public static function normalize($_text) {
        $text = mb_strtolower(trim((string) $_text), 'UTF-8');
        $text = strtr($text, array(
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
        ));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /* =========================================== DONNÉES POUR L'INTERFACE */

    /*
     * Le tableau des trains tel que l'onglet « Trains » et le widget le lisent :
     * déjà mis en forme, pour que ni le JS ni le gabarit n'aient à connaître le
     * vocabulaire d'iRail.
     */
    public function board() {
        $journeys = $this->getJourneys();
        $now = time();
        $next = $this->nextTrain(isset($journeys['trains']) ? $journeys['trains'] : array(), $now);
        $rows = array();

        foreach (isset($journeys['trains']) ? $journeys['trains'] : array() as $train) {
            $delay = self::minutes($train['depDelay']);
            $canceled = ($train['depCanceled'] || $train['arrCanceled']);
            $rows[] = array(
                'key'       => $train['key'],
                'day'       => self::dayLabel(isset($train['date']) ? $train['date'] : '', $now),
                'time'      => date('H:i', $train['depTs']),
                'real'      => date('H:i', $train['depTs'] + $train['depDelay']),
                'delay'     => $delay,
                'vehicle'   => $train['vehicle'],
                'direction' => $train['direction'],
                'platform'  => ($train['depPlatform'] == '?') ? '' : $train['depPlatform'],
                'platformChanged' => !$train['depPlatformNormal'],
                'arrival'   => ($train['arrTs'] > 0) ? date('H:i', $train['arrTs'] + $train['arrDelay']) : '',
                'duration'  => self::minutes($train['duration']),
                'transfers' => $train['transfers'],
                'occupancy' => self::occupancyLabel($train['occupancy']),
                'canceled'  => $canceled,
                'left'      => $train['left'],
                'isNext'    => ($next !== null && $next['key'] === $train['key']),
                'alerts'    => $train['alerts'],
                'status'    => $canceled ? 'canceled' : (($delay >= $this->threshold()) ? 'delayed' : (($delay > 0) ? 'slight' : 'ontime')),
            );
        }

        return array(
            'route'       => $this->routeLabel(),
            'lastUpdate'  => isset($journeys['fetchedAt']) ? date('d/m/Y H:i', $journeys['fetchedAt']) : '',
            'watching'    => $this->isWatching($now),
            'threshold'   => $this->threshold(),
            'trains'      => $rows,
            'disturbances' => $this->matchingDisturbances(false),
        );
    }
}

class sncbnmbsCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'refresh':
                $eqLogic->update(true);
                /*
                 * update() ne lève pas quand des horaires sont déjà en cache :
                 * sans ce relais, un scénario appelant cette commande croirait
                 * ses trains relus alors qu'iRail est en panne.
                 */
                if ($eqLogic->getRefreshError() != '') {
                    throw new Exception($eqLogic->getRefreshError());
                }
                return true;

            case 'acknowledge':
                $eqLogic->acknowledge();
                return true;
        }
        return true;
    }
}
