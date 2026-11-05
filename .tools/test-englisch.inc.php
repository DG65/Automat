<?php
/**
 * Englische Fassung (von test-automat.php am Ende eingebunden): Quelltexte sind englisch, Deutsch kommt aus
 * Automat/locale.json (gebaut aus .tools/locale-teile). Geprüft wird die Übersetzungstabelle und dass Formular,
 * Bearbeiten-Dialoge und Kachel ohne Übersetzer (= englisches Symcon) keine deutschen Reste zeigen.
 */

declare(strict_types=1);

heading('E. Englisch und Übersetzung');

$enDe = $GLOBALS['DE'];
check('locale.json: deutsche Tabelle vorhanden (> 1000 Texte)', count($enDe) > 1000);

// Jeder AUTOMATText::t()-Aufruf mit festem Text hat eine deutsche Fassung; Platzhalter passen.
$enFehlt = [];
$enPlatz = [];
foreach (glob(dirname(__DIR__) . '/Automat/*.php') as $enDatei) {
    $enT = token_get_all((string)file_get_contents($enDatei));
    $enN = count($enT);
    for ($i = 0; $i < $enN - 4; $i++) {
        if (is_array($enT[$i]) && $enT[$i][1] === 'AUTOMATText' && $enT[$i + 1][1] === '::' && $enT[$i + 2][1] === 't' && $enT[$i + 3] === '('
            && is_array($enT[$i + 4]) && $enT[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING) {
            $enText = $enT[$i + 4][1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($enT[$i + 4][1], 1, -1)) : stripcslashes(substr($enT[$i + 4][1], 1, -1));
            $enNext = $i + 5;
            while ($enNext < $enN && is_array($enT[$enNext]) && $enT[$enNext][0] === T_WHITESPACE) {
                $enNext++;
            }
            if (($enT[$enNext] ?? '') === '.') {
                continue; // über mehrere Zeilen verketteter Text: die Prüfung sieht nur das erste Stück
            }
            if (!isset($enDe[$enText]) && preg_match('/[a-z]{3,}/', $enText)) {
                $enFehlt[] = basename($enDatei) . ': ' . $enText;
            }
            $enArgs = 0;
            $d = 0;
            for ($j = $i + 5; $j < $enN; $j++) {
                $x = is_array($enT[$j]) ? $enT[$j][1] : $enT[$j];
                if ($x === '(' || $x === '[') {
                    $d++;
                } elseif ($x === ')' || $x === ']') {
                    if ($d === 0) {
                        break;
                    }
                    $d--;
                } elseif ($x === ',' && $d === 0) {
                    $enArgs++;
                }
            }
            $enPh = preg_match_all('/%(\d+\$)?[sd]/', str_replace('%%', '', $enText));
            if ($enArgs !== $enPh && $enArgs > 0) {
                $enPlatz[] = basename($enDatei) . ': ' . $enText . " ($enPh Platzhalter, $enArgs Werte)";
            }
            if (isset($enDe[$enText]) && preg_match_all('/%(\d+\$)?[sd]/', str_replace('%%', '', $enDe[$enText])) !== $enPh) {
                $enPlatz[] = 'Deutsch: ' . $enText . ' → ' . $enDe[$enText];
            }
        }
    }
}
check('jeder AUTOMATText::t()-Text hat eine deutsche Fassung', $enFehlt === [], implode(' | ', array_slice($enFehlt, 0, 8)) . ' (' . count($enFehlt) . ')');
check('Platzhalter passen: Anzahl Werte = %s im englischen und im deutschen Text', $enPlatz === [], implode(' | ', array_slice($enPlatz, 0, 6)) . ' (' . count($enPlatz) . ')');

// Englisch: kein Übersetzer.
$enTexte = function ($x) use (&$enTexte): array {
    $aus = [];
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (in_array($k, ['caption', 'label', 'Caption', 'confirm', 'Panel'], true) && is_string($v)) {
                $aus[] = $v;
            } elseif (is_array($v)) {
                $aus = array_merge($aus, $enTexte($v));
            }
        }
    }
    return $aus;
};
// Erlaubt: Produkt-/Modulnamen und Gerätenamen aus dem Prüfstand (Nutzerdaten).
$enDeutsch = function (string $s): bool {
    if (preg_match('/^\d\d\.\d\d\.\d{4} /', $s)) {
        return false; // Protokollzeile: gespeichert in der Sprache, in der sie entstand
    }
    $s = preg_replace('/Raumstruktur|Anwesenheitssimulation|Batteriewächter|Messwertrechner|Baden-Württemberg|DG65-Toolkit \w+|„[^“]*“|"[^"]*"|\'[^\']*\'/u', '', $s);
    return (bool)preg_match('/[äöüÄÖÜß]|\b(und|oder|der|die|das|nicht|bitte|Bitte|Gerät|Geräte|Licht|Melder|Hausmodus|Automatismus|Automatismen|Übernehmen|übernehmen|jetzt|Jetzt|wenn|aus|ein|mit|bei|nach|für)\b/u', $s);
};
$GLOBALS['WARN_VOR_EN'] = count($GLOBALS['WARN']);
AUTOMATText::setzen(null);
$enReste = [];
$enM = $m;
foreach ($enTexte(json_decode($enM->GetConfigurationForm(), true)) as $s) {
    if ($enDeutsch($s)) {
        $enReste['Formular: ' . mb_substr($s, 0, 90)] = true;
    }
}
foreach (array_keys(AUTOMATKatalog::TYPES) as $enTyp) {
    foreach ($enTexte(json_decode($enM->UIEditForm('', $enTyp), true)) as $s) {
        if ($enDeutsch($s)) {
            $enReste['Dialog ' . $enTyp . ': ' . mb_substr($s, 0, 90)] = true;
        }
    }
}
check('Englisch: Formular und alle Bearbeiten-Dialoge ohne deutsche Reste', $enReste === [], implode(' | ', array_slice(array_keys($enReste), 0, 12)) . ' (' . count($enReste) . ')');
$enTile = (new ReflectionMethod('Automat', 'tilePayload'))->invoke($enM);
$enTileText = json_encode($enTile, JSON_UNESCAPED_UNICODE);
check('Englisch: Kachel-Texte unübersetzt (Quelltext = Anzeige)', isset($enTile['texte']) && $enTile['texte']['All'] === 'All');
check('Englisch: Hausmodi heißen Day … Holiday', array_values(AUTOMATKatalog::modes()) === ['Day', 'Evening', 'Night', 'Away', 'Holiday']);
$enWarn = array_filter(array_slice($GLOBALS['WARN'], $GLOBALS['WARN_VOR_EN']), fn($w) => strpos($w, 'Ident nicht gefunden') === false);
check('Englisch: keine PHP-Warnungen beim Bauen von Formular, Dialogen und Kachel', $enWarn === [], implode(' | ', array_slice($enWarn, 0, 4)));
AUTOMATText::setzen(function (string $t): string { return $GLOBALS['DE'][$t] ?? $t; });
check('Deutsch wieder an: Hausmodi heißen Tag … Urlaub', array_values(AUTOMATKatalog::modes()) === ['Tag', 'Abend', 'Nacht', 'Abwesend', 'Urlaub']);

