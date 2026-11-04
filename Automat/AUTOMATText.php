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

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
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
