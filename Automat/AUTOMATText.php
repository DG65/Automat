<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für alle Automat-Teile. Quelltexte stehen auf Englisch im Code,
// die deutsche Fassung in locale.json (Symcons übliches Muster; die Datei wird
// aus .tools/locale-teile/*.json erzeugt: php .tools/locale-bauen.php).
//
// Logik, Katalog und Erkennung haben kein $this->Translate(); das Modul setzt
// deshalb im Konstruktor den Übersetzer. Ohne Übersetzer bleibt der englische
// Text stehen; der Prüfstand setzt die deutsche Tabelle.
// ===========================================================================

final class AUTOMATText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    /**
     * Formular für die Konsole (Store-Punkt 25: „Das Formular übersetzt die Konsole selbst“). Automat baut das Formular
     * wie immer fertig übersetzt und merkt sich dabei jede Übersetzung eines festen Texts. Danach steht in jeder
     * Beschriftung, die genau so eine Übersetzung ist, wieder der englische Quelltext — die Konsole übersetzt ihn über
     * locale.json zurück. Zusammengesetzte Texte (mit Werten) bleiben übersetzt, die findet die Konsole nicht.
     * Ergebnis: dieselbe Anzeige, aber feste Texte übersetzt die Konsole (Prüfstand H vergleicht beides).
     */
    public static $formModeAllowed = true;
    /** @var array<string,string>|null übersetzt → englischer Quelltext, während ein Formular gebaut wird */
    private static $rueck = null;

    public static function forForm(callable $fn): string
    {
        $vorher = self::$rueck;
        self::$rueck = [];
        try {
            $json = (string)$fn();
            $rueck = self::$rueck;
        } finally {
            self::$rueck = $vorher;
        }
        if (!self::$formModeAllowed || $rueck === []) {
            return $json;
        }
        $form = json_decode($json, true);
        if (!is_array($form)) {
            return $json;
        }
        $walk = function ($x) use (&$walk, $rueck) {
            if (!is_array($x)) {
                return $x;
            }
            foreach ($x as $k => $v) {
                if (is_string($v) && in_array($k, ['caption', 'label', 'confirm', 'placeholder'], true) && isset($rueck[$v])) {
                    $x[$k] = $rueck[$v];
                } elseif (is_array($v)) {
                    $x[$k] = $walk($v);
                }
            }
            return $x;
        };
        return (string)json_encode($walk($form));
    }

    /** @var array<string,string> gemerkte Übersetzungen */
    private static $gemerkt = [];
    /** @var int für den Prüfstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
        self::$gemerkt = [];
    }

    /**
     * Übersetzer für das Modul: Symcons Translate() liest bei jedem Aufruf locale.json — bei hunderten Texten je
     * Durchlauf dauerte ein Tick Sekunden (live 05.11.2026: Tick 2,5 s, Formular 5,4 s, der Timer kam nicht mehr
     * hinterher; SUITE.md Stolperstein 24). Deshalb fragt jeder Text Symcon höchstens einmal je Prozess und die
     * Antwort wird gemerkt. locale.json liest das Modul nie selbst (Store: Dateizugriff nur als Ausnahme).
     */
    public static function mitMerkliste(callable $translate): callable
    {
        return function (string $text) use ($translate): string {
            if (!isset(self::$gemerkt[$text])) {
                self::$anfragen++;
                try {
                    self::$gemerkt[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    return $text; // z. B. Instanz während des Aufrufs gelöscht: Quelltext zeigen, nichts merken
                }
            }
            return self::$gemerkt[$text];
        };
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            if (self::$rueck !== null && $uebersetzt !== $text) {
                self::$rueck[$uebersetzt] = $text; // für forForm(): diese Beschriftung kann die Konsole selbst übersetzen
            }
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /** Für Werte aus Tabellen (Konstanten), die erst beim Anzeigen übersetzt werden. */
    public static function wert($wert)
    {
        return is_string($wert) && $wert !== '' ? self::t($wert) : $wert;
    }
}
