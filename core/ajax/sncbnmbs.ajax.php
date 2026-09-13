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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Récupère un trajet du plugin.
     * eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans ce contrôle, un id étranger ferait agir le plugin sur
     * l'équipement d'un autre. */
    $getJourney = function ($_id) {
        $eqLogic = sncbnmbs::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'sncbnmbs') {
            throw new Exception(__('Trajet introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /* unautorizedInDemo() sur toutes les actions qui sortent de la box : une
     * démonstration publique ne doit pas interroger un service extérieur. */
    if (init('action') == 'searchStation') {
        unautorizedInDemo();
        ajax::success(sncbnmbs::searchStations(init('q')));
    }

    if (init('action') == 'board') {
        /*
         * Lit le cache du serveur, sans jamais appeler iRail : ouvrir l'onglet
         * « Trains » ne doit pas consommer le quota de l'utilisateur.
         */
        $eqLogic = $getJourney(init('id'));
        ajax::success($eqLogic->board());
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = $getJourney(init('id'));
        if (!$eqLogic->isConfigured()) {
            throw new Exception($eqLogic->configurationError());
        }

        $eqLogic->update(true);
        /*
         * update() ne lève pas quand des horaires sont déjà en cache : sans ce
         * contrôle, une panne d'iRail produirait un message vert « 6 trains
         * connus » et l'utilisateur croirait ses horaires à jour.
         */
        if ($eqLogic->getRefreshError() != '') {
            throw new Exception($eqLogic->getRefreshError());
        }

        $board = $eqLogic->board();
        ajax::success(array(
            'summary' => count($board['trains']) . ' ' . __('trains connus pour ce trajet.', __FILE__),
            'board'   => $board,
        ));
    }

    if (init('action') == 'acknowledge') {
        // Modifie l'état de la box : une démonstration publique ne doit pas
        // pouvoir éteindre les alertes des autres.
        unautorizedInDemo();
        $eqLogic = $getJourney(init('id'));
        $count = $eqLogic->acknowledge();
        ajax::success(array(
            'summary' => ($count == 0)
                ? __('Aucune alerte en cours à acquitter.', __FILE__)
                : $count . ' ' . __('train(s) acquitté(s) : plus de notification pour eux, les autres restent surveillés.', __FILE__),
        ));
    }

    if (init('action') == 'disturbances') {
        unautorizedInDemo();
        $disturbances = array();
        foreach (sncbnmbs::disturbances() as $disturbance) {
            $disturbances[] = array(
                'title'       => isset($disturbance['title']) ? $disturbance['title'] : '',
                'description' => isset($disturbance['description']) ? $disturbance['description'] : '',
                'link'        => isset($disturbance['link']) ? $disturbance['link'] : '',
                'date'        => isset($disturbance['timestamp']) ? date('d/m/Y H:i', (int) $disturbance['timestamp']) : '',
            );
        }
        ajax::success($disturbances);
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
