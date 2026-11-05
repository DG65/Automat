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

    /** @var array<string,string>|null deutsche Tabelle aus locale.json, [] = Englisch (Quelltext bleibt) */
    private static $tabelle = null;
    /** @var array<string,string> gemerkte Ergebnisse, wenn weder Deutsch noch Englisch */
    private static $gemerkt = [];

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /**
     * Übersetzer für das Modul: Symcons Translate() liest bei jedem Aufruf locale.json — bei hunderten Texten je
     * Durchlauf dauerte ein Tick Sekunden (live 05.11.2026: Tick 2,5 s, Formular 5,4 s, der Timer kam nicht mehr
     * hinterher). Deshalb fragt Automat Symcon nur einmal, in welcher Sprache es läuft, und liest die Tabelle selbst.
     */
    public static function mitTabelle(callable $translate, string $localeDatei): callable
    {
        return function (string $text) use ($translate, $localeDatei): string {
            if (self::$tabelle === null) {
                $probe = 'House mode';
                $ziel = (string)$translate($probe);
                $de = $ziel === $probe ? [] : (json_decode((string)@file_get_contents($localeDatei), true)['translations']['de'] ?? []);
                // Andere Sprache als Deutsch (oder Tabelle unlesbar): jeden Text einmal bei Symcon nachfragen und merken.
                self::$tabelle = $ziel === $probe || ($de[$probe] ?? null) === $ziel ? $de : false;
                self::$gemerkt[$probe] = $ziel;
            }
            if (self::$tabelle !== false) {
                return self::$tabelle[$text] ?? $text;
            }
            return self::$gemerkt[$text] ?? (self::$gemerkt[$text] = (string)$translate($text));
        };
    }

    /** Nur für den Prüfstand: Sprachwahl vergessen. */
    public static function vergessen(): void
    {
        self::$tabelle = null;
        self::$gemerkt = [];
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
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
