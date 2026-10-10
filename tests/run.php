<?php
/* Rejeu hors ligne de la lecture des trajets.
 *
 *   php tests/run.php          # hors ligne
 *   php tests/run.php --live   # ajoute un essai sur la vraie API iRail
 *
 * Chaque panne vue en production doit y laisser un cas : il devient un test de
 * non-régression permanent. */

require_once __DIR__ . '/stub.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation,
 * on la recopie sans ce require. La copie est effacée en sortant. */
$original = __DIR__ . '/../core/class/sncbnmbs.class.php';
$copy = __DIR__ . '/.sncbnmbs.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$live = in_array('--live', array_slice($argv, 1), true);
$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-58s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-58s obtenu %s, attendu %s\n", $_label,
            var_export($_actual, true), var_export($_expected, true));
    }
}

/* Un trajet dont les réponses d'iRail sont fournies page par page. */
class trajetRejoue extends sncbnmbs {
    public static $pages = array();
    public static $demandes = array();
    public static function call($_path, $_params = array()) {
        self::$demandes[] = $_params;
        $page = array_shift(self::$pages);
        if ($page instanceof Exception) {
            throw $page;
        }
        return array('connection' => $page === null ? array() : $page);
    }
}

function connexion($_ts, $_left = false, $_vehicle = null, $_delay = 0) {
    return array(
        'id'        => (string) $_ts,
        'duration'  => '300',
        'departure' => array(
            'time'    => (string) $_ts,
            'delay'   => (string) $_delay,
            'left'    => $_left ? '1' : '0',
            'vehicle' => 'BE.NMBS.' . ($_vehicle === null ? 'IC' . date('Hi', $_ts) : $_vehicle),
        ),
        'arrival' => array('time' => (string) ($_ts + 300)),
    );
}

function lire($_slot, $_now) {
    $trajet = new trajetRejoue();
    $trajet->setConfiguration('from_id', 'BE.NMBS.008813003');
    $trajet->setConfiguration('to_id', 'BE.NMBS.008812005');
    $fetch = new ReflectionMethod('sncbnmbs', 'fetchSlot');
    $fetch->setAccessible(true);
    $next = new ReflectionMethod('sncbnmbs', 'nextTrain');
    $next->setAccessible(true);
    $trains = $fetch->invoke($trajet, $_slot, $_now);
    return array($trains, $next->invoke($trajet, $trains, $_now));
}

$jour = mktime(0, 0, 0, 10, 10, 2026);
$h = function ($_hhmm) use ($jour) {
    return $jour + (int) substr($_hhmm, 0, 2) * 3600 + (int) substr($_hhmm, 2, 2) * 60;
};
$creneau = array('date' => date('Ymd', $jour), 'start' => $h('1200'), 'end' => $h('1500'));

echo "Axe chargé : six trains déjà partis dans la demi-heure de recul\n";
trajetRejoue::$demandes = array();
trajetRejoue::$pages = array(
    array(connexion($h('1307'), true), connexion($h('1310'), true), connexion($h('1313'), true),
          connexion($h('1316'), true), connexion($h('1320'), true), connexion($h('1323'), true)),
    array(connexion($h('1323'), true), connexion($h('1326')), connexion($h('1329')),
          connexion($h('1332')), connexion($h('1335')), connexion($h('1338'))),
    array(connexion($h('1341')), connexion($h('1344')), connexion($h('1347')),
          connexion($h('1350')), connexion($h('1353')), connexion($h('1356'))),
);
list($trains, $prochain) = lire($creneau, $h('1338') - 30);
check('le prochain train est trouvé', $prochain === null ? null : date('H:i', $prochain['depTs']), '13:38');
check('trois pages demandées', count(trajetRejoue::$demandes), 3);
check('la 2e page repart du dernier départ lu', trajetRejoue::$demandes[1]['time'], '1323');
check('le 13:23 relu n\'est pas compté deux fois', count($trains), 17);

echo "Ligne calme : une page suffit\n";
trajetRejoue::$demandes = array();
trajetRejoue::$pages = array(array(connexion($h('1320'), true), connexion($h('1350')), connexion($h('1420'))));
list($trains, $prochain) = lire($creneau, $h('1330'));
check('une seule demande', count(trajetRejoue::$demandes), 1);
check('le prochain train est le 13:50', date('H:i', $prochain['depTs']), '13:50');

echo "Page pleine qui dépasse la fin du créneau : on s'arrête\n";
trajetRejoue::$demandes = array();
trajetRejoue::$pages = array(array(connexion($h('1440'), true), connexion($h('1450')), connexion($h('1500')),
    connexion($h('1510')), connexion($h('1520')), connexion($h('1530'))));
list($trains, $prochain) = lire($creneau, $h('1445'));
check('une seule demande', count(trajetRejoue::$demandes), 1);
check('les trains après le créneau sont écartés', count($trains), 3);

echo "Une page suivante en échec ne fait pas tomber le créneau\n";
trajetRejoue::$demandes = array();
trajetRejoue::$pages = array(
    array(connexion($h('1307'), true), connexion($h('1310'), true), connexion($h('1313'), true),
          connexion($h('1316'), true), connexion($h('1320'), true), connexion($h('1323'))),
    new sncbnmbsTransientException('HTTP 504'),
);
list($trains, $prochain) = lire($creneau, $h('1322'));
check('les trains de la 1re page sont gardés', count($trains), 6);
check('le prochain train reste connu', date('H:i', $prochain['depTs']), '13:23');

echo "La 1re page en échec remonte l'erreur\n";
trajetRejoue::$pages = array(new sncbnmbsTransientException('HTTP 504'));
try {
    lire($creneau, $h('1322'));
    check('exception levée', false, true);
} catch (sncbnmbsTransientException $e) {
    check('exception levée', true, true);
}

echo "Un train parti en retard reste proposé tant qu'il n'a pas quitté le quai\n";
trajetRejoue::$pages = array(array(connexion($h('1310'), false, 'IC2137', 900), connexion($h('1340'))));
list($trains, $prochain) = lire($creneau, $h('1320'));
check('le 13:10 à +15 est le prochain', $prochain['vehicle'], 'IC2137');

if ($live) {
    echo "Essai réel : Bruxelles-Central → Bruxelles-Nord, maintenant\n";
    $now = time();
    $trajet = new sncbnmbs();
    $trajet->setConfiguration('from_id', 'BE.NMBS.008813003');
    $trajet->setConfiguration('to_id', 'BE.NMBS.008812005');
    $fetch = new ReflectionMethod('sncbnmbs', 'fetchSlot');
    $fetch->setAccessible(true);
    $next = new ReflectionMethod('sncbnmbs', 'nextTrain');
    $next->setAccessible(true);
    $slot = array('date' => date('Ymd', $now), 'start' => $now - 3600, 'end' => $now + 3600);
    $trains = $fetch->invoke($trajet, $slot, $now);
    $prochain = $next->invoke($trajet, $trains, $now);
    check('un prochain train est trouvé', $prochain !== null, true);
    if ($prochain !== null) {
        printf("        %s à %s (%d trains lus)\n", $prochain['vehicle'], date('H:i', $prochain['depTs']), count($trains));
    }
}

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