// Kachel: jeder T('…')-Text in module.html steht in KACHEL_TEXTE und hat eine deutsche Fassung.
$enHtml = (string)file_get_contents(dirname(__DIR__) . '/Automat/module.html');
$enKachel = (new ReflectionClassConstant('Automat', 'KACHEL_TEXTE'))->getValue();
preg_match_all("/\\bT\\('((?:[^'\\\\]|\\\\.)*)'/u", $enHtml, $enT);
$enOhne = array_values(array_filter(array_unique($enT[1]), function ($s) use ($enKachel, $enDe) { return !in_array($s, $enKachel, true) || !isset($enDe[$s]); }));
check('Kachel: jeder T()-Text in KACHEL_TEXTE und locale.json', count($enT[1]) >= 10 && $enOhne === [], implode(' | ', $enOhne) . ' / ' . count($enT[1]));
$enSkript = preg_replace(['~^\s*//.*$~m', '~/\*.*?\*/~s', '~ // .*$~m'], '', $enHtml);
preg_match_all("/'[^'\\n]*[äöüÄÖÜß][^'\\n]*'/u", $enSkript, $enDeHtml);
check('Kachel: keine deutschen Texte im Skript', $enDeHtml[0] === [], implode(' | ', array_slice($enDeHtml[0], 0, 6)));

