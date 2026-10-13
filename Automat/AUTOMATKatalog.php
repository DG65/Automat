<?php

declare(strict_types=1);

// ===========================================================================
// AUTOMATKatalog — alle Automatismus-Typen an EINER Stelle: Bezeichnung,
// Kategorie, welche Geräte sie brauchen, welche Felder der Bearbeiten-Dialog
// zeigt und welche Standardwerte gelten. Formular (Dialog, Katalog-Panel),
// Laufzeit-Logik und Prüfstand lesen alle aus dieser Tabelle — ein neuer Typ
// entsteht hier und in AUTOMATLogik, nirgends sonst.
//
// Feldnamen tragen ein Typ-Kürzel (zl_, bl_, …): Der Dialog schreibt JEDES
// benannte Element in die Listenzeile, und Namen dürfen im Formular nicht
// doppelt vorkommen (Symcon-Doku „List → form“).
// ===========================================================================

class AUTOMATKatalog
{
    /** Anzahl Variablen-Plätze je Mehrfach-Feld (Melder, Ausgänge, Kontakte, …). */
    public const SLOTS = 4;

    /** Hausmodi (Werte der Variable „Hausmodus“). */
    public const MODES = [0 => 'Tag', 1 => 'Abend', 2 => 'Nacht', 3 => 'Abwesend', 4 => 'Urlaub'];
    public const MODE_NIGHT = 2;
    public const MODE_AWAY = 3;
    public const MODE_HOLIDAY = 4;

    public const CATEGORIES = [
        'licht'        => '💡 Licht',
        'klima'        => '🌡 Klima',
        'ueberwachung' => '🔔 Überwachung',
    ];

    /** Geräte-Rollen der Erkennung, mit Anzeigename für „dafür fehlt …“. */
    public const ROLES = [
        'light'    => 'Licht (Schalter oder Dimmer)',
        'motion'   => 'Bewegungs-/Präsenzmelder',
        'contact'  => 'Fenster-/Türkontakt',
        'setpoint' => 'Heizungs-Sollwert (Thermostat)',
        'lux'      => 'Helligkeitssensor',
        'power'    => 'Leistungsmessung eines Haushaltsgeräts',
    ];

    public const TYPES = [
        'zeitlicht' => [
            'label' => 'Zeitlicht (Treppenlicht)', 'icon' => '⏲', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Licht geht nach einer einstellbaren Zeit von selbst wieder aus — per Taster gestartet oder bei jedem Einschalten. Mit Vorwarnung, Nachtriggern und Dauerlicht.',
            'needs' => ['light'],
        ],
        'bewegung' => [
            'label' => 'Bewegungslicht', 'icon' => '🚶', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Licht an bei Bewegung, aus nach Nachlaufzeit. Bleibt an, solange noch Bewegung gemeldet wird, schaltet nur bei Dunkelheit und respektiert Handbedienung.',
            'needs' => ['motion', 'light'],
        ],
        'daemmerung' => [
            'label' => 'Dämmerungs- und Zeitschaltung', 'icon' => '🌆', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Schaltet z. B. Außenlicht bei Sonnenuntergang, zu einer Uhrzeit oder bei Dunkelheit ein und zu einer Uhrzeit, bei Sonnenaufgang oder bei Helligkeit wieder aus — auf Wunsch mit Zufallsversatz.',
            'needs' => ['light'],
        ],
        'fenster' => [
            'label' => 'Fenster offen → Heizung runter', 'icon' => '🪟', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Senkt den Heizungs-Sollwert ab, sobald ein Fenster länger als die Verzögerung offen ist, und stellt ihn beim Schließen wieder her. Ein zwischenzeitlich vom Wochenplan geänderter Sollwert wird gemerkt.',
            'needs' => ['contact', 'setpoint'],
        ],
        'waechter' => [
            'label' => 'Wächter „bleibt zu lange so“', 'icon' => '⏱', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Meldet, wenn ein Zustand zu lange anhält — z. B. Tür/Fenster offen, Temperatur zu hoch, Feuchte zu hoch, Wert unverändert. Mit Wiederholung und Entwarnung.',
            'needs' => [],
        ],
        'fertig' => [
            'label' => 'Gerät fertig', 'icon' => '🧺', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Meldet, wenn z. B. Waschmaschine, Trockner oder Spülmaschine fertig ist — erkannt an der Leistungsaufnahme.',
            'needs' => ['power'],
        ],
    ];

