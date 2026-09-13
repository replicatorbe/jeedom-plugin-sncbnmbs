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

/*
 * Attention : le coeur appelle cette fonction à la DÉSACTIVATION du plugin
 * (plugin::setIsEnable(0)), pas seulement à sa désinstallation. Y jeter la liste
 * des 715 gares, mise en cache pour une semaine, obligerait à la retélécharger
 * à chaque aller-retour dans la page des plugins. Seules les perturbations,
 * périssables par nature, sont nettoyées ici.
 */
function sncbnmbs_remove() {
    foreach (array('fr', 'nl', 'de', 'en') as $lang) {
        try {
            $cache = cache::byKey('sncbnmbs::disturbances::' . $lang);
            if (is_object($cache)) {
                $cache->remove();
            }
        } catch (Throwable $e) {
            log::add('sncbnmbs', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
        }
    }
}
