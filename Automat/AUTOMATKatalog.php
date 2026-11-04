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
    /** Freie Plätze über den belegten hinaus — Mehrfachfelder haben damit keine feste Obergrenze (Dietmar 15.10.2026). */
    public const SPARE = 8;
    /** Fenster-/Türkontakte: deren Richtung (wahr = offen oder zu) erkennt Automat je Variable selbst. */
    public const CONTACT_FIELDS = ['fo_kon', 'gc_kon', 'sl_kon', 'rl_tuer', 'lf_kon'];

    /**
     * Kleinste echte Objekt-ID in Symcon (IDs sind fünfstellig, 10000–59999). Kleinere Werte in einem
     * Variablenfeld heißen „nicht gewählt“ — das Auswahlfeld kann beim Leeren/Abbrechen z. B. 1 speichern
     * (an Dietmars Instanz gesehen, 13.10.2026).
     */
    public static int $minId = 10000; // im Prüfstand mit Kurz-IDs herabsetzbar

    /** Hausmodi (Werte der Variable „Hausmodus“). */
    public const MODES = [0 => 'Day', 1 => 'Evening', 2 => 'Night', 3 => 'Away', 4 => 'Holiday'];
    public const MODE_NIGHT = 2;
    public const MODE_AWAY = 3;
    public const MODE_HOLIDAY = 4;

    public const CATEGORIES = [
        'licht'        => '💡 Lighting',
        'klima'        => '🌡 Climate',
        'ueberwachung' => '🔔 Monitoring',
        'haus'         => '🏠 House and presence',
    ];

    /** Geräte-Rollen der Erkennung, mit Anzeigename für „dafür fehlt …“. */
    public const ROLES = [
        'light'    => 'Light (switch or dimmer)',
        'motion'   => 'Motion/presence sensor',
        'contact'  => 'Window/door contact',
        'setpoint' => 'Heating setpoint (thermostat)',
        'lux'      => 'Brightness sensor',
        'power'    => 'Power measurement of a household appliance',
        'presence' => 'Presence of a person (e.g. Geofency, phone on Wi-Fi)',
        'alarm'    => 'Water or smoke detector',
        'shutter'  => 'Shutter/blind (position)',
        'air'      => 'Indoor air (humidity or CO₂)',
        'button'   => 'Button/scene switch',
        'bell'     => 'Doorbell',
        'cooling'  => 'Power measurement of fridge/freezer',
        'temp'     => 'Temperature measurement (indoor and outdoor)',
    ];

    public const TYPES = [
        'zeitlicht' => [
            'label' => 'Timed light (staircase light)', 'icon' => '⏲', 'cat' => 'licht', 'light' => true,
            'desc'  => 'The light switches itself off after an adjustable time — started by a button or every time it is switched on. With pre-warning, retriggering and permanent light.',
            'needs' => ['light'],
        ],
        'bewegung' => [
            'label' => 'Motion light', 'icon' => '🚶', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Light on with motion, off after the follow-up time. Stays on as long as motion is still reported, only switches when it is dark and respects manual operation.',
            'needs' => ['motion', 'light'],
        ],
        'daemmerung' => [
            'label' => 'Dusk and time switching', 'icon' => '🌆', 'cat' => 'licht', 'light' => true,
            'desc'  => 'Switches e.g. outdoor lights on at sunset, at a set time or when it gets dark, and off again at a set time, at sunrise or when it gets bright — optionally with a random offset.',
            'needs' => ['light'],
        ],
        'fenster' => [
            'label' => 'Window open → heating down', 'icon' => '🪟', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Lowers the heating setpoint as soon as a window has been open longer than the delay, and restores it when the window is closed. A setpoint changed by the weekly schedule in the meantime is remembered.',
            'needs' => ['contact', 'setpoint'],
        ],
        'waechter' => [
            'label' => 'Watchdog "stays like this too long"', 'icon' => '⏱', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Notifies when a state lasts too long — e.g. door/window open, temperature too high, humidity too high, value unchanged. With repetition and all-clear.',
            'needs' => [],
        ],
        'fertig' => [
            'label' => 'Appliance finished', 'icon' => '🧺', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Notifies when e.g. the washing machine, dryer or dishwasher has finished — detected by its power consumption.',
            'needs' => ['power'],
        ],
        'anwesenheit' => [
            'label' => 'Combine presence', 'icon' => '🏠', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Combines several presence signals (e.g. Geofency or phone on Wi-Fi per person) into "Someone at home" — "present" immediately, "nobody home" only after a waiting time to bridge short dropouts. The house mode uses the result automatically.',
            'needs' => ['presence'],
        ],
        'gehen' => [
            'label' => 'Leaving check', 'icon' => '🚪', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'When the house mode changes to "Away" or "Holiday" (optionally "Night"), it checks windows, doors and devices and reports in ONE notification what is still open or on — optionally it switches the devices off right away.',
            'needs' => ['contact'],
        ],
        'klingel' => [
            'label' => 'Doorbell', 'icon' => '🛎', 'cat' => 'haus', 'light' => false,
            'desc'  => 'When the doorbell rings: notify (prominently when away), in the evening switch the light on for a few minutes and turn the music down — afterwards everything goes back to how it was. A camera image is sent along to script notification channels (e.g. Telegram).',
            'needs' => ['bell'],
        ],
        'sommer' => [
            'label' => 'Summer ventilation', 'icon' => '☀️', 'cat' => 'klima', 'light' => false,
            'desc'  => 'If it is warmer outside than inside: "Close the windows". When it gets cooler outside: "Ventilate now". With indoor and outdoor humidity the dew point is compared — important for the cellar.',
            'needs' => ['temp'],
        ],
        'ausfall' => [
            'label' => 'Failure watchdog', 'icon' => '🧊', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Notifies when a device draws no power for too long (fridge, freezer — compressor no longer starts) or runs continuously for too long (pump, heating element). Shows what has been "normal" so far.',
            'needs' => ['cooling'],
        ],
        'zeitschalt' => [
            'label' => 'Timer switch', 'icon' => '📅', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Switches devices by weekday and time of day, with public holidays and random offset. If someone switches off by hand in between, it stays that way until the next switching time.',
            'needs' => [],
        ],
        'alarm' => [
            'label' => 'Water/smoke detector response', 'icon' => '🚨', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'When a water or smoke detector triggers: light on, shutters up, water valve or devices off and notify — even during quiet hours and even when paused. Reminds until the all-clear. Does not switch anything back afterwards (safety).',
            'needs' => ['alarm'],
        ],
        'rollladen' => [
            'label' => 'Shutters by time/sun', 'icon' => '🪟', 'cat' => 'haus', 'light' => false,
            'desc'  => 'Up in the morning, down in the evening — at a set time, by sunrise/sunset, brightness or house mode, with earliest/latest and a separate time for the weekend. Lock-out protection: if the door is open, the shutter stays up until it is closed.',
            'needs' => ['shutter'],
        ],
        'standby' => [
            'label' => 'Standby cut-off', 'icon' => '🔌', 'cat' => 'haus', 'light' => false,
            'desc'  => 'If the power stays below a threshold for a while (device only in standby), the socket is switched off — e.g. TV, printer, PC accessories.',
            'needs' => ['power'],
        ],
        'lueften' => [
            'label' => 'Ventilation reminder', 'icon' => '💨', 'cat' => 'klima', 'light' => false,
            'desc'  => 'If the humidity or CO₂ level is too high for a while: "Please ventilate". If a window is open and the air is good again: "Can be closed again".',
            'needs' => ['air'],
        ],
        'heizung' => [
            'label' => 'Heating by house mode', 'icon' => '🌡', 'cat' => 'klima', 'light' => false,
            'desc'  => 'Lowers the heating setpoints in "Away", "Holiday" (and optionally "Night") and restores the remembered value on return. Setpoints changed by hand are not overwritten.',
            'needs' => ['setpoint'],
        ],
        'taster' => [
            'label' => 'Button assignment', 'icon' => '🔘', 'cat' => 'licht', 'light' => false,
            'desc'  => 'Assigns one action each to short, double and long press of a button — toggle light, set a value or change the house mode. For buttons that report their press as a value (e.g. "single", "double", "hold" or 1/2/3).',
            'needs' => ['button'],
        ],
        'wecklicht' => [
            'label' => 'Wake-up/fall-asleep light', 'icon' => '🌅', 'cat' => 'licht', 'light' => false,
            'desc'  => 'Slowly dims the light up over an adjustable time (bright at wake-up time) or down until off. If someone intervenes by hand, the ramp ends.',
            'needs' => ['light'],
        ],
        'szene' => [
            'label' => 'Central off / scene by house mode', 'icon' => '🌙', 'cat' => 'haus', 'light' => false,
            'desc'  => 'When the house mode changes (e.g. to "Night" or "Away"), switches a group of devices off, on or to a value — e.g. everything off when leaving, mood lighting on in the evening. Only at the change, afterwards anyone may switch again.',
            'needs' => ['light'],
        ],
        'ruecksetzen' => [
            'label' => 'Reset sensors', 'icon' => '🔁', 'cat' => 'ueberwachung', 'light' => false,
            'desc'  => 'Resets motion/alarm sensors to "false" when they are "true" and no new report has arrived within the hold time — e.g. because the "no motion" report got lost over the radio. Afterwards lights and events trigger again on the next detection. The hold time should be longer than the time after which the sensor resets itself.',
            'needs' => ['motion'],
        ],
    ];
    public const SZ_ACTIONS = [0 => 'switch off', 1 => 'switch on', 2 => 'set to a value'];
    public const TG_ACTIONS = [0 => 'nothing', 1 => 'toggle (on ↔ off)', 2 => 'switch on', 3 => 'switch off', 4 => 'set to a value', 5 => 'set house mode (value 0 Day … 4 Holiday)'];
    public const RL_UP = [0 => 'at a set time', 1 => 'at sunrise (± offset)', 2 => 'when the house mode changes to "Day"'];
    public const RL_DOWN = [0 => 'at a set time', 1 => 'at sunset (± offset)', 2 => 'when it gets dark (brightness sensor under "🏠 House mode")', 3 => 'when the house mode changes to "Night"'];
    public const WL_DIR = [0 => 'Wake up: slowly brighter until wake-up time', 1 => 'Fall asleep: slowly darker until off'];
    public const WL_START = [0 => 'at a set time', 1 => 'wake-up time from a variable (timestamp or "HH:MM")', 2 => 'when the house mode changes to "Night" (fall asleep)'];
    /** Typen, bei denen der Hausmodus selbst Auslöser ist oder die immer gelten müssen — ohne die allgemeinen Modus-Häkchen. */
    public const NO_MODE_FILTER = ['szene', 'alarm', 'heizung', 'gehen'];
    public const AF_MODES = [0 => 'draws no power for too long (e.g. fridge, freezer)', 1 => 'runs continuously for too long (e.g. pump, heating element)'];
    public const ZS_MODES = [0 => 'switch on and off', 1 => 'switch off only', 2 => 'switch on only'];
    public const ZS_HOLIDAY = [0 => 'like the weekday', 1 => 'like Sunday', 2 => 'do not switch at all'];
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    /** Bundesländer für Feiertage (Kürzel => Name), '' = keine Feiertage berücksichtigen. */
    public const LAENDER = ['' => 'ignore public holidays', 'BW' => 'Baden-Württemberg', 'BY' => 'Bavaria', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hesse', 'MV' => 'Mecklenburg-Western Pomerania', 'NI' => 'Lower Saxony', 'NW' => 'North Rhine-Westphalia', 'RP' => 'Rhineland-Palatinate', 'SL' => 'Saarland', 'SN' => 'Saxony', 'ST' => 'Saxony-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thuringia', 'AT' => 'Austria', 'CH' => 'Switzerland (nationwide holidays)'];

    // Auswahllisten
    public const WA_OPS = [0 => 'equals', 1 => 'does not equal', 2 => 'is greater than', 3 => 'is less than', 4 => 'is on / true', 5 => 'is off / false'];
    public const DZ_ON = [0 => 'Sunset (± offset)', 1 => 'Time of day', 2 => 'Darkness (brightness below threshold)'];
    public const DZ_OFF = [0 => 'Time of day', 1 => 'Sunrise (± offset)', 2 => 'Brightness above threshold', 3 => 'never (switch on only)'];

    /** Typ bekannt? */
    /** Hausmodi in der Sprache des Systems (Konstante MODES bleibt der englische Quelltext). */
    public static function modes(): array
    {
        return array_map([AUTOMATText::class, 'wert'], self::MODES);
    }

    /** Kategorien in der Sprache des Systems. */
    public static function categories(): array
    {
        return array_map([AUTOMATText::class, 'wert'], self::CATEGORIES);
    }

    /** Geräte-Rollen in der Sprache des Systems. */
    public static function roles(): array
    {
        return array_map([AUTOMATText::class, 'wert'], self::ROLES);
    }

    /** Typen mit übersetztem Namen und übersetzter Beschreibung. */
    public static function types(): array
    {
        $out = [];
        foreach (self::TYPES as $k => $d) {
            $d['label'] = AUTOMATText::t($d['label']);
            $d['desc'] = AUTOMATText::t($d['desc']);
            $out[$k] = $d;
        }
        return $out;
    }

    /** Auswahlliste (Konstante) in der Sprache des Systems. */
    public static function tr(array $options): array
    {
        return array_map([AUTOMATText::class, 'wert'], $options);
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return isset(self::TYPES[$type]) ? self::TYPES[$type]['icon'] . ' ' . AUTOMATText::t(self::TYPES[$type]['label']) : AUTOMATText::t('❔ Unknown type');
    }

    public static function time(int $h, int $m): string
    {
        return json_encode(['hour' => $h, 'minute' => $m, 'second' => 0]);
    }

    /**
     * Felder je Typ in der Sprache des Systems (Beschriftung 'c', Hilfe 'help' und Auswahl 'options' übersetzt).
     * 'k' = Art (var, vars, int, float, bool, select, time, text, info),
     * 'd' = Standardwert. Bei 'vars' entstehen SLOTS Felder name1..nameN.
     */
    public static function fields(string $type): array
    {
        $out = [];
        foreach (self::fieldDefs($type) as $f) {
            if (isset($f['c'])) {
                $f['c'] = AUTOMATText::t($f['c']);
            }
            if (isset($f['help'])) {
                $f['help'] = [AUTOMATText::t($f['help'][0]), array_map(function ($line) {
                    return AUTOMATText::t($line);
                }, $f['help'][1])];
            }
            if (isset($f['options'])) {
                $f['options'] = self::tr($f['options']);
            }
            $out[] = $f;
        }
        return $out;
    }

    /** Felder je Typ mit den englischen Quelltexten (für alles, was nur Aufbau und Standardwerte braucht). */
    private static function fieldDefs(string $type): array
    {
        switch ($type) {
            case 'zeitlicht':
                return [
                    ['k' => 'info', 'c' => 'What should be switched?'],
                    ['n' => 'zl_out', 'k' => 'vars', 'c' => 'Light / output', 'req' => true],
                    ['k' => 'info', 'c' => 'What starts the timer? (leave empty = every time the light is switched on, e.g. via the normal light switch)'],
                    ['n' => 'zl_trg', 'k' => 'vars', 'c' => 'Button / trigger'],
                    ['k' => 'info', 'c' => 'Times and brightness'],
                    ['n' => 'zl_dauer', 'k' => 'int', 'c' => 'Light duration', 'd' => 180, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'zl_vorwarn', 'k' => 'int', 'c' => 'Pre-warning before switching off (0 = off)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'zl_nachtrigger', 'k' => 'bool', 'c' => 'Triggering again extends the time (retrigger)', 'd' => true],
                    ['n' => 'zl_wert', 'k' => 'int', 'c' => 'Brightness for dimmers', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'zl_nachtwert', 'k' => 'int', 'c' => 'Brightness in house mode "Night" (0 = as above)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                ];
            case 'bewegung':
                return [
                    ['k' => 'info', 'c' => 'Sensors (one is enough — several are OR-combined)'],
                    ['n' => 'bl_mel', 'k' => 'vars', 'c' => 'Motion sensor', 'req' => true],
                    ['n' => 'bl_impuls', 'k' => 'bool', 'c' => 'Sensor never (or only very late) resets the variable to "off" — restart the follow-up time on every motion report', 'd' => false,
                        'help' => ['When do I need this switch?', [
                            'A motion sensor sets its variable to "on" (true) on motion and normally back to "off" (false) by itself some time later.',
                            'Switch OFF (normal case): the light stays on as long as any sensor is still "on". The follow-up time only starts when all report "off" again.',
                            'Switch ON: Automat only looks at when "on" was last reported and restarts the follow-up time from there each time. An "off" is ignored.',
                            'Switch on if the light stays on permanently although nobody is there any more — then the sensor variable never returns to "off", or only very late (e.g. sensors that just write "on" again on every motion).',
                        ]]],
                    ['n' => 'bl_melmax', 'k' => 'int', 'c' => 'Sensor counts as "no motion" if it reports nothing for this long (0 = learned automatically)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 7200,
                        'help' => ['What is the sensor reset time for?', [
                            'Wireless sensors (e.g. Z-Wave) occasionally lose the "no motion" report. The sensor then seems to be on "motion" permanently and the light would never go off.',
                            'Automat remembers for each sensor how long "motion" normally lasts (from the archive of the last days and continuously learned). If a sensor reports nothing new for more than three times that (at least 30 s), it counts as "no motion" and the follow-up time starts. This is then noted in the log.',
                            'A new motion report resets the time — anyone moving in the room keeps the light. Presence sensors that stay "on" for a long time automatically learn a long time.',
                            '0 = automatic (recommended). A fixed value applies to all sensors of this automation.',
                        ]]],
                    ['k' => 'info', 'c' => 'What should be switched?'],
                    ['n' => 'bl_out', 'k' => 'vars', 'c' => 'Light / output', 'req' => true],
                    ['k' => 'info', 'c' => 'Times, brightness and manual operation'],
                    ['n' => 'bl_nachlauf', 'k' => 'int', 'c' => 'Follow-up time after the last motion', 'd' => 120, 'suffix' => ' s', 'min' => 5, 'max' => 86400],
                    ['n' => 'bl_vorwarn', 'k' => 'int', 'c' => 'Pre-warning before switching off (0 = off)', 'd' => 0, 'suffix' => ' s', 'min' => 0, 'max' => 600],
                    ['n' => 'bl_lux', 'k' => 'var', 'c' => 'Brightness sensor (optional)'],
                    ['n' => 'bl_luxschwelle', 'k' => 'float', 'c' => 'Only switch on if darker than', 'd' => 30.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000],
                    ['n' => 'bl_wert', 'k' => 'int', 'c' => 'Brightness for dimmers', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                    ['n' => 'bl_nachtwert', 'k' => 'int', 'c' => 'Brightness in house mode "Night" (0 = as above)', 'd' => 0, 'suffix' => ' %', 'min' => 0, 'max' => 100],
                    ['n' => 'bl_handpause', 'k' => 'int', 'c' => 'After switching off by hand: pause automatic for', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['k' => 'info', 'c' => 'Learning and further signs of presence'],
                    ['n' => 'bl_lernen', 'k' => 'bool', 'c' => 'Learn follow-up time from false switch-offs (extends when the light comes back on shortly after switching off)', 'd' => true],
                    ['n' => 'bl_nachlaufmax', 'k' => 'int', 'c' => '… learned follow-up time at most', 'd' => 1800, 'suffix' => ' s', 'min' => 10, 'max' => 86400,
                        'help' => ['How does the follow-up time learn?', [
                            'If the light comes back on within 3 minutes of being switched off automatically — by motion or by hand —, someone was still there whom the sensor did not see (sitting still at the table, at the stove). That is a false switch-off.',
                            'Automat then extends the follow-up time of this automation by half, at most up to the configured maximum. If there are no false switch-offs for a day, it goes back towards the base value by 10 %.',
                            'Shortly after its own switch-off, motion switches the light back on even if the room would still be bright enough according to the brightness sensor (residual light of the lamp just switched off).',
                            'The current value and the number of false switch-offs in the last 7 days are shown in the "Settings" column and in the log.',
                        ]]],
                    ['n' => 'bl_hint', 'k' => 'vars', 'c' => 'Further sign of presence',
                        'help' => ['What are further signs of presence?', [
                            'Variables showing that someone is in the room even if the sensor sees nothing — e.g. power of the stove, extractor hood or coffee machine, a running TV, music, a switch.',
                            'As long as a sign is active, no follow-up time starts and a pre-warning is withdrawn. The time only runs once no sensor and no sign is active any more. Signs never switch the light on by themselves.',
                            'Active means: switch "on", numbers (e.g. power in W) above the threshold below. Do not enter devices that run for hours and have nothing to do with presence (dishwasher, fridge).',
                        ]]],
                    ['n' => 'bl_hintmin', 'k' => 'float', 'c' => 'Sign counts as active above (for numeric values, e.g. power in W)', 'd' => 10.0, 'suffix' => '', 'min' => 0, 'max' => 100000],
                ];
            case 'daemmerung':
                return [
                    ['k' => 'info', 'c' => 'What should be switched?'],
                    ['n' => 'dz_out', 'k' => 'vars', 'c' => 'Light / output', 'req' => true],
                    ['k' => 'info', 'c' => 'Switching on'],
                    ['n' => 'dz_ein', 'k' => 'select', 'c' => 'Switch on at', 'd' => 0, 'options' => self::DZ_ON],
                    ['n' => 'dz_einversatz', 'k' => 'int', 'c' => 'Offset to sunset (− = earlier)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['dz_ein', [0]]]],
                    ['n' => 'dz_einzeit', 'k' => 'time', 'c' => 'Switch-on time', 'd' => self::time(18, 0), 'when' => [['dz_ein', [1]]]],
                    ['k' => 'info', 'c' => 'Switching off'],
                    ['n' => 'dz_aus', 'k' => 'select', 'c' => 'Switch off at', 'd' => 0, 'options' => self::DZ_OFF],
                    ['n' => 'dz_auszeit', 'k' => 'time', 'c' => 'Switch-off time', 'd' => self::time(23, 0), 'when' => [['dz_aus', [0]]]],
                    ['n' => 'dz_ausversatz', 'k' => 'int', 'c' => 'Offset to sunrise (− = earlier)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['dz_aus', [1]]]],
                    ['k' => 'info', 'c' => 'Brightness and randomness'],
                    ['n' => 'dz_lux', 'k' => 'var', 'c' => 'Own brightness sensor (optional — empty = the central one under "🏠 House mode"; own one e.g. for a room sensor)', 'when' => [['dz_ein', [2]], ['dz_aus', [2]]]],
                    ['n' => 'dz_luxschwelle', 'k' => 'float', 'c' => 'Threshold for the own sensor', 'd' => 20.0, 'suffix' => ' lx', 'min' => 0, 'max' => 100000, 'when' => [['dz_ein', [2]], ['dz_aus', [2]]]],
                    ['n' => 'dz_zufall', 'k' => 'int', 'c' => 'Random offset per day (±)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['n' => 'dz_wert', 'k' => 'int', 'c' => 'Brightness for dimmers', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100],
                ];
            case 'fenster':
                return [
                    ['k' => 'info', 'c' => 'Window/door contacts (one open is enough)'],
                    ['n' => 'fo_kon', 'k' => 'vars', 'c' => 'Contact', 'req' => true],
                    ['n' => 'fo_invert', 'k' => 'bool', 'c' => 'Contacts report "true" = CLOSED (some Matter/Zigbee contacts)', 'd' => false],
                    ['k' => 'info', 'c' => 'Heating setpoints to be lowered'],
                    ['n' => 'fo_soll', 'k' => 'vars', 'c' => 'Setpoint', 'req' => true],
                    ['k' => 'info', 'c' => 'Setback'],
                    ['n' => 'fo_verz', 'k' => 'int', 'c' => 'Only lower when open longer than', 'd' => 60, 'suffix' => ' s', 'min' => 0, 'max' => 3600],
                    ['n' => 'fo_absenk', 'k' => 'float', 'c' => 'Lowered setpoint', 'd' => 12.0, 'suffix' => ' °C', 'min' => 0, 'max' => 30],
                ];
            case 'waechter':
                return [
                    ['k' => 'info', 'c' => 'What is monitored?'],
                    ['n' => 'wa_var', 'k' => 'var', 'c' => 'Variable', 'req' => true],
                    ['n' => 'wa_op', 'k' => 'select', 'c' => 'Notify when the value', 'd' => 4, 'options' => self::WA_OPS],
                    ['n' => 'wa_wert', 'k' => 'text', 'c' => 'Comparison value (for equal/not equal/greater/less)', 'd' => '', 'when' => [['wa_op', [0, 1, 2, 3]]]],
                    ['n' => 'wa_pick', 'k' => 'pick', 'c' => '… or choose from the possible values of the variable', 'src' => 'wa_var', 'to' => 'wa_wert', 'op' => 'wa_op', 'd' => ''],
                    ['n' => 'wa_frei', 'k' => 'text', 'c' => 'Only "OK" again (and ready to notify again) once the value is back above/below … (empty = immediately; e.g. limit 20, here 30)', 'd' => '', 'when' => [['wa_op', [2, 3]]]],
                    ['n' => 'wa_dauer', 'k' => 'int', 'c' => '… for longer than', 'd' => 15, 'suffix' => ' min', 'min' => 0, 'max' => 10080],
                    ['k' => 'info', 'c' => 'Notification'],
                    ['n' => 'wa_text', 'k' => 'text', 'c' => 'Notification text (empty = automatic)', 'd' => ''],
                    ['n' => 'wa_wdh', 'k' => 'int', 'c' => 'Repeat every (0 = once)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'wa_ende', 'k' => 'bool', 'c' => 'Send all-clear when the state is over', 'd' => true],
                    ['n' => 'wa_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications": push, e-mail, quiet hours)', 'd' => true],
                ];
            case 'anwesenheit':
                return [
                    ['k' => 'info', 'c' => 'Signals for "at home" (one is enough for "someone home")'],
                    ['n' => 'an_p', 'k' => 'vars', 'max' => 8, 'c' => 'Person / presence signal', 'req' => true,
                        'help' => ['Which signals belong here?', [
                            'One signal per person reporting "at home" — e.g. Geofency/location app, phone on Wi-Fi, a key switch, a presence variable of another control system.',
                            'Counts as "present": on/true, numbers above 0 and texts like "home", "zuhause", "anwesend".',
                            'Not for here: presence sensors in individual rooms (they belong to the motion light), "at home" of vehicles and planned calendar entries.',
                        ]]],
                    ['k' => 'info', 'c' => 'Result'],
                    ['n' => 'an_weg', 'k' => 'int', 'c' => '"Nobody home" only once everyone has been away this long', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'an_push', 'k' => 'bool', 'c' => 'Notify "Everyone away" and "Someone came home" (how is set under "🔔 Notifications")', 'd' => false],
                ];
            case 'gehen':
                return [
                    ['k' => 'info', 'c' => 'When? When the house mode changes to …'],
                    ['n' => 'gc_m3', 'k' => 'bool', 'c' => 'Away', 'd' => true],
                    ['n' => 'gc_m4', 'k' => 'bool', 'c' => 'Holiday', 'd' => true],
                    ['n' => 'gc_m2', 'k' => 'bool', 'c' => 'Night', 'd' => false],
                    ['k' => 'info', 'c' => 'What should be closed?'],
                    ['n' => 'gc_kon', 'k' => 'vars', 'max' => 16, 'c' => 'Window/door/gate (open = true)', 'req' => true],
                    ['n' => 'gc_invert', 'k' => 'bool', 'c' => 'Contacts are inverted (true = closed)', 'd' => false],
                    ['k' => 'info', 'c' => 'What should be off? (optional)'],
                    ['n' => 'gc_geraet', 'k' => 'vars', 'max' => 8, 'c' => 'Device (switch or power)'],
                    ['n' => 'gc_watt', 'k' => 'float', 'c' => 'For power values: counts as "on" from', 'd' => 10.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gc_aus', 'k' => 'bool', 'c' => 'Switch devices that are on off right away (instead of only notifying)', 'd' => false],
                    ['k' => 'info', 'c' => 'Notification'],
                    ['n' => 'gc_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                    ['n' => 'gc_ok', 'k' => 'bool', 'c' => 'Also notify when everything is closed ("All closed")', 'd' => false],
                ];
            case 'klingel':
                return [
                    ['k' => 'info', 'c' => 'Bell'],
                    ['n' => 'kl_taster', 'k' => 'var', 'c' => 'Bell (true/value when ringing)', 'req' => true],
                    ['n' => 'kl_impuls', 'k' => 'bool', 'c' => 'Bell reports every ring with the same value (e.g. stays "true")', 'd' => false],
                    ['n' => 'kl_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                    ['n' => 'kl_bild', 'k' => 'object', 'c' => 'Camera image (media object, optional — sent along to script notification channels)'],
                    ['k' => 'info', 'c' => 'Light (only in "Evening" and "Night")'],
                    ['n' => 'kl_licht', 'k' => 'vars', 'c' => 'Light'],
                    ['n' => 'kl_dauer', 'k' => 'int', 'c' => 'Then off again after', 'd' => 3, 'suffix' => ' min', 'min' => 1, 'max' => 60],
                    ['k' => 'info', 'c' => 'Music quieter (optional)'],
                    ['n' => 'kl_laut', 'k' => 'vars', 'c' => 'Volume'],
                    ['n' => 'kl_lautwert', 'k' => 'float', 'c' => 'Volume when ringing', 'd' => 5.0, 'min' => 0, 'max' => 100],
                ];
            case 'sommer':
                return [
                    ['k' => 'info', 'c' => 'Temperatures'],
                    ['n' => 'sl_innen', 'k' => 'var', 'c' => 'Indoor temperature', 'req' => true],
                    ['n' => 'sl_aussen', 'k' => 'var', 'c' => 'Outdoor temperature', 'req' => true],
                    ['n' => 'sl_min', 'k' => 'float', 'c' => 'Only if it is at least this warm inside', 'd' => 23.0, 'suffix' => ' °C', 'min' => 0, 'max' => 40],
                    ['n' => 'sl_diff', 'k' => 'float', 'c' => 'Difference from which it counts', 'd' => 1.5, 'suffix' => ' K', 'min' => 0.5, 'max' => 10],
                    ['k' => 'info', 'c' => 'Humidity (optional — then the dew point is compared, e.g. cellar)'],
                    ['n' => 'sl_rhinnen', 'k' => 'var', 'c' => 'Indoor humidity (%)'],
                    ['n' => 'sl_rhaussen', 'k' => 'var', 'c' => 'Outdoor humidity (%)'],
                    ['k' => 'info', 'c' => 'Window and notification'],
                    ['n' => 'sl_kon', 'k' => 'vars', 'c' => 'Window contact (optional: "close" only when open; "ventilate" only when closed)'],
                    ['n' => 'sl_konInvert', 'k' => 'bool', 'c' => 'Contact is inverted (true = closed)', 'd' => false],
                    ['n' => 'sl_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                ];
            case 'ausfall':
                return [
                    ['k' => 'info', 'c' => 'Device'],
                    ['n' => 'af_var', 'k' => 'var', 'c' => 'Power (W)', 'req' => true],
                    ['n' => 'af_modus', 'k' => 'select', 'c' => 'Notify when the device', 'd' => 0, 'options' => self::AF_MODES],
                    ['n' => 'af_schwelle', 'k' => 'float', 'c' => 'Running from', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'af_dauer', 'k' => 'int', 'c' => 'Longer than', 'd' => 120, 'suffix' => ' min', 'min' => 1, 'max' => 10080],
                    ['n' => 'af_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                    ['n' => 'af_ende', 'k' => 'bool', 'c' => 'Send all-clear', 'd' => true],
                ];
            case 'zeitschalt':
                $o = [
                    ['k' => 'info', 'c' => 'What?'],
                    ['n' => 'zs_out', 'k' => 'vars', 'max' => 8, 'c' => 'Device / light', 'req' => true],
                    ['n' => 'zs_modus', 'k' => 'select', 'c' => 'Switching', 'd' => 0, 'options' => self::ZS_MODES],
                    ['n' => 'zs_wert', 'k' => 'int', 'c' => 'Brightness for dimmers', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['zs_modus', [0, 2]]]],
                    ['k' => 'info', 'c' => 'When?'],
                    ['n' => 'zs_ein', 'k' => 'time', 'c' => 'Switch on at time', 'd' => self::time(7, 0), 'when' => [['zs_modus', [0, 2]]]],
                    ['n' => 'zs_aus', 'k' => 'time', 'c' => 'Switch off at time (earlier than "on" = next day)', 'd' => self::time(8, 0), 'when' => [['zs_modus', [0, 1]]]],
                ];
                foreach (self::WEEKDAYS as $n => $l) {
                    $o[] = ['n' => 'zs_t' . $n, 'k' => 'bool', 'c' => $l, 'd' => true];
                }
                $o[] = ['n' => 'zs_feiertag', 'k' => 'select', 'c' => 'On public holidays (state under "🏠 House mode")', 'd' => 1, 'options' => self::ZS_HOLIDAY];
                $o[] = ['n' => 'zs_zufall', 'k' => 'int', 'c' => 'Random offset per day (±)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 120];
                return $o;
            case 'alarm':
                return [
                    ['k' => 'info', 'c' => 'Sensor'],
                    ['n' => 'al_mel', 'k' => 'vars', 'max' => 8, 'c' => 'Water/smoke detector', 'req' => true],
                    ['n' => 'al_invert', 'k' => 'bool', 'c' => 'Sensors are inverted (true = everything OK)', 'd' => false],
                    ['k' => 'info', 'c' => 'What happens on alarm?'],
                    ['n' => 'al_licht', 'k' => 'vars', 'max' => 8, 'c' => 'Switch light on (full)'],
                    ['n' => 'al_hoch', 'k' => 'vars', 'max' => 8, 'c' => 'Open shutter'],
                    ['n' => 'al_hochwert', 'k' => 'float', 'c' => 'Value for "shutter open" (only without profile "~Shutter" — otherwise Automat detects the direction itself)', 'd' => 0.0, 'min' => 0, 'max' => 255],
                    ['n' => 'al_aus', 'k' => 'vars', 'max' => 8, 'c' => 'Switch off (e.g. close water valve, fan heater, ventilation)'],
                    ['k' => 'info', 'c' => 'Notification (sent immediately even during quiet hours)'],
                    ['n' => 'al_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                    ['n' => 'al_wdh', 'k' => 'int', 'c' => 'Remind while the alarm is active, every (0 = never)', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'al_ende', 'k' => 'bool', 'c' => 'Send all-clear', 'd' => true],
                ];
            case 'rollladen':
                return [
                    ['k' => 'info', 'c' => 'Shutters'],
                    ['n' => 'rl_out', 'k' => 'vars', 'max' => 16, 'c' => 'Shutter (position)', 'req' => true],
                    ['n' => 'rl_auf', 'k' => 'float', 'c' => 'Value for "open" (only for shutters without profile "~Shutter" — there Automat detects the direction itself)', 'd' => 0.0, 'min' => 0, 'max' => 255],
                    ['n' => 'rl_zu', 'k' => 'float', 'c' => 'Value for "closed" (likewise)', 'd' => 100.0, 'min' => 0, 'max' => 255],
                    ['k' => 'info', 'c' => 'Open in the morning'],
                    ['n' => 'rl_upref', 'k' => 'select', 'c' => 'Opening', 'd' => 1, 'options' => self::RL_UP],
                    ['n' => 'rl_upzeit', 'k' => 'time', 'c' => 'Time (for "at a set time")', 'd' => self::time(7, 0), 'when' => [['rl_upref', [0]]]],
                    ['n' => 'rl_upoff', 'k' => 'int', 'c' => 'Offset to sunrise (− = earlier)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['rl_upref', [1]]]],
                    ['n' => 'rl_frueh', 'k' => 'time', 'c' => 'Weekdays not before', 'd' => self::time(6, 30)],
                    ['n' => 'rl_fruehwe', 'k' => 'time', 'c' => 'Weekends not before', 'd' => self::time(8, 0)],
                    ['k' => 'info', 'c' => 'Close in the evening'],
                    ['n' => 'rl_downref', 'k' => 'select', 'c' => 'Closing', 'd' => 1, 'options' => self::RL_DOWN],
                    ['n' => 'rl_downzeit', 'k' => 'time', 'c' => 'Time (for "at a set time")', 'd' => self::time(21, 0), 'when' => [['rl_downref', [0]]]],
                    ['n' => 'rl_downoff', 'k' => 'int', 'c' => 'Offset to sunset (− = earlier)', 'd' => 0, 'suffix' => ' min', 'min' => -240, 'max' => 240, 'when' => [['rl_downref', [1]]]],
                    ['n' => 'rl_spaet', 'k' => 'time', 'c' => 'At the latest', 'd' => self::time(22, 30)],
                    ['k' => 'info', 'c' => 'Lock-out protection'],
                    ['n' => 'rl_tuer', 'k' => 'vars', 'c' => 'Door/window contact (open = shutter stays up, closes as soon as closed)'],
                    ['n' => 'rl_tuerinvert', 'k' => 'bool', 'c' => 'Contact is inverted (true = closed)', 'd' => false],
                    ['n' => 'rl_push', 'k' => 'bool', 'c' => 'Notify when lock-out protection holds back closing', 'd' => false],
                ];
            case 'standby':
                return [
                    ['k' => 'info', 'c' => 'Device'],
                    ['n' => 'sb_power', 'k' => 'var', 'c' => 'Power (W)', 'req' => true],
                    ['n' => 'sb_out', 'k' => 'var', 'c' => 'Socket / switch', 'req' => true],
                    ['n' => 'sb_schwelle', 'k' => 'float', 'c' => 'Standby below', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'sb_dauer', 'k' => 'int', 'c' => 'Switch off when in standby this long', 'd' => 30, 'suffix' => ' min', 'min' => 1, 'max' => 1440],
                    ['n' => 'sb_push', 'k' => 'bool', 'c' => 'Notify when switched off', 'd' => false],
                ];
            case 'lueften':
                return [
                    ['k' => 'info', 'c' => 'Measured value'],
                    ['n' => 'lf_var', 'k' => 'var', 'c' => 'Humidity (%) or CO₂ (ppm)', 'req' => true],
                    ['n' => 'lf_hoch', 'k' => 'float', 'c' => 'Remind from (e.g. 65 % or 1200 ppm)', 'd' => 65.0, 'min' => 0, 'max' => 10000],
                    ['n' => 'lf_ok', 'k' => 'float', 'c' => 'Good again below (e.g. 55 % or 800 ppm)', 'd' => 55.0, 'min' => 0, 'max' => 10000],
                    ['n' => 'lf_dauer', 'k' => 'int', 'c' => 'Only notify when too high this long', 'd' => 10, 'suffix' => ' min', 'min' => 0, 'max' => 240],
                    ['n' => 'lf_kon', 'k' => 'vars', 'c' => 'Window contact (optional: then "Can be closed again" when open and air good)'],
                    ['n' => 'lf_konInvert', 'k' => 'bool', 'c' => 'Contact is inverted (true = closed)', 'd' => false],
                    ['n' => 'lf_wdh', 'k' => 'int', 'c' => 'Remind while too high and window closed, every (0 = never)', 'd' => 30, 'suffix' => ' min', 'min' => 0, 'max' => 1440],
                    ['n' => 'lf_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications")', 'd' => true],
                ];
            case 'heizung':
                return [
                    ['k' => 'info', 'c' => 'Heating setpoints'],
                    ['n' => 'hz_soll', 'k' => 'vars', 'max' => 8, 'c' => 'Setpoint', 'req' => true],
                    ['k' => 'info', 'c' => 'Lower in …'],
                    ['n' => 'hz_m3', 'k' => 'bool', 'c' => 'Away', 'd' => true],
                    ['n' => 'hz_abw', 'k' => 'float', 'c' => 'Setpoint in "Away"', 'd' => 17.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                    ['n' => 'hz_m4', 'k' => 'bool', 'c' => 'Holiday', 'd' => true],
                    ['n' => 'hz_url', 'k' => 'float', 'c' => 'Setpoint in "Holiday"', 'd' => 15.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                    ['n' => 'hz_m2', 'k' => 'bool', 'c' => 'Night', 'd' => false],
                    ['n' => 'hz_nacht', 'k' => 'float', 'c' => 'Setpoint in "Night"', 'd' => 18.0, 'suffix' => ' °C', 'min' => 5, 'max' => 30],
                ];
            case 'taster':
                $o = [
                    ['k' => 'info', 'c' => 'Button'],
                    ['n' => 'tg_in', 'k' => 'var', 'c' => 'Button variable (reports the press as a value)', 'req' => true,
                        'help' => ['Which values does my button report?', [
                            'Many Zigbee/Z-Wave buttons report a value per press: e.g. "single", "double", "hold" (Zigbee2MQTT "action") or numbers 1/2/3 (Z-Wave "Central Scene").',
                            'You can see the value in the object tree when you press once. For a simple switch (true/false) enter "wahr" for "short".',
                            'Upper/lower case does not matter; several values separated by commas, e.g. "1, single".',
                        ]]],
                ];
                $presses = [
                    'k' => ['Short press', 'Value of the button variable for "short"', 'single, 1, wahr'],
                    'd' => ['Double press', 'Value of the button variable for "double"', 'double, 2'],
                    'l' => ['Long press', 'Value of the button variable for "long"', 'hold, long, 3'],
                ];
                foreach ($presses as $p => [$head, $caption, $def]) {
                    $o[] = ['k' => 'info', 'c' => $head];
                    $o[] = ['n' => 'tg_' . $p . 'wert', 'k' => 'text', 'c' => $caption, 'd' => $def];
                    $o[] = ['n' => 'tg_' . $p . 'akt', 'k' => 'select', 'c' => 'Action', 'd' => $p === 'k' ? 1 : 0, 'options' => self::TG_ACTIONS];
                    $o[] = ['n' => 'tg_' . $p . 'ziel', 'k' => 'var', 'c' => 'Target (light, device …)', 'when' => [['tg_' . $p . 'akt', [1, 2, 3, 4]]]];
                    $o[] = ['n' => 'tg_' . $p . 'zwert', 'k' => 'float', 'c' => 'Value (for "set to value"; for "house mode" 0 Day … 4 Holiday)', 'd' => 0.0, 'min' => -1000, 'max' => 100000, 'when' => [['tg_' . $p . 'akt', [4, 5]]]];
                }
                return $o;
            case 'wecklicht':
                return [
                    ['k' => 'info', 'c' => 'Light'],
                    ['n' => 'wl_out', 'k' => 'vars', 'c' => 'Dimmer', 'req' => true],
                    ['n' => 'wl_richtung', 'k' => 'select', 'c' => 'Ramp type', 'd' => 0, 'options' => self::WL_DIR],
                    ['k' => 'info', 'c' => 'When?'],
                    ['n' => 'wl_start', 'k' => 'select', 'c' => 'Start', 'd' => 0, 'options' => self::WL_START],
                    ['n' => 'wl_zeit', 'k' => 'time', 'c' => 'Time (wake up: when it should be bright; fall asleep: start)', 'd' => self::time(6, 30), 'when' => [['wl_start', [0]]]],
                    ['n' => 'wl_wecker', 'k' => 'var', 'c' => 'Wake-up time variable (for "wake-up time from a variable")', 'when' => [['wl_start', [1]]]],
                    ['n' => 'wl_we', 'k' => 'bool', 'c' => 'Also at weekends', 'd' => false],
                    ['k' => 'info', 'c' => 'Ramp'],
                    ['n' => 'wl_dauer', 'k' => 'int', 'c' => 'Ramp duration', 'd' => 20, 'suffix' => ' min', 'min' => 1, 'max' => 120],
                    ['n' => 'wl_ziel', 'k' => 'int', 'c' => 'Brightness at the end of waking up', 'd' => 80, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['wl_richtung', [0]]]],
                ];
            case 'szene':
                return [
                    ['k' => 'info', 'c' => 'When? When the house mode changes to …'],
                    ['n' => 'sz_m0', 'k' => 'bool', 'c' => 'Day', 'd' => false],
                    ['n' => 'sz_m1', 'k' => 'bool', 'c' => 'Evening', 'd' => false],
                    ['n' => 'sz_m2', 'k' => 'bool', 'c' => 'Night', 'd' => true],
                    ['n' => 'sz_m3', 'k' => 'bool', 'c' => 'Away', 'd' => true],
                    ['n' => 'sz_m4', 'k' => 'bool', 'c' => 'Holiday', 'd' => true],
                    ['n' => 'sz_verz', 'k' => 'int', 'c' => 'Delay (e.g. for "Night" time to go to bed; if the mode changes back before, it is skipped)', 'd' => 0, 'suffix' => ' min', 'min' => 0, 'max' => 240],
                    ['k' => 'info', 'c' => 'What?'],
                    ['n' => 'sz_out', 'k' => 'vars', 'max' => 16, 'c' => 'Device', 'req' => true],
                    ['n' => 'sz_aktion', 'k' => 'select', 'c' => 'These devices', 'd' => 0, 'options' => self::SZ_ACTIONS],
                    ['n' => 'sz_hell', 'k' => 'int', 'c' => 'Brightness for "switch on" (dimmer)', 'd' => 100, 'suffix' => ' %', 'min' => 1, 'max' => 100, 'when' => [['sz_aktion', [1]]]],
                    ['n' => 'sz_wert', 'k' => 'float', 'c' => 'Value for "set to a value" (e.g. 17 °C, shutter 100 %)', 'd' => 0.0, 'min' => -1000, 'max' => 100000, 'when' => [['sz_aktion', [2]]]],
                    ['k' => 'info', 'c' => 'Notification'],
                    ['n' => 'sz_push', 'k' => 'bool', 'c' => 'Notify what was switched (how is set under "🔔 Notifications")', 'd' => false],
                ];
            case 'ruecksetzen':
                return [
                    ['k' => 'info', 'c' => 'Sensor'],
                    ['n' => 'mr_mel', 'k' => 'vars', 'max' => 16, 'c' => 'Sensor (true = motion/triggered)', 'req' => true],
                    ['n' => 'mr_halt', 'k' => 'int', 'c' => 'Reset when no new report has arrived for this long (longer than the sensor\'s own reset time)', 'd' => 60, 'suffix' => ' s', 'min' => 5, 'max' => 3600],
                ];
            case 'fertig':
                return [
                    ['k' => 'info', 'c' => 'Power measurement of the appliance'],
                    ['n' => 'gf_var', 'k' => 'var', 'c' => 'Power (W)', 'req' => true],
                    ['n' => 'gf_lauf', 'k' => 'float', 'c' => 'Running from', 'd' => 20.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_fertig', 'k' => 'float', 'c' => 'Finished below', 'd' => 5.0, 'suffix' => ' W', 'min' => 0, 'max' => 10000],
                    ['n' => 'gf_minlauf', 'k' => 'int', 'c' => 'Minimum run time (shorter peaks do not count)', 'd' => 5, 'suffix' => ' min', 'min' => 0, 'max' => 600],
                    ['n' => 'gf_nach', 'k' => 'int', 'c' => 'Finished when below the threshold this long', 'd' => 3, 'suffix' => ' min', 'min' => 0, 'max' => 120],
                    ['k' => 'info', 'c' => 'Notification'],
                    ['n' => 'gf_text', 'k' => 'text', 'c' => 'Notification text (empty = "<Name> is finished.")', 'd' => ''],
                    ['n' => 'gf_push', 'k' => 'bool', 'c' => 'Notify (how is set under "🔔 Notifications": push, e-mail, quiet hours)', 'd' => true],
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
        foreach (self::fieldDefs($type) as $f) {
            if (!isset($f['n'])) {
                continue;
            }
            if ($f['k'] === 'vars') {
                for ($i = 1; $i <= ($f['max'] ?? self::SLOTS); $i++) {
                    $row[$f['n'] . $i] = 0;
                }
            } elseif ($f['k'] === 'var' || $f['k'] === 'object') {
                $row[$f['n']] = 0;
            } else {
                $row[$f['n']] = $f['d'];
            }
        }
        return $row;
    }

    /**
     * Ist ein Feld bei diesen Auswahlwerten sichtbar? 'when' => [[Auswahlfeld, [Werte]], …] — eine erfüllte
     * Bedingung genügt (z. B. Helligkeitssensor bei „Dunkelheit“ ODER „Helligkeit über Schwelle“).
     */
    public static function visibleWhen(array $f, array $values, string $type): bool
    {
        if (!isset($f['when'])) {
            return true;
        }
        $defs = [];
        foreach (self::fieldDefs($type) as $g) {
            if (isset($g['n'])) {
                $defs[$g['n']] = $g['d'] ?? null;
            }
        }
        foreach ($f['when'] as [$field, $vals]) {
            $v = array_key_exists($field, $values) ? $values[$field] : ($defs[$field] ?? null);
            if (in_array((int)$v, $vals, true)) {
                return true;
            }
        }
        return false;
    }

    /** Auswahlfelder eines Typs, von denen andere Felder abhängen. */
    public static function controllers(string $type): array
    {
        $c = [];
        foreach (self::fieldDefs($type) as $f) {
            foreach ($f['when'] ?? [] as [$field, $_]) {
                $c[$field] = true;
            }
        }
        return array_keys($c);
    }

    /** Feldkürzel je Typ („zl_“, „bl_“, …) — jeder Typ hat genau eines. */
    public static function prefix(string $type): string
    {
        foreach (self::fieldDefs($type) as $f) {
            if (isset($f['n'])) {
                return substr((string)$f['n'], 0, 3);
            }
        }
        return '';
    }

    /**
     * Felder ANDERER Typen aus einer Zeile entfernen. Der Bearbeiten-Dialog speicherte bis 0.1.35 die Felder
     * aller Typen in jede Zeile (bei 20 Typen über 350 Werte je Zeile) — daran hat sich die Konsole beim
     * Speichern verschluckt (Dietmar 14.10.2026).
     */
    public static function stripForeign(array $row): array
    {
        $own = self::prefix((string)($row['Type'] ?? ''));
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (self::TYPES as $t => $_) {
                $all[] = self::prefix($t);
            }
        }
        foreach (array_keys($row) as $k) {
            $p = substr((string)$k, 0, 3);
            if ($p !== $own && in_array($p, $all, true) && preg_match('/^[a-z]{2}_/', (string)$k)) {
                unset($row[$k]);
            }
        }
        return $row;
    }

    /** Alle Variablenfelder einer Zeile bereinigen: Werte unter MIN_ID werden 0 („nicht gewählt“). */
    public static function sanitize(array $row): array
    {
        foreach (self::TYPES as $t => $_) {
            foreach (self::fieldDefs($t) as $f) {
                if (!isset($f['n']) || ($f['k'] !== 'var' && $f['k'] !== 'vars')) {
                    continue;
                }
                $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                    return $f['n'] . $i;
                }, range(1, self::slotsIn($row, $f['n']))) : [$f['n']];
                foreach ($names as $n) {
                    if (array_key_exists($n, $row) && (int)$row[$n] < self::$minId) {
                        $row[$n] = 0;
                    }
                }
            }
        }
        return $row;
    }

    /**
     * Probleme je Feld für den Dialog: [Feldname => Text]. Fehlt eine Pflichtangabe, steht der
     * Hinweis am ersten Platz des Felds.
     */
    public static function fieldProblems(array $row, callable $exists): array
    {
        $t = (string)($row['Type'] ?? '');
        $out = [];
        foreach (self::fieldDefs($t) as $f) {
            if (!isset($f['n']) || ($f['k'] !== 'var' && $f['k'] !== 'vars')) {
                continue;
            }
            $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                return $f['n'] . $i;
            }, range(1, self::slotsIn($row, $f['n']))) : [$f['n']];
            $any = false;
            foreach ($names as $n) {
                $v = (int)($row[$n] ?? 0);
                if ($v >= self::$minId) {
                    $any = true;
                    if (!$exists($v)) {
                        $out[$n] = AUTOMATText::t('⛔ Variable #%s no longer exists — please choose again or clear.', $v);
                    }
                }
            }
            if (!$any && !empty($f['req'])) {
                $out[$names[0]] = AUTOMATText::t('⛔ Please choose — without "%s" the automation cannot work.', AUTOMATText::t($f['c']));
            }
        }
        return $out;
    }

    /** Zahl ohne überflüssige Nachkommastellen, deutsches Komma („17“, „17,5“). */
    public static function num(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }

    /** Anzahl der Plätze eines Mehrfach-Felds (Standard SLOTS, je Feld über 'max' erweiterbar). */
    public static function slots(string $name): int
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::TYPES as $t => $_) {
                foreach (self::fieldDefs($t) as $f) {
                    if (isset($f['n']) && $f['k'] === 'vars') {
                        $map[$f['n']] = (int)($f['max'] ?? self::SLOTS);
                    }
                }
            }
        }
        return $map[$name] ?? self::SLOTS;
    }

    /**
     * Plätze eines Mehrfach-Felds in DIESER Zeile: mindestens die Katalog-Anzahl, sonst so viele, wie die Zeile
     * trägt — die Obergrenze ist nur der Startwert, der Dialog bietet immer weitere freie Plätze an.
     */
    public static function slotsIn(array $row, string $name): int
    {
        $n = self::slots($name);
        $len = strlen($name);
        foreach ($row as $k => $_) {
            $k = (string)$k;
            if (strncmp($k, $name, $len) === 0 && ctype_digit($rest = substr($k, $len))) {
                $n = max($n, (int)$rest);
            }
        }
        return $n;
    }

    /** Höchster belegter Platz eines Mehrfach-Felds (0 = keiner). */
    public static function lastFilled(array $row, string $name): int
    {
        $last = 0;
        for ($i = 1; $i <= self::slotsIn($row, $name); $i++) {
            if ((int)($row[$name . $i] ?? 0) >= self::$minId) {
                $last = $i;
            }
        }
        return $last;
    }

    /** Variablen eines Mehrfach-Felds (0 und doppelte entfernt). */
    public static function vars(array $row, string $name): array
    {
        $out = [];
        for ($i = 1; $i <= self::slotsIn($row, $name); $i++) {
            $v = (int)($row[$name . $i] ?? 0);
            if ($v >= self::$minId && !in_array($v, $out, true)) {
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
                $in = array_values(array_unique(array_merge(self::vars($row, 'bl_mel'), self::vars($row, 'bl_hint'))));
                $out = self::vars($row, 'bl_out');
                if ((int)($row['bl_lux'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['bl_lux'];
                }
                break;
            case 'daemmerung':
                $out = self::vars($row, 'dz_out');
                if ((int)($row['dz_lux'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['dz_lux'];
                }
                break;
            case 'fenster':
                $in = self::vars($row, 'fo_kon');
                $out = self::vars($row, 'fo_soll');
                break;
            case 'waechter':
                if ((int)($row['wa_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['wa_var'];
                }
                break;
            case 'anwesenheit':
                $in = self::vars($row, 'an_p');
                break;
            case 'szene':
                $out = self::vars($row, 'sz_out');
                break;
            case 'ruecksetzen':
                $in = self::vars($row, 'mr_mel'); // nur „in“: das eigene Zurücksetzen kommt als Melder-Meldung zurück
                break;
            case 'gehen':
                $read = array_merge(self::vars($row, 'gc_kon'), self::vars($row, 'gc_geraet'));
                if (!empty($row['gc_aus'])) {
                    $out = self::vars($row, 'gc_geraet');
                }
                break;
            case 'klingel':
                if ((int)($row['kl_taster'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['kl_taster'];
                }
                $out = array_values(array_unique(array_merge(self::vars($row, 'kl_licht'), self::vars($row, 'kl_laut'))));
                break;
            case 'sommer':
                foreach (['sl_innen', 'sl_aussen', 'sl_rhinnen', 'sl_rhaussen'] as $f) {
                    if ((int)($row[$f] ?? 0) >= self::$minId) {
                        $in[] = (int)$row[$f];
                    }
                }
                $in = array_merge($in, self::vars($row, 'sl_kon'));
                break;
            case 'ausfall':
                if ((int)($row['af_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['af_var'];
                }
                break;
            case 'zeitschalt':
                $out = self::vars($row, 'zs_out');
                break;
            case 'alarm':
                $in = self::vars($row, 'al_mel');
                $out = array_values(array_unique(array_merge(self::vars($row, 'al_licht'), self::vars($row, 'al_hoch'), self::vars($row, 'al_aus'))));
                break;
            case 'rollladen':
                $out = self::vars($row, 'rl_out');
                $in = self::vars($row, 'rl_tuer');
                break;
            case 'standby':
                foreach (['sb_power' => 'in', 'sb_out' => 'out'] as $f => $k) {
                    if ((int)($row[$f] ?? 0) >= self::$minId) {
                        ${$k}[] = (int)$row[$f];
                    }
                }
                break;
            case 'lueften':
                if ((int)($row['lf_var'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['lf_var'];
                }
                $in = array_merge($in, self::vars($row, 'lf_kon'));
                break;
            case 'heizung':
                $out = self::vars($row, 'hz_soll');
                break;
            case 'taster':
                if ((int)($row['tg_in'] ?? 0) >= self::$minId) {
                    $in[] = (int)$row['tg_in'];
                }
                foreach (['k', 'd', 'l'] as $p) {
                    $z = (int)($row['tg_' . $p . 'ziel'] ?? 0);
                    if ($z >= self::$minId && !in_array($z, $out, true)) {
                        $out[] = $z;
                    }
                }
                break;
            case 'wecklicht':
                $out = self::vars($row, 'wl_out');
                if ((int)($row['wl_wecker'] ?? 0) >= self::$minId) {
                    $read[] = (int)$row['wl_wecker'];
                }
                break;
            case 'fertig':
                if ((int)($row['gf_var'] ?? 0) >= self::$minId) {
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
            return [AUTOMATText::t('Type is missing or unknown — edit the row and choose a type.')];
        }
        $p = [];
        foreach (self::fieldDefs($t) as $f) {
            if (empty($f['req'])) {
                continue;
            }
            $vals = $f['k'] === 'vars' ? self::vars($row, $f['n']) : (((int)($row[$f['n']] ?? 0) >= self::$minId) ? [(int)$row[$f['n']]] : []);
            if (count($vals) === 0) {
                $p[] = AUTOMATText::t('"%s" is not selected.', AUTOMATText::t($f['c']));
            }
        }
        foreach (self::variables($row) as $list) {
            foreach ($list as $vid) {
                if (!$exists($vid)) {
                    $p[] = AUTOMATText::t('Variable #%s no longer exists.', $vid);
                }
            }
        }
        if ($t === 'gehen') {
            $any = false;
            foreach ([2, 3, 4] as $m) {
                $any = $any || !empty($row['gc_m' . $m]);
            }
            if (!$any) {
                $p[] = AUTOMATText::t('No house mode ticked in which the check runs.');
            }
        }
        if ($t === 'rollladen') {
            foreach (['rl_spaet' => 'At the latest', 'rl_downzeit' => 'Time for closing'] as $f => $c) {
                $tm = json_decode((string)($row[$f] ?? ''), true);
                if (is_array($tm) && (int)($tm['hour'] ?? 12) < 12 && ($f === 'rl_spaet' || (int)($row['rl_downref'] ?? 1) === 0)) {
                    $p[] = AUTOMATText::t('"%s" is before 12:00 — closing times after midnight are not supported (please choose e.g. 23:59).', AUTOMATText::t($c));
                }
            }
        }
        if ($t === 'lueften' && (float)($row['lf_ok'] ?? 55) >= (float)($row['lf_hoch'] ?? 65)) {
            $p[] = AUTOMATText::t('"Good again below" must be smaller than "Remind from" — otherwise the notification keeps flipping.');
        }
        if ($t === 'zeitschalt') {
            $any = false;
            foreach (self::WEEKDAYS as $n => $_) {
                $any = $any || !empty($row['zs_t' . $n]);
            }
            if (!$any) {
                $p[] = AUTOMATText::t('No weekday ticked.');
            }
        }
        if ($t === 'taster') {
            $any = false;
            foreach (['k', 'd', 'l'] as $q) {
                $akt = (int)($row['tg_' . $q . 'akt'] ?? 0);
                $any = $any || $akt > 0;
                if (in_array($akt, [1, 2, 3, 4], true) && (int)($row['tg_' . $q . 'ziel'] ?? 0) < self::$minId) {
                    $p[] = AUTOMATText::t('Action "%s" needs a target.', self::tr(self::TG_ACTIONS)[$akt]);
                }
            }
            if (!$any) {
                $p[] = AUTOMATText::t('No action chosen for short, double or long press.');
            }
        }
        if ($t === 'wecklicht' && (int)($row['wl_start'] ?? 0) === 1 && (int)($row['wl_wecker'] ?? 0) < self::$minId) {
            $p[] = AUTOMATText::t('Wake-up time variable is not selected.');
        }
        if ($t === 'szene') {
            $any = false;
            foreach (self::MODES as $m => $_) {
                $any = $any || !empty($row['sz_m' . $m]);
            }
            if (!$any) {
                $p[] = AUTOMATText::t('No house mode ticked whose change triggers switching.');
            }
        }
        if ($t === 'daemmerung') {
            $needLux = false; // ohne eigenen Sensor gilt der zentrale unter „🏠 Hausmodus“ (ab 0.1.39)
            if ($needLux && (int)($row['dz_lux'] ?? 0) < self::$minId) {
                $p[] = AUTOMATText::t('The brightness sensor for "Darkness"/"Brightness" is missing.');
            }
        }
        if ($t === 'fertig' && (float)($row['gf_fertig'] ?? 0) >= (float)($row['gf_lauf'] ?? 0)) {
            $p[] = AUTOMATText::t('"Finished below" must be smaller than "Running from".');
        }
        return $p;
    }

    /** Dauer kurz: „45 s", „2:00 min", „1 h 30 min". */
    public static function dur(int $sec): string
    {
        if ($sec >= 3600) {
            $m = intdiv($sec % 3600, 60);
            return intdiv($sec, 3600) . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
        }
        if ($sec >= 60) {
            return intdiv($sec, 60) . ':' . sprintf('%02d', $sec % 60) . ' min';
        }
        return $sec . ' s';
    }

    private static function hm(string $timeJson): string
    {
        $t = json_decode($timeJson, true);
        return sprintf('%02d:%02d', (int)($t['hour'] ?? 0), (int)($t['minute'] ?? 0));
    }

    private static function offset(int $min): string
    {
        return $min === 0 ? '' : ' ' . ($min > 0 ? '+' : '−') . abs($min) . ' min';
    }

    /**
     * Die wichtigsten Einstellungen einer Zeile in einer Zeile Text (Spalte „Einstellungen").
     * $live: callable(int $vid): ?string — aktueller Wert einer gelesenen Variable als Text (z. B. „12 lx"), null = unbekannt.
     */
    public static function summary(array $row, callable $live): string
    {
        $t = (string)($row['Type'] ?? '');
        $p = [];
        $light = function (string $pre) use ($row) {
            $w = (int)($row[$pre . '_wert'] ?? 100);
            $n = (int)($row[$pre . '_nachtwert'] ?? 0);
            return $w . ' %' . ($n > 0 ? AUTOMATText::t(', night %s %%', $n) : '');
        };
        switch ($t) {
            case 'zeitlicht':
                $p[] = AUTOMATText::t('Duration %s', self::dur((int)($row['zl_dauer'] ?? 0)));
                if ((int)($row['zl_vorwarn'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Pre-warning %s', self::dur((int)$row['zl_vorwarn']));
                }
                $p[] = $light('zl');
                $n = count(self::vars($row, 'zl_trg'));
                $p[] = $n > 0 ? AUTOMATText::t('%s button(s)', $n) : AUTOMATText::t('starts on every switch-on');
                break;
            case 'bewegung':
                $p[] = AUTOMATText::t('Follow-up %s', self::dur((int)($row['bl_nachlauf'] ?? 0)));
                if ((int)($row['bl_lux'] ?? 0) >= self::$minId) {
                    $now = $live((int)$row['bl_lux']);
                    $p[] = AUTOMATText::t('below %s lx', rtrim(rtrim(number_format((float)($row['bl_luxschwelle'] ?? 0), 1, ',', ''), '0'), ',')) . ($now !== null ? AUTOMATText::t(' (now %s)', $now) : '');
                }
                $p[] = $light('bl');
                if ((int)($row['bl_vorwarn'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Pre-warning %s', self::dur((int)$row['bl_vorwarn']));
                }
                if ((int)($row['bl_handpause'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Manual pause %s min', (int)$row['bl_handpause']);
                }
                $p[] = AUTOMATText::t('%s sensor(s)', count(self::vars($row, 'bl_mel')));
                $nh = count(self::vars($row, 'bl_hint'));
                if ($nh > 0) {
                    $p[] = AUTOMATText::t('%1$s sign(s) of presence (above %2$s)', $nh, rtrim(rtrim(number_format((float)($row['bl_hintmin'] ?? 10), 1, ',', ''), '0'), ','));
                }
                if ((int)($row['bl_melmax'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Sensor reset %s', self::dur((int)$row['bl_melmax']));
                }
                break;
            case 'daemmerung':
                $ein = (int)($row['dz_ein'] ?? 0);
                $aus = (int)($row['dz_aus'] ?? 0);
                $p[] = AUTOMATText::t('on: %s', $ein === 0 ? AUTOMATText::t('sunset') . self::offset((int)($row['dz_einversatz'] ?? 0)) : ($ein === 1 ? self::hm((string)($row['dz_einzeit'] ?? '')) : AUTOMATText::t('darkness')));
                $p[] = AUTOMATText::t('off: %s', $aus === 0 ? self::hm((string)($row['dz_auszeit'] ?? '')) : ($aus === 1 ? AUTOMATText::t('sunrise') . self::offset((int)($row['dz_ausversatz'] ?? 0)) : ($aus === 2 ? AUTOMATText::t('brightness') : AUTOMATText::t('never'))));
                if ((int)($row['dz_zufall'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Random ±%s min', (int)$row['dz_zufall']);
                }
                if ((int)($row['dz_lux'] ?? 0) >= self::$minId && ($ein === 2 || $aus === 2)) {
                    $now = $live((int)$row['dz_lux']);
                    $p[] = AUTOMATText::t('Threshold %s lx', rtrim(rtrim(number_format((float)($row['dz_luxschwelle'] ?? 0), 1, ',', ''), '0'), ',')) . ($now !== null ? AUTOMATText::t(' (now %s)', $now) : '');
                }
                break;
            case 'fenster':
                $p[] = AUTOMATText::t('after %1$s to %2$s °C', self::dur((int)($row['fo_verz'] ?? 0)), rtrim(rtrim(number_format((float)($row['fo_absenk'] ?? 0), 1, ',', ''), '0'), ','));
                $p[] = AUTOMATText::t('%1$s contact(s), %2$s setpoint(s)', count(self::vars($row, 'fo_kon')), count(self::vars($row, 'fo_soll')));
                break;
            case 'waechter':
                $op = (int)($row['wa_op'] ?? 4);
                $p[] = (self::tr(self::WA_OPS)[$op] ?? '') . (in_array($op, [4, 5], true) ? '' : ' ' . (string)($row['wa_wert'] ?? ''));
                $p[] = AUTOMATText::t('longer than %s', self::dur((int)($row['wa_dauer'] ?? 0) * 60));
                if ((int)($row['wa_wdh'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('Reminder every %s min', (int)$row['wa_wdh']);
                }
                break;
            case 'gehen':
                $modes = [];
                foreach ([3, 4, 2] as $m) {
                    if (!empty($row['gc_m' . $m])) {
                        $modes[] = self::modes()[$m];
                    }
                }
                $p[] = AUTOMATText::t('in %s', implode('/', $modes) ?: '—');
                $p[] = AUTOMATText::t('%s contact(s)', count(self::vars($row, 'gc_kon'))) . (count(self::vars($row, 'gc_geraet')) > 0 ? AUTOMATText::t(', %s device(s)', count(self::vars($row, 'gc_geraet'))) : '');
                break;
            case 'klingel':
                $p[] = count(self::vars($row, 'kl_licht')) > 0 ? AUTOMATText::t('Light %s min', (int)($row['kl_dauer'] ?? 3)) : AUTOMATText::t('notification only');
                break;
            case 'sommer':
                $p[] = AUTOMATText::t('from %1$s °C inside, ±%2$s K', self::num((float)($row['sl_min'] ?? 23)), self::num((float)($row['sl_diff'] ?? 1.5)));
                if ((int)($row['sl_rhinnen'] ?? 0) >= self::$minId && (int)($row['sl_rhaussen'] ?? 0) >= self::$minId) {
                    $p[] = AUTOMATText::t('with dew point');
                }
                break;
            case 'ausfall':
                $p[] = ((int)($row['af_modus'] ?? 0) === 0 ? AUTOMATText::t('without power') : AUTOMATText::t('continuously')) . ' > ' . (int)($row['af_dauer'] ?? 120) . ' min';
                break;
            case 'zeitschalt':
                $days = [];
                foreach (self::tr(self::WEEKDAYS) as $n => $l) {
                    if (!empty($row['zs_t' . $n])) {
                        $days[] = $l;
                    }
                }
                $mo = (int)($row['zs_modus'] ?? 0);
                $p[] = ($mo !== 1 ? AUTOMATText::t('on %s', self::hm((string)($row['zs_ein'] ?? ''))) : '') . ($mo === 0 ? ', ' : '') . ($mo !== 2 ? AUTOMATText::t('off %s', self::hm((string)($row['zs_aus'] ?? ''))) : '');
                $p[] = count($days) === 7 ? AUTOMATText::t('daily') : (implode(' ', $days) ?: AUTOMATText::t('no day'));
                break;
            case 'alarm':
                $p[] = AUTOMATText::t('%s sensor(s)', count(self::vars($row, 'al_mel')));
                $n = count(self::vars($row, 'al_licht')) + count(self::vars($row, 'al_hoch')) + count(self::vars($row, 'al_aus'));
                $p[] = AUTOMATText::t('%s device(s) on alarm', $n);
                break;
            case 'rollladen':
                $p[] = AUTOMATText::t('%s shutter(s)', count(self::vars($row, 'rl_out')));
                $p[] = AUTOMATText::t('up %s', self::tr(self::RL_UP)[(int)($row['rl_upref'] ?? 1)] ?? '');
                $p[] = AUTOMATText::t('down %s', self::tr(self::RL_DOWN)[(int)($row['rl_downref'] ?? 1)] ?? '');
                break;
            case 'standby':
                $p[] = AUTOMATText::t('below %1$s W for %2$s min → off', (float)($row['sb_schwelle'] ?? 5), (int)($row['sb_dauer'] ?? 30));
                break;
            case 'ruecksetzen':
                $p[] = AUTOMATText::t('%s sensor(s)', count(self::vars($row, 'mr_mel')));
                $p[] = AUTOMATText::t('reset after %s s without new report', (int)($row['mr_halt'] ?? 60));
                break;
            case 'lueften':
                $p[] = AUTOMATText::t('from %1$s, good below %2$s', self::num((float)($row['lf_hoch'] ?? 65)), self::num((float)($row['lf_ok'] ?? 55)));
                break;
            case 'heizung':
                $p[] = AUTOMATText::t('%s setpoint(s)', count(self::vars($row, 'hz_soll')));
                $low = [];
                foreach ([3 => 'hz_abw', 4 => 'hz_url', 2 => 'hz_nacht'] as $m => $f) {
                    if (!empty($row['hz_m' . $m])) {
                        $low[] = self::modes()[$m] . ' ' . self::num((float)($row[$f] ?? 0)) . ' °C';
                    }
                }
                $p[] = implode(', ', $low) ?: AUTOMATText::t('nothing ticked');
                break;
            case 'taster':
                $acts = [];
                foreach (['k' => AUTOMATText::t('short'), 'd' => AUTOMATText::t('double'), 'l' => AUTOMATText::t('long')] as $q => $l) {
                    if ((int)($row['tg_' . $q . 'akt'] ?? 0) > 0) {
                        $acts[] = $l;
                    }
                }
                $p[] = implode('/', $acts) ?: AUTOMATText::t('no action');
                break;
            case 'wecklicht':
                $p[] = (int)($row['wl_richtung'] ?? 0) === 0 ? AUTOMATText::t('Wake up') : AUTOMATText::t('Fall asleep');
                $p[] = (int)($row['wl_dauer'] ?? 20) . ' min';
                break;
            case 'szene':
                $modes = [];
                foreach (self::modes() as $m => $l) {
                    if (!empty($row['sz_m' . $m])) {
                        $modes[] = $l;
                    }
                }
                $p[] = AUTOMATText::t('in %s', implode('/', $modes) ?: '—');
                $p[] = AUTOMATText::t('%1$s device(s) %2$s', count(self::vars($row, 'sz_out')), self::tr(self::SZ_ACTIONS)[(int)($row['sz_aktion'] ?? 0)] ?? '');
                if ((int)($row['sz_verz'] ?? 0) > 0) {
                    $p[] = AUTOMATText::t('after %s min', (int)$row['sz_verz']);
                }
                break;
            case 'anwesenheit':
                $p[] = AUTOMATText::t('%s signal(s)', count(self::vars($row, 'an_p')));
                $p[] = AUTOMATText::t('"nobody home" after %s min', (int)($row['an_weg'] ?? 10));
                break;
            case 'fertig':
                $p[] = AUTOMATText::t('running from %s W', (float)($row['gf_lauf'] ?? 0));
                $p[] = AUTOMATText::t('finished below %1$s W after %2$s min', (float)($row['gf_fertig'] ?? 0), (int)($row['gf_nach'] ?? 0));
                break;
        }
        $off = [];
        foreach (self::modes() as $m => $label) {
            if (array_key_exists('m' . $m, $row) && !$row['m' . $m]) {
                $off[] = $label;
            }
        }
        if (count($off) > 0) {
            $p[] = AUTOMATText::t('not in %s', implode('/', $off));
        }
        if (trim((string)($row['Cond'] ?? '')) !== '' && trim((string)$row['Cond']) !== '[]') {
            $p[] = AUTOMATText::t('with condition');
        }
        return implode(' · ', $p);
    }
}
