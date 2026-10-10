<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation. Ils ne simulent que ce dont la lecture des trajets
 * a besoin : traduction, configuration, journal et la classe de base. */

/* Le coeur pose le fuseau dans core.inc.php ; PHP en ligne de commande est en
 * UTC, et les heures de créneau seraient décalées d'une ou deux heures. */
date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

class eqLogic {
    public $configuration = array();
    public function getId() { return 1; }
    public function getHumanName() { return '[Test][Trajet]'; }
    public function getConfiguration($_key, $_default = '') {
        return isset($this->configuration[$_key]) ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->configuration[$_key] = $_value;
        return $this;
    }
}

/* Le fichier déclare aussi sa classe de commandes. */
class cmd {
}