// Tempo (live 05.11.2026, SUITE.md Stolperstein 24): jeder Text fragt Symcons Translate() höchstens einmal je Prozess.
$enRufe = 0;
AUTOMATText::setzen(AUTOMATText::mitMerkliste(function (string $t) use (&$enRufe): string { $enRufe++; return $GLOBALS['DE'][$t] ?? $t; }));
$enM->GetConfigurationForm();
$enErst = $enRufe;
$enM->GetConfigurationForm();
check('Merkliste: zweites Formular fragt Symcon kein einziges Mal mehr', $enErst > 50 && $enRufe === $enErst, $enErst . ' / ' . $enRufe);
$enA = AUTOMATText::t('House mode') . '|' . AUTOMATText::t('Switches at %s', '07:00');
check('… und übersetzt weiter richtig (Deutsch)', strpos($enA, 'Hausmodus|') === 0, $enA);
$enRufe = 0;
$enM->Tick();
$enM->Tick();
check('Ein Timer-Durchlauf nach dem ersten: höchstens eine Handvoll neuer Fragen (Kachel-Texte nur im ersten Bild)', $enRufe <= 5, (string)$enRufe);
$enRufe = 0;
AUTOMATText::setzen(AUTOMATText::mitMerkliste(function (string $t) use (&$enRufe): string { $enRufe++; return $GLOBALS['DE'][$t] ?? $t; }));
$enM->Tick();
echo '      (Tick mit leerer Merkliste: ' . $enRufe . " Fragen an Translate)\n";
check('Auch mit leerer Merkliste (neuer Prozess) fragt ein Tick nur wenige Texte', $enRufe <= 80, (string)$enRufe);
$enUpd = json_decode((string)end($enM->tileUpdates), true);
check('Kachel-Aktualisierung ohne Textliste, erstes Bild mit', is_array($enUpd) && !isset($enUpd['texte']) && strpos($enM->GetVisualizationTile(), '"texte"') !== false);
check('Kachel behält die Texte bei Aktualisierungen', strpos((string)file_get_contents(dirname(__DIR__) . '/Automat/module.html'), 'if (!data.texte && AU && AU.texte) { data.texte = AU.texte; }') !== false);
check('Modul liest locale.json nie selbst (Store: Dateizugriff nur als Ausnahme)', strpos((string)file_get_contents(dirname(__DIR__) . '/Automat/AUTOMATText.php') . (string)file_get_contents(dirname(__DIR__) . '/Automat/module.php'), 'locale.json\')') === false && strpos((string)file_get_contents(dirname(__DIR__) . '/Automat/module.php'), "'/locale.json'") === false);
AUTOMATText::setzen(function (string $t): string { return $GLOBALS['DE'][$t] ?? $t; });

heading('F. Sicherheitsnetz Timer (live 05.11.2026)');
$fN = count($enM->tileUpdates);
$enM->attrs['Due'] = json_encode(['xyz' => $GLOBALS['CLOCK'] - 60]);
$enM->MessageSink($GLOBALS['CLOCK'], 999999, VM_UPDATE, [1, true, 0]);
check('Überfällige Frist: eine beliebige Gerätemeldung holt den Durchlauf nach', count($enM->tileUpdates) > $fN);
$fN = count($enM->tileUpdates);
$enM->attrs['Due'] = json_encode(['xyz' => $GLOBALS['CLOCK'] + 60]);
$enM->MessageSink($GLOBALS['CLOCK'], 999999, VM_UPDATE, [1, true, 0]);
check('Frist in der Zukunft: Meldung ohne Bezug löst keinen Durchlauf aus', count($enM->tileUpdates) === $fN);
