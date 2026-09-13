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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function sncbnmbs_install() {
}

function sncbnmbs_update() {
}

function sncbnmbs_remove() {
    /*
     * La liste des gares et les perturbations sont partagées par tous les
     * trajets : aucun preRemove d'équipement ne les nettoie. Les laisser
     * derrière soi ferait repartir une réinstallation sur des données peut-être
     * périmées, sans moyen de le voir depuis l'interface.
     */
    foreach (array('stations', 'disturbances') as $family) {
        foreach (array('fr', 'nl', 'de', 'en') as $lang) {
            try {
                $cache = cache::byKey('sncbnmbs::' . $family . '::' . $lang);
                if (is_object($cache)) {
                    $cache->remove();
                }
            } catch (Throwable $e) {
                log::add('sncbnmbs', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }
}
