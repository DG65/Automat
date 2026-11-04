<?php
/**
 * Erzeugt Automat/locale.json aus .tools/locale-teile/*.json (je Teil: {"englischer Quelltext": "deutsche Fassung"}).
 * Gleicher Schlüssel in zwei Teilen ist nur erlaubt, wenn die deutsche Fassung gleich ist.
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$de = ['Automat' => 'Automat'];
$von = [];
$fehler = [];
foreach (glob(__DIR__ . '/locale-teile/*.json') as $datei) {
    $teil = json_decode((string)file_get_contents($datei), true, 512, JSON_THROW_ON_ERROR);
    foreach ($teil as $en => $text) {
        if (isset($de[$en]) && $de[$en] !== $text) {
            $fehler[] = basename($datei) . ' vs. ' . ($von[$en] ?? '?') . ': „' . $en . '“ → „' . $text . '“ / „' . $de[$en] . '“';
        }
        $de[$en] = $text;
        $von[$en] = basename($datei);
    }
}
if ($fehler !== []) {
    fwrite(STDERR, "Widersprüchliche Übersetzungen:\n" . implode("\n", $fehler) . "\n");
    exit(1);
}
ksort($de);
file_put_contents(dirname(__DIR__) . '/Automat/locale.json', json_encode(['translations' => ['de' => $de]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Automat/locale.json: ' . count($de) . " Texte\n";