    // Auswahllisten
    public const WA_OPS = [0 => 'ist gleich', 1 => 'ist ungleich', 2 => 'ist größer als', 3 => 'ist kleiner als', 4 => 'ist an / wahr', 5 => 'ist aus / falsch'];
    public const DZ_ON = [0 => 'Sonnenuntergang (± Versatz)', 1 => 'Uhrzeit', 2 => 'Dunkelheit (Helligkeit unter Schwelle)'];
    public const DZ_OFF = [0 => 'Uhrzeit', 1 => 'Sonnenaufgang (± Versatz)', 2 => 'Helligkeit über Schwelle', 3 => 'gar nicht (nur einschalten)'];

    /** Typ bekannt? */
    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return isset(self::TYPES[$type]) ? self::TYPES[$type]['icon'] . ' ' . self::TYPES[$type]['label'] : '❔ Unbekannter Typ';
    }

    public static function time(int $h, int $m): string
    {
        return json_encode(['hour' => $h, 'minute' => $m, 'second' => 0]);
    }

    /**
     * Felder je Typ. 'k' = Art (var, vars, int, float, bool, select, time, text, info),
     * 'd' = Standardwert. Bei 'vars' entstehen SLOTS Felder name1..nameN.
     */
    public static function fields(string $type): array
    {
        switch ($type) {
            case 'zeitlicht':
                return [
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'zl_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Wodurch startet die Zeit? (leer lassen = jedes Einschalten des Lichts startet die Zeit, z. B. über den normalen Lichtschalter)'],
                    ['n' => 'zl_trg', 'k' => 'vars', 'c' => 'Taster / Auslöser'],
                    ['k' => 'info', 'c' => 'Zeiten und Helligkeit'],
                    ['n' => 'zl_dauer', 'k' => 'int', 'c' => 'Leuchtdauer', 'd' => 180, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'zl_vorwarn', 'k' => 'int', 'c' => 'Vorwarnung vor dem Ausschalten (0 = aus)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'zl_nachtrigger', 'k' => 'bool', 'c' => 'Erneutes Auslösen verlängert die Zeit (Nachtriggern)', 'd' => true],
                    ['n' => 'zl_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'zl_nachtwert', 'k' => 'int', 'c' => 'Helligkeit im Hausmodus „Nacht“ (0 = wie oben)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                ];
            case 'bewegung':
                return [
                    ['k' => 'info', 'c' => 'Melder (einer reicht — mehrere werden ODER-verknüpft)'],
                    ['n' => 'bl_mel', 'k' => 'vars', 'c' => 'Bewegungsmelder', 'req' => true],
                    ['n' => 'bl_impuls', 'k' => 'bool', 'c' => 'Melder senden nur „Bewegung“, nie „keine Bewegung“ (Nachlauf ab jeder Meldung)', 'd' => false],
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'bl_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Zeiten, Helligkeit und Handbedienung'],
                    ['n' => 'bl_nachlauf', 'k' => 'int', 'c' => 'Nachlaufzeit nach der letzten Bewegung', 'd' => 120, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'bl_vorwarn', 'k' => 'int', 'c' => 'Vorwarnung vor dem Ausschalten (0 = aus)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'bl_lux', 'k' => 'var', 'c' => 'Helligkeitssensor (optional)'],
                    ['n' => 'bl_luxschwelle', 'k' => 'float', 'c' => 'Nur einschalten, wenn dunkler als', 'd' => 30.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000],
                    ['n' => 'bl_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'bl_nachtwert', 'k' => 'int', 'c' => 'Helligkeit im Hausmodus „Nacht“ (0 = wie oben)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                    ['n' => 'bl_handpause', 'k' => 'int', 'c' => 'Nach Ausschalten von Hand: Automatik pausieren für', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                ];
            case 'daemmerung':
                return [
                    ['k' => 'info', 'c' => 'Was soll geschaltet werden?'],
                    ['n' => 'dz_out', 'k' => 'vars', 'c' => 'Licht / Ausgang', 'req' => true],
                    ['k' => 'info', 'c' => 'Einschalten'],
                    ['n' => 'dz_ein', 'k' => 'select', 'c' => 'Einschalten bei', 'd' => 0, 'options' => self::DZ_ON],
                    ['n' => 'dz_einversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenuntergang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240],
                    ['n' => 'dz_einzeit', 'k' => 'time', 'c' => 'Einschaltzeit', 'd' => self::time(18, 0)],
                    ['k' => 'info', 'c' => 'Ausschalten'],
                    ['n' => 'dz_aus', 'k' => 'select', 'c' => 'Ausschalten bei', 'd' => 0, 'options' => self::DZ_OFF],
                    ['n' => 'dz_auszeit', 'k' => 'time', 'c' => 'Ausschaltzeit', 'd' => self::time(23, 0)],
                    ['n' => 'dz_ausversatz', 'k' => 'int', 'c' => 'Versatz zum Sonnenaufgang (− = früher)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240],
                    ['k' => 'info', 'c' => 'Helligkeit und Zufall'],
                    ['n' => 'dz_lux', 'k' => 'var', 'c' => 'Helligkeitssensor (nur für „Dunkelheit“/„Helligkeit“)'],
                    ['n' => 'dz_luxschwelle', 'k' => 'float', 'c' => 'Helligkeitsschwelle', 'd' => 20.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000],
                    ['n' => 'dz_zufall', 'k' => 'int', 'c' => 'Zufallsversatz je Tag (±)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['n' => 'dz_wert', 'k' => 'int', 'c' => 'Helligkeit bei Dimmern', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                ];
            case 'fenster':
                return [
                    ['k' => 'info', 'c' => 'Fenster-/Türkontakte (eines offen reicht)'],
                    ['n' => 'fo_kon', 'k' => 'vars', 'c' => 'Kontakt', 'req' => true],
                    ['n' => 'fo_invert', 'k' => 'bool', 'c' => 'Kontakte melden „wahr“ = GESCHLOSSEN (manche Matter-/Zigbee-Kontakte)', 'd' => false],
                    ['k' => 'info', 'c' => 'Heizungs-Sollwerte, die abgesenkt werden'],
                    ['n' => 'fo_soll', 'k' => 'vars', 'c' => 'Sollwert', 'req' => true],
                    ['k' => 'info', 'c' => 'Absenkung'],
                    ['n' => 'fo_verz', 'k' => 'int', 'c' => 'Erst absenken, wenn länger offen als', 'd' => 60, 'suffix' => ' s', 'min' => 0, 'max' => 3600],
                    ['n' => 'fo_absenk', 'k' => 'float', 'c' => 'Abgesenkter Sollwert', 'd' => 12.0, 'suffix' => ' °C', 'min' => 0, 'max' => 30],
                ];
            case 'waechter':
                return [
                    ['k' => 'info', 'c' => 'Was wird überwacht?'],
                    ['n' => 'wa_var', 'k' => 'var', 'c' => 'Variable', 'req' => true],
                    ['n' => 'wa_op', 'k' => 'select', 'c' => 'Melden, wenn der Wert', 'd' => 4, 'options' => self::WA_OPS],
                    ['n' => 'wa_wert', 'k' => 'text', 'c' => 'Vergleichswert (für gleich/ungleich/größer/kleiner)', 'd' => ''],
                    ['n' => 'wa_dauer', 'k' => 'int', 'c' => '… und zwar länger als', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 10080],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'wa_text', 'k' => 'text', 'c' => 'Meldungstext (leer = automatisch)', 'd' => ''],
                    ['n' => 'wa_wdh', 'k' => 'int', 'c' => 'Wiederholen alle (0 = einmal)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'wa_ende', 'k' => 'bool', 'c' => 'Entwarnung melden, wenn der Zustand vorbei ist', 'd' => true],
                    ['n' => 'wa_push', 'k' => 'bool', 'c' => 'Als Push-Nachricht an alle Visualisierungen senden', 'd' => true],
                ];
            case 'fertig':
                return [
                    ['k' => 'info', 'c' => 'Leistungsmessung des Geräts'],
                    ['n' => 'gf_var', 'k' => 'var', 'c' => 'Leistung (W)', 'req' => true],
                    ['n' => 'gf_lauf', 'k' => 'float', 'c' => 'Läuft ab', 'd' => 20.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_fertig', 'k' => 'float', 'c' => 'Fertig unter', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_minlauf', 'k' => 'int', 'c' => 'Mindestlaufzeit (kürzere Spitzen zählen nicht)', 'd' => 5, 'suffix' => ' min', 'min' => 0, 'max' => 600],
                    ['n' => 'gf_nach', 'k' => 'int', 'c' => 'Fertig, wenn so lange unter der Schwelle', 'd' => 3, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['k' => 'info', 'c' => 'Meldung'],
                    ['n' => 'gf_text', 'k' => 'text', 'c' => 'Meldungstext (leer = „<Name> ist fertig.“)', 'd' => ''],
                    ['n' => 'gf_push', 'k' => 'bool', 'c' => 'Als Push-Nachricht an alle Visualisierungen senden', 'd' => true],
                ];
        }
        return [];
    }

    /** Standardwerte eines Typs als Zeile (ohne Name/Raum/Uid). */
    public static function defaults(string $type): array
    {
        $row = ['Type' => $type, 'TypeLabel' => self::label($type), 'Enabled' => true, 'Cond' => ''];
        foreach (self::MODES as $m => $_) {
            $row['m' . $m] = true;
        }
        foreach (self::fields($type) as $f) {
            if (!isset($f['n'])) {
                continue;
            }
            if ($f['k'] === 'vars') {
                for ($i = 1; $i <= self::SLOTS; $i++) {
                    $row[$f['n'] . $i] = 0;
                }
            } elseif ($f['k'] === 'var') {
                $row[$f['n']] = 0;
            } else {
                $row[$f['n']] = $f['d'];
            }
        }
        return $row;
    }

    /** Variablen eines Mehrfach-Felds (0 und doppelte entfernt). */
    public static function vars(array $row, string $name): array
    {
        $out = [];
        for ($i = 1; $i <= self::SLOTS; $i++) {
            $v = (int)($row[$name . $i] ?? 0);
            if ($v > 0 && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Alle Variablen einer Zeile nach Rolle: 'in' (Ereignisse, auf die reagiert
     * wird), 'out' (wird geschaltet), 'read' (wird nur gelesen).
     */
    public static function variables(array $row): array
    {
        $t = (string)($row['Type'] ?? '');
        $in = $out = $read = [];
        switch ($t) {
            case 'zeitlicht':
                $out = self::vars($row, 'zl_out');
                $in = self::vars($row, 'zl_trg');
                break;
            case 'bewegung':
                $in = self::vars($row, 'bl_mel');
                $out = self::vars($row, 'bl_out');
                if ((int)($row['bl_lux'] ?? 0) > 0) {
                    $read[] = (int)$row['bl_lux'];
                }
                break;
            case 'daemmerung':
                $out = self::vars($row, 'dz_out');
                if ((int)($row['dz_lux'] ?? 0) > 0) {
                    $read[] = (int)$row['dz_lux'];
                }
                break;
            case 'fenster':
                $in = self::vars($row, 'fo_kon');
                $out = self::vars($row, 'fo_soll');
                break;
            case 'waechter':
                if ((int)($row['wa_var'] ?? 0) > 0) {
                    $in[] = (int)$row['wa_var'];
                }
                break;
            case 'fertig':
                if ((int)($row['gf_var'] ?? 0) > 0) {
                    $in[] = (int)$row['gf_var'];
                }
                break;
        }
        return ['in' => $in, 'out' => $out, 'read' => $read];
    }

    /**
     * Was fehlt, damit der Automatismus arbeiten kann? Leere Liste = vollständig.
     * $exists: callable(int $vid): bool — ob die Variable existiert.
     */
    public static function problems(array $row, callable $exists): array
    {
        $t = (string)($row['Type'] ?? '');
        if (!self::has($t)) {
            return ['Typ fehlt oder ist unbekannt — Zeile bearbeiten und einen Typ wählen.'];
        }
        $p = [];
        foreach (self::fields($t) as $f) {
            if (empty($f['req'])) {
                continue;
            }
            $vals = $f['k'] === 'vars' ? self::vars($row, $f['n']) : (((int)($row[$f['n']] ?? 0) > 0) ? [(int)$row[$f['n']]] : []);
            if (count($vals) === 0) {
                $p[] = '„' . $f['c'] . '“ ist nicht gewählt.';
            }
        }
        foreach (self::variables($row) as $list) {
            foreach ($list as $vid) {
                if (!$exists($vid)) {
                    $p[] = 'Variable #' . $vid . ' existiert nicht mehr.';
                }
            }
        }
        if ($t === 'daemmerung') {
            $needLux = (int)($row['dz_ein'] ?? 0) === 2 || (int)($row['dz_aus'] ?? 0) === 2;
            if ($needLux && (int)($row['dz_lux'] ?? 0) <= 0) {
                $p[] = 'Für „Dunkelheit“/„Helligkeit“ fehlt der Helligkeitssensor.';
            }
        }
        if ($t === 'fertig' && (float)($row['gf_fertig'] ?? 0) >= (float)($row['gf_lauf'] ?? 0)) {
            $p[] = '„Fertig unter“ muss kleiner sein als „Läuft ab“.';
        }
        return $p;
    }
}
