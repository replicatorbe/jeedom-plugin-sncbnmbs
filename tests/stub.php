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

/* Un cache en mémoire, le temps d'un essai : le recul et les trajets lus y vivent. */
class cache {
    public static $values = array();
    private $key;
    public function __construct($_key) { $this->key = $_key; }
    public static function byKey($_key) { return new cache($_key); }
    public static function set($_key, $_value, $_lifetime = 0) { self::$values[$_key] = $_value; }
    public function getValue() { return isset(self::$values[$this->key]) ? self::$values[$this->key] : null; }
    public function remove() { unset(self::$values[$this->key]); }
}

/* Le fichier déclare aussi sa classe de commandes. */
class cmd {
}
