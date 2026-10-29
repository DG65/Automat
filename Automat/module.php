<?php

declare(strict_types=1);

// ===========================================================================
// Automat (DG65-Toolkit) — alle kleinen Standard-Automatismen eines Hauses in
// EINER Instanz: Zeitlicht (Treppenlicht), Bewegungslicht, Dämmerungs- und
// Zeitschaltung, Fenster offen → Heizung runter, Wächter „bleibt zu lange
// so" und Gerät fertig.
//
// WAS ES ANDERS MACHT ALS EINZEL-INSTANZEN JE AUTOMATIK:
//   Übersicht  — eine Liste mit Zustand und Grund je Automatismus, eine
//                Kachel, ein Protokoll „warum ging das Licht an?“.
//   Entdecken  — sucht im Objektbaum nach Meldern, Lichtern, Kontakten,
//                Thermostaten und Leistungsmessungen, gruppiert sie nach Raum
//                (Raumstruktur, falls installiert, sonst nach Gerätenamen) und
//                schlägt passende Automatismen vor. Angelegt wird nur, was der
//                Nutzer bestätigt.
//   Querschnitt — für alle Typen gleich: Sperre (sichtbar als Variable),
//                globale Pause, Hausmodus (Tag/Abend/Nacht/Abwesend/Urlaub),
//                Symcon-Bedingungen, Respekt vor Handbedienung.
//
// Aufbau: Die Entscheidungen fallen ausschließlich in AUTOMATLogik (ohne
// Symcon testbar), die Typen stehen in AUTOMATKatalog, die Erkennung in
// AUTOMATErkennung. Dieses Modul sammelt Werte, schaltet, meldet und zeigt an.
//
// Eigenständig: setzt kein anderes Modul voraus. Raumstruktur (Räume) und die
// Location-Control-Instanz (Sonnenauf-/-untergang) werden genutzt, wenn
// vorhanden.
// ===========================================================================

require_once __DIR__ . '/AUTOMATKatalog.php';
require_once __DIR__ . '/AUTOMATLogik.php';
require_once __DIR__ . '/AUTOMATErkennung.php';

class Automat extends IPSModule
{
    private const LIBRARY_GUID = '{D7A3CB8F-E43B-4307-AB64-BAD160C1823A}';
    private const MODULE_GUID  = '{A92CA7FD-B746-45C4-902B-3CBAE1F3FA27}';

    // Symcon-Kernmodul Location Control (Idents Sunrise/Sunset, live verifiziert an IPS 9.0 durch die Anwesenheitssimulation, vormals Schein).
    // Je Typ das Feld „Melden“ im Dialog (was gemeldet wird, entscheidet der Nutzer je Automatismus).
    private const NOTIFY_FIELD = ['waechter' => 'wa_push', 'fertig' => 'gf_push', 'anwesenheit' => 'an_push', 'szene' => 'sz_push',
        'alarm' => 'al_push', 'rollladen' => 'rl_push', 'standby' => 'sb_push', 'lueften' => 'lf_push',
        'gehen' => 'gc_push', 'klingel' => 'kl_push', 'sommer' => 'sl_push', 'ausfall' => 'af_push'];
    // Typen, die beim Wechsel des Hausmodus ein eigenes Ereignis (von → nach) bekommen.
    private const MODE_EVENT_TYPES = ['szene', 'heizung', 'rollladen', 'wecklicht', 'gehen'];
    private const SMTP_GUID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    // Zeitpunkte der Location Control je Auswahlwert (Idents live geprüft). Die Werte 0–2 bleiben aus 0.1.17 gültig.
    private const DAY_EVENTS = [1 => 'Sunrise', 3 => 'CivilTwilightStart', 4 => 'NauticTwilightStart', 5 => 'AstronomicTwilightStart'];
    private const EVE_EVENTS = [0 => 'Sunset', 3 => 'CivilTwilightEnd', 4 => 'NauticTwilightEnd', 5 => 'AstronomicTwilightEnd'];
    private const NIGHT_EVENTS = [3 => 'CivilTwilightEnd', 4 => 'NauticTwilightEnd', 5 => 'AstronomicTwilightEnd'];
    private const EVENT_LABELS = [
        'Sunrise' => 'Sonnenaufgang', 'Sunset' => 'Sonnenuntergang',
        'CivilTwilightStart' => 'Beginn bürgerliche Dämmerung', 'CivilTwilightEnd' => 'Ende bürgerliche Dämmerung',
        'NauticTwilightStart' => 'Beginn nautische Dämmerung', 'NauticTwilightEnd' => 'Ende nautische Dämmerung',
        'AstronomicTwilightStart' => 'Beginn astronomische Dämmerung', 'AstronomicTwilightEnd' => 'Ende astronomische Dämmerung',
    ];
    // DG65-Toolkit Raumstruktur (vormals Raumstruktur; Räume/Etagen), Vertrag RAUM_/STRUKT_GetStructure 1.x — optional.
    private const RAUM_GUID = '{CA700334-0982-F356-0617-6952868137E9}';
    // Push-Ziele (SUITE.md Stolperstein 22): klassisches WebFront und Kachel-Visualisierung.
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const VISU_GUID     = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    // Archive Control (Kernmodul) — zum Anlernen der Melder-Dauern aus den letzten Tagen.
    private const ARCHIVE_GUID  = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // „Neu bis Version“: erst ab der ersten veröffentlichten Version gepflegt
    // (Toolkit-Präzedenzfall Batteriewächter) — bis dahin steht die Geschichte im CHANGELOG.
    // Neu bis Version: ab der ersten Store-Veröffentlichung (0.1.32, 14.10.2026) gepflegt.
    private const NEWS_VERSIONS = [
        '0.1.55' => [
            '🪟 Formular: Aufbau nach der einheitlichen Regel aller DG65-Module: „📖 Dokumentation & Hilfe“ steht jetzt fest oben nach „Neu“ und lässt sich nicht mehr verschieben oder ausblenden. Hattest du sie ausgeblendet, ist sie wieder da. Alle Fachbereiche wählst du wie bisher selbst.',
        ],
        '0.1.54' => [
            '⏱ Wächter mit Hysterese: Bei „größer als“/„kleiner als“ lässt sich eine zweite Schwelle eintragen, ab der der Zustand wieder als „in Ordnung“ gilt — z. B. melden unter 20 GB freiem Speicher, erst wieder meldebereit über 30 GB. Ein Wert, der um die Grenze pendelt, meldet so nur einmal.',
        ],
        '0.1.53' => [
            '🔁 Neu: „Melder zurücksetzen“ — setzt Bewegungs-/Alarmmelder auf „falsch“, wenn sie auf „wahr“ stehen und seit der Haltezeit (Standard 60 s) keine neue Meldung kam, z. B. weil die „keine Bewegung“-Meldung im Funk verloren ging. Ersetzt Ablaufpläne/Skripte mit Passwort oder JSON-RPC. Der Status zählt je Melder, wie oft zurückgesetzt wurde. Hinweis: Bei Z-Wave-Instanzen mit „Variablen sperren“ lehnt Symcon das Setzen ab — Automat sagt das im Protokoll.',
        ],
        '0.1.52' => [
            '🔃 Rollläden: Automat nimmt die Richtung jetzt je Rollladen aus dessen Profil — „~Shutter“: 0 = offen, „~Shutter.Reversed“: 0 = zu. Gemischte Aktoren (z. B. Z-Wave und Shelly) in einem Automatismus fahren damit alle richtig herum. Die Werte „offen/zu“ im Automatismus gelten nur noch für Rollläden ohne dieses Profil. Gilt auch für „Rollladen öffnen“ beim Wasser-/Rauchalarm.',
        ],
        '0.1.51' => [
            '🛠 Rollläden: Der Befehl geht jetzt immer raus — auch wenn die Anzeige schon „zu“ bzw. „offen“ zeigt. Manche Aktoren (z. B. Z-Wave) setzen die Anzeige schon beim Befehl; ging einer im Funk verloren, schickte Automat danach keinen mehr, auch nicht von Hand.',
        ],
        '0.1.50' => [
            '▶ Von Hand auslösen: In der Liste Automatismen anhaken und „▶ Jetzt ausführen“ — holt eine verschlafene Schaltung nach (Szene, Zeitschaltuhr, Treppenlicht, Gehen-Check als Probe, Heizung neu setzen). Für Rollläden „⬇ schließen“ und „⬆ öffnen“. Dieselben Knöpfe gibt es in der Kachel. Der Aussperrschutz (offene Tür) gilt auch hier.',
        ],
        '0.1.49' => [
            '🔐 Türschlösser: Meldet ein Schloss „Gesperrt“, gilt die Tür als zu. Automat liest dafür die Beschriftung des Werts („Gesperrt“, „Geschlossen“, „Entriegelt“, „Geöffnet“ …) statt nur des Profilnamens. Beim Riegel („~Lock.Reversed“) lag die Erkennung vorher genau falsch herum.',
        ],
        '0.1.48' => [
            '🔎 Die letzten offenen Punkte der Prüfung behoben:',
            '• Reagiert ein Licht oder Schalter nicht auf einen Befehl (z. B. Funkstörung), steht das nach 20 s im Protokoll — auf Wunsch als Störungsmeldung.',
            '• Hausmodus „Abend“ und Rollladen „bei Dunkelheit“ frühestens 2 h vor Sonnenuntergang — ein Gewitter am Nachmittag schließt nicht mehr für den ganzen Tag.',
            '• Ausfall-Wächter warnt, wenn die Messsteckdose seit 6 h nichts mehr meldet. Wasser-/Rauchalarm meldet weitere Melder sofort. Türklingel: wiederholte Meldungen ohne Änderung zählen nicht als neues Klingeln (neue Option für Klingeln mit immer gleichem Wert).',
            '• Wächter: Bei Matter-Kontakten heißt die Auswahl jetzt „zu“ und „offen“.',
            '• Dialog zeigt auch bei noch nicht übernommenen Automatismen (Entdecken, Duplizieren) alle eingetragenen Geräte.',
            '• Gelöschte Automatismen kommen nach einem früheren Speichern mit Filter nicht zurück; neue Automatismen bekommen immer eine feste Kennung.',
        ],
        '0.1.47' => [
            '🔎 Gründliche Prüfung aller Automatismen — rund 30 Fehler behoben, jeder mit eigenem Test. Die wichtigsten:',
            '• Rollladen: Das Protokoll nennt, welcher Rollladen wirklich einen Befehl bekam. Nach 3 Minuten prüft Automat nach und sendet einmal erneut. Ein Aussperrschutz über Mitternacht wird sauber beendet. Beim Rauchalarm gilt der „offen“-Wert des Rollladen-Automatismus.',
            '• Fenster offen + Heizung nach Hausmodus am selben Thermostat: Der Komfortwert geht nicht mehr verloren. Ein Thermostat, das den neuen Wert erst später meldet, gilt nicht mehr als „von Hand verstellt“.',
            '• Abschalten, Löschen, Probelauf oder Umbau eines Automatismus gibt abgesenkte Sollwerte wieder frei.',
            '• Bewegungslicht: Der Nachlauf startet erst, wenn keine Bewegung mehr gemeldet wird — auch nach Handeinschaltung, Dauerlicht und Sperre.',
            '• Dämmerung: Im Sommer brannte das Licht fast 24 Stunden, wenn der Sonnenuntergang nach der Ausschaltzeit lag.',
            '• Zeitschaltuhr, Aufwachlicht, Sommer-Lüften, Lüften, Ausfall-Wächter, Taster, Gehen-Check, Anwesenheit: einzelne Fehler behoben (siehe Änderungsprotokoll).',
            '• Grundgerüst: gleichzeitige Abläufe überschreiben sich nicht mehr gegenseitig; Geräte, die ihren Wert mehrfach oder in Zwischenschritten melden, gelten nicht mehr als Handbedienung; ein per Skript gesetzter Hausmodus wird erkannt.',
        ],
        '0.1.46' => [
            '🛠 Fenster-/Türkontakte: Ob ein Kontakt „wahr = offen“ oder „wahr = zu“ meldet, erkennt Automat jetzt je Sensor selbst (Matter-Kontakte, Profile „…Reversed“, Darstellung) und wendet das in jedem Automatismus gleich an. Das Häkchen „umgekehrt“ braucht es nur noch für Sensoren, die Automat nicht erkennt. Vorher hielt eine geschlossene Matter-Terrassentür den Rollladen oben.',
        ],
        '0.1.45' => [
            '🕒 Bewegungslicht: Der lernende Nachlauf wird höchstens das Vierfache des eingestellten Werts (30 s → höchstens 2 min). Als „zu früh ausgeschaltet“ zählt nur noch, wer innerhalb der eingestellten Nachlaufzeit zurückkommt. Wer den Nachlauf ändert, verwirft das Gelernte. Das Protokoll nennt den gelernten Wert. Beim Update beginnt das Lernen bei allen Bewegungslichtern neu.',
        ],
        '0.1.44' => [
            '🛠 Bewegungslicht: Licht ging tagsüber an, obwohl es hell genug war. Nach jedem automatischen Ausschalten wurde der Helligkeitssensor bis zum nächsten Einschalten übergangen statt nur in den ersten drei Minuten (gegen Restlicht). Behoben.',
        ],
        '0.1.43' => [
            '🖼 Kachel: größere Symbole, Name und Zustand brechen um statt abgeschnitten zu werden.',
        ],
        '0.1.42' => [
            '➕ Keine feste Obergrenze mehr bei Meldern, Lichtern, Kontakten, Geräten usw.: Hinter dem letzten belegten Platz gibt es immer weitere freie. Sind alle belegt, nach „Übernehmen“ den Dialog neu öffnen. „Entdecken“ übernimmt jetzt alle gefundenen Geräte.',
        ],
        '0.1.41' => [
            '🛠 Wichtige Korrektur: Wer mit aktivem Filter „Übernehmen“ gedrückt hat, verlor die Einstellungen der ausgeblendeten Automatismen (sie standen danach leer in der Liste). Ausgeblendete werden jetzt vollständig gespeichert. Bereits leer gewordene Automatismen bitte neu einstellen — die tägliche Symcon-Sicherung enthält den alten Stand.',
        ],
        '0.1.40' => [
            '➕ Übersichtlichere Dialoge: Bei Meldern, Lichtern, Kontakten usw. stehen nur noch die belegten Plätze und EIN freier da. Füllt man ihn, erscheint der nächste.',
        ],
        '0.1.39' => [
            '☀️ Ein Helligkeitssensor für alles: Der Sensor unter „🏠 Hausmodus“ (mit Schwellen und Haltezeit) gilt jetzt auch für Rollläden „wenn es dunkel wird“ und die Dämmerungsschaltung — kein zweites Eintragen mehr. Die Dämmerungsschaltung kann weiterhin einen eigenen Sensor nutzen (z. B. Raumsensor).',
        ],
        '0.1.38' => [
            '🧹 Der Bearbeiten-Dialog zeigt nur noch die Felder, die zur Auswahl passen — z. B. beim Rollladen „Öffnen bei Tag“ ohne Uhrzeit und Versatz. Umstellen blendet sofort passend ein und aus (Rollladen, Dämmerung, Aufwachlicht, Szene, Taster, Zeitschaltuhr, Wächter).',
        ],
        '0.1.37' => [
            '🔍 Filter über der Liste der Automatismen: Suchtext, Raum, Funktion/Art und Zustand (auffällig, läuft, aktiv, nicht aktiv, gesperrt, Probelauf). Ausgefilterte bleiben beim „Übernehmen“ erhalten.',
            '🔍 Filter beim Entdecken: Suchtext, Raum und Art der Vorschläge.',
        ],
        '0.1.36' => [
            '🛠 Behoben: Beim Speichern hing die Konsole. Der Bearbeiten-Dialog enthält jetzt nur noch die Einstellungen der eigenen Art (vorher die aller 20 Arten, über 350 Werte je Zeile). Neue Automatismen über „+“: erst die Art wählen, dann mit ✏️ einstellen.',
        ],
        '0.1.35' => [
            '🚪 Gehen-Check: beim Wechsel auf „Abwesend“/„Urlaub“ EINE Meldung, was noch offen oder an ist.',
            '🛎 Türklingel, ☀️ Sommer-Lüften (mit Taupunkt), 🧊 Ausfall-Wächter (Kühlschrank, Pumpe) und 📅 Zeitschaltuhr als neue Automatismen.',
            '📄 Duplizieren: Automatismen in der Liste anhaken (☑︎) und in einen anderen Raum kopieren — Geräte gleicher Art werden dort eingesetzt.',
            '🧪 Probelauf je Automatismus: nichts schalten, nur protokollieren, was passiert wäre.',
            '📅 Feiertage nach Bundesland (unter „🏠 Hausmodus“) für Rollläden, Aufwachlicht und Zeitschaltuhr.',
            '📊 Statistik je Automatismus und Hinweis auf solche, die 30 Tage nichts getan haben (unter „📜 Protokoll und Kachel“).',
            '💡 Entdecken schlägt aus dem Archiv Gewohnheiten vor, z. B. „Licht geht werktags meist um 22:30 aus“.',
        ],
        '0.1.34' => [
            '💬 Es gibt jetzt einen Forum-Thread für Fragen, Wünsche und Rückmeldungen — Link unter „💬 Rückmeldungen“ und in „📖 Dokumentation & Hilfe“.',
            '🕒 Uhrzeiten, die Stunden entfernt liegen, stehen ohne Sekunden in den Zuständen („Rollläden schließen um 18:41 Uhr“).',
        ],
    ];
    private const REPO_URL    = 'https://github.com/DG65/Automat';
    private const FORUM_URL   = 'https://community.symcon.de/t/beta-modul-dg65-toolkit-automat-alle-standard-automatismen-in-einer-instanz/144647';
    private const LICENSE_URL = 'https://github.com/DG65/Automat/blob/main/LICENSE';
    private const PAYPAL_URL  = 'https://paypal.me/DietmarGureth';

    private const PROFILE_MODE = 'AUTOMAT.Hausmodus';
    // onClick-Vorspann: Schlüssel der angehakten Vorschläge aus der offenen Liste lesen (Zeilen per Array-Zugriff).
    private const PICKED_KEYS = '$k = []; foreach ($SuggestList as $x) { if (!empty($x[\'Pick\'])) { $k[] = (string)$x[\'Key\']; } }';
    private const MAX_TICK_S   = 60;   // spätestens minütlich nachsehen (Dämmerung, Hausmodus, Wächter)
    private const REST_TICK_S  = 5;    // so oft wird „… · Restzeit" nachgeführt, solange unter einer Stunde läuft
    private const ECHO_S       = 15;   // so lange gilt eine Wertänderung als Echo des eigenen Befehls
    private const PROTOCOL_MAX = 200;

    /** Pro Aufruf zwischengespeicherte Instanz-Konfigurationen (Doppelregelungs-Prüfung). */
    private ?array $confCache = null;

    /** Raumnamen aus Raumstruktur (für Zuordnung über Gerätenamen), je Suche gefüllt. */
    private array $roomLabels = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Automations', '[]');
        // Beim Filtern ausgeblendete Automatismen — unsichtbare Liste, wird mitgespeichert (sonst wären sie gelöscht).
        $this->RegisterPropertyString('AutomationsHidden', '[]');
        // Standard an (Dietmar 13.10.2026): bestimmt nur, welcher Hausmodus gilt — schaltet selbst nichts.
        $this->RegisterPropertyBoolean('cfg_ModeAuto', true);
        $this->RegisterPropertyInteger('cfg_DayRef', 0);
        $this->RegisterPropertyString('cfg_DayFrom', AUTOMATKatalog::time(6, 30));
        $this->RegisterPropertyInteger('cfg_DayOffset', 0);
        $this->RegisterPropertyInteger('cfg_EveningOffset', 0);
        $this->RegisterPropertyInteger('cfg_EveningRef', 0);
        $this->RegisterPropertyString('cfg_EveningFrom', AUTOMATKatalog::time(18, 0));
        $this->RegisterPropertyString('cfg_NightFrom', AUTOMATKatalog::time(22, 30));
        $this->RegisterPropertyInteger('cfg_NightRef', 0);
        $this->RegisterPropertyInteger('cfg_NightOffset', 0);
        $this->RegisterPropertyBoolean('cfg_NotifyPush', true);
        $this->RegisterPropertyString('cfg_PushTargets', '[]');
        $this->RegisterPropertyBoolean('cfg_NotifyMail', false);
        $this->RegisterPropertyInteger('cfg_MailInstance', 0);
        $this->RegisterPropertyString('cfg_MailTo', '');
        $this->RegisterPropertyBoolean('cfg_QuietEnabled', false);
        $this->RegisterPropertyInteger('cfg_QuietFrom', 22);
        $this->RegisterPropertyInteger('cfg_QuietTo', 7);
        $this->RegisterPropertyBoolean('cfg_NotifyFaults', false);
        // Meldewege (ab 0.1.27). Leer = aus den früheren Einstellungen (cfg_NotifyPush/-Mail …) gebildet.
        $this->RegisterPropertyString('cfg_Ways', '[]');
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterPropertyString('cfg_Bundesland', '');
        $this->RegisterPropertyInteger('cfg_LightVar', 0);
        $this->RegisterPropertyFloat('cfg_LightDay', 50);
        $this->RegisterPropertyFloat('cfg_LightNight', 20);
        $this->RegisterPropertyInteger('cfg_LightHold', 10);
        $this->RegisterPropertyInteger('cfg_PresenceVar', 0);
        $this->RegisterPropertyBoolean('cfg_PresenceInvert', false);
        $this->RegisterPropertyBoolean('cfg_TileLight', false);
        $this->RegisterPropertyInteger('cfg_ListOrder', 0);
        $this->RegisterPropertyInteger('cfg_SuggestOrder', 0);

        $this->RegisterAttributeString('States', '{}');
        $this->RegisterAttributeString('Status', '{}');
        $this->RegisterAttributeString('Due', '{}');
        $this->RegisterAttributeString('End', '{}');
        $this->RegisterAttributeString('Sent', '{}');
        $this->RegisterAttributeString('Fp', '{}');
        $this->RegisterAttributeInteger('ModeKnown', -1);
        $this->RegisterAttributeString('MelStats', '{}');
        $this->RegisterAttributeString('Protocol', '[]');
        $this->RegisterAttributeString('Suggestions', '[]');
        $this->RegisterAttributeString('Dismissed', '[]');
        $this->RegisterAttributeString('RoleCounts', '');
        $this->RegisterAttributeString('RoomSource', '');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastAutoMode', -1);
        $this->RegisterAttributeString('LightState', '{}');
        $this->RegisterAttributeString('Deferred', '[]');
        $this->RegisterAttributeString('Stats', '{}');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('Tick', 0, 'AUTOMAT_Tick($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->ensureModeProfile();
        $this->MaintainVariable('Hausmodus', 'Hausmodus', VARIABLETYPE_INTEGER, self::PROFILE_MODE, 1, true);
        $this->EnableAction('Hausmodus');
        $this->MaintainVariable('Pause', 'Alle Automatismen pausieren', VARIABLETYPE_BOOLEAN, '~Switch', 2, true);
        $this->EnableAction('Pause');
        $this->MaintainVariable('LastMessage', 'Letzte Meldung', VARIABLETYPE_STRING, '', 3, true);
        // Gedächtnis der Automatismen (gemerkte Sollwerte, gelernte Zeiten, Melderzustände) als versteckte
        // Variable statt Attribut: übersteht ein Neueinbinden des Moduls in der Modulverwaltung.
        $this->MaintainVariable('store', 'Gedächtnis (intern)', VARIABLETYPE_STRING, '', 999, true);
        $storeID = @IPS_GetObjectIDByIdent('store', $this->InstanceID);
        if ($storeID) {
            IPS_SetHidden($storeID, true);
            $old = $this->aStr('States');
            if ((string)GetValue($storeID) === '' && $old !== '' && $old !== '{}') {
                SetValueString($storeID, $old); // Übernahme aus Versionen bis 0.1.13
            }
        }

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($sender !== 0) {
                    $this->UnregisterMessage($sender, $message);
                }
            }
        }

        // Zeilen ohne Kennung (z. B. über „+“, falls Symcon den Dialogwert nicht übernimmt) bekommen einmal eine
        // feste — sonst hinge die Kennung am Listenplatz und Sperre/Zustand wechselten beim Löschen davor.
        $vis = json_decode((string)$this->pStr('Automations'), true);
        if (is_array($vis) && function_exists('IPS_SetProperty')) {
            $fixed = false;
            foreach ($vis as $i => $vr) {
                if (is_array($vr) && preg_replace('/[^a-z0-9]/', '', strtolower((string)($vr['Uid'] ?? ''))) === '') {
                    $vis[$i]['Uid'] = $this->newUid($vis);
                    $fixed = true;
                }
            }
            if ($fixed) {
                IPS_SetProperty($this->InstanceID, 'Automations', json_encode(array_values($vis)));
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }
        $rows = $this->rows();
        $keep = [];
        $enabled = 0;
        foreach ($rows as $i => $row) {
            $uid = $row['Uid'];
            $name = $this->rowName($row);
            $pos = 10 + $i * 4;
            $this->ensureVar('s_' . $uid, $name . ' · Status', VARIABLETYPE_STRING, '', $pos);
            $this->ensureVar('r_' . $uid, $name . ' · Restzeit', VARIABLETYPE_STRING, '', $pos + 1);
            $this->ensureVar('l_' . $uid, $name . ' · gesperrt', VARIABLETYPE_BOOLEAN, '~Switch', $pos + 2);
            $this->EnableAction('l_' . $uid);
            $keep['s_' . $uid] = $keep['r_' . $uid] = $keep['l_' . $uid] = true;
            if (!empty(AUTOMATKatalog::TYPES[$row['Type']]['light']) && $row['Type'] !== 'daemmerung') {
                $this->ensureVar('d_' . $uid, $name . ' · Dauerlicht', VARIABLETYPE_BOOLEAN, '~Switch', $pos + 3);
                $this->EnableAction('d_' . $uid);
                $keep['d_' . $uid] = true;
            }
            if ($row['Type'] === 'anwesenheit') {
                $this->ensureVar('p_' . $uid, $name . ' · Jemand zu Hause', VARIABLETYPE_BOOLEAN, IPS_VariableProfileExists('~Presence') ? '~Presence' : '~Switch', $pos + 3);
                $keep['p_' . $uid] = true;
            }
            if (empty($row['Enabled'])) {
                continue;
            }
            $enabled++;
            foreach (AUTOMATKatalog::variables($row) as $role => $list) {
                if ($role === 'read') {
                    continue;
                }
                foreach ($list as $vid) {
                    if (IPS_VariableExists($vid)) {
                        $this->RegisterMessage($vid, VM_UPDATE);
                    }
                }
            }
        }
        $presence = $this->externalPresenceVar();
        if ($presence > 0) {
            $this->RegisterMessage($presence, VM_UPDATE);
        }
        $modeID = @IPS_GetObjectIDByIdent('Hausmodus', $this->InstanceID);
        if ($modeID) {
            $this->RegisterMessage($modeID, VM_UPDATE);
            if ($this->aInt('ModeKnown') < 0) {
                $this->WriteAttributeInteger('ModeKnown', (int)GetValue($modeID));
            }
        }
        $isDay = $this->locationVariable('IsDay');
        if ($isDay > 0 && ($this->pInt('cfg_DayRef') === 2 || $this->pInt('cfg_EveningRef') === 2)) {
            $this->RegisterMessage($isDay, VM_UPDATE);
        }
        if ($this->lightVar() > 0) {
            $this->RegisterMessage($this->lightVar(), VM_UPDATE);
        }

        // Variablen entfernter Automatismen aufräumen (nur eigene Idents).
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $ident = (string)(IPS_GetObject($cid)['ObjectIdent'] ?? '');
            if (preg_match('/^[sldrp]_[a-z0-9]+$/', $ident) && !isset($keep[$ident]) && IPS_VariableExists($cid)) {
                IPS_DeleteVariable($cid);
            }
        }
        $this->cleanupChanged($rows);
        // Zustände entfernter Automatismen vergessen.
        $uids = array_column($rows, 'Uid');
        foreach (['Status', 'Due', 'End'] as $attr) {
            $map = $this->attrMap($attr);
            $this->WriteAttributeString($attr, json_encode(array_intersect_key($map, array_flip($uids))));
        }
        $this->statesWrite(array_intersect_key($this->statesMap(), array_flip($uids)));

        $this->SetStatus($enabled > 0 ? 102 : 104);
        $this->bootstrapMelders($rows);
        foreach ($rows as $row) {
            // 'startup': z. B. ein beim Neustart noch abgesenktes Thermostat bei geschlossenen Fenstern zurückstellen
            $this->process($row, ['k' => 'flag', 'startup' => true]);
        }
        $this->evaluateMode(false);
        $this->reschedule();
        $this->refreshTile();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE) {
            if (($Data[0] ?? null) === KR_READY) {
                $this->ApplyChanges();
            }
            return;
        }
        if ($Message !== VM_UPDATE || IPS_GetKernelRunlevel() !== KR_READY || !IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        $vid = (int)$SenderID;
        $value = $Data[0] ?? null;
        // $Data[1] = Wert hat sich geändert. Viele Geräte melden denselben Wert regelmäßig erneut (Abfrage,
        // Funkqualität) — das ist weder Handbedienung noch ein neuer Anwesenheitswechsel.
        $changed = !array_key_exists(1, $Data) || (bool)$Data[1];
        if ($vid > 0 && $vid === $this->externalPresenceVar() && $changed) {
            $this->evaluateMode(true);
        }
        if ($vid === $this->locationVariable('IsDay') || ($vid > 0 && $vid === $this->lightVar())) {
            $this->evaluateMode(false);
        }
        $modeID = @IPS_GetObjectIDByIdent('Hausmodus', $this->InstanceID);
        if ($modeID && $vid === $modeID) {
            // Hausmodus per SetValue von außen (Skript, Ereignis) — sonst bekämen Szene, Heizung, Gehen-Check den
            // Wechsel nicht mit (Prüfbericht 17.10.2026)
            $known = $this->aInt('ModeKnown');
            if ($changed && (int)$value !== $known && isset(AUTOMATKatalog::MODES[(int)$value])) {
                $this->WriteAttributeInteger('ModeKnown', (int)$value);
                $this->announceMode($known >= 0 ? $known : (int)($Data[2] ?? 0), (int)$value, 'von außen gesetzt');
                $this->reschedule();
                $this->refreshTile();
            }
            return;
        }
        $origin = '';
        $own = $this->consumeEcho($vid, $value, $origin);
        $central = '';
        if ($own && $origin !== '') {
            $or = $this->rowByUid($origin);
            $central = $or !== null ? 'Szene „' . $this->rowName($or) . '“' : '';
        }
        $this->learnMelder($vid, $value);
        $touched = false;
        foreach ($this->rows() as $row) {
            if (empty($row['Enabled'])) {
                continue;
            }
            $vars = AUTOMATKatalog::variables($row);
            if (in_array($vid, $vars['in'], true)) {
                $this->process($row, ['k' => 'trigger', 'vid' => $vid, 'v' => $value, 'changed' => $changed]);
                $touched = true;
            }
            if ($changed && in_array($vid, $vars['out'], true)) {
                // Schaltet eine Szene ein Gerät, das auch ein anderer Automatismus steuert, ist das für diesen
                // weder eigenes Echo noch Handbedienung, sondern „zentral“ (keine Handpause).
                $other = $central !== '' && ($row['Uid'] ?? '') !== $origin;
                $this->process($row, ['k' => 'output', 'vid' => $vid, 'v' => $value, 'own' => $other ? false : $own] + ($other ? ['central' => $central] : []));
                $touched = true;
            }
        }
        if ($touched) {
            $this->reschedule();
            $this->refreshTile();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $Ident = (string)$Ident;
        if ($Ident === 'tile') {
            $this->tileAction((string)$Value);
            return;
        }
        if ($Ident === 'Hausmodus') {
            $mode = (int)$Value;
            if (!isset(AUTOMATKatalog::MODES[$mode])) {
                throw new Exception('Unbekannter Hausmodus: ' . $mode);
            }
            $this->setMode($mode, 'von Hand');
        } elseif ($Ident === 'Pause') {
            $this->SetValue('Pause', (bool)$Value);
            $this->addLog('', (bool)$Value ? '⏸ Alle Automatismen pausiert.' : '▶️ Pause beendet — alle Automatismen arbeiten wieder.');
            foreach ($this->rows() as $row) {
                $this->process($row, ['k' => 'flag']);
            }
        } elseif (preg_match('/^([ld])_([a-z0-9]+)$/', $Ident, $m)) {
            $row = $this->rowByUid($m[2]);
            if ($row === null) {
                throw new Exception('Unbekannter Automatismus: ' . $m[2]);
            }
            $this->SetValue($Ident, (bool)$Value);
            $this->addLog($this->rowName($row), $m[1] === 'l'
                ? ((bool)$Value ? '🔒 Gesperrt.' : '🔓 Sperre aufgehoben.')
                : ((bool)$Value ? '🔆 Dauerlicht an.' : 'Dauerlicht aus.'));
            $this->process($row, ['k' => 'flag']);
        } else {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->reschedule();
        $this->refreshTile();
    }

    // =====================================================================
    //  Laufzeit
    // =====================================================================

    /** Timer-Einstieg: alle Automatismen bekommen ein „tick“. */
    public function Tick(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->evaluateMode(false);
        foreach ($this->rows() as $row) {
            $this->process($row, ['k' => 'tick']);
        }
        $this->checkUnanswered();
        $this->flushDeferred();
        $this->reschedule();
        $this->refreshTile();
    }

    /**
     * Eine Zeile mit einem Ereignis durch die Logik schicken und das Ergebnis umsetzen. Lesen → Rechnen → Speichern
     * läuft unter einer Sperre: Timer und Gerätemeldungen laufen in Symcon parallel, sonst überschreibt einer den
     * gerade gespeicherten Zustand des anderen. Geschaltet wird erst danach (die Rückmeldung des Geräts läuft
     * wieder hier durch und darf nicht auf die eigene Sperre warten).
     */
    private function process(array $row, array $ev): ?array
    {
        try {
            $this->lockEnter();
            try {
                $res = $this->evaluateRow($row, $ev);
            } finally {
                $this->lockLeave();
            }
            if ($res !== null) {
                $this->applyResult($row, $res);
            }
            return $res;
        } catch (Throwable $e) {
            // Ein Fehler in einem Automatismus darf die anderen im selben Durchlauf nicht aufhalten.
            $this->LogMessage('Automatismus „' . $this->rowName($row) . '“: Fehler — ' . $e->getMessage(), KL_ERROR);
            return null;
        }
    }

    /**
     * AUTOMAT_RunNow($id, $uid, $what): string — einen Automatismus von Hand auslösen: verschlafene Schaltung
     * nachholen bzw. testen. $what: '' = das jetzt Fällige (Szene ausführen, Zeitschaltuhr-Zustand herstellen,
     * Treppenlicht starten, Gehen-Check prüfen, Heizung neu setzen), 'down'/'up' = Rollläden schließen/öffnen.
     */
    public function RunNow(string $uid, string $what): string
    {
        $row = $this->rowByUid(preg_replace('/[^a-z0-9]/', '', strtolower($uid)));
        if ($row === null) {
            return '⚠️ Unbekannter oder noch nicht übernommener Automatismus „' . $uid . '“ — erst „Übernehmen“.';
        }
        if (empty($row['Enabled'])) {
            return '⚫ „' . $this->rowName($row) . '“ ist nicht aktiv.';
        }
        if (in_array($what, ['down', 'up'], true) && $row['Type'] !== 'rollladen') {
            return 'ℹ️ „' . $this->rowName($row) . '“ ist kein Rollladen-Automatismus.';
        }
        $res = $this->process($row, ['k' => 'run', 'what' => $what]);
        $this->reschedule();
        $this->refreshTile();
        $log = $res !== null && $res['log'] !== null ? (string)$res['log'] : 'Nichts zu tun — ' . (string)($res['status'] ?? '');
        return (!empty($row['Dry']) ? '🧪 ' : '▶ ') . $this->rowName($row) . ': ' . $log;
    }

    /** AUTOMAT_UIRunMarked($id, $rowsJson, $what): string — angehakte Automatismen von Hand auslösen. */
    public function UIRunMarked(string $rowsJson, string $what): string
    {
        $rows = json_decode($rowsJson, true);
        $marked = array_values(array_filter(is_array($rows) ? $rows : [], function ($r) {
            return is_array($r) && !empty($r['Mark']);
        }));
        if (count($marked) === 0) {
            return 'ℹ️ Bitte zuerst in der Liste bei den Automatismen, die du auslösen willst, das Häkchen ☑︎ setzen.';
        }
        $out = [];
        foreach ($marked as $r) {
            if ($what !== '' && ($r['Type'] ?? '') !== 'rollladen') {
                continue;
            }
            $out[] = $this->RunNow((string)($r['Uid'] ?? ''), $what);
        }
        return count($out) > 0 ? implode("\n", $out) : 'ℹ️ Unter den angehakten ist kein Rollladen-Automatismus.';
    }

    /** Sperre für Lese-/Schreibabschnitte der Attribute (nicht verschachteln, nie während eines Schaltbefehls halten). */
    private int $lockDepth = 0;

    private function lockEnter(): void
    {
        if ($this->lockDepth++ === 0 && function_exists('IPS_SemaphoreEnter')) {
            IPS_SemaphoreEnter('AUTOMAT_' . $this->InstanceID, 5000);
        }
    }

    private function lockLeave(): void
    {
        if (--$this->lockDepth === 0 && function_exists('IPS_SemaphoreLeave')) {
            IPS_SemaphoreLeave('AUTOMAT_' . $this->InstanceID);
        }
    }

    /**
     * Abgeschaltete, gelöschte, auf Probelauf gestellte oder umgebaute Automatismen geben frei, was sie festhalten
     * (abgesenkte Sollwerte), und beginnen mit frischem Zustand. Vorher blieb z. B. ein Thermostat dauerhaft auf
     * 12 °C, wenn „Fenster offen“ mitten in der Absenkung abgeschaltet wurde (Prüfbericht 17.10.2026).
     */
    private function cleanupChanged(array $rows): void
    {
        $old = json_decode($this->aStr('Fp'), true);
        $new = [];
        foreach ($rows as $r) {
            $v = AUTOMATKatalog::variables($r);
            $out = $v['out'];
            sort($out);
            $new[$r['Uid']] = ['t' => $r['Type'], 'on' => !empty($r['Enabled']) && empty($r['Dry']), 'out' => $out];
        }
        $this->WriteAttributeString('Fp', json_encode($new));
        if (!is_array($old)) {
            return; // erster Lauf nach dem Update: nur merken
        }
        $byUid = array_column($rows, null, 'Uid');
        $states = $this->statesMap();
        $cmds = [];
        foreach ($old as $uid => $o) {
            $n = $new[$uid] ?? null;
            if ($n !== null && $n['on'] === $o['on'] && $n['out'] === $o['out']) {
                continue;
            }
            $st = is_array($states[$uid] ?? null) ? $states[$uid] : [];
            if (!empty($o['on']) && count($st) > 0) {
                $row = $byUid[$uid] ?? ['Uid' => $uid, 'Type' => (string)$o['t'], 'Name' => (string)$o['t']];
                $val = [];
                foreach (array_merge((array)$o['out'], array_keys((array)($st['saved'] ?? []))) as $vid) {
                    if (IPS_VariableExists((int)$vid)) {
                        $val[(int)$vid] = GetValue((int)$vid);
                    }
                }
                [$c, $text] = AUTOMATLogik::cleanup((string)$o['t'], $st, ['val' => $val]);
                if (count($c) > 0) {
                    $cmds = array_merge($cmds, $c);
                    $why = $n === null ? 'gelöscht' : (!$n['on'] ? 'abgeschaltet' : 'geändert');
                    $this->addLog(isset($byUid[$uid]) ? $this->rowName($row) : '', 'Automatismus ' . $why . ' — ' . $text);
                }
            }
            unset($states[$uid]); // frischer Zustand (auch nach einem Probelauf, der nur so getan hat)
        }
        $this->statesWrite($states);
        foreach ($cmds as [$vid, $value]) {
            $this->switchVariable((int)$vid, $value);
        }
    }

    /** Teil 1 von process(): unter der Sperre rechnen und Zustand speichern. null = nichts weiter zu tun. */
    private function evaluateRow(array $row, array $ev): ?array
    {
        $uid = $row['Uid'];
        $states = $this->statesMap();
        $status = $this->attrMap('Status');
        $due = $this->attrMap('Due');
        if (empty($row['Enabled'])) {
            $status[$uid] = '⚫ Nicht aktiv (in der Liste)';
            unset($due[$uid]);
            $this->WriteAttributeString('Status', json_encode($status));
            $this->WriteAttributeString('Due', json_encode($due));
            $this->setEnd($uid, null);
            $this->setVarIfChanged('s_' . $uid, $status[$uid]);
            return null;
        }
        $problems = AUTOMATKatalog::problems($row, function (int $vid) {
            return IPS_VariableExists($vid);
        });
        if (count($problems) > 0) {
            $status[$uid] = '⚠️ ' . $problems[0];
            unset($due[$uid]);
            $this->WriteAttributeString('Status', json_encode($status));
            $this->WriteAttributeString('Due', json_encode($due));
            $this->setEnd($uid, null);
            $this->setVarIfChanged('s_' . $uid, $status[$uid]);
            return null;
        }

        $ctx = $this->context($row);
        $st = is_array($states[$uid] ?? null) ? $states[$uid] : [];
        try {
            $res = AUTOMATLogik::step($row, $st, $ev, $ctx);
        } catch (Throwable $e) {
            $this->LogMessage('Automatismus „' . $this->rowName($row) . '“: Fehler in der Auswertung — ' . $e->getMessage(), KL_ERROR);
            return null;
        }
        $states[$uid] = $res['st'];
        $status[$uid] = $res['status'];
        if ($res['due'] !== null) {
            $due[$uid] = (int)$res['due'];
        } else {
            unset($due[$uid]);
        }
        $this->statesWrite($states);
        $this->WriteAttributeString('Status', json_encode($status));
        $this->WriteAttributeString('Due', json_encode($due));
        return $res;
    }

    /** Teil 2 von process(): schalten, melden, anzeigen (ohne Sperre). */
    private function applyResult(array $row, array $res): void
    {
        $uid = $row['Uid'];
        $dry = !empty($row['Dry']);
        $failed = [];
        if ($dry) {
            // Probelauf: nichts schalten, nichts melden — nur festhalten, was passiert wäre.
            $would = [];
            foreach ($res['cmd'] as [$vid, $value]) {
                $would[] = $this->varLabel((int)$vid) . ' → ' . AUTOMATLogik::fmtNum($value);
            }
            foreach ($res['msg'] as [$title, $text]) {
                $would[] = 'Meldung „' . $title . '“';
            }
            if (isset($res['mode'])) {
                $would[] = 'Hausmodus „' . (AUTOMATKatalog::MODES[(int)$res['mode']] ?? '') . '“';
            }
            if ($would) {
                $res['log'] = '🧪 Probelauf — würde: ' . implode('; ', $would) . ($res['log'] !== null ? ' (' . $res['log'] . ')' : '');
            } elseif ($res['log'] !== null) {
                $res['log'] = '🧪 ' . $res['log'];
            }
            $res['status'] = '🧪 ' . $res['status'];
            $this->setVarIfChanged('s_' . $uid, $res['status']);
            $res['cmd'] = [];
            $res['msg'] = [];
            unset($res['mode'], $res['fault']);
            $res['self'] = null;
        }
        foreach ($res['cmd'] as [$vid, $value]) {
            $why = '';
            if (!$this->switchVariable((int)$vid, $value, $row['Type'] === 'szene' ? $uid : '', !empty($res['raw']), $why)) {
                $failed[] = $this->varLabel((int)$vid) . ($why !== '' ? ' (' . $why . ')' : '');
            }
        }
        $this->countStats($uid, count($res['cmd']) > 0, count($res['msg']) > 0);
        if (!empty($res['lost'])) {
            $this->recordLost($res['lost']);
        }
        if ($res['log'] !== null) {
            $this->addLog($this->rowName($row), $res['log'] . (count($failed) > 0 ? ' ⚠️ Schalten fehlgeschlagen: ' . implode(', ', $failed) : ''));
        }
        if (!empty($res['fault']) && $this->pBool('cfg_NotifyFaults')) {
            $this->message('⚠️ ' . $this->rowName($row), (string)$res['fault'], true, null);
        }
        if (count($failed) > 0 && $this->pBool('cfg_NotifyFaults')) {
            $this->message('⚠️ ' . $this->rowName($row), 'Schalten fehlgeschlagen: ' . implode(', ', $failed) . '.', true, null);
        }
        $notify = !empty($row[self::NOTIFY_FIELD[$row['Type']] ?? '']);
        foreach ($res['msg'] as [$title, $text]) {
            $this->message((string)$title, (string)$text, $notify, $row, !empty($res['urgent']), (int)($res['media'] ?? 0));
        }
        $this->setVarIfChanged('s_' . $uid, $res['status']);
        $this->setEnd($uid, isset($res['end']) ? (int)$res['end'] : null);
        if (isset($res['mode']) && isset(AUTOMATKatalog::MODES[(int)$res['mode']])) {
            // Taster setzt den Hausmodus (erst jetzt, nachdem der eigene Zustand gespeichert ist)
            $this->setMode((int)$res['mode'], 'Taster „' . $this->rowName($row) . '“');
        }
        if (array_key_exists('self', $res) && $res['self'] !== null) {
            // Ergebnis eines Automatismus, der selbst eine Variable führt (Anwesenheit zusammenfassen)
            $pid = @IPS_GetObjectIDByIdent('p_' . $uid, $this->InstanceID);
            if ($pid && (bool)GetValue($pid) !== (bool)$res['self']) {
                $this->SetValue('p_' . $uid, (bool)$res['self']);
                if ($this->externalPresenceVar() === 0) {
                    $this->evaluateMode(true);
                }
            }
        }
    }

    /** Zeitpunkt des nächsten Ereignisses merken und „… · Restzeit" nachführen. */
    private function setEnd(string $uid, ?int $end): void
    {
        $map = $this->attrMap('End');
        if ($end !== null && $end > $this->now()) {
            $map[$uid] = $end;
        } else {
            unset($map[$uid]);
            $end = null;
        }
        $this->WriteAttributeString('End', json_encode($map));
        $this->setVarIfChanged('r_' . $uid, $end === null ? '' : AUTOMATLogik::fmtRest($end, $this->now()));
    }

    /** Schnappschuss der Umgebung für die Logik. */
    private function context(array $row): array
    {
        $uid = $row['Uid'];
        $vars = AUTOMATKatalog::variables($row);
        $val = [];
        $names = [];
        foreach (array_merge($vars['in'], $vars['out'], $vars['read']) as $vid) {
            if (IPS_VariableExists($vid)) {
                $val[$vid] = GetValue($vid);
                $names[$vid] = $this->varLabel($vid);
                if ($row['Type'] === 'anwesenheit') {
                    // „Dietmar / Zuhause“ → „Dietmar“: die Instanz trägt meist den Namen der Person
                    $p = IPS_GetParent($vid);
                    $names[$vid] = $p > 0 && IPS_InstanceExists($p) ? IPS_GetName($p) : IPS_GetName($vid);
                }
            }
        }
        $dim = [];
        foreach ($vars['out'] as $vid) {
            if (IPS_VariableExists($vid)) {
                $dim[$vid] = $this->dimMax($vid);
            }
        }
        $ctx = [
            'now'       => $this->now(),
            'mode'      => (int)$this->GetValue('Hausmodus'),
            'paused'    => (bool)$this->GetValue('Pause'),
            'locked'    => (bool)$this->getOwn('l_' . $uid, false),
            'permanent' => (bool)$this->getOwn('d_' . $uid, false),
            'condOk'    => $this->conditionOk((string)($row['Cond'] ?? '')),
            'val'       => $val,
            'dim'       => $dim,
            'names'     => $names,
            'rnd'       => $this->randomUnit(),
        ];
        foreach (AUTOMATKatalog::CONTACT_FIELDS as $cf) {
            foreach (AUTOMATKatalog::vars($row, $cf) as $cv) {
                if (IPS_VariableExists($cv) && AUTOMATErkennung::contactInverted($this->varInfo($cv))) {
                    $ctx['inv'][$cv] = true;
                }
            }
        }
        if ($row['Type'] === 'ruecksetzen') {
            foreach (AUTOMATKatalog::vars($row, 'mr_mel') as $mv) {
                if (IPS_VariableExists($mv)) {
                    $ctx['upd'][$mv] = (int)(IPS_GetVariable($mv)['VariableUpdated'] ?? 0) ?: null;
                }
            }
        }
        if ($row['Type'] === 'ausfall') {
            $av = (int)($row['af_var'] ?? 0);
            if (IPS_VariableExists($av)) {
                $ctx['upd'][$av] = (int)(IPS_GetVariable($av)['VariableUpdated'] ?? 0) ?: null;
            }
        }
        if ($row['Type'] === 'gehen') {
            foreach (AUTOMATKatalog::vars($row, 'gc_geraet') as $gv) {
                $ctx['switchable'][$gv] = IPS_VariableExists($gv) && HasAction($gv);
            }
        }
        if ($row['Type'] === 'rollladen' || $row['Type'] === 'alarm') {
            foreach (array_merge(AUTOMATKatalog::vars($row, 'rl_out'), AUTOMATKatalog::vars($row, 'al_hoch')) as $sv) {
                $dir = IPS_VariableExists($sv) ? $this->shutterDir($sv) : null;
                if ($dir !== null) {
                    $ctx['rlDir'][$sv] = $dir;
                }
            }
        }
        if ($row['Type'] === 'alarm') {
            foreach ($this->rows() as $rr) {
                if ($rr['Type'] === 'rollladen' && !empty($rr['Enabled'])) {
                    foreach (AUTOMATKatalog::vars($rr, 'rl_out') as $rv) {
                        $ctx['rlOpen'][$rv] = (float)($rr['rl_auf'] ?? 0);
                    }
                }
            }
        }
        if ($row['Type'] === 'heizung') {
            // Thermostate, die „Fenster offen“ gerade abgesenkt hält — dort fährt die Heizung nicht dazwischen
            $states = $this->statesMap();
            foreach ($this->rows() as $fr) {
                $fst = $states[$fr['Uid']] ?? [];
                if ($fr['Type'] === 'fenster' && !empty($fr['Enabled']) && empty($fr['Dry']) && ($fst['ph'] ?? '') === 'ab') {
                    foreach (array_keys((array)($fst['saved'] ?? [])) as $hv) {
                        $ctx['held'][(int)$hv] = true;
                    }
                }
            }
        }
        if ($row['Type'] === 'bewegung') {
            foreach (AUTOMATKatalog::vars($row, 'bl_mel') as $m) {
                $ctx['melTimeout'][$m] = $this->melderTimeout($m);
            }
        }
        if ($row['Type'] === 'waechter') {
            $wv = (int)($row['wa_var'] ?? 0);
            $ctx['labels'][$wv] = $this->valueLabels($wv);
        }
        if (in_array($row['Type'], ['zeitschalt', 'rollladen', 'wecklicht'], true)) {
            $land = $this->pStr('cfg_Bundesland');
            $today = date('Y-m-d', $ctx['now']);
            foreach ([-1, 0, 1] as $n) {
                $d = AUTOMATLogik::addDays($today, $n);
                $h = AUTOMATLogik::holiday($d, $land);
                if ($h !== '') {
                    $ctx['holiday'][$d] = $h;
                }
            }
        }
        if ($row['Type'] === 'daemmerung' || $row['Type'] === 'rollladen') {
            $ld = $this->lightDayNow($ctx['now']);
            $ctx['dark'] = $ld === null ? null : !$ld;
            $today = date('Y-m-d', $ctx['now']);
            foreach ([-1, 0, 1, 2] as $n) {
                $d = AUTOMATLogik::addDays($today, $n);
                $ctx['sun'][$d] = ['ss' => $this->sunOn('Sunset', $d), 'sr' => $this->sunOn('Sunrise', $d)];
            }
        }
        return $ctx;
    }

    /** Symcon-Bedingung auswerten; leer = erfüllt. */
    private function conditionOk(string $cond): bool
    {
        $c = trim($cond);
        if ($c === '' || $c === '[]') {
            return true;
        }
        try {
            return (bool)IPS_IsConditionPassing($c);
        } catch (Throwable $e) {
            $this->SendDebug('Bedingung', 'Nicht auswertbar: ' . $e->getMessage(), 0);
            return false;
        }
    }

    /** Timer auf die nächste fällige Frist stellen (höchstens MAX_TICK_S). */
    private function reschedule(): void
    {
        $rows = array_filter($this->rows(), function ($r) {
            return !empty($r['Enabled']);
        });
        if (count($rows) === 0 && !$this->pBool('cfg_ModeAuto') && $this->aStr('Deferred') === '[]') {
            $this->SetTimerInterval('Tick', 0);
            return;
        }
        $now = $this->now();
        $next = $now + self::MAX_TICK_S;
        foreach ($this->attrMap('Due') as $ts) {
            $next = min($next, (int)$ts);
        }
        // Läuft irgendwo ein Countdown unter einer Stunde, „Restzeit" alle paar Sekunden nachführen.
        foreach ($this->attrMap('End') as $ts) {
            if ((int)$ts > $now && (int)$ts - $now <= 3600) {
                $next = min($next, $now + self::REST_TICK_S);
            }
        }
        // Offene Schaltbefehle: kurz nach Ablauf der Echo-Zeit nachkontrollieren
        foreach ($this->sentMap() as $list) {
            $last = end($list);
            if (is_array($last)) {
                $next = min($next, max($now + 1, (int)$last[1] + self::ECHO_S + 6));
            }
        }
        $this->SetTimerInterval('Tick', max(1, $next - $now) * 1000);
    }

    /** Schalten einer Zielvariable; merkt sich den Wert, damit das Echo nicht als Handbedienung zählt. */
    /** $origin: Kennung einer Szene, die schaltet — andere Automatismen sehen das als „zentral“, nicht als Handbedienung. */
    private function switchVariable(int $vid, $value, string $origin = '', bool $raw = false, string &$why = ''): bool
    {
        if (!IPS_VariableExists($vid)) {
            return false;
        }
        $value = $this->castForVariable($vid, $value);
        // Alle Befehle der letzten ECHO_S Sekunden je Variable merken (nicht nur den letzten): bei der Vorwarnung
        // gehen „aus“ und eine Sekunde später „an“ raus — kommt das „aus“-Echo erst danach, wäre es sonst fremd.
        $this->lockEnter();
        try {
            $sent = $this->sentMap();
            $now = $this->now();
            $list = array_values(array_filter($sent[$vid] ?? [], function ($e) use ($now) {
                return $now - (int)$e[1] <= self::ECHO_S;
            }));
            $list[] = [$value, $now, $origin, GetValue($vid)];
            $sent[$vid] = array_slice($list, -6);
            $this->WriteAttributeString('Sent', json_encode($sent));
        } finally {
            $this->lockLeave();
        }
        $ok = false;
        $error = '';
        try {
            $parent = IPS_GetParent($vid);
            if (HasAction($vid)) {
                $ok = (bool)RequestAction($vid, $value);
            } elseif ($raw) {
                // Sensorwert direkt setzen (Melder zurücksetzen). Hat die Instanz „Variablen sperren“ an, lehnt Symcon ab.
                $warn = '';
                set_error_handler(function ($no, $str) use (&$warn) {
                    $warn = $str;
                    return true;
                });
                try {
                    $ok = (bool)SetValue($vid, $value);
                } finally {
                    restore_error_handler();
                }
                if (!$ok) {
                    $error = stripos($warn, 'read-only') !== false || stripos($warn, 'schreibgeschützt') !== false
                        ? 'Variable ist gesperrt — in der Instanz „' . IPS_GetName($parent) . '“ „Variablen sperren“ ausschalten'
                        : ($warn !== '' ? $warn : 'Setzen abgelehnt');
                }
            } elseif ($parent > 0 && $parent !== $this->InstanceID && IPS_InstanceExists($parent)) {
                // Variable einer fremden Instanz ohne Aktion: SetValue würde nur die Anzeige ändern, nicht das Gerät.
                $error = 'gehört zur Instanz „' . IPS_GetName($parent) . '“ und hat keine Aktion — nicht schaltbar, bitte die schaltbare Variable des Geräts wählen';
            } else {
                $ok = (bool)SetValue($vid, $value);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if (!$ok) {
            // Dauerhaft loggen, nicht nur ins Debug (SUITE.md „Sichtbare Rückmeldung“ Regel 5).
            $this->LogMessage('Schalten fehlgeschlagen: ' . $this->varLabel($vid) . ' → ' . AUTOMATLogik::fmtNum($value) . ($error !== '' ? ' (' . $error . ')' : ''), KL_WARNING);
            $why = $error;
        }
        return $ok;
    }

    /**
     * Nachkontrolle für alle Schaltbefehle: hat ein Gerät 20 s nach dem Befehl seinen Wert nicht geändert
     * (RequestAction meldete Erfolg, das Gerät tat nichts), steht das im Protokoll und — wenn gewünscht — als
     * Störung in den Meldungen. Rollläden fahren länger und haben ihre eigene Nachkontrolle.
     */
    private function checkUnanswered(): void
    {
        $now = $this->now();
        $bad = [];
        $this->lockEnter();
        try {
            $sent = $this->sentMap();
            $changed = false;
            foreach ($sent as $vid => $list) {
                $last = end($list);
                if (!is_array($last) || $now - (int)$last[1] < self::ECHO_S + 5) {
                    continue;
                }
                unset($sent[$vid]);
                $changed = true;
                if (!IPS_VariableExists((int)$vid) || preg_match('/shutter|jalousie|rollladen|rolladen|blind/i', (string)$this->varInfo((int)$vid)['profile'])) {
                    continue;
                }
                $cur = GetValue((int)$vid);
                if (!$this->sameValue($cur, $last[0], (int)$vid) && array_key_exists(3, $last) && $this->sameValue($cur, $last[3], (int)$vid)) {
                    $bad[] = $this->varLabel((int)$vid) . ' → ' . AUTOMATLogik::fmtNum($last[0]);
                }
            }
            if ($changed) {
                $this->WriteAttributeString('Sent', json_encode($sent));
            }
        } finally {
            $this->lockLeave();
        }
        if (count($bad) > 0) {
            $text = 'Gerät hat nicht reagiert (Wert unverändert): ' . implode(', ', $bad) . '. Funkverbindung prüfen.';
            $this->addLog('', '⚠️ ' . $text);
            if ($this->pBool('cfg_NotifyFaults')) {
                $this->message('⚠️ Automat', $text, true, null);
            }
        }
    }

    /** Gesendete Befehle je Variable: [[Wert, Zeit, Szene, Wert vor dem Befehl], …] (ältere Form [Wert, Zeit, Szene] wird umgewandelt). */
    private function sentMap(): array
    {
        $out = [];
        foreach ($this->attrMap('Sent') as $vid => $e) {
            if (!is_array($e) || count($e) === 0) {
                continue;
            }
            $out[(int)$vid] = is_array($e[0] ?? null) ? $e : [$e];
        }
        return $out;
    }

    /**
     * War diese Wertänderung das Echo eines eigenen Befehls? Passt sie zu irgendeinem Befehl der letzten ECHO_S
     * Sekunden — auch als Zwischenwert auf dem Weg dorthin (Dimmer 40 → 20 → 0, Rollladen unterwegs). Die Einträge
     * bleiben bis zum Ablauf stehen: Geräte, die erst den eigenen und dann den bestätigten Wert melden, liefern sonst
     * eine „Handbedienung“. $origin = schaltende Szene.
     */
    private function consumeEcho(int $vid, $value, string &$origin = ''): bool
    {
        $origin = '';
        $this->lockEnter();
        try {
            $sent = $this->sentMap();
            if (!isset($sent[$vid])) {
                return false;
            }
            $now = $this->now();
            $list = array_values(array_filter($sent[$vid], function ($e) use ($now) {
                return $now - (int)$e[1] <= self::ECHO_S;
            }));
            if (count($list) !== count($sent[$vid])) {
                if (count($list) > 0) {
                    $sent[$vid] = $list;
                } else {
                    unset($sent[$vid]);
                }
                $this->WriteAttributeString('Sent', json_encode($sent));
            }
            foreach (array_reverse($list) as $e) {
                $want = $e[0];
                $from = $e[3] ?? null;
                $between = is_numeric($value) && !is_bool($value) && is_numeric($want) && !is_bool($want) && is_numeric($from) && !is_bool($from)
                    && (float)$value >= min((float)$from, (float)$want) - 0.01 && (float)$value <= max((float)$from, (float)$want) + 0.01;
                if ($this->sameValue($value, $want, $vid) || $between) {
                    $origin = (string)($e[2] ?? '');
                    return true;
                }
            }
            return false;
        } finally {
            $this->lockLeave();
        }
    }

    // =====================================================================
    //  Melder-Dauern lernen (Rückfall bei verlorener „keine Bewegung“-Meldung)
    // =====================================================================

    /** Melder aller aktiven Bewegungslichter. */
    private function melderIDs(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (!empty($r['Enabled']) && $r['Type'] === 'bewegung' && empty($r['bl_impuls'])) {
                foreach (AUTOMATKatalog::vars($r, 'bl_mel') as $m) {
                    $ids[$m] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** Dauer „Bewegung“ → „keine Bewegung“ je Melder mitschreiben (die letzten 50). */
    private function learnMelder(int $vid, $value): void
    {
        if (!in_array($vid, $this->melderIDs($this->rows()), true)) {
            return;
        }
        $stats = $this->attrMap('MelStats');
        $st = ($stats[$vid] ?? []) + ['d' => [], 't' => null, 'boot' => false, 'on' => 0, 'off' => 0];
        $now = $this->now();
        if (AUTOMATLogik::isTrue($value)) {
            $st['on']++;
            if ($st['t'] === null) {
                $st['t'] = $now;
            }
        } else {
            $st['off']++;
        }
        if (!AUTOMATLogik::isTrue($value) && $st['t'] !== null) {
            $d = $now - (int)$st['t'];
            if ($d >= 0 && $d <= 7200) {
                $st['d'][] = $d;
                $st['d'] = array_slice($st['d'], -50);
            }
            $st['t'] = null;
        }
        $stats[$vid] = $st;
        $this->WriteAttributeString('MelStats', json_encode($stats));
    }

    /**
     * Einmalig je Melder die Dauern der letzten 3 Tage aus dem Archiv lesen (falls archiviert),
     * damit die Rückfallzeit nicht erst nach Tagen passt.
     */
    private function bootstrapMelders(array $rows): void
    {
        $stats = $this->attrMap('MelStats');
        $ac = (int)(@IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0);
        $changed = false;
        foreach ($this->melderIDs($rows) as $vid) {
            if (!empty($stats[$vid]['boot']) || !IPS_VariableExists($vid)) {
                continue;
            }
            $st = $stats[$vid] ?? ['d' => [], 't' => null, 'boot' => false];
            $st['boot'] = true;
            if ($ac > 0 && function_exists('AC_GetLoggingStatus') && function_exists('AC_GetLoggedValues')) {
                try {
                    if (AC_GetLoggingStatus($ac, $vid)) {
                        $vals = AC_GetLoggedValues($ac, $vid, strtotime('-3 days', $this->now()), $this->now(), 0);
                        $vals = is_array($vals) ? array_reverse($vals) : []; // älteste zuerst
                        $start = null;
                        $d = [];
                        foreach ($vals as $e) {
                            if (AUTOMATLogik::isTrue($e['Value'])) {
                                $st['on'] = ($st['on'] ?? 0) + 1;
                                $start = $start ?? (int)$e['TimeStamp'];
                            } else {
                                $st['off'] = ($st['off'] ?? 0) + 1;
                            }
                            if (!AUTOMATLogik::isTrue($e['Value']) && $start !== null) {
                                $d[] = (int)$e['TimeStamp'] - $start;
                                $start = null;
                            }
                        }
                        $st['d'] = array_slice(array_merge($d, $st['d']), -50);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Melder', 'Archiv für #' . $vid . ' nicht lesbar: ' . $e->getMessage(), 0);
                }
            }
            $stats[$vid] = $st;
            $changed = true;
        }
        if ($changed) {
            $this->WriteAttributeString('MelStats', json_encode($stats));
        }
    }

    /**
     * Gelernte Rückfallzeit eines Melders: 3 × das 90-%-Quantil der üblichen „Bewegung“-Dauer, 30 s bis 1 h.
     * Ein einzelner verlorener „aus“-Wert verfälscht das 90-%-Quantil kaum. Unter 5 Messungen: 10 min.
     */
    private function melderTimeout(int $vid): int
    {
        $d = $this->attrMap('MelStats')[$vid]['d'] ?? [];
        if (count($d) < 5) {
            return 600;
        }
        sort($d);
        $p90 = (int)$d[(int)floor((count($d) - 1) * 0.9)];
        return max(30, min(3600, 3 * $p90));
    }

    /** Melder sendet offenbar nie „aus“ (mindestens 10 × „an“, kein einziges „aus“) → Impuls-Melder. */
    private function isImpulseMelder(int $vid): bool
    {
        $st = $this->attrMap('MelStats')[$vid] ?? [];
        return (int)($st['on'] ?? 0) >= 10 && (int)($st['off'] ?? 0) === 0;
    }

    /** Eingriffe des Melder-Rückfalls je Melder mitschreiben (Zeitpunkte der letzten 30 Tage). */
    private function recordLost(array $vids): void
    {
        $stats = $this->attrMap('MelStats');
        $now = $this->now();
        $cut = strtotime('-30 days', $now);
        foreach ($vids as $vid) {
            $st = ($stats[$vid] ?? []) + ['d' => [], 't' => null, 'boot' => true, 'on' => 0, 'off' => 0, 'lost' => []];
            $st['lost'] = array_values(array_filter((array)$st['lost'], function ($t) use ($cut) {
                return (int)$t >= $cut;
            }));
            $st['lost'][] = $now;
            $stats[$vid] = $st;
        }
        $this->WriteAttributeString('MelStats', json_encode($stats));
    }

    /** Anzahl verlorener „aus“-Meldungen eines Melders in den letzten 7 Tagen. */
    private function lostCount(int $vid): int
    {
        $cut = strtotime('-7 days', $this->now());
        return count(array_filter((array)($this->attrMap('MelStats')[$vid]['lost'] ?? []), function ($t) use ($cut) {
            return (int)$t >= $cut;
        }));
    }

    /** Hinweise je Feld zum Verhalten der Melder (Impuls erkannt, Funkqualität) — für Dialog und Liste. */
    private function melderHints(array $row): array
    {
        $out = [];
        if (($row['Type'] ?? '') !== 'bewegung') {
            return $out;
        }
        $impulse = [];
        for ($i = 1; $i <= AUTOMATKatalog::slotsIn($row, 'bl_mel'); $i++) {
            $vid = (int)($row['bl_mel' . $i] ?? 0);
            if ($vid < AUTOMATKatalog::$minId || !IPS_VariableExists($vid)) {
                continue;
            }
            $n = $this->lostCount($vid);
            if ($n > 0) {
                $out['bl_mel' . $i] = '📶 In den letzten 7 Tagen ' . $n . '× „keine Bewegung“ nicht angekommen (Rückfall hat eingegriffen) — Empfang, Batterie oder Standort des Melders prüfen.';
            }
            if (empty($row['bl_impuls']) && $this->isImpulseMelder($vid)) {
                $impulse[] = $this->varLabel($vid);
            }
        }
        $stt = $this->statesMap()[$row['Uid'] ?? ''] ?? [];
        $eff = AUTOMATLogik::effectiveNachlauf($row, is_array($stt) ? $stt : []);
        $fo = count((array)($stt['fo'] ?? []));
        if ($fo > 0 || $eff > max(1, (int)($row['bl_nachlauf'] ?? 120))) {
            $out['bl_nachlauf'] = '🕒 Gelernt: ' . AUTOMATKatalog::dur($eff) . ($fo > 0 ? ' — ' . $fo . '× zu früh ausgeschaltet in den letzten 7 Tagen' : '') . '. Der Wert hier ist der Grundwert, zu dem Automat zurückkehrt; gelernt wird höchstens das ' . AUTOMATLogik::LEARN_FACTOR_MAX . '-Fache (' . AUTOMATKatalog::dur(AUTOMATLogik::learnCap($row)) . '). Wert ändern verwirft das Gelernte.';
        }
        if (count($impulse) > 0) {
            $out['bl_impuls'] = 'ℹ️ ' . implode(', ', $impulse) . ' sendet offenbar nie „aus“ (nur „Bewegung“) — diesen Schalter einschalten, dann beginnt der Nachlauf bei jeder Meldung.';
        }
        return $out;
    }

    // =====================================================================
    //  Hausmodus
    // =====================================================================

    private function ensureModeProfile(): void
    {
        if (IPS_VariableProfileExists(self::PROFILE_MODE)) {
            return;
        }
        IPS_CreateVariableProfile(self::PROFILE_MODE, VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileIcon(self::PROFILE_MODE, 'House');
        $colors = [0 => 0xF5C542, 1 => 0xE08A3C, 2 => 0x3C5BA8, 3 => 0x8A8A8A, 4 => 0x2E8B3D];
        foreach (AUTOMATKatalog::MODES as $v => $label) {
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, $v, $label, '', $colors[$v]);
        }
    }

    private function setMode(int $mode, string $source): void
    {
        if ((int)$this->GetValue('Hausmodus') === $mode) {
            return;
        }
        $from = (int)$this->GetValue('Hausmodus');
        $this->WriteAttributeInteger('ModeKnown', $mode);
        $this->SetValue('Hausmodus', $mode);
        $this->announceMode($from, $mode, $source);
    }

    /** Alle Automatismen über einen Wechsel des Hausmodus informieren. */
    private function announceMode(int $from, int $mode, string $source): void
    {
        $this->addLog('', '🏠 Hausmodus „' . (AUTOMATKatalog::MODES[$mode] ?? $mode) . '“ (' . $source . ').');
        foreach ($this->rows() as $row) {
            $this->process($row, in_array($row['Type'], self::MODE_EVENT_TYPES, true) ? ['k' => 'mode', 'from' => $from, 'to' => $mode] : ['k' => 'flag']);
        }
    }

    /**
     * Hausmodus-Automatik: nur bei einem WECHSEL des automatisch ermittelten
     * Modus umstellen — eine Handeinstellung bleibt so bis zum nächsten
     * Übergang (z. B. „Nacht“ von Hand um 21 Uhr bleibt bis zum Morgen).
     */
    private function evaluateMode(bool $presenceChanged): void
    {
        [$presence] = $this->presenceSource();
        if (!$this->pBool('cfg_ModeAuto') && $presence === null) {
            return;
        }
        $current = (int)$this->GetValue('Hausmodus');
        $now = $this->now();
        if ($this->pBool('cfg_ModeAuto')) {
            $today = date('Y-m-d', $now);
            $isDayVid = $this->locationVariable('IsDay');
            // Gewählter Zeitpunkt der Location Control → für die Logik „sunrise“/„sunset“ (Ref 1 bzw. 0).
            $dayRef = $this->pInt('cfg_DayRef');
            $eveRef = $this->pInt('cfg_EveningRef');
            $nightRef = $this->pInt('cfg_NightRef');
            $target = AUTOMATLogik::autoMode($now, [
                'dayRef' => isset(self::DAY_EVENTS[$dayRef]) ? 1 : $dayRef, 'dayFrom' => $this->pStr('cfg_DayFrom'), 'dayOff' => $this->pInt('cfg_DayOffset'),
                'eveRef' => isset(self::EVE_EVENTS[$eveRef]) ? 0 : $eveRef, 'eveFrom' => $this->pStr('cfg_EveningFrom'), 'eveOff' => $this->pInt('cfg_EveningOffset'),
                'night' => $this->pStr('cfg_NightFrom'),
                'nightAt' => isset(self::NIGHT_EVENTS[$nightRef]) ? $this->sunOn(self::NIGHT_EVENTS[$nightRef], $today) : null,
                'nightOff' => $this->pInt('cfg_NightOffset'),
                'sunrise' => isset(self::DAY_EVENTS[$dayRef]) ? $this->sunOn(self::DAY_EVENTS[$dayRef], $today) : null,
                'sunset' => isset(self::EVE_EVENTS[$eveRef]) ? $this->sunOn(self::EVE_EVENTS[$eveRef], $today) : null,
                'isDay' => $isDayVid > 0 ? (bool)GetValue($isDayVid) : null,
                'lightDay' => $this->lightDayNow($now),
                'ssAny' => $this->sunOn('Sunset', $today),
            ], $presence, $current);
        } else {
            // Nur Anwesenheit: Abwesend setzen bzw. beim Heimkommen auf Tag zurück.
            if ($current === AUTOMATKatalog::MODE_HOLIDAY) {
                return;
            }
            $target = $presence === false ? AUTOMATKatalog::MODE_AWAY : ($current === AUTOMATKatalog::MODE_AWAY ? 0 : $current);
        }
        $last = $this->aInt('LastAutoMode');
        if ($target !== $last || ($presenceChanged && $target !== $current)) {
            $this->WriteAttributeInteger('LastAutoMode', $target);
            if ($target !== $current) {
                $this->setMode($target, $presenceChanged ? 'Anwesenheit' : 'automatisch');
            }
        }
    }

    /**
     * Woher kommt „jemand zu Hause“? [true/false/null, Beschreibung]. Eingetragene Variable hat Vorrang,
     * sonst das Ergebnis eines Automatismus „Anwesenheit zusammenfassen“.
     */
    private function presenceSource(): array
    {
        $presVid = $this->externalPresenceVar();
        if ($presVid > 0) {
            $p = AUTOMATLogik::isTrue(GetValue($presVid));
            if ($this->pBool('cfg_PresenceInvert')) {
                $p = !$p;
            }
            return [$p, '✏️ Anwesenheit aus der eingetragenen Variable „' . $this->varLabel($presVid) . '“: ' . ($p ? 'jemand da' : 'niemand da') . '.'];
        }
        foreach ($this->rows() as $r) {
            if ($r['Type'] === 'anwesenheit' && !empty($r['Enabled'])) {
                $pid = @IPS_GetObjectIDByIdent('p_' . $r['Uid'], $this->InstanceID);
                if ($pid) {
                    $p = (bool)GetValue($pid);
                    return [$p, '✅ 🔗 Anwesenheit automatisch aus dem Automatismus „' . $this->rowName($r) . '“: ' . ($p ? 'jemand da' : 'niemand da') . '.'];
                }
            }
        }
        return [null, 'ℹ️ Keine Anwesenheit verknüpft — „Abwesend“ wird nur von Hand gesetzt. Tipp: Automatismus „🏠 Anwesenheit zusammenfassen“ anlegen (Entdecken schlägt ihn vor), dann nutzt der Hausmodus ihn automatisch.'];
    }

    /**
     * Fremde Anwesenheits-Variable aus dem Feld (0 = keine). Die eigene Ergebnisvariable eines
     * „Anwesenheit zusammenfassen“ zählt nicht als fremd — sie wird ohnehin automatisch genutzt.
     */
    private function externalPresenceVar(): int
    {
        $vid = $this->pInt('cfg_PresenceVar');
        if ($vid < AUTOMATKatalog::$minId || !IPS_VariableExists($vid) || $this->isOwnPresenceVar($vid)) {
            return 0;
        }
        return $vid;
    }

    private function isOwnPresenceVar(int $vid): bool
    {
        return IPS_GetParent($vid) === $this->InstanceID && preg_match('/^p_[a-z0-9]+$/', (string)(IPS_GetObject($vid)['ObjectIdent'] ?? '')) === 1;
    }

    /** Gibt es einen aktiven Automatismus „Anwesenheit zusammenfassen“? */
    private function hasPresenceRow(): bool
    {
        foreach ($this->rows() as $r) {
            if ($r['Type'] === 'anwesenheit' && !empty($r['Enabled'])) {
                return true;
            }
        }
        return false;
    }

    // =====================================================================
    //  Sonnenstand (Location Control)
    // =====================================================================

    /** Sonnenauf-/-untergang am Kalendertag $ymd, abgeleitet aus Location Control (zeigt immer aufs NÄCHSTE Ereignis). */
    private function sunOn(string $ident, string $ymd): ?int
    {
        $vid = $this->locationVariable($ident);
        if ($vid === 0) {
            return null;
        }
        $v = (int)GetValue($vid);
        if ($v <= 0) {
            return null;
        }
        for ($i = 0; $i < 400 && date('Y-m-d', $v) !== $ymd; $i++) {
            $v = date('Y-m-d', $v) < $ymd ? strtotime('+1 day', $v) : strtotime('-1 day', $v);
        }
        return date('Y-m-d', $v) === $ymd ? $v : null;
    }

    private function locationVariable(string $ident): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID) as $iid) {
            $vid = @IPS_GetObjectIDByIdent($ident, $iid);
            if ($vid && IPS_VariableExists($vid)) {
                return (int)$vid;
            }
        }
        return 0;
    }

    // =====================================================================
    //  Meldungen und Protokoll
    // =====================================================================

    /**
     * Meldung: steht immer in „Letzte Meldung“; zugestellt wird nur, wenn der Automatismus melden soll ($notify),
     * über die Meldewege, die er gewählt hat (sonst die Standard-Wege). Wege mit „Ruhezeit beachten“ halten
     * die Meldung in der Ruhezeit zurück; die übrigen stellen sofort zu.
     */
    private function message(string $title, string $text, bool $notify, ?array $row, bool $urgent = false, int $media = 0): void
    {
        $this->SetValue('LastMessage', date('d.m.Y H:i', $this->now()) . ' — ' . $title . ': ' . $text);
        if (!$notify) {
            return;
        }
        // Alarm (Wasser/Rauch) wartet nie auf das Ende der Ruhezeit.
        $quiet = !$urgent && $this->inQuiet($this->now());
        $hold = [];
        foreach ($this->waysFor($row) as $w) {
            if ($quiet && $w['quiet']) {
                $hold[] = [$this->now(), $title, $text, $w['id'], $media];
                $this->SendDebug('Meldung', 'Ruhezeit — zurückgehalten für „' . $w['name'] . '“: ' . $title, 0);
                continue;
            }
            $this->deliverWay($w, $title, $text, $media);
        }
        if (count($hold) > 0) {
            $this->lockEnter();
            try {
                $q = array_merge(json_decode($this->aStr('Deferred'), true) ?: [], $hold);
                if (count($q) > 50) {
                    $this->LogMessage('Ruhezeit: mehr als 50 zurückgehaltene Meldungen — die ältesten ' . (count($q) - 50) . ' entfallen.', KL_WARNING);
                }
                $this->WriteAttributeString('Deferred', json_encode(array_slice($q, -50), JSON_UNESCAPED_UNICODE));
            } finally {
                $this->lockLeave();
            }
        }
    }

    /** Ruhezeit (volle Stunden, über Mitternacht möglich). */
    private function inQuiet(int $now): bool
    {
        if (!$this->pBool('cfg_QuietEnabled')) {
            return false;
        }
        $h = (int)date('G', $now);
        $from = $this->pInt('cfg_QuietFrom');
        $to = $this->pInt('cfg_QuietTo');
        return $from === $to ? false : ($from < $to ? $h >= $from && $h < $to : $h >= $from || $h < $to);
    }

    /** Nach der Ruhezeit zurückgehaltene Meldungen über ihren Weg zustellen. */
    private function flushDeferred(): void
    {
        if ($this->inQuiet($this->now())) {
            return;
        }
        $this->lockEnter();
        try {
            $q = json_decode($this->aStr('Deferred'), true) ?: [];
            if (count($q) > 0) {
                $this->WriteAttributeString('Deferred', '[]');
            }
        } finally {
            $this->lockLeave();
        }
        $ways = array_column($this->ways(), null, 'id');
        foreach ($q as $e) {
            $w = $ways[(string)($e[3] ?? '')] ?? null;
            foreach ($w !== null ? [$w] : $this->waysFor(null) as $way) {
                try {
                    $this->deliverWay($way, (string)$e[1], $e[2] . ' (zurückgehalten seit ' . date('d.m.Y H:i', (int)$e[0]) . ' Uhr, Ruhezeit)', (int)($e[4] ?? 0));
                } catch (Throwable $ex) {
                    $this->LogMessage('Zurückgehaltene Meldung nicht zugestellt: ' . $ex->getMessage(), KL_WARNING);
                }
            }
        }
    }

    /**
     * Alle Meldewege: [id, name, typ (push|mail|script), target, to, std, quiet].
     * Ohne gespeicherte Liste aus den früheren Einstellungen gebildet (Push an alle bzw. die gewählten
     * Visualisierungen, E-Mail), damit nach dem Update nichts verloren geht.
     */
    private function ways(): array
    {
        $rows = json_decode($this->pStr('cfg_Ways'), true);
        $out = [];
        if (is_array($rows) && count($rows) > 0) {
            $seen = [];
            foreach ($rows as $i => $r) {
                $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($r['Id'] ?? ''))) ?: 'w' . ($i + 1);
                while (isset($seen[$id])) {
                    $id .= 'x'; // in einer Sitzung doppelt hinzugefügt
                }
                $seen[$id] = true;
                $typ = in_array($r['Typ'] ?? '', ['push', 'mail', 'script'], true) ? $r['Typ'] : 'push';
                $out[] = ['id' => $id, 'name' => trim((string)($r['Name'] ?? '')) ?: 'Meldeweg ' . ($i + 1), 'typ' => $typ,
                    'target' => (int)($r['Target'] ?? 0), 'to' => trim((string)($r['To'] ?? '')),
                    'std' => !empty($r['Std']), 'quiet' => !array_key_exists('Quiet', $r) || !empty($r['Quiet'])];
            }
            return $out;
        }
        if ($this->pBool('cfg_NotifyPush')) {
            $chosen = array_values(array_filter(array_map(function ($r) {
                return (int)($r['Instance'] ?? 0);
            }, json_decode($this->pStr('cfg_PushTargets'), true) ?: [])));
            if (count($chosen) === 0) {
                $out[] = ['id' => 'push', 'name' => 'Push an alle Visualisierungen', 'typ' => 'push', 'target' => 0, 'to' => '', 'std' => true, 'quiet' => true];
            }
            foreach ($chosen as $n => $vid) {
                $out[] = ['id' => 'push' . ($n + 1), 'name' => 'Push an „' . (IPS_InstanceExists($vid) ? IPS_GetName($vid) : '#' . $vid) . '“', 'typ' => 'push', 'target' => $vid, 'to' => '', 'std' => true, 'quiet' => true];
            }
        }
        if ($this->pBool('cfg_NotifyMail')) {
            $out[] = ['id' => 'mail', 'name' => 'E-Mail', 'typ' => 'mail', 'target' => $this->pInt('cfg_MailInstance'), 'to' => trim($this->pStr('cfg_MailTo')), 'std' => true, 'quiet' => true];
        }
        return $out;
    }

    /** Meldewege eines Automatismus: seine angehakten, sonst die Standard-Wege. */
    private function waysFor(?array $row): array
    {
        $all = $this->ways();
        if ($row !== null) {
            $prefix = substr(self::NOTIFY_FIELD[$row['Type']] ?? '', 0, 3);
            $mine = array_values(array_filter($all, function ($w) use ($row, $prefix) {
                return $prefix !== '' && !empty($row[$prefix . 'mw_' . $w['id']]);
            }));
            if (count($mine) > 0) {
                return $mine;
            }
        }
        return array_values(array_filter($all, function ($w) {
            return $w['std'];
        }));
    }

    /** Über einen Meldeweg zustellen. Rückgabe: Ergebnis in Worten (für Testmeldung und Debug). */
    private function deliverWay(array $w, string $title, string $text, int $media = 0): string
    {
        switch ($w['typ']) {
            case 'mail':
                $err = $this->sendMail($w['target'], $w['to'], $title, $text);
                $res = $err === '' ? '✅ gesendet' : '⚠️ nicht gesendet — ' . $err;
                break;
            case 'script':
                if ($w['target'] <= 0 || !IPS_ScriptExists($w['target'])) {
                    $res = '⚠️ kein Skript gewählt (oder es gibt es nicht mehr)';
                    $this->LogMessage('Meldeweg „' . $w['name'] . '“: ' . $res . ' — Meldung „' . $title . '“ nicht zugestellt.', KL_WARNING);
                    break;
                }
                // Das Skript bekommt Titel und Text, z. B. für Telegram, Pushover oder eine Sprachausgabe.
                $ok = IPS_RunScriptEx($w['target'], ['TITLE' => $title, 'TEXT' => $text, 'MEDIA' => (string)$media, 'SENDER_AUTOMAT' => (string)$this->InstanceID]);
                $res = $ok ? '✅ Skript „' . IPS_GetName($w['target']) . '“ gestartet' : '⚠️ Skript ließ sich nicht starten';
                if (!$ok) {
                    $this->LogMessage('Meldeweg „' . $w['name'] . '“: Skript #' . $w['target'] . ' ließ sich nicht starten — Meldung „' . $title . '“ nicht zugestellt.', KL_WARNING);
                }
                break;
            default:
                $targets = $this->pushTargets($w['target']);
                $sent = 0;
                foreach ($targets as $t) {
                    try {
                        if ($t['typ'] === 'kachel' && function_exists('VISU_PostNotificationEx')) {
                            $ok = VISU_PostNotificationEx($t['id'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'Bell', 'bell', 0);
                        } elseif ($t['typ'] === 'webfront' && function_exists('WFC_PushNotification')) {
                            $ok = WFC_PushNotification($t['id'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'bell', $this->InstanceID);
                        } else {
                            $ok = false;
                        }
                    } catch (Throwable $e) {
                        $ok = false;
                    }
                    if ($ok) {
                        $sent++;
                    } else {
                        $this->LogMessage('Push an „' . $t['name'] . '“ fehlgeschlagen: ' . $title, KL_WARNING);
                    }
                }
                if (count($targets) === 0) {
                    $this->LogMessage('Meldeweg „' . $w['name'] . '“: keine passende Visualisierung gefunden — Meldung „' . $title . '“ nicht zugestellt.', KL_WARNING);
                }
                $res = count($targets) === 0 ? '⚠️ keine passende Visualisierung gefunden' : ($sent === count($targets) ? '✅ ' : '⚠️ ') . $sent . ' von ' . count($targets) . ' Zielen';
        }
        $this->SendDebug('Meldung', $w['name'] . ': ' . $title . ' — ' . $text . ' → ' . $res, 0);
        return $res;
    }

    /** E-Mail über eine SMTP-Instanz (0 = die erste gefundene); Rückgabe: '' bei Erfolg, sonst der Grund. */
    private function sendMail(int $inst, string $to, string $title, string $text): string
    {
        if ($inst <= 0) {
            $inst = (int)((@IPS_GetInstanceListByModuleID(self::SMTP_GUID) ?: [0])[0]);
        }
        if ($inst <= 0 || !IPS_InstanceExists($inst)) {
            return 'keine SMTP-Instanz gefunden oder gewählt';
        }
        $warnings = [];
        $err = '';
        // Das SMTP-Modul meldet den Grund (z. B. „Login denied“) als PHP-Warnung und gibt nur false zurück.
        set_error_handler(function ($no, $str) use (&$warnings) {
            $warnings[] = (string)$str;
            return true;
        });
        $sent = false;
        try {
            if ($to === '') {
                if (!function_exists('SMTP_SendMail')) {
                    $err = 'die Funktion SMTP_SendMail gibt es nicht (SMTP-Modul fehlt?)';
                } else {
                    $sent = (bool)SMTP_SendMail($inst, $title, $text);
                }
            } elseif (!function_exists('SMTP_SendMailEx')) {
                $err = 'die Funktion SMTP_SendMailEx gibt es nicht (SMTP-Modul fehlt?)';
            } else {
                foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $to))) as $addr) {
                    if (SMTP_SendMailEx($inst, $addr, $title, $text)) {
                        $sent = true;
                    } else {
                        $this->LogMessage('E-Mail an „' . $addr . '“ fehlgeschlagen.', KL_WARNING);
                    }
                }
            }
        } catch (Throwable $e) {
            $warnings[] = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($sent) {
            return '';
        }
        if ($err === '') {
            $raw = trim(implode(' / ', array_filter(array_map('trim', $warnings))));
            $err = $raw === '' ? 'das SMTP-Modul nennt keinen Grund (Debug der SMTP-Instanz zeigt den Dialog mit dem Server)' : AUTOMATLogik::mailError($raw);
        }
        $this->LogMessage('E-Mail-Versand fehlgeschlagen: ' . $err, KL_WARNING);
        return $err;
    }

    /** Push-Ziele: Kachel-Visualisierung UND WebFront (SUITE.md Stolperstein 22); 0 = alle, sonst nur diese Instanz. */
    private function pushTargets(int $only): array
    {
        $out = [];
        foreach ([[self::VISU_GUID, 'kachel'], [self::WEBFRONT_GUID, 'webfront']] as [$guid, $typ]) {
            foreach (@IPS_GetInstanceListByModuleID($guid) ?: [] as $id) {
                if ($only <= 0 || (int)$id === $only) {
                    $out[] = ['id' => (int)$id, 'name' => IPS_GetName($id), 'typ' => $typ];
                }
            }
        }
        return $out;
    }

    private function truncateBytes(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        $r = '';
        foreach (mb_str_split($s) as $ch) {
            if (strlen($r . $ch) > $max) {
                break;
            }
            $r .= $ch;
        }
        return $r;
    }

    private function addLog(string $name, string $text): void
    {
        $log = json_decode($this->aStr('Protocol'), true);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [$this->now(), $name, $text]);
        $this->WriteAttributeString('Protocol', json_encode(array_slice($log, 0, self::PROTOCOL_MAX)));
        $this->SendDebug('Protokoll', ($name !== '' ? $name . ': ' : '') . $text, 0);
    }

    private function protocolLines(int $n): array
    {
        $log = json_decode($this->aStr('Protocol'), true);
        $out = [];
        foreach (array_slice(is_array($log) ? $log : [], 0, $n) as $e) {
            $out[] = date('d.m.Y H:i:s', (int)$e[0]) . ' · ' . ($e[1] !== '' ? $e[1] . ': ' : '') . $e[2];
        }
        return $out;
    }

    /** AUTOMAT_TestMessage($id): string — Testmeldung über alle Wege. */
    public function TestMessage(): string
    {
        // Über ALLE Meldewege mit den gespeicherten Einstellungen; die Ruhezeit wird beim Test übergangen.
        $this->SetValue('LastMessage', date('d.m.Y H:i', $this->now()) . ' — 🧪 Automat: Testmeldung');
        $ways = $this->ways();
        if (count($ways) === 0) {
            return 'ℹ️ Kein Meldeweg angelegt — unter „🔔 Meldungen“ einen hinzufügen und „Übernehmen“.';
        }
        $parts = [];
        foreach ($ways as $w) {
            $parts[] = '„' . $w['name'] . '“: ' . $this->deliverWay($w, '🧪 Automat', 'Testmeldung über „' . $w['name'] . '“ — so sehen Meldungen deiner Automatismen aus.');
        }
        return "🧪 Testmeldung über alle Meldewege (gespeicherte Einstellungen, Ruhezeit übergangen):\n" . implode("\n", $parts);
    }

    // =====================================================================
    //  Entdecken
    // =====================================================================

    /** AUTOMAT_Discover($id): string — Objektbaum durchsuchen und Vorschläge bilden. */
    public function Discover(): string
    {
        $this->confCache = null;
        $items = $this->collectItems();
        $sugg = AUTOMATErkennung::suggest($items, $this->rows(), $this->dismissed(), $this->roomLabels, $this->habits($items));
        foreach ($sugg as &$s) {
            $s['conflicts'] = self::conflictLines($this->conflicts($s['row'], ''));
        }
        unset($s);
        $this->WriteAttributeString('Suggestions', json_encode($sugg));
        $counts = AUTOMATErkennung::roleCounts($items);
        $this->WriteAttributeString('RoleCounts', json_encode($counts));
        foreach (AUTOMATKatalog::TYPES as $t => $_) {
            // Katalog-Zeilen „passende Geräte gefunden / dafür fehlt“ im offenen Formular nachziehen
            $line = $this->catalogLine($t, $counts);
            $this->UpdateFormField('cat_' . $t, 'caption', $line);
            $this->UpdateFormField('cat_' . $t, 'visible', $line !== '');
        }
        $this->WriteAttributeInteger('LastDiscoveryTs', $this->now());

        $this->refreshSuggestionsForm($this->pInt('cfg_SuggestOrder'));
        $src = $this->aStr('RoomSource');
        $this->UpdateFormField('RoomSource', 'caption', $src);
        $this->UpdateFormField('RoomSource', 'color', strpos($src, '🔗') !== false ? 0x2E8B3D : -1);
        $counts = AUTOMATErkennung::roleCounts($items);
        $parts = [];
        foreach ($counts as $role => $n) {
            $parts[] = $n . '× ' . AUTOMATKatalog::ROLES[$role];
        }
        return (count($sugg) > 0 ? '✅ ' . count($sugg) . ' Vorschläge gefunden.' : 'ℹ️ Keine neuen Vorschläge.')
            . "\nGefunden: " . implode(', ', $parts) . '.'
            . (count($sugg) > 0 ? "\nGewünschte Vorschläge in der Liste anhaken und „Angehakte in die Liste übernehmen“ klicken — angelegt wird erst mit „Übernehmen“ unten." : '');
    }

    /** Steckbriefe aller Variablen, die eine Rolle spielen könnten. */
    private function collectItems(): array
    {
        $this->roomLabels = [];
        $roomByInstance = $this->raumRooms();
        $items = [];
        foreach (IPS_GetVariableList() as $vid) {
            $obj = IPS_GetObject($vid);
            $parent = (int)$obj['ParentID'];
            if ($parent === $this->InstanceID || !empty($obj['ObjectIsHidden'])) {
                continue;
            }
            $v = IPS_GetVariable($vid);
            $pres = $v['VariableCustomPresentation'] ?? [];
            if (!is_array($pres) || count($pres) === 0) {
                $pres = $v['VariablePresentation'] ?? [];
            }
            if (!is_array($pres)) {
                $pres = [];
            }
            $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '') ?: (string)($pres['PROFILE'] ?? '');
            $suffix = (string)($pres['SUFFIX'] ?? '');
            if ($suffix === '' && $profile !== '' && IPS_VariableProfileExists($profile)) {
                $suffix = (string)(IPS_GetVariableProfile($profile)['Suffix'] ?? '');
            }
            $instID = IPS_InstanceExists($parent) ? $parent : 0;
            $item = [
                'vid' => $vid, 'type' => (int)$v['VariableType'],
                'action' => ((int)($v['VariableCustomAction'] ?? 0) > 1) || ((int)($v['VariableAction'] ?? 0) > 1),
                'profile' => $profile, 'pres' => $pres, 'suffix' => trim($suffix), 'assoc' => $this->profileAssoc($profile),
                'name' => (string)$obj['ObjectName'], 'inst' => $parent > 0 ? IPS_GetName($parent) : '', 'ident' => (string)$obj['ObjectIdent'],
                'room' => $roomByInstance[$instID] ?? ($roomByInstance[$vid] ?? null),
                'module' => $instID > 0 ? (string)(@IPS_GetInstance($instID)['ModuleInfo']['ModuleName'] ?? '') : '',
            ];
            if ($item['room'] === null && count($this->roomLabels) > 0) {
                // Gerät hängt nicht in einem Raum, nennt ihn aber im Namen („Thermostat Kind II“).
                $item['room'] = AUTOMATErkennung::roomFromName($item['inst'] . ' ' . $item['name'], $this->roomLabels);
            }
            $item['role'] = AUTOMATErkennung::role($item);
            if ($item['role'] !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** Raumnamen aus Raumstruktur (Name => true), leer ohne das Modul. */
    private function raumRoomNames(): array
    {
        $o = [];
        foreach ($this->raumRooms() as $label) {
            if (is_string($label) && trim($label) !== '') {
                $o[trim($label)] = true;
            }
        }
        return $o;
    }

    /** Raumzuordnung aus Raumstruktur (optional): [InstanzID => Raumname]. */
    private function raumRooms(): array
    {
        $map = [];
        $ids = @IPS_GetInstanceListByModuleID(self::RAUM_GUID) ?: [];
        // Präfix-Umbenennung 13.10.2026 (TOOLKIT.md): RAUM_ zuerst, STRUKT_ (Raumstruktur) als Rückfall.
        $data = null;
        $available = function_exists('RAUM_GetStructure') || function_exists('STRUKT_GetStructure');
        if (count($ids) === 0 || !$available) {
            $this->WriteAttributeString('RoomSource', 'ℹ️ Raumstruktur nicht installiert — Räume werden aus den Gerätenamen abgeleitet (z. B. „Licht Flur“ + „Bewegungsmelder Flur“ = Flur).');
            return $map;
        }
        try {
            if (function_exists('RAUM_GetStructure')) {
                $data = json_decode((string)RAUM_GetStructure((int)$ids[0]), true);
            } elseif (function_exists('STRUKT_GetStructure')) {
                $data = json_decode((string)STRUKT_GetStructure((int)$ids[0]), true);
            }
        } catch (Throwable $e) {
            $data = null;
        }
        if (!is_array($data) || !isset($data['rooms']) || version_compare((string)($data['contractVersion'] ?? '1.0'), '2.0', '>=')) {
            $this->WriteAttributeString('RoomSource', '⚠️ Raumstruktur #' . $ids[0] . ' gefunden, liefert aber keine verwertbaren Räume — Räume werden aus den Gerätenamen abgeleitet.');
            return $map;
        }
        $n = 0;
        $this->roomLabels = array_values(array_filter(array_map(function ($r) {
            return (string)($r['label'] ?? '');
        }, $data['rooms'])));
        foreach ($data['rooms'] as $room) {
            foreach ((array)($room['deviceInstanceIDs'] ?? []) as $iid) {
                $map[(int)$iid] = (string)($room['label'] ?? $room['key'] ?? '');
                $n++;
            }
        }
        $this->WriteAttributeString('RoomSource', '✅ 🔗 Räume von Raumstruktur #' . $ids[0] . ' übernommen: ' . count($data['rooms']) . ' Räume, ' . $n . ' Geräte zugeordnet (Vertrag ' . ($data['contractVersion'] ?? '1.0') . '). Geräte ohne Raum werden nach Namen gruppiert.');
        return $map;
    }

    private function dismissed(): array
    {
        $d = json_decode($this->aStr('Dismissed'), true);
        return is_array($d) ? $d : [];
    }

    private function suggestions(): array
    {
        $s = json_decode($this->aStr('Suggestions'), true);
        if (!is_array($s)) {
            return [];
        }
        // Vorschläge, die inzwischen angelegt wurden, nicht mehr anbieten.
        $used = [];
        foreach ($this->rows() as $row) {
            foreach (AUTOMATKatalog::variables($row) as $list) {
                foreach ($list as $vid) {
                    $used[$row['Type']][$vid] = true;
                }
            }
        }
        return array_values(array_filter($s, function ($x) use ($used) {
            foreach (AUTOMATKatalog::variables($x['row']) as $list) {
                foreach ($list as $vid) {
                    if (isset($used[$x['type']][$vid])) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }

    /** Vorschläge geordnet: 0 = Raum → Art → Titel, 1 = Art → Raum → Titel. */
    private function sortedSuggestions(int $order): array
    {
        $list = $this->suggestions();
        usort($list, function ($a, $b) use ($order) {
            $ka = $this->suggestionSortKey($a, $order);
            $kb = $this->suggestionSortKey($b, $order);
            return strcmp($ka, $kb);
        });
        return $list;
    }

    private function suggestionSortKey(array $s, int $order): string
    {
        $room = mb_strtolower(trim((string)$s['room']) ?: '~');
        $type = (string)$s['type'];
        $title = mb_strtolower((string)$s['title']);
        return $order === 1 ? $type . '|' . $room . '|' . $title : $room . '|' . $type . '|' . $title;
    }

    private function suggestionWarnings(array $s): int
    {
        return count(array_filter($s['conflicts'], function ($c) {
            return strpos((string)$c, '⚠️') === 0;
        }));
    }

    /** Zeilen der Vorschlagsliste (nur Anzeige, nichts davon wird gespeichert). */
    private function suggestionRows(): array
    {
        $rows = [];
        foreach ($this->suggestions() as $s) {
            $rows[] = [
                'Pick' => false,
                'Key' => $s['key'],
                'Room' => trim((string)$s['room']) ?: 'ohne Raum',
                'TypeLabel' => AUTOMATKatalog::label((string)$s['type']),
                'Title' => $s['title'],
                'Why' => $s['why'],
                'Hints' => count($s['conflicts']) > 0 ? implode(' | ', $s['conflicts']) : '—',
                'SortRoom' => $this->suggestionSortKey($s, 0),
                'SortType' => $this->suggestionSortKey($s, 1),
                'rowColor' => $this->suggestionWarnings($s) > 0 ? '#FFFFC0' : '',
            ];
        }
        return $rows;
    }

    private function suggestionSort(int $order): array
    {
        return ['column' => $order === 1 ? 'TypeLabel' : 'Room', 'direction' => 'ascending'];
    }

    /** Kopfzeile, Liste und Auswahl der Vorschläge im offenen Formular gemeinsam auffrischen (SUITE.md: nie einzeln). */
    private function refreshSuggestionsForm(int $order): void
    {
        $this->UpdateFormField('DiscoverHead', 'caption', $this->discoverHeadLine());
        $this->UpdateFormField('SuggestList', 'values', json_encode($this->suggestionRows()));
        $this->UpdateFormField('SuggestList', 'rowCount', max(5, min(15, count($this->suggestions()))));
        // neue Suche: Filter zurücksetzen und ihre Auswahl an die gefundenen Räume/Arten anpassen
        [$ro, $to] = $this->suggestFilterOptions();
        $this->UpdateFormField('sg_text', 'value', '');
        $this->UpdateFormField('sg_room', 'options', json_encode($ro));
        $this->UpdateFormField('sg_room', 'value', '');
        $this->UpdateFormField('sg_type', 'options', json_encode($to));
        $this->UpdateFormField('sg_type', 'value', '');
    }

    /**
     * AUTOMAT_UIFilterList($id, $rowsJson, $text, $room, $cat, $state) — Liste im offenen Formular filtern.
     * Passende Zeilen stehen in der sichtbaren Liste, die übrigen in der unsichtbaren „AutomationsHidden“; beide
     * werden mit „Übernehmen“ gespeichert, beim Lesen zusammengeführt — Filtern löscht also nie etwas.
     */
    public function UIFilterList(string $rowsJson, string $text, string $room, string $cat, string $state): void
    {
        $rows = [];
        $seen = [];
        foreach (json_decode($rowsJson, true) ?: [] as $r) {
            $r = self::unwrapHidden($r);
            if (!is_array($r) || !array_key_exists('Type', $r)) {
                continue;
            }
            $uid = (string)($r['Uid'] ?? '');
            if ($uid !== '' && isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            foreach (['State', 'Settings', 'SortRoom', 'SortType', 'rowColor', 'Mark'] as $k) {
                unset($r[$k]); // Anzeigespalten nicht in die unsichtbare Liste tragen
            }
            $rows[] = $r;
        }
        if (count($rows) === 0) {
            return; // Liste nicht lesbar — lieber nichts tun als Zeilen verschieben
        }
        $show = [];
        $hide = [];
        foreach ($rows as $r) {
            if ($this->rowMatches($r, $text, $room, $cat, $state)) {
                $show[] = $r;
            } else {
                $hide[] = $r;
            }
        }
        $active = trim($text) !== '' || $room !== '' || $cat !== '' || $state !== '';
        // Kennung dieses Filterstands an sichtbaren und ausgeblendeten Zeilen: wird später ohne Filter gespeichert,
        // passen alte ausgeblendete Zeilen nicht mehr dazu und kommen nicht zurück (z. B. nach dem Löschen)
        $gen = $active ? substr(md5(uniqid('', true)), 0, 8) : '';
        $this->UpdateFormField('Automations', 'values', json_encode($this->listValues($show, $gen)));
        $this->UpdateFormField('AutomationsHidden', 'values', json_encode(array_map(function ($r) use ($gen) {
            return ['Uid' => (string)($r['Uid'] ?? ''), 'Type' => (string)($r['Type'] ?? ''), 'Data' => json_encode($r), 'Gen' => $gen];
        }, array_values($hide))));
        $this->UpdateFormField('FilterInfo', 'caption', '🔎 Zeigt ' . count($show) . ' von ' . count($rows) . ' Automatismen — die übrigen sind nur ausgeblendet und bleiben beim „Übernehmen“ erhalten.');
        $this->UpdateFormField('FilterInfo', 'visible', $active);
    }

    /**
     * Zeile aus der unsichtbaren Liste: vollständig aus „Data“; Altbestand (0.1.39/0.1.40) ohne „Data“ bleibt als
     * leere Zeile mit Kennung und Art stehen, damit man sieht, welcher Automatismus neu eingestellt werden muss.
     */
    private static function unwrapHidden($h)
    {
        if (!is_array($h)) {
            return null;
        }
        if (isset($h['Data'])) {
            $r = json_decode((string)$h['Data'], true);
            return is_array($r) ? $r : null;
        }
        return $h;
    }

    /** Passt eine Zeile zum Filter? (Suchtext über Name, Raum, Art, Zustand; Raum gleich inkl. Kurz-/Langform.) */
    private function rowMatches(array $r, string $text, string $room, string $cat, string $state): bool
    {
        $type = (string)($r['Type'] ?? '');
        $status = $this->attrMap('Status')[(string)($r['Uid'] ?? '')] ?? '';
        if ($room !== '' && !($room === '-' ? trim((string)($r['Room'] ?? '')) === '' : AUTOMATErkennung::sameRoom((string)($r['Room'] ?? ''), $room))) {
            return false;
        }
        if ($cat !== '') {
            $c = AUTOMATKatalog::TYPES[$type]['cat'] ?? '';
            if (strpos($cat, 'cat:') === 0 ? $c !== substr($cat, 4) : $type !== $cat) {
                return false;
            }
        }
        if ($state !== '') {
            $tileState = $this->tileState($r, (string)$status, (bool)$this->getOwn('l_' . ($r['Uid'] ?? ''), false), isset($this->attrMap('End')[(string)($r['Uid'] ?? '')]));
            $ok = ['warn' => $tileState === 'warn', 'run' => $tileState === 'run', 'on' => !empty($r['Enabled']),
                'off' => empty($r['Enabled']), 'locked' => $tileState === 'locked', 'dry' => !empty($r['Dry'])][$state] ?? true;
            if (!$ok) {
                return false;
            }
        }
        $q = mb_strtolower(trim($text));
        if ($q !== '') {
            $hay = mb_strtolower(($r['Name'] ?? '') . ' ' . ($r['Room'] ?? '') . ' ' . (AUTOMATKatalog::has($type) ? AUTOMATKatalog::label($type) : '') . ' ' . $status);
            if (mb_strpos($hay, $q) === false) {
                return false;
            }
        }
        return true;
    }

    private function filterRoomOptions(array $rows): array
    {
        $rooms = [];
        foreach ($rows as $r) {
            $x = trim((string)($r['Room'] ?? ''));
            $rooms[$x === '' ? '-' : $x] = true;
        }
        ksort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
        $o = [['caption' => 'alle Räume', 'value' => '']];
        foreach (array_keys($rooms) as $x) {
            $o[] = ['caption' => $x === '-' ? 'ohne Raum' : (string)$x, 'value' => (string)$x];
        }
        return $o;
    }

    private function filterCatOptions(): array
    {
        $o = [['caption' => 'alle', 'value' => '']];
        foreach (AUTOMATKatalog::CATEGORIES as $k => $label) {
            $o[] = ['caption' => $label, 'value' => 'cat:' . $k];
            foreach (AUTOMATKatalog::TYPES as $t => $def) {
                if ($def['cat'] === $k) {
                    $o[] = ['caption' => '      ' . $def['icon'] . ' ' . $def['label'], 'value' => $t];
                }
            }
        }
        return $o;
    }

    /** AUTOMAT_UIFilterSuggestions($id, $text, $room, $type) — Vorschlagsliste im offenen Formular filtern. */
    public function UIFilterSuggestions(string $text, string $room, string $type): void
    {
        $all = $this->suggestionRows();
        $q = mb_strtolower(trim($text));
        $show = array_values(array_filter($all, function ($r) use ($q, $room, $type) {
            $s = $this->suggestions()[array_search($r['Key'], array_column($this->suggestions(), 'key'), true)] ?? [];
            if ($room !== '' && !($room === '-' ? trim((string)($s['room'] ?? '')) === '' : AUTOMATErkennung::sameRoom((string)($s['room'] ?? ''), $room))) {
                return false;
            }
            if ($type !== '' && ($s['type'] ?? '') !== $type) {
                return false;
            }
            return $q === '' || mb_strpos(mb_strtolower($r['Room'] . ' ' . $r['TypeLabel'] . ' ' . $r['Title'] . ' ' . $r['Why']), $q) !== false;
        }));
        $this->UpdateFormField('SuggestList', 'values', json_encode($show));
        $this->UpdateFormField('SuggestList', 'rowCount', max(5, min(15, count($show))));
        $this->UpdateFormField('DiscoverHead', 'caption', $this->discoverHeadLine() . (count($show) < count($all) ? ' Gefiltert: ' . count($show) . ' angezeigt.' : ''));
    }

    private function suggestFilterOptions(): array
    {
        $rooms = [];
        $types = [];
        foreach ($this->suggestions() as $s) {
            $x = trim((string)$s['room']);
            $rooms[$x === '' ? '-' : $x] = true;
            $types[(string)$s['type']] = true;
        }
        ksort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
        $ro = [['caption' => 'alle Räume', 'value' => '']];
        foreach (array_keys($rooms) as $x) {
            $ro[] = ['caption' => $x === '-' ? 'ohne Raum' : (string)$x, 'value' => (string)$x];
        }
        $to = [['caption' => 'alle Arten', 'value' => '']];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            if (isset($types[$t])) {
                $to[] = ['caption' => $def['icon'] . ' ' . $def['label'], 'value' => $t];
            }
        }
        return [$ro, $to];
    }

    /** AUTOMAT_UISetSuggestOrder($id, $order) — Vorschläge nach Raum (0) oder Funktion (1) ordnen. */
    public function UISetSuggestOrder(int $order): void
    {
        $this->UpdateFormField('SuggestList', 'sort', json_encode($this->suggestionSort($order)));
    }

    private function discoverHeadLine(): string
    {
        $ts = $this->aInt('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — „Jetzt Vorschläge suchen“ durchsucht den Objektbaum.';
        }
        $n = count($this->suggestions());
        return ($n > 0 ? '✅ ' : '⚠️ ') . $n . ' Vorschläge gefunden (zuletzt ' . date('H:i:s', $ts) . ' Uhr).';
    }

    /**
     * AUTOMAT_UIAdoptMany($id, $keysJson, $rowsJson): string — angehakte Vorschläge als neue Zeilen
     * in die OFFENE Liste einfügen (gespeichert wird erst mit „Übernehmen“).
     */
    public function UIAdoptMany(string $keysJson, string $rowsJson): string
    {
        $keys = json_decode($keysJson, true);
        if (!is_array($keys) || count($keys) === 0) {
            return 'ℹ️ Bitte zuerst in der Vorschlagsliste die gewünschten Vorschläge anhaken (Spalte „✔“).';
        }
        $new = [];
        foreach ($this->suggestions() as $s) {
            if (in_array($s['key'], $keys, true)) {
                $new[] = $s['row'];
            }
        }
        if (count($new) === 0) {
            return 'ℹ️ Die angehakten Vorschläge gibt es nicht mehr — bitte „Jetzt Vorschläge suchen“.';
        }
        $names = array_map(function ($r) {
            return '„' . $r['Name'] . '“';
        }, $new);
        return $this->insertRows($new, $rowsJson, count($new) === 1 ? $names[0] : count($new) . ' Automatismen (' . implode(', ', $names) . ')');
    }

    /** AUTOMAT_UIAddType($id, $type, $rowsJson): string — leeren Automatismus eines Typs einfügen. */
    public function UIAddType(string $type, string $rowsJson): string
    {
        if (!AUTOMATKatalog::has($type)) {
            return 'ℹ️ Bitte zuerst einen Typ auswählen.';
        }
        $row = AUTOMATKatalog::defaults($type);
        $row['Name'] = AUTOMATKatalog::TYPES[$type]['label'];
        $row['Room'] = '';
        return $this->insertRows([$row], $rowsJson, 'Ein neuer Automatismus „' . $row['Name'] . '“');
    }

    private function insertRows(array $newRows, string $rowsJson, string $what, bool $keepEnabled = false): string
    {
        $rows = json_decode($rowsJson, true);
        $note = '';
        $valid = is_array($rows);
        if ($valid) {
            foreach ($rows as $r) {
                if (!is_array($r) || !array_key_exists('Type', $r)) {
                    $valid = false;
                    break;
                }
            }
        }
        if (!$valid) {
            // Die Liste der offenen Maske war nicht lesbar: auf den gespeicherten Stand aufsetzen.
            $rows = $this->rows();
            $note = "\n⚠️ Noch nicht übernommene Änderungen in der Liste konnten nicht gelesen werden — die Liste zeigt jetzt den gespeicherten Stand plus den neuen Eintrag.";
        }
        foreach ($newRows as $row) {
            $row['Uid'] = $this->newUid($rows);
            if (!$keepEnabled) {
                $row['Enabled'] = true;
            }
            unset($row['Mark']);
            $rows[] = $row;
        }
        $this->UpdateFormField('Automations', 'values', json_encode($this->listValues($rows)));
        $this->refreshSuggestionsForm($this->pInt('cfg_SuggestOrder'));
        return '✅ ' . $what . (count($newRows) === 1 ? ' steht' : ' stehen') . ' jetzt in der Liste — noch NICHT gespeichert.' . "\n"
            . 'Bitte mit ✏️ prüfen (Geräte, Zeiten) und unten „Übernehmen“ klicken.' . $note;
    }

    /**
     * AUTOMAT_UIDuplicate($id, $rowsJson, $room): string — angehakte Automatismen kopieren. Mit Zielraum werden die
     * Geräte gleicher Rolle (Melder, Licht, Kontakt …) aus diesem Raum eingesetzt; was nicht passt, bleibt leer (rot).
     */
    public function UIDuplicate(string $rowsJson, string $room): string
    {
        $rows = json_decode($rowsJson, true);
        $marked = array_values(array_filter(is_array($rows) ? $rows : [], function ($r) {
            return is_array($r) && !empty($r['Mark']);
        }));
        if (count($marked) === 0) {
            return 'ℹ️ Bitte zuerst in der Liste bei den Automatismen, die du kopieren willst, das Häkchen ☑︎ setzen.';
        }
        $items = $room !== '' ? $this->collectItems() : [];
        $roleOf = [];
        $byRoom = [];
        foreach ($items as $it) {
            $roleOf[(int)$it['vid']] = (string)($it['role'] ?? '');
            // Raum aus Raumstruktur oder — ohne das Modul — aus dem Gerätenamen („Licht Bad“)
            $itRoom = (string)($it['room'] ?? '') !== '' ? (string)$it['room'] : (string)AUTOMATErkennung::roomFromName($it['inst'] . ' ' . $it['name'], [$room]);
            if (($it['role'] ?? '') !== '' && AUTOMATErkennung::sameRoom($itRoom, $room)) {
                $byRoom[$it['role']][] = (int)$it['vid'];
            }
        }
        $copies = [];
        $mapped = 0;
        $total = 0;
        foreach ($marked as $r) {
            $c = $r;
            $cTotal = 0; // je Kopie zählen — eine unvollständige Kopie darf nicht aktiv werden
            $cMapped = 0;
            $old = trim((string)($r['Room'] ?? ''));
            if ($room !== '' && $room !== $old) {
                $c['Room'] = $room;
                $c['Name'] = $old !== '' && mb_stripos((string)$r['Name'], $old) !== false ? str_ireplace($old, $room, (string)$r['Name']) : trim((string)$r['Name']) . ' ' . $room;
                $taken = [];
                foreach (AUTOMATKatalog::fields((string)$r['Type']) as $f) {
                    if (!isset($f['n']) || !in_array($f['k'], ['var', 'vars'], true)) {
                        continue;
                    }
                    $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                        return $f['n'] . $i;
                    }, range(1, AUTOMATKatalog::slotsIn($r, $f['n']))) : [$f['n']];
                    foreach ($names as $n) {
                        $vid = (int)($r[$n] ?? 0);
                        if ($vid < AUTOMATKatalog::$minId) {
                            continue;
                        }
                        $total++;
                        $cTotal++;
                        $role = $roleOf[$vid] ?? '';
                        $pick = 0;
                        foreach ($byRoom[$role] ?? [] as $cand) {
                            if (!isset($taken[$cand])) {
                                $pick = $cand;
                                break;
                            }
                        }
                        if ($pick > 0) {
                            $taken[$pick] = true;
                            $mapped++;
                            $cMapped++;
                        }
                        $c[$n] = $pick;
                    }
                }
            } else {
                $c['Name'] = trim((string)$r['Name']) . ' (Kopie)';
            }
            // Eine Kopie mit denselben Geräten würde doppelt regeln — deshalb erst nach dem Prüfen aktiv.
            $c['Enabled'] = $room !== '' && $room !== $old && $cTotal > 0 && $cMapped === $cTotal;
            $copies[] = $c;
        }
        $msg = $this->insertRows($copies, $rowsJson, count($copies) . ' Kopie(n)', true);
        if ($room !== '') {
            $msg .= "\n🔁 Geräte für „" . $room . '“: ' . $mapped . ' von ' . $total . ' passend gefunden' . ($mapped < $total ? ' — die übrigen sind leer (rot), bitte im Dialog wählen; die Kopie ist bis dahin nicht aktiv.' : '.');
        } else {
            $msg .= "\nℹ️ Die Kopie nutzt dieselben Geräte und ist deshalb noch nicht aktiv — im Dialog anpassen und „Aktiv“ setzen.";
        }
        return $msg;
    }

    /** Räume für „Duplizieren in Raum“: bekannte Räume aus Raumstruktur bzw. Gerätenamen und Liste. */
    private function copyRoomOptions(): array
    {
        $rooms = [];
        foreach ($this->rows() as $r) {
            $x = trim((string)($r['Room'] ?? ''));
            if ($x !== '') {
                $rooms[$x] = true;
            }
        }
        foreach (array_keys($this->raumRoomNames()) as $x) {
            $rooms[$x] = true;
        }
        ksort($rooms, SORT_NATURAL | SORT_FLAG_CASE);
        $o = [['caption' => '— gleicher Raum, gleiche Geräte (nur kopieren) —', 'value' => '']];
        foreach (array_keys($rooms) as $x) {
            $o[] = ['caption' => (string)$x, 'value' => (string)$x];
        }
        return $o;
    }

    /** Statistik je Automatismus: Schaltungen und Meldungen je Tag (30 Tage). */
    private function countStats(string $uid, bool $acted, bool $msg): void
    {
        $all = json_decode($this->aStr('Stats'), true) ?: [];
        $now = $this->now();
        $s = $all[$uid] ?? ['first' => $now, 'd' => [], 'last' => 0];
        if (!$acted && !$msg && isset($all[$uid])) {
            return;
        }
        $day = date('Y-m-d', $now);
        $s['d'][$day] = [(int)($s['d'][$day][0] ?? 0) + ($acted ? 1 : 0), (int)($s['d'][$day][1] ?? 0) + ($msg ? 1 : 0)];
        if ($acted || $msg) {
            $s['last'] = $now;
        }
        $cut = date('Y-m-d', strtotime('-30 days', $now));
        $s['d'] = array_filter($s['d'], function ($k) use ($cut) {
            return $k >= $cut;
        }, ARRAY_FILTER_USE_KEY);
        $all[$uid] = $s;
        $this->WriteAttributeString('Stats', json_encode($all));
    }

    /** Statistik-Zeilen fürs Formular und die Liste der untätigen Automatismen. */
    private function statsRows(): array
    {
        $all = json_decode($this->aStr('Stats'), true) ?: [];
        $now = $this->now();
        $d7 = date('Y-m-d', strtotime('-7 days', $now));
        $rows = [];
        $idle = [];
        foreach ($this->rows() as $r) {
            $s = $all[$r['Uid']] ?? null;
            $a7 = $a30 = $m30 = 0;
            foreach ((array)($s['d'] ?? []) as $day => [$a, $m]) {
                $a30 += $a;
                $m30 += $m;
                if ($day >= $d7) {
                    $a7 += $a;
                }
            }
            $last = (int)($s['last'] ?? 0);
            $rows[] = ['Name' => $this->rowName($r), 'A7' => $a7, 'A30' => $a30, 'M30' => $m30, 'Last' => $last > 0 ? date('d.m.Y H:i', $last) : '—'];
            if (!empty($r['Enabled']) && $s !== null && $now - (int)($s['first'] ?? $now) >= 30 * 86400 && $a30 + $m30 === 0) {
                $idle[] = '„' . $this->rowName($r) . '“';
            }
        }
        return [$rows, $idle];
    }

    /**
     * Gewohnheiten aus dem Archiv: Lichter, die werktags meist zur selben Zeit ausgehen (4 Wochen, wochenweise abgefragt).
     * Rückgabe vid => ['min' => Minuten, 'days' => n, 'hits' => n].
     */
    private function habits(array $items): array
    {
        $ac = (int)(@IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0);
        if ($ac <= 0 || !function_exists('AC_GetLoggingStatus') || !function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $now = $this->now();
        $out = [];
        $n = 0;
        foreach ($items as $it) {
            if (($it['role'] ?? '') !== 'light' || $n >= 25) {
                continue;
            }
            $vid = (int)$it['vid'];
            try {
                if (!AC_GetLoggingStatus($ac, $vid)) {
                    continue;
                }
                $n++;
                $ev = [];
                for ($w = 4; $w >= 1; $w--) {
                    $vals = AC_GetLoggedValues($ac, $vid, strtotime('-' . $w . ' weeks', $now), strtotime('-' . ($w - 1) . ' weeks', $now), 0);
                    if ($vals === false) {
                        $this->SendDebug('Gewohnheit', 'Archiv lieferte nichts für #' . $vid, 0);
                        continue;
                    }
                    foreach (array_reverse((array)$vals) as $e) {
                        $ev[] = [(int)$e['TimeStamp'], AUTOMATLogik::isTrue($e['Value'])];
                    }
                }
                $h = AUTOMATLogik::habitOff($ev);
                if ($h !== null) {
                    $out[$vid] = $h;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $out;
    }

    /** AUTOMAT_DismissSuggestions($id, $keysJson): string — angehakte Vorschläge nicht mehr anbieten. */
    public function DismissSuggestions(string $keysJson): string
    {
        $keys = json_decode($keysJson, true);
        if (!is_array($keys) || count($keys) === 0) {
            return 'ℹ️ Bitte zuerst in der Vorschlagsliste die Vorschläge anhaken, die nicht mehr erscheinen sollen.';
        }
        $d = array_values(array_unique(array_merge($this->dismissed(), array_map('strval', $keys))));
        $this->WriteAttributeString('Dismissed', json_encode($d));
        $sugg = array_values(array_filter(json_decode($this->aStr('Suggestions'), true) ?: [], function ($s) use ($keys) {
            return !in_array($s['key'], $keys, true);
        }));
        $this->WriteAttributeString('Suggestions', json_encode($sugg));
        $this->refreshSuggestionsForm($this->pInt('cfg_SuggestOrder'));
        $this->UpdateFormField('RestoreSuggestionsBtn', 'visible', true);
        return '🙈 ' . count($keys) . ' Vorschlag/Vorschläge ausgeblendet — auch bei der nächsten Suche nicht mehr. („Ausgeblendete Vorschläge wieder anzeigen“ holt sie zurück.)';
    }

    /** AUTOMAT_RestoreSuggestions($id): string */
    public function RestoreSuggestions(): string
    {
        $n = count($this->dismissed());
        $this->WriteAttributeString('Dismissed', '[]');
        $this->UpdateFormField('RestoreSuggestionsBtn', 'visible', false);
        return $n > 0 ? '✅ ' . $n . ' ausgeblendete Vorschläge kommen bei der nächsten Suche wieder. ' . $this->Discover() : 'ℹ️ Es war nichts ausgeblendet.';
    }

    // =====================================================================
    //  Doppelregelung
    // =====================================================================

    /**
     * Hinweise, wenn dieselben Variablen schon anderswo gesteuert oder ausgewertet werden.
     * Rückgabe: Liste ['level' => '⚠️'|'ℹ️', 'text' => …, 'id' => Objekt zum Öffnen].
     * ⚠️ = schaltet nachweislich dieselbe Variable; ℹ️ = reagiert nur auf dieselbe Variable bzw. reicht sie weiter.
     */
    private function conflicts(array $row, string $ownUid): array
    {
        $vars = AUTOMATKatalog::variables($row);
        $outs = $vars['out'];
        $ins = $vars['in'];
        $out = [];
        // Andere Automatismen dieser und anderer Automat-Instanzen
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $iid) {
            $list = $iid === $this->InstanceID ? $this->rows() : array_merge(json_decode((string)@IPS_GetProperty($iid, 'Automations'), true) ?: [], json_decode((string)@IPS_GetProperty($iid, 'AutomationsHidden'), true) ?: []);
            foreach ($list as $r) {
                if ($iid === $this->InstanceID && ($r['Uid'] ?? '') === $ownUid) {
                    continue;
                }
                foreach (array_intersect($outs, AUTOMATKatalog::variables($r)['out']) as $vid) {
                    if (in_array($row['Type'], ['szene', 'alarm'], true) || in_array($r['Type'] ?? '', ['szene', 'alarm'], true)) {
                        $out[] = ['level' => 'ℹ️', 'id' => $iid, 'text' => 'Automatismus „' . $this->rowName($r) . '“ schaltet „' . $this->varLabel($vid) . '“ ebenfalls — gewollt: Szene bzw. Alarm schalten nur bei ihrem Anlass, der andere arbeitet danach normal weiter (ohne Handpause).'];
                        continue;
                    }
                    $out[] = ['level' => '⚠️', 'id' => $iid, 'text' => 'Automatismus „' . $this->rowName($r) . '“' . ($iid !== $this->InstanceID ? ' (Automat #' . $iid . ')' : ' (diese Instanz)') . ' schaltet „' . $this->varLabel($vid) . '“ ebenfalls.'];
                }
            }
        }
        // Ausgelöste Ereignisse auf denselben Variablen
        foreach (IPS_GetEventList() as $eid) {
            $e = @IPS_GetEvent($eid);
            if (!is_array($e) || (int)($e['EventType'] ?? -1) !== 0 || empty($e['EventActive'])) {
                continue;
            }
            $tv = (int)($e['TriggerVariableID'] ?? 0);
            if ($tv <= 0 || (!in_array($tv, $ins, true) && !in_array($tv, $outs, true))) {
                continue;
            }
            [$does, $target] = $this->eventAction($eid, $e);
            $same = $target > 0 && in_array($target, $outs, true);
            $out[] = ['level' => $same ? '⚠️' : 'ℹ️', 'id' => $eid, 'text' => $this->eventLabel($eid) . ': ' . $this->triggerText($e, $tv) . ' → ' . $does
                . ($same ? ' — schaltet dasselbe wie dieser Automatismus.' : ' — prüfen, ob es dasselbe tut.')];
        }
        // Andere Instanzen, deren Konfiguration eine der geschalteten Variablen nennt
        if ($this->confCache === null) {
            $this->confCache = [];
            $own = IPS_GetInstanceListByModuleID(self::MODULE_GUID);
            foreach (IPS_GetInstanceList() as $iid) {
                if (in_array($iid, $own, true)) {
                    continue;
                }
                $conf = (string)@IPS_GetConfiguration($iid);
                if ($conf !== '' && $conf !== '{}') {
                    $this->confCache[$iid] = $conf;
                }
            }
        }
        foreach ($outs as $vid) {
            $rx = '/[:\[,]\s*"?' . $vid . '"?\s*[,\]}]/';
            foreach ($this->confCache as $iid => $conf) {
                if (!preg_match($rx, $conf)) {
                    continue;
                }
                $module = $this->moduleName($iid);
                $where = 'Instanz „' . IPS_GetName($iid) . '“ (#' . $iid . ', Modul ' . $module . ', ' . $this->location($iid) . ')';
                if (preg_match('/anwesenheit|schein|presence ?sim/i', $module . ' ' . IPS_GetName($iid))) {
                    $out[] = ['level' => 'ℹ️', 'id' => $iid, 'text' => $where . ' schaltet „' . $this->varLabel($vid) . '“ nur, solange die Anwesenheitssimulation läuft (niemand zu Hause). Tipp: diesen Automatismus im Hausmodus „Abwesend“ abwählen (Dialog → „Wann ist der Automatismus aktiv?“).'];
                } elseif ($this->isPassThrough($iid, $module)) {
                    $out[] = ['level' => 'ℹ️', 'id' => $iid, 'text' => $where . ' gibt „' . $this->varLabel($vid) . '“ nur an ein anderes System weiter oder zeigt es an — kein zweiter Regler.'];
                } else {
                    $out[] = ['level' => '⚠️', 'id' => $iid, 'text' => $where . ' verwendet „' . $this->varLabel($vid) . '“ ebenfalls — regelt sie das schon, entsteht ein zweiter Regler.'];
                }
            }
        }
        $uniq = [];
        foreach ($out as $c) {
            $uniq[$c['text']] = $c;
        }
        return array_values($uniq);
    }

    /** Konflikte als Textzeilen (für Vorschlagsliste und Speicher). */
    private static function conflictLines(array $conflicts): array
    {
        return array_map(function ($c) {
            return is_array($c) ? $c['level'] . ' ' . $c['text'] : (string)$c;
        }, $conflicts);
    }

    /** Lesbarer Name eines Ereignisses: Symcons automatische Namen („Bei bestimmtem Wert for Variable …") durch Ort ersetzen. */
    private function eventLabel(int $eid): string
    {
        $parent = IPS_GetParent($eid);
        $name = IPS_GetName($eid);
        $auto = preg_match('/^(Bei |On |Trigger)/i', $name) || stripos($name, 'for Variable') !== false;
        $kind = $parent > 0 ? ([0 => 'Kategorie', 1 => 'Instanz', 2 => 'Variable', 3 => 'Skript'][(int)(@IPS_GetObject($parent)['ObjectType'] ?? -1)] ?? 'Objekt') : '';
        return 'Ereignis #' . $eid . ($auto ? '' : ' „' . $name . '“') . ($parent > 0 ? ' unter ' . $kind . ' „' . IPS_GetName($parent) . '“ (' . $this->location($parent) . ')' : '');
    }

    private function triggerText(array $e, int $tv): string
    {
        $val = $e['TriggerValue'] ?? null;
        $v = is_bool($val) ? ($val ? '„an“' : '„aus“') : '„' . AUTOMATLogik::fmtNum($val) . '“';
        switch ((int)($e['TriggerType'] ?? -1)) {
            case 0:
                $t = 'bei jeder Aktualisierung';
                break;
            case 1:
                $t = 'bei jeder Änderung';
                break;
            case 2:
                $t = 'beim Überschreiten von ' . $v;
                break;
            case 3:
                $t = 'beim Unterschreiten von ' . $v;
                break;
            case 4:
                $t = 'bei Wert ' . $v;
                break;
            default:
                $t = 'bei Auslösung';
        }
        $cond = is_array($e['EventConditions'] ?? null) && count($e['EventConditions']) > 0;
        return $t . ' von „' . $this->varLabel($tv) . '“' . ($cond ? ' (mit Bedingung)' : '');
    }

    /** Was ein Ereignis tut, als Text, und welche Variable es ggf. schaltet: [Text, VariablenID|0]. */
    private function eventAction(int $eid, array $e): array
    {
        $params = $e['EventActionParameters'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }
        $target = (int)($params['TARGET'] ?? 0);
        if ($target > 0 && IPS_VariableExists($target)) {
            return ['schaltet „' . $this->varLabel($target) . '“' . (isset($params['VALUE']) ? ' auf ' . AUTOMATLogik::fmtNum($params['VALUE']) : ''), $target];
        }
        if (trim((string)($e['EventScript'] ?? '')) !== '') {
            return ['führt eigenen Skriptcode aus', 0];
        }
        $parent = IPS_GetParent($eid);
        if ($parent > 0 && (int)(@IPS_GetObject($parent)['ObjectType'] ?? -1) === 3) {
            return ['startet das Skript', 0];
        }
        if ($target > 0) {
            return ['schaltet Objekt #' . $target, 0];
        }
        return ['führt eine Aktion aus', 0];
    }

    private function moduleName(int $iid): string
    {
        $i = @IPS_GetInstance($iid);
        return is_array($i) ? (string)($i['ModuleInfo']['ModuleName'] ?? '?') : '?';
    }

    private function location(int $id): string
    {
        $loc = function_exists('IPS_GetLocation') ? (string)@IPS_GetLocation($id) : '';
        if ($loc === '') {
            return 'Objekt #' . $id;
        }
        // Lange Pfade vorne kürzen, das Ende ist das Aussagekräftige.
        $parts = explode('\\', $loc);
        return count($parts) > 3 ? '…\\' . implode('\\', array_slice($parts, -3)) : $loc;
    }

    /**
     * Instanzen, die Variablen nur weiterreichen oder anzeigen (Sprachassistenten, HomeKit, Visualisierungen,
     * Archiv, Export): kein zweiter Regler. Erkannt am Modultyp (kein Gerät) bzw. am Modulnamen.
     */
    private function isPassThrough(int $iid, string $module): bool
    {
        if (preg_match('/homekit|alexa|google|assistant|sprach|bridge|matter|visu|webfront|dashboard|kachel|archive|archiv|export|mqtt ?server|modbus ?server|influx|grafana|logging/i', $module)) {
            return true;
        }
        $i = @IPS_GetInstance($iid);
        $type = is_array($i) ? (int)($i['ModuleInfo']['ModuleType'] ?? 3) : 3;
        return in_array($type, [4, 5, 6], true); // Konfigurator, Discovery, Visualisierung
    }

    // =====================================================================
    //  Bearbeiten-Dialog (List → form)
    // =====================================================================

    /**
     * AUTOMAT_UIEditForm($id, $uid, $type): string — Elemente des Bearbeiten-Dialogs
     * als JSON. Wird bei jedem Öffnen neu gebaut (Symcon-Doku „List → form“ als
     * PHP-Code), daher je Zeile passend. Alle Typen sind enthalten, sichtbar ist
     * nur der gewählte — so kann der Typ beim Anlegen im Dialog gewählt werden.
     */
    public function UIEditForm(string $uid, string $type): string
    {
        return $this->editForm($uid, $type, null);
    }

    /**
     * AUTOMAT_UIEditFormRow($id, $rowJson): string — wie UIEditForm, aber mit der Zeile, wie sie gerade in der
     * Liste steht. So zeigt der Dialog auch bei noch nicht übernommenen Zeilen (Entdecken, Duplizieren) alle
     * belegten Plätze (Prüfbericht 17.10.2026).
     */
    public function UIEditFormRow(string $rowJson): string
    {
        $row = json_decode($rowJson, true);
        $row = is_array($row) ? $row : [];
        return $this->editForm((string)($row['Uid'] ?? ''), (string)($row['Type'] ?? ''), $row);
    }

    private function editForm(string $uid, string $type, ?array $current): string
    {
        $this->confCache = null;
        $isNew = $uid === '';
        $typeOpts = [['caption' => '— bitte wählen —', 'value' => '']];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            $typeOpts[] = ['caption' => $def['icon'] . ' ' . $def['label'], 'value' => $t];
        }
        $el = [
            ['type' => 'Select', 'name' => 'Type', 'caption' => 'Art des Automatismus', 'options' => $typeOpts, 'value' => $type, 'width' => '420px', 'onChange' => 'AUTOMAT_UIDialogType($id, $Type);', 'enabled' => $isNew || !AUTOMATKatalog::has($type)],
            ['type' => 'Label', 'name' => 'TypeDesc', 'caption' => AUTOMATKatalog::has($type) ? AUTOMATKatalog::TYPES[$type]['desc'] : 'Zuerst die Art wählen — danach erscheinen die passenden Einstellungen.'],
            ['type' => 'ValidationTextBox', 'name' => 'Name', 'caption' => 'Bezeichnung (z. B. „Flurlicht“)', 'width' => '420px'],
            ['type' => 'ValidationTextBox', 'name' => 'Room', 'caption' => 'Raum (optional, für Übersicht und Kachel)', 'width' => '420px'],
            ['type' => 'CheckBox', 'name' => 'Enabled', 'caption' => 'Aktiv', 'value' => true],
            ['type' => 'CheckBox', 'name' => 'Dry', 'caption' => '🧪 Probelauf: nichts schalten und nichts melden, nur im Protokoll festhalten, was passiert wäre', 'value' => false],
            ['type' => 'ValidationTextBox', 'name' => 'Uid', 'caption' => 'Kennung', 'visible' => false, 'value' => $isNew ? $this->newUid($this->rows()) : $uid],
            ['type' => 'ValidationTextBox', 'name' => 'TypeLabel', 'caption' => 'Typ', 'visible' => false, 'value' => AUTOMATKatalog::has($type) ? AUTOMATKatalog::label($type) : ''],
        ];
        $saved = $isNew ? null : $this->rowByUid($uid);
        $this->editRow = $current !== null && count($current) > 0 ? $current + ($saved ?? []) : ($saved ?? []);
        $fieldProblems = count($this->editRow) > 0 && ($this->editRow['Type'] ?? '') === $type ? AUTOMATKatalog::fieldProblems($this->editRow, function (int $vid) {
            return IPS_VariableExists($vid);
        }) : [];
        if (count($fieldProblems) > 0) {
            array_splice($el, 2, 0, [['type' => 'Label', 'caption' => '⛔ ' . count($fieldProblems) . ' Feld(er) unten brauchen Aufmerksamkeit (rot markiert).', 'color' => 0xFF0000, 'bold' => true]]);
        }
        $infoHints = $saved !== null && $saved['Type'] === $type ? $this->melderHints($saved) : [];
        // Nur die Felder der eigenen Art in den Dialog — der Dialog speichert jedes Feld in die Zeile, und alle
        // 20 Arten zusammen haben die Konsole beim Speichern lahmgelegt. Neue Zeilen: erst die Art wählen.
        $own = !$isNew && AUTOMATKatalog::has($type);
        if (!$own) {
            $el[] = ['type' => 'Label', 'name' => 'NewHint', 'caption' => '➡️ Art wählen und mit „OK“ bestätigen — danach die Zeile mit ✏️ öffnen, dann erscheinen die Einstellungen dieser Art. (Schneller: „📚 Katalog“ oder „🔎 Entdecken“.)', 'bold' => true];
        }
        foreach ($own ? [$type => AUTOMATKatalog::TYPES[$type]] : [] as $t => $def) {
            $items = [];
            foreach (AUTOMATKatalog::fields($t) as $f) {
                foreach ($this->fieldElements($f, $t === $type ? $fieldProblems : [], $t === $type ? $infoHints : []) as $e) {
                    $items[] = $e;
                }
                if (($f['n'] ?? '') !== '' && $f['n'] === (self::NOTIFY_FIELD[$t] ?? null)) {
                    foreach ($this->wayChoice(substr($f['n'], 0, 3)) as $e) {
                        $items[] = $e;
                    }
                }
            }
            $bad = $t === $type && count($fieldProblems) > 0;
            $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_' . $t, 'caption' => ($bad ? '⛔ ' : $def['icon'] . ' ') . 'Einstellungen: ' . $def['label'], 'expanded' => true, 'visible' => $t === $type, 'items' => $items];
        }
        $modeItems = [['type' => 'Label', 'name' => 'ModesLabel', 'caption' => 'Aktiv in diesen Hausmodi (Variable „Hausmodus“ dieser Instanz):', 'visible' => !in_array($type, AUTOMATKatalog::NO_MODE_FILTER, true)]];
        foreach (AUTOMATKatalog::MODES as $m => $label) {
            $modeItems[] = ['type' => 'CheckBox', 'name' => 'm' . $m, 'caption' => $label, 'value' => true, 'visible' => !in_array($type, AUTOMATKatalog::NO_MODE_FILTER, true)];
        }
        $modeItems[] = ['type' => 'SelectCondition', 'name' => 'Cond', 'caption' => 'Zusätzliche Bedingung (optional, z. B. nur werktags oder nur wenn ein Schalter an ist)', 'multi' => true];
        $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_when', 'caption' => '🕒 Wann ist der Automatismus aktiv?', 'expanded' => false, 'items' => $modeItems];

        if (!$isNew) {
            $row = $this->rowByUid($uid);
            if ($row !== null) {
                $c = $this->conflicts($row, $uid);
                if (count($c) === 0) {
                    $el[] = ['type' => 'Label', 'caption' => '✅ Keine andere Steuerung derselben Geräte gefunden (gespeicherter Stand).'];
                } else {
                    $items = [['type' => 'Label', 'caption' => '⚠️ = schaltet nachweislich dasselbe · ℹ️ = reagiert auf dieselbe Variable oder reicht sie nur weiter. „Öffnen“ springt zur Fundstelle im Objektbaum.']];
                    foreach ($c as $i => $x) {
                        $items[] = ['type' => 'RowLayout', 'items' => [
                            ['type' => 'OpenObjectButton', 'caption' => 'Öffnen', 'objectID' => $x['id'], 'width' => '90px'],
                            ['type' => 'Label', 'caption' => $x['level'] . ' ' . $x['text']],
                        ]];
                    }
                    $warn = count(array_filter($c, function ($x) {
                        return $x['level'] === '⚠️';
                    }));
                    $el[] = ['type' => 'ExpansionPanel', 'name' => 'sec_conflicts', 'expanded' => $warn > 0,
                        'caption' => ($warn > 0 ? '⚠️ ' : 'ℹ️ ') . 'Andere Steuerungen derselben Geräte: ' . count($c) . ($warn > 0 ? ' (davon ' . $warn . ' schalten dasselbe)' : ''), 'items' => $items];
                }
            }
        } else {
            $el[] = ['type' => 'Label', 'caption' => 'ℹ️ Nach „Übernehmen“ prüft Automat, ob dieselben Geräte schon von einem Ereignis oder einer anderen Instanz gesteuert werden (Hinweis beim nächsten Öffnen).'];
        }
        return json_encode($el);
    }

    /** Formular-Elemente eines Katalog-Felds, ggf. mit Hilfe-Knopf (volle Frage als Beschriftung, SUITE.md Feld-Hilfestellung). */
    private function fieldElements(array $f, array $problems, array $hints): array
    {
        $els = [];
        $type = (string)($this->editRow['Type'] ?? '');
        $show = $type === '' || AUTOMATKatalog::visibleWhen($f, $this->editRow, $type);
        foreach ($this->fieldInputs($f) as $e) {
            if (!$show && isset($e['name'])) {
                $e['visible'] = false; // nur bei passender Auswahl zeigen (z. B. Uhrzeit nur bei „zur Uhrzeit“)
            }
            $els[] = $e;
            if (isset($e['name'], $hints[$e['name']])) {
                // Hinweis aus dem beobachteten Verhalten (z. B. Impuls-Melder, verlorene Meldungen)
                $els[] = ['type' => 'Label', 'caption' => $hints[$e['name']], 'bold' => true];
            }
            if (($e['type'] ?? '') === 'SelectVariable') {
                // Roter Hinweis direkt unter dem Feld; verschwindet, sobald eine gültige Variable gewählt ist.
                $msg = $problems[$e['name']] ?? '';
                $els[] = ['type' => 'Label', 'name' => 'chk_' . $e['name'], 'caption' => $msg, 'color' => 0xFF0000, 'visible' => $msg !== ''];
            }
        }
        if (isset($f['help'])) {
            [$question, $lines] = $f['help'];
            $els[] = ['type' => 'PopupButton', 'caption' => $question, 'width' => '420px', 'popup' => [
                'caption' => $question,
                'items' => array_map(function ($l) {
                    return ['type' => 'Label', 'caption' => $l];
                }, $lines),
            ]];
        }
        return $els;
    }

    /** Kontrollkästchen „über welche Meldewege“ im Dialog; Namen je Typ-Präfix, damit sie nicht kollidieren. */
    private function wayChoice(string $prefix): array
    {
        $ways = $this->ways();
        if (count($ways) === 0) {
            return [['type' => 'Label', 'caption' => 'ℹ️ Noch kein Meldeweg angelegt — unter „🔔 Meldungen“ anlegen.']];
        }
        $std = implode(', ', array_column(array_filter($ways, function ($w) {
            return $w['std'];
        }), 'name')) ?: '— keiner —';
        $els = [['type' => 'Label', 'caption' => 'Über welche Meldewege? (kein Haken = die Standard-Wege: ' . $std . ')']];
        foreach ($ways as $w) {
            $els[] = ['type' => 'CheckBox', 'name' => $prefix . 'mw_' . $w['id'], 'caption' => $w['name'] . ($w['std'] ? ' (Standard)' : ''), 'value' => false];
        }
        return $els;
    }

    /** Gespeicherte Zeile des gerade geöffneten Dialogs (für Felder, die von einer Variable abhängen). */
    private array $editRow = [];

    private function fieldInputs(array $f): array
    {
        $k = $f['k'];
        if ($k === 'info') {
            return [['type' => 'Label', 'caption' => '— ' . $f['c'] . ' —', 'bold' => true]];
        }
        $base = ['name' => $f['n'], 'caption' => $f['c']];
        switch ($k) {
            case 'vars':
                // Nur gefüllte Plätze plus EIN leerer — füllt man ihn, erscheint der nächste (Dietmar 15.10.2026).
                // Keine feste Obergrenze: immer SPARE freie Plätze hinter dem letzten belegten; sind auch die voll,
                // gibt es nach „Übernehmen“ und erneutem Öffnen weitere.
                $max = max(AUTOMATKatalog::slots($f['n']), AUTOMATKatalog::lastFilled($this->editRow, $f['n'])) + AUTOMATKatalog::SPARE;
                $vals = [];
                for ($i = 1; $i <= $max; $i++) {
                    $vals[$i] = (int)($this->editRow[$f['n'] . $i] ?? 0);
                }
                $shown = self::slotVisibility($vals);
                $all = implode(', ', array_map(function ($i) use ($f) {
                    return '(int)$' . $f['n'] . $i;
                }, range(1, $max)));
                $o = [];
                for ($i = 1; $i <= $max; $i++) {
                    $o[] = ['type' => 'SelectVariable', 'name' => $f['n'] . $i, 'caption' => $f['c'] . ($max > 1 ? ' ' . $i : '') . ($i > 1 || empty($f['req']) ? ' (optional)' : ''), 'value' => 0, 'width' => '520px',
                        'visible' => $shown[$i],
                        'onChange' => 'AUTOMAT_UIVarsChanged($id, \'' . $f['n'] . '\', ' . $i . ', json_encode([' . $all . ']));'];
                }
                $o[] = ['type' => 'Label', 'name' => 'more_' . $f['n'], 'visible' => !in_array(false, $vals === [] ? [false] : array_map(function ($v) {
                    return $v >= AUTOMATKatalog::$minId;
                }, $vals), true), 'caption' => '➕ Alle Plätze belegt — nach „Übernehmen“ und erneutem Öffnen gibt es weitere.'];
                return $o;
            case 'var':
                return [$base + ['type' => 'SelectVariable', 'value' => 0, 'width' => '520px', 'onChange' => 'AUTOMAT_UIVarChanged($id, \'' . $f['n'] . '\', (int)$' . $f['n'] . ');']];
            case 'int':
                return [$base + ['type' => 'NumberSpinner', 'value' => $f['d'], 'suffix' => $f['suffix'] ?? '', 'minimum' => $f['min'] ?? 0, 'maximum' => $f['max'] ?? 100000]];
            case 'float':
                return [$base + ['type' => 'NumberSpinner', 'value' => $f['d'], 'digits' => 1, 'suffix' => $f['suffix'] ?? '', 'minimum' => $f['min'] ?? 0, 'maximum' => $f['max'] ?? 100000]];
            case 'bool':
                return [$base + ['type' => 'CheckBox', 'value' => $f['d']]];
            case 'select':
                $opts = [];
                foreach ($f['options'] as $v => $c) {
                    $opts[] = ['caption' => $c, 'value' => $v];
                }
                $sel = $base + ['type' => 'Select', 'value' => $f['d'], 'options' => $opts, 'width' => '420px'];
                $type = (string)($this->editRow['Type'] ?? '');
                $ctl = $type !== '' ? AUTOMATKatalog::controllers($type) : [];
                if (in_array($f['n'], $ctl, true)) {
                    $pairs = array_map(function ($c) {
                        return "'" . $c . "' => (int)\$" . $c;
                    }, $ctl);
                    $sel['onChange'] = 'AUTOMAT_UIDeps($id, \'' . $type . '\', json_encode([' . implode(', ', $pairs) . ']));';
                }
                return [$sel];
            case 'time':
                return [$base + ['type' => 'SelectTime', 'value' => $f['d']]];
            case 'text':
                return [$base + ['type' => 'ValidationTextBox', 'value' => $f['d'], 'width' => '520px']];
            case 'object':
                return [$base + ['type' => 'SelectObject', 'value' => 0, 'width' => '520px']];
            case 'pick':
                // Auswahl aus den möglichen Werten der gewählten Variable; schreibt ins Vergleichsfeld.
                $opts = $this->pickOptions((int)($this->editRow[$f['src']] ?? 0));
                return [$base + ['type' => 'Select', 'value' => '', 'options' => $opts, 'width' => '520px', 'visible' => count($opts) > 1,
                    'onChange' => 'AUTOMAT_UIPickValue($id, \'' . $f['to'] . '\', \'' . $f['op'] . '\', (string)$' . $f['n'] . ', (int)$' . $f['op'] . ');']];
        }
        return [];
    }

    /** AUTOMAT_UIVarChanged($id, $field, $vid) — roten Hinweis unter einem Variablenfeld im offenen Dialog nachführen. */
    /** AUTOMAT_UIDeps($id, $type, $valuesJson) — im Dialog nur die Felder zeigen, die zur Auswahl passen. */
    public function UIDeps(string $type, string $valuesJson): void
    {
        if (!AUTOMATKatalog::has($type)) {
            return;
        }
        $values = json_decode($valuesJson, true) ?: [];
        foreach (AUTOMATKatalog::fields($type) as $f) {
            if (!isset($f['when'], $f['n'])) {
                continue;
            }
            $vis = AUTOMATKatalog::visibleWhen($f, $values, $type);
            $names = $f['k'] === 'vars' ? array_map(function ($i) use ($f) {
                return $f['n'] . $i;
            }, range(1, AUTOMATKatalog::slots($f['n']))) : [$f['n']];
            foreach ($names as $n) {
                $this->UpdateFormField($n, 'visible', $vis);
            }
        }
    }

    /** AUTOMAT_UIPickValue($id, $to, $opField, $value, $op) — gewählten Wert ins Vergleichsfeld; „ist an/aus“ wird dabei zu „ist gleich“. */
    public function UIPickValue(string $to, string $opField, string $value, int $op): void
    {
        if ($value === '') {
            return;
        }
        $this->UpdateFormField($to, 'value', $value);
        if ($op === 4 || $op === 5) {
            $this->UpdateFormField($opField, 'value', 0);
            $this->UpdateFormField($to, 'visible', true); // „ist gleich“ braucht das Vergleichsfeld
        }
    }

    /**
     * Mögliche Werte einer Variable als [Wert => Beschriftung]: Darstellung (OPTIONS), sonst Profil-Zuordnungen,
     * bei Bool notfalls „an/wahr“ / „aus/falsch“. Leer, wenn nichts Sinnvolles da ist (z. B. Messwerte).
     */
    private function valueLabels(int $vid): array
    {
        if ($vid < AUTOMATKatalog::$minId || !IPS_VariableExists($vid)) {
            return [];
        }
        $v = IPS_GetVariable($vid);
        $out = [];
        foreach ([$v['VariableCustomPresentation'] ?? [], $v['VariablePresentation'] ?? []] as $pres) {
            $raw = is_array($pres) ? ($pres['OPTIONS'] ?? null) : null;
            $opts = is_string($raw) ? json_decode($raw, true) : $raw;
            foreach (is_array($opts) ? $opts : [] as $o) {
                if (is_array($o) && array_key_exists('Value', $o) && trim((string)($o['Caption'] ?? '')) !== '') {
                    $out[AUTOMATLogik::valueKey($o['Value'])] = trim((string)$o['Caption']);
                }
            }
            if (count($out) > 0) {
                return $out;
            }
        }
        $presP = (array)(($v['VariableCustomPresentation'] ?? []) ?: ($v['VariablePresentation'] ?? []));
        $profile = (string)(($v['VariableCustomProfile'] ?? '') ?: (($v['VariableProfile'] ?? '') ?: ($presP['PROFILE'] ?? '')));
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            foreach ((array)(IPS_GetVariableProfile($profile)['Associations'] ?? []) as $a) {
                if (trim((string)($a['Name'] ?? '')) !== '') {
                    $key = (int)$v['VariableType'] === VARIABLETYPE_BOOLEAN ? AUTOMATLogik::valueKey((bool)$a['Value']) : AUTOMATLogik::valueKey($a['Value']);
                    $out[$key] = trim((string)$a['Name']);
                }
            }
        }
        if (count($out) === 0 && (int)$v['VariableType'] === VARIABLETYPE_BOOLEAN) {
            // Matter-Kontakt ohne Beschriftung: wahr = geschlossen — so steht es auch in der Auswahl
            $out = AUTOMATErkennung::contactInverted($this->varInfo($vid)) && preg_match(AUTOMATErkennung::contactPattern(), IPS_GetName($vid) . ' ' . IPS_GetName(IPS_GetParent($vid)))
                ? ['true' => 'zu', 'false' => 'offen']
                : ['true' => 'an / wahr', 'false' => 'aus / falsch'];
        }
        return $out;
    }

    /** Auswahlliste fürs Dialogfeld: erster Eintrag „bitte wählen“, dann „Beschriftung (Wert)“. */
    private function pickOptions(int $vid): array
    {
        $labels = $this->valueLabels($vid);
        if (count($labels) === 0) {
            return [['caption' => '— keine festen Werte bekannt, oben eintippen —', 'value' => '']];
        }
        $opts = [['caption' => '— Wert wählen —', 'value' => '']];
        foreach ($labels as $val => $cap) {
            $val = (string)$val; // PHP macht aus Schlüsseln wie „0“ eine Zahl
            $raw = $val === 'true' ? 'wahr' : ($val === 'false' ? 'falsch' : $val);
            $opts[] = ['caption' => $cap . (mb_strpos(mb_strtolower($cap), mb_strtolower($raw)) !== false ? '' : ' (' . $raw . ')'), 'value' => $val];
        }
        return $opts;
    }

    /** Sichtbare Plätze eines Mehrfachfelds: alle gefüllten und der erste leere. $vals: [Platz => Variablen-ID]. */
    private static function slotVisibility(array $vals): array
    {
        $out = [];
        $firstEmpty = null;
        foreach ($vals as $i => $v) {
            $filled = (int)$v >= AUTOMATKatalog::$minId;
            if (!$filled && $firstEmpty === null) {
                $firstEmpty = $i;
            }
            $out[$i] = $filled;
        }
        if ($firstEmpty !== null) {
            $out[$firstEmpty] = true;
        }
        return $out;
    }

    /** AUTOMAT_UIVarsChanged($id, $base, $index, $valsJson) — Platz geändert: prüfen wie ein Einzelfeld, dann Plätze ein-/ausblenden. */
    public function UIVarsChanged(string $base, int $index, string $valsJson): void
    {
        $list = array_values(json_decode($valsJson, true) ?: []);
        $vals = [];
        foreach ($list as $k => $v) {
            $vals[$k + 1] = (int)$v < AUTOMATKatalog::$minId ? 0 : (int)$v; // geleert: Symcon liefert teils 1
        }
        $this->UIVarChanged($base . $index, (int)($list[$index - 1] ?? 0));
        foreach (self::slotVisibility($vals) as $i => $vis) {
            $this->UpdateFormField($base . $i, 'visible', $vis);
        }
        $this->UpdateFormField('more_' . $base, 'visible', count($vals) > 0 && !in_array(0, $vals, true));
    }

    public function UIVarChanged(string $field, int $vid): void
    {
        if ($vid !== 0 && $vid < AUTOMATKatalog::$minId) {
            // Feld wurde geleert, Symcon liefert dafür teils 1 statt 0 — sauber auf 0 setzen, damit 0 gespeichert wird.
            $this->UpdateFormField($field, 'value', 0);
            $vid = 0;
        }
        $bad = $vid >= AUTOMATKatalog::$minId && !IPS_VariableExists($vid);
        // Hängt an dieser Variable eine Werteauswahl (Wächter), deren Einträge nachführen.
        foreach (AUTOMATKatalog::TYPES as $t => $_) {
            foreach (AUTOMATKatalog::fields($t) as $f) {
                if (($f['k'] ?? '') === 'pick' && $f['src'] === $field) {
                    $opts = $this->pickOptions($vid);
                    $this->UpdateFormField($f['n'], 'options', json_encode($opts, JSON_UNESCAPED_UNICODE));
                    $this->UpdateFormField($f['n'], 'visible', count($opts) > 1);
                }
            }
        }
        $this->UpdateFormField('chk_' . $field, 'caption', $bad ? '⛔ Variable #' . $vid . ' existiert nicht — bitte neu wählen oder leeren.' : '');
        $this->UpdateFormField('chk_' . $field, 'visible', $bad);
    }

    /** AUTOMAT_UIDialogType($id, $type) — im offenen Dialog den passenden Einstellungsblock zeigen. */
    public function UIDialogType(string $type): void
    {
        // Abschnitte gibt es im Dialog nur für bestehende Automatismen (Art fest) — hier nur Beschreibung und Modus-Häkchen.
        $this->UpdateFormField('TypeLabel', 'value', AUTOMATKatalog::has($type) ? AUTOMATKatalog::label($type) : '');
        // Bei der Szene ist der Hausmodus der Auslöser — die allgemeinen Modus-Häkchen wären dort verwirrend.
        $modeFilter = !in_array($type, AUTOMATKatalog::NO_MODE_FILTER, true);
        $this->UpdateFormField('ModesLabel', 'visible', $modeFilter);
        foreach (AUTOMATKatalog::MODES as $m => $_) {
            $this->UpdateFormField('m' . $m, 'visible', $modeFilter);
        }
        $this->UpdateFormField('TypeDesc', 'caption', AUTOMATKatalog::has($type) ? AUTOMATKatalog::TYPES[$type]['desc'] : 'Zuerst die Art wählen — danach erscheinen die passenden Einstellungen.');
    }

    // =====================================================================
    //  Kachel
    // =====================================================================

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode($this->tilePayload(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');</script>';
    }

    private function refreshTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tilePayload(), JSON_UNESCAPED_UNICODE));
    }

    private function tilePayload(): array
    {
        $status = $this->attrMap('Status');
        $due = $this->attrMap('End');
        $items = [];
        foreach ($this->rows() as $row) {
            $uid = $row['Uid'];
            $type = (string)$row['Type'];
            $def = AUTOMATKatalog::TYPES[$type] ?? [];
            $light = !empty($def['light']) && $type !== 'daemmerung';
            $st = (string)($status[$uid] ?? '');
            $locked = (bool)$this->getOwn('l_' . $uid, false);
            $items[] = [
                'uid' => $uid, 'name' => $this->rowName($row), 'icon' => $def['icon'] ?? '❔',
                'type' => $def['label'] ?? '', 'cat' => AUTOMATKatalog::CATEGORIES[$def['cat'] ?? ''] ?? 'Sonstiges',
                'room' => trim((string)($row['Room'] ?? '')), 'status' => $st,
                'due' => isset($due[$uid]) ? (int)$due[$uid] : null, 'enabled' => !empty($row['Enabled']),
                'locked' => $locked, 'permanent' => $light ? (bool)$this->getOwn('d_' . $uid, false) : null,
                'run' => $type === 'rollladen' ? 'updown' : (in_array($type, AUTOMATLogik::RUN_TYPES, true) ? 'run' : ''),
                'state' => $this->tileState($row, $st, $locked, isset($due[$uid])),
            ];
        }
        return [
            'light' => $this->pBool('cfg_TileLight'),
            'now' => $this->now(),
            'mode' => (int)$this->getOwn('Hausmodus', 0),
            'modes' => array_values(AUTOMATKatalog::MODES),
            'paused' => (bool)$this->getOwn('Pause', false),
            'items' => $items,
            'log' => $this->protocolLines(8),
        ];
    }

    /**
     * Zustand für Filter und Sortierung der Kachel: warn (auffällig: gemeldet, unvollständig, Vorwarnung),
     * run (läuft gerade: an, Countdown, abgesenkt), idle (bereit), locked (gesperrt), off (nicht aktiv).
     */
    private function tileState(array $row, string $status, bool $locked, bool $hasEnd): string
    {
        if (empty($row['Enabled'])) {
            return 'off';
        }
        if ($locked) {
            return 'locked';
        }
        $problems = AUTOMATKatalog::problems($row, function (int $vid) {
            return IPS_VariableExists($vid);
        });
        foreach (['🔔', '⚠️', '❔', '⛔', '🚨', '💨 Bitte'] as $p) {
            if (count($problems) > 0 || strpos($status, $p) === 0) {
                return 'warn';
            }
        }
        foreach (['💡', '🔆', '▶️', '⏳', '🪟', '🚪 Alle weg', '🌅 Wird', '🌙 Wird', '🌡 Abgesenkt', '💤 Standby'] as $p) {
            if (strpos($status, $p) === 0) {
                return 'run';
            }
        }
        return $hasEnd ? 'run' : 'idle';
    }

    /** Rückkanal aus der Kachel: {"a":"lock|perm|pause|mode","uid":…,"v":…}. */
    private function tileAction(string $json): void
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return;
        }
        $a = (string)($d['a'] ?? '');
        $uid = preg_replace('/[^a-z0-9]/', '', (string)($d['uid'] ?? ''));
        if ($a === 'lock' && $this->rowByUid($uid) !== null) {
            $this->RequestAction('l_' . $uid, !empty($d['v']));
        } elseif ($a === 'perm' && $this->rowByUid($uid) !== null && @IPS_GetObjectIDByIdent('d_' . $uid, $this->InstanceID)) {
            $this->RequestAction('d_' . $uid, !empty($d['v']));
        } elseif ($a === 'run' && $this->rowByUid($uid) !== null) {
            $this->RunNow($uid, in_array($d['v'] ?? '', ['down', 'up'], true) ? (string)$d['v'] : ''); // landet im Protokoll
        } elseif ($a === 'pause') {
            $this->RequestAction('Pause', !empty($d['v']));
        } elseif ($a === 'mode' && isset(AUTOMATKatalog::MODES[(int)($d['v'] ?? -1)])) {
            $this->RequestAction('Hausmodus', (int)$d['v']);
        } else {
            $this->refreshTile();
        }
    }

    // =====================================================================
    //  Helfer
    // =====================================================================

    // =====================================================================
    //  Typsichere Lesezugriffe (SUITE.md Store-Checkliste 9c): Während die Instanz neu lädt, liefern
    //  ReadProperty*/ReadAttribute* false statt des Typs — mit strict_types wäre das ein TypeError.
    // =====================================================================

    private function pInt(string $name): int
    {
        return (int)$this->ReadPropertyInteger($name);
    }

    private function pStr(string $name): string
    {
        return (string)$this->ReadPropertyString($name);
    }

    private function pBool(string $name): bool
    {
        return (bool)$this->ReadPropertyBoolean($name);
    }

    private function pFloat(string $name): float
    {
        return (float)$this->ReadPropertyFloat($name);
    }

    private function aStr(string $name): string
    {
        return (string)$this->ReadAttributeString($name);
    }

    private function aInt(string $name): int
    {
        return (int)$this->ReadAttributeInteger($name);
    }

    private function aBool(string $name): bool
    {
        return (bool)$this->ReadAttributeBoolean($name);
    }

    /** Testbar überschreibbar (Prüfstand ohne Symcon). */
    protected function now(): int
    {
        return time();
    }

    protected function randomUnit(): float
    {
        return random_int(-1000, 1000) / 1000;
    }

    /** Listenzeilen mit garantierter Kennung und bekanntem Typ. */
    private function rows(): array
    {
        $rows = json_decode((string)$this->pStr('Automations'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        // Ausgefilterte Zeilen (unsichtbare Liste) dazu — ohne Doppelte (die sichtbare Liste gewinnt)
        $seen = [];
        $gens = [];
        $genKnown = false;
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['Uid'])) {
                $seen[(string)$r['Uid']] = true;
            }
            if (is_array($r) && array_key_exists('Gen', $r)) {
                $genKnown = true;
                $gens[(string)$r['Gen']] = true;
            }
        }
        foreach (json_decode((string)$this->pStr('AutomationsHidden'), true) ?: [] as $h) {
            // Nur ausgeblendete Zeilen desselben Filterstands: wurde danach ohne Filter gespeichert (z. B. eine Zeile
            // gelöscht), gehört die alte unsichtbare Liste nicht mehr dazu — sonst käme Gelöschtes zurück.
            // Altbestand ohne Kennung zählt, solange die sichtbare Liste auch noch keine trägt.
            $hg = is_array($h) && array_key_exists('Gen', $h) ? (string)$h['Gen'] : null;
            // Trägt die sichtbare Liste gar keine Kennung (Symcon hätte sie nicht gespeichert), lieber alles behalten.
            $belongs = !$genKnown || ($hg !== null && $hg !== '' && (count($rows) === 0 || isset($gens[$hg])));
            if (!$belongs) {
                continue;
            }
            // Die ganze Zeile steckt als JSON in der Spalte „Data“ — Symcon speichert in dieser unsichtbaren
            // Liste nur die Spalten (Fund 15.10.2026: ohne „Data“ blieben nur Kennung und Art übrig).
            $r = self::unwrapHidden($h);
            if (is_array($r) && (string)($r['Uid'] ?? '') !== '' && !isset($seen[(string)($r['Uid'] ?? '')])) {
                $rows[] = $r;
            }
        }
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $r['Uid'] = preg_replace('/[^a-z0-9]/', '', strtolower((string)($r['Uid'] ?? '')));
            if ($r['Uid'] === '') {
                $r['Uid'] = 'r' . $i; // Rückfall für Zeilen ohne Kennung
            }
            $r['Type'] = (string)($r['Type'] ?? '');
            $out[] = AUTOMATKatalog::sanitize(AUTOMATKatalog::stripForeign($r));
        }
        return $out;
    }

    private function rowByUid(string $uid): ?array
    {
        foreach ($this->rows() as $r) {
            if ($r['Uid'] === $uid) {
                return $r;
            }
        }
        return null;
    }

    private function rowName(array $row): string
    {
        $n = trim((string)($row['Name'] ?? ''));
        return $n !== '' ? $n : (AUTOMATKatalog::TYPES[$row['Type'] ?? '']['label'] ?? 'Automatismus');
    }

    private function newUid(array $rows): string
    {
        $used = array_flip(array_map(function ($r) {
            return (string)($r['Uid'] ?? '');
        }, array_merge($rows, $this->rows()))); // auch gespeicherte/ausgefilterte Zeilen
        // Immer mit „a“ beginnend — kollidiert so nie mit den Rückfall-Kennungen „r<Index>“.
        do {
            $uid = 'a' . substr(bin2hex(random_bytes(4)), 0, 5);
        } while (isset($used[$uid]));
        return $uid;
    }

    /** Gedächtnis aller Automatismen (versteckte Variable „store“). */
    private function statesMap(): array
    {
        $id = @IPS_GetObjectIDByIdent('store', $this->InstanceID);
        $m = $id ? json_decode((string)GetValue($id), true) : null;
        return is_array($m) ? $m : [];
    }

    private function statesWrite(array $states): void
    {
        $id = @IPS_GetObjectIDByIdent('store', $this->InstanceID);
        if (!$id) {
            return;
        }
        $json = json_encode($states);
        if ((string)GetValue($id) !== $json) {
            SetValueString($id, $json);
        }
    }

    private function attrMap(string $name): array
    {
        $m = json_decode((string)$this->aStr($name), true);
        return is_array($m) ? $m : [];
    }

    private function ensureVar(string $ident, string $name, int $type, string $profile, int $pos): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        // Position nur beim Anlegen vorgeben — eine Umsortierung im Objektbaum bleibt erhalten (Store-Checkliste 9m).
        if ($id) {
            $pos = (int)(IPS_GetObject($id)['ObjectPosition'] ?? $pos);
        }
        $this->MaintainVariable($ident, $name, $type, $profile, $pos, true);
        if ($id) {
            // MaintainVariable benennt nicht um (Pitfall 16): nur Standardnamen nachziehen, Nutzernamen bleiben.
            $cur = IPS_GetName($id);
            if ($cur !== $name && preg_match('/ · (Status|Restzeit|gesperrt|Dauerlicht|Jemand zu Hause)$/u', $cur)) {
                IPS_SetName($id, $name);
            }
        }
    }

    private function getOwn(string $ident, $default)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id ? GetValue($id) : $default;
    }

    private function setVarIfChanged(string $ident, string $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id && GetValue($id) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /** Variablenname mit Gerät: „Licht Flur / Status“. */
    private function varLabel(int $vid): string
    {
        if (!IPS_VariableExists($vid)) {
            return '#' . $vid;
        }
        $p = IPS_GetParent($vid);
        $name = IPS_GetName($vid);
        if ($p > 0 && IPS_InstanceExists($p)) {
            $inst = IPS_GetName($p);
            // „Essen Terrassentür / Essen Terrassentür“ (Matter, Zigbee2MQTT …) nur einmal nennen
            return mb_strtolower(trim($inst)) === mb_strtolower(trim($name)) ? $name : $inst . ' / ' . $name;
        }
        return $name;
    }

    /**
     * Richtung eines Rollladens aus seinem Profil: ~Shutter = 0 offen / Höchstwert zu, ~Shutter.Reversed = 0 zu /
     * Höchstwert offen. null = unbekannt (dann gelten die Werte im Automatismus).
     */
    private function shutterDir(int $vid): ?array
    {
        $p = mb_strtolower((string)$this->varInfo($vid)['profile']);
        if (!preg_match('/^~shutter(\.reversed)?$/', $p)) {
            return null;
        }
        $max = $this->dimMax($vid) > 0 ? $this->dimMax($vid) : 100.0;
        return strpos($p, 'reversed') !== false ? ['open' => $max, 'closed' => 0.0] : ['open' => 0.0, 'closed' => $max];
    }

    /** Profil, Darstellung und Ident einer Variable — für die Erkennung der Kontakt-Richtung. */
    private function varInfo(int $vid): array
    {
        $v = IPS_GetVariable($vid);
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || count($pres) === 0) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '') ?: (string)((is_array($pres) ? $pres : [])['PROFILE'] ?? '');
        return [
            'profile' => $profile,
            'pres'    => is_array($pres) ? $pres : [],
            'ident'   => (string)IPS_GetObject($vid)['ObjectIdent'],
            'assoc'   => $this->profileAssoc($profile),
        ];
    }

    /** Zuordnungen eines Profils als [Wert => Beschriftung] (für die Kontakt-Richtung, z. B. ~Lock: wahr = Gesperrt). */
    private function profileAssoc(string $profile): array
    {
        $out = [];
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            foreach ((array)(IPS_GetVariableProfile($profile)['Associations'] ?? []) as $a) {
                if (is_array($a) && isset($a['Value'])) {
                    $out[(int)$a['Value']] = (string)($a['Name'] ?? '');
                }
            }
        }
        return $out;
    }

    /** Größter Wert eines Dimmers (0 = Schalter). */
    private function dimMax(int $vid): float
    {
        $v = IPS_GetVariable($vid);
        $type = (int)$v['VariableType'];
        if ($type === VARIABLETYPE_BOOLEAN || $type === VARIABLETYPE_STRING) {
            return 0;
        }
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || count($pres) === 0) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        if (is_array($pres) && isset($pres['MAX']) && (float)$pres['MAX'] > 0) {
            return (float)$pres['MAX'];
        }
        $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '') ?: (string)($pres['PROFILE'] ?? '');
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $max = (float)(IPS_GetVariableProfile($profile)['MaxValue'] ?? 0);
            if ($max > 0) {
                return $max;
            }
        }
        return 100;
    }

    private function castForVariable(int $vid, $value)
    {
        switch ((int)(IPS_GetVariable($vid)['VariableType'] ?? -1)) {
            case VARIABLETYPE_BOOLEAN:
                return (bool)$value;
            case VARIABLETYPE_INTEGER:
                return (int)round((float)$value);
            case VARIABLETYPE_FLOAT:
                return (float)$value;
        }
        return (string)$value;
    }

    private function sameValue($a, $b, int $vid = 0): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return AUTOMATLogik::isTrue($a) === AUTOMATLogik::isTrue($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            // Toleranz am Wertebereich messen (Dimmer 0–99 meldet 4 statt 5), bei Ganzzahlen mindestens 1
            $range = $vid > 0 && IPS_VariableExists($vid) ? $this->dimMax($vid) : 0.0;
            $tol = max(0.02, abs((float)$b) * 0.02, $range > 0 ? $range * 0.02 : 0.0);
            if ($vid > 0 && IPS_VariableExists($vid) && (int)IPS_GetVariable($vid)['VariableType'] === VARIABLETYPE_INTEGER) {
                $tol = max($tol, 1.0);
            }
            return abs((float)$a - (float)$b) <= $tol;
        }
        return (string)$a === (string)$b;
    }

    // =====================================================================
    //  Formular
    // =====================================================================

    /** Listenwerte für das Formular: gespeicherte Zeilen plus berechneter Zustand. */
    private function listValues(array $rows, string $gen = ''): array
    {
        $status = $this->attrMap('Status');
        $out = [];
        foreach ($rows as $r) {
            $r['Gen'] = $gen; // Filterstand, zu dem die unsichtbare Liste gehört (siehe rows())
            $uid = (string)($r['Uid'] ?? '');
            $p = AUTOMATKatalog::problems($r, function (int $vid) {
                return IPS_VariableExists($vid);
            });
            $r = AUTOMATKatalog::stripForeign($r);
            $r['TypeLabel'] = AUTOMATKatalog::has((string)($r['Type'] ?? '')) ? AUTOMATKatalog::label((string)$r['Type']) : '❔ Typ wählen (✏️)';
            $r['State'] = empty($r['Enabled']) ? '⚫ Nicht aktiv' : (count($p) > 0 ? '⚠️ ' . $p[0] : (string)($status[$uid] ?? 'ℹ️ Nach „Übernehmen“ aktiv'));
            if (!empty($r['Dry']) && strpos($r['State'], '🧪') !== 0) {
                $r['State'] = '🧪 ' . $r['State'];
            }
            $r['Mark'] = false;
            $r['Settings'] = AUTOMATKatalog::has((string)($r['Type'] ?? '')) ? AUTOMATKatalog::summary($r, function (int $vid) {
                return $this->liveText($vid);
            }) : '';
            if (($r['Type'] ?? '') === 'bewegung' && empty($r['bl_impuls']) && (int)($r['bl_melmax'] ?? 0) <= 0) {
                $t = [];
                foreach (AUTOMATKatalog::vars($r, 'bl_mel') as $m) {
                    $t[] = $this->melderTimeout($m);
                }
                if (count($t) > 0) {
                    $r['Settings'] .= ' · Melder-Rückfall auto ' . AUTOMATKatalog::dur(max($t));
                }
            }
            if (($r['Type'] ?? '') === 'bewegung') {
                $lost = 0;
                foreach (AUTOMATKatalog::vars($r, 'bl_mel') as $m) {
                    $lost += $this->lostCount($m);
                }
                $hints = $this->melderHints($r);
                $pre = isset($hints['bl_impuls']) ? 'ℹ️ Impuls-Melder erkannt (✏️) · ' : '';
                $stt = $this->statesMap()[$r['Uid'] ?? ''] ?? [];
                $eff = AUTOMATLogik::effectiveNachlauf($r, is_array($stt) ? $stt : []);
                $fo = count((array)($stt['fo'] ?? []));
                $learned = $eff > max(1, (int)($r['bl_nachlauf'] ?? 120)) ? ' · 🕒 Nachlauf gelernt ' . AUTOMATKatalog::dur($eff) : '';
                $learned .= $fo > 0 ? ' · ' . $fo . '× zu früh aus (7 Tage)' : '';
                $r['Settings'] = $pre . $r['Settings'] . $learned . ($lost > 0 ? ' · 📶 ' . $lost . '× Meldung verloren (7 Tage)' : '');
            }
            // Sortierschlüssel: Raum → Art → Name bzw. Art → Raum → Name (Spaltenkopf oder „Ordnen nach“).
            $room = mb_strtolower(trim((string)($r['Room'] ?? '')) ?: '~');
            $type = (string)($r['Type'] ?? '');
            $name = mb_strtolower((string)($r['Name'] ?? ''));
            $r['SortRoom'] = $room . '|' . $type . '|' . $name;
            $r['SortType'] = $type . '|' . $room . '|' . $name;
            if (count($p) > 0 && !empty($r['Enabled'])) {
                $r['rowColor'] = '#FFC0C0';
            } elseif (empty($r['Enabled'])) {
                $r['rowColor'] = '#DFDFDF';
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Aktueller Wert einer Variable kurz als Text mit Einheit („12 lx"), null wenn unbekannt. */
    /** Nächster Feiertag fürs gewählte Bundesland (Anzeige im Hausmodus-Bereich). */
    private function holidayLine(): string
    {
        $land = $this->pStr('cfg_Bundesland');
        if ($land === '') {
            return 'ℹ️ Feiertage werden nicht berücksichtigt.';
        }
        $d = date('Y-m-d', $this->now());
        for ($i = 0; $i < 400; $i++) {
            $h = AUTOMATLogik::holiday($d, $land);
            if ($h !== '') {
                return '📅 Nächster Feiertag: ' . $h . ', ' . date('d.m.Y', (int)strtotime($d)) . '.';
            }
            $d = AUTOMATLogik::addDays($d, 1);
        }
        return '';
    }

    /** Katalog: Was gibt es für diesen Typ im Haus? Leer, solange noch nicht gesucht wurde. */
    private function catalogLine(string $type, ?array $counts): string
    {
        if ($counts === null) {
            return '';
        }
        $def = AUTOMATKatalog::TYPES[$type];
        if (count($def['needs']) === 0) {
            return '      ✅ geht mit jeder Variable';
        }
        $missing = [];
        $have = [];
        foreach ($def['needs'] as $role) {
            if ((int)($counts[$role] ?? 0) > 0) {
                $have[] = $counts[$role] . '× ' . AUTOMATKatalog::ROLES[$role];
            } else {
                $missing[] = AUTOMATKatalog::ROLES[$role];
            }
        }
        return count($missing) === 0 ? '      ✅ passende Geräte gefunden: ' . implode(', ', $have) : '      ⚪ dafür fehlt: ' . implode(', ', $missing) . ' (oder wurde nicht erkannt — von Hand wählbar)';
    }

    private function liveText(int $vid): ?string
    {
        if (!IPS_VariableExists($vid)) {
            return null;
        }
        $v = GetValue($vid);
        if (!is_numeric($v)) {
            return null;
        }
        $num = rtrim(rtrim(number_format((float)$v, 1, ',', '.'), '0'), ',');
        $unit = $this->unitOf($vid);
        return $num . ' ' . ($unit !== '' ? $unit : 'lx');
    }

    /** AUTOMAT_UISetListOrder($id, $order) — Liste im offenen Formular nach Raum (0) oder Art (1) ordnen. */
    public function UISetListOrder(int $order): void
    {
        $this->UpdateFormField('Automations', 'sort', json_encode($this->listSort($order)));
    }

    private function listSort(int $order): array
    {
        return ['column' => $order === 1 ? 'TypeLabel' : 'Room', 'direction' => 'ascending'];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $this->WriteAttributeString('SeenNews', preg_replace('/-.*$/', '', is_array($lib) ? (string)($lib['Version'] ?? '') : ''));
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    /** AUTOMAT_Overview($id): string — Zustand aller Automatismen als Text. */
    public function Overview(): string
    {
        $rows = $this->rows();
        if (count($rows) === 0) {
            return 'ℹ️ Noch kein Automatismus eingerichtet.';
        }
        $status = $this->attrMap('Status');
        $lines = ['🏠 Hausmodus: ' . (AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?') . ((bool)$this->getOwn('Pause', false) ? ' · ⏸ ALLE PAUSIERT' : '')];
        foreach ($rows as $r) {
            $lines[] = (AUTOMATKatalog::TYPES[$r['Type']]['icon'] ?? '❔') . ' ' . $this->rowName($r) . ': ' . ($status[$r['Uid']] ?? '—');
            foreach ($this->melderHints($r) as $h) {
                $lines[] = '      ' . $h;
            }
        }
        return implode("\n", $lines);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->aBool('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Automat bündelt die kleinen Standard-Automatismen eines Hauses in einer Instanz: Treppenlicht, Bewegungslicht, Dämmerungsschaltung, Fenster offen → Heizung runter, „Tür zu lange offen“-Wächter und „Waschmaschine fertig“.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: eine Liste statt dutzender Einzel-Instanzen und Ereignisse, je Automatismus ein sichtbarer Zustand mit Grund, ein Protokoll, eine Kachel — und „Entdecken“ schlägt aus den vorhandenen Geräten vor, was sich lohnt.'],
                ['type' => 'Label', 'caption' => 'Für alle gleich: Sperre je Automatismus, globale Pause, Hausmodus (Tag/Abend/Nacht/Abwesend/Urlaub) und Respekt vor Handbedienung. Räume kommen aus dem Toolkit-Modul Raumstruktur, falls installiert. Für Urlaub und Abwesenheit gibt es die Anwesenheitssimulation, für Batterien den Batteriewächter (beide ebenfalls DG65-Toolkit).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        $seen = $this->aStr('SeenNews') ?: '0';
        $pending = [];
        foreach (self::NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => '🆕  Neu bis Version ' . array_key_last($pending), 'items' => $items];
    }

    private function DocPanel(): array
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) && isset($lib['Version']) ? 'ℹ️ Automat Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')' : 'ℹ️ Automat';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $ver],
                ['type' => 'Label', 'caption' => 'Anlegen: „Entdecken“ schlägt Automatismen aus den vorhandenen Geräten vor, „Katalog“ legt einen leeren Automatismus eines Typs an, „+“ an der Liste öffnet den Dialog direkt. Immer gilt: mit ✏️ prüfen, dann unten „Übernehmen“.'],
                ['type' => 'Label', 'caption' => 'Je Automatismus entstehen unter der Instanz: „… · Status“ (Zustand und Grund), „… · Restzeit“ (läuft mit, solange etwas ansteht: Licht aus, Absenkung, Meldung — sonst leer), „… · gesperrt“ (Schalter: Automatismus tut nichts, z. B. Putzen, Party, Handwerker) und bei Licht-Automatismen „… · Dauerlicht“. Alle drei sind auch aus WebFront, Kachel und Skript (IPS_RequestAction) bedienbar.'],
                ['type' => 'Label', 'caption' => 'Handbedienung hat Vorrang: Wer ein Licht von Hand ausschaltet, beendet die laufende Zeit; beim Bewegungslicht pausiert die Automatik danach für die eingestellte Zeit. Wer es von Hand einschaltet, bekommt beim Zeitlicht die normale Leuchtdauer, beim Bewegungslicht die längere aus Nachlauf und Handpause.'],
                ['type' => 'Label', 'caption' => 'Hausmodus: „Nacht“ nutzt bei Licht-Automatismen den Nacht-Helligkeitswert; jeder Automatismus kann auf bestimmte Modi beschränkt werden (im Dialog unter „Wann ist der Automatismus aktiv?“). Die Automatik stellt den Modus nur bei einem Übergang um — eine Handeinstellung bleibt bis zum nächsten Übergang. Urlaub wird nie automatisch gesetzt oder verlassen.'],
                ['type' => 'Label', 'caption' => 'Sperre und Pause sind „hart“ (gar nichts passiert, laufende Zeiten werden abgebrochen, ein abgesenkter Heizungs-Sollwert wird zurückgestellt). Hausmodus und Bedingung sind „weich“: es wird nichts NEU eingeschaltet oder gemeldet, laufende Zeiten laufen aber zu Ende.'],
                ['type' => 'Label', 'caption' => 'Doppelte Steuerung: Automat warnt im Dialog und bei Vorschlägen, wenn dieselben Geräte schon von einem anderen Automatismus, einem ausgelösten Ereignis oder einer anderen Instanz verwendet werden. Zwei Regler an einem Licht widersprechen sich — eine Seite abschalten.'],
                ['type' => 'Label', 'caption' => 'Skripte: AUTOMAT_Overview(<InstanzID>) liefert den Zustand aller Automatismen als Text; IPS_RequestAction(<InstanzID>, \'Hausmodus\', 0…4) bzw. \'Pause\', true/false schaltet global.'],
                ['type' => 'Button', 'caption' => '💬 Forum-Thread: Fragen, Wünsche, Fehler', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
            ],
        ];
    }

    private function AutomationsPanel(): array
    {
        $rows = $this->rows();
        $enabled = 0;
        $problems = 0;
        $locked = 0;
        foreach ($rows as $r) {
            if (empty($r['Enabled'])) {
                continue;
            }
            $enabled++;
            if (count(AUTOMATKatalog::problems($r, function (int $vid) {
                return IPS_VariableExists($vid);
            })) > 0) {
                $problems++;
            }
            if ($this->getOwn('l_' . $r['Uid'], false)) {
                $locked++;
            }
        }
        $summary = count($rows) === 0
            ? 'ℹ️ Noch kein Automatismus — unten „Entdecken“ nutzen, im „Katalog“ einen Typ wählen oder „+“ an der Liste klicken.'
            : ($problems > 0 ? '⚠️ ' : '✅ ') . count($rows) . ' Automatismen, ' . $enabled . ' aktiv'
                . ($problems > 0 ? ', ' . $problems . ' unvollständig (rot)' : '') . ($locked > 0 ? ', ' . $locked . ' gesperrt' : '')
                . ' · Hausmodus „' . (AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?') . '“' . ($this->pBool('cfg_ModeAuto') ? ' (automatisch)' : ' (Automatik aus — nur von Hand)') . ((bool)$this->getOwn('Pause', false) ? ' · ⏸ ALLE PAUSIERT' : '');
        return [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '⚙️  Automatismen',
            'items' => [
                ['type' => 'Label', 'name' => 'Summary', 'caption' => $summary],
                ['type' => 'Select', 'name' => 'cfg_ListOrder', 'caption' => 'Ordnen nach', 'width' => '320px', 'onChange' => 'AUTOMAT_UISetListOrder($id, $cfg_ListOrder);', 'options' => [
                    ['caption' => 'Raum, darin nach Art', 'value' => 0],
                    ['caption' => 'Art (Funktion), darin nach Raum', 'value' => 1],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'flt_text', 'caption' => '🔍 Suchen (Name, Raum, Art, Zustand)', 'width' => '260px', 'value' => '', 'onChange' => '$a = []; foreach ($Automations as $x) { $a[] = $x; } foreach ($AutomationsHidden as $x) { $a[] = $x; } AUTOMAT_UIFilterList($id, json_encode($a), (string)$flt_text, (string)$flt_room, (string)$flt_cat, (string)$flt_state);'],
                    ['type' => 'Select', 'name' => 'flt_room', 'caption' => 'Raum', 'width' => '170px', 'value' => '', 'options' => $this->filterRoomOptions($rows), 'onChange' => '$a = []; foreach ($Automations as $x) { $a[] = $x; } foreach ($AutomationsHidden as $x) { $a[] = $x; } AUTOMAT_UIFilterList($id, json_encode($a), (string)$flt_text, (string)$flt_room, (string)$flt_cat, (string)$flt_state);'],
                    ['type' => 'Select', 'name' => 'flt_cat', 'caption' => 'Funktion / Art', 'width' => '230px', 'value' => '', 'options' => $this->filterCatOptions(), 'onChange' => '$a = []; foreach ($Automations as $x) { $a[] = $x; } foreach ($AutomationsHidden as $x) { $a[] = $x; } AUTOMAT_UIFilterList($id, json_encode($a), (string)$flt_text, (string)$flt_room, (string)$flt_cat, (string)$flt_state);'],
                    ['type' => 'Select', 'name' => 'flt_state', 'caption' => 'Zustand', 'width' => '190px', 'value' => '', 'options' => [
                        ['caption' => 'alle', 'value' => ''],
                        ['caption' => '⚠️ auffällig / unvollständig', 'value' => 'warn'],
                        ['caption' => '▶️ läuft gerade', 'value' => 'run'],
                        ['caption' => '✅ aktiv', 'value' => 'on'],
                        ['caption' => '⚫ nicht aktiv', 'value' => 'off'],
                        ['caption' => '🔒 gesperrt', 'value' => 'locked'],
                        ['caption' => '🧪 Probelauf', 'value' => 'dry'],
                    ], 'onChange' => '$a = []; foreach ($Automations as $x) { $a[] = $x; } foreach ($AutomationsHidden as $x) { $a[] = $x; } AUTOMAT_UIFilterList($id, json_encode($a), (string)$flt_text, (string)$flt_room, (string)$flt_cat, (string)$flt_state);'],
                ]],
                ['type' => 'Label', 'name' => 'FilterInfo', 'caption' => '', 'visible' => false],
                [
                    'type' => 'List', 'name' => 'AutomationsHidden', 'caption' => 'ausgefiltert', 'visible' => false, 'rowCount' => 1, 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false, 'values' => [],
                    'columns' => [
                        ['caption' => 'Kennung', 'name' => 'Uid', 'width' => '80px', 'add' => '', 'save' => true],
                        ['caption' => 'Typ', 'name' => 'Type', 'width' => '80px', 'add' => '', 'save' => true],
                        ['caption' => 'Daten', 'name' => 'Data', 'width' => '80px', 'add' => '', 'save' => true],
                        ['caption' => 'Filterstand', 'name' => 'Gen', 'width' => '80px', 'add' => '', 'save' => true],
                    ],
                ],
                [
                    'type' => 'List', 'name' => 'Automations', 'caption' => 'Automatismen', 'rowCount' => max(12, min(25, count($rows) + 3)),
                    'add' => true, 'delete' => true, 'loadValuesFromConfiguration' => false, 'values' => $this->listValues($rows),
                    'sort' => $this->listSort($this->pInt('cfg_ListOrder')),
                    'columns' => [
                        ['caption' => '☑︎', 'name' => 'Mark', 'width' => '40px', 'add' => false, 'save' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Aktiv', 'name' => 'Enabled', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Raum', 'name' => 'Room', 'width' => '120px', 'add' => '', 'save' => true, 'sortColumn' => 'SortRoom'],
                        ['caption' => 'Art', 'name' => 'TypeLabel', 'width' => '210px', 'add' => '', 'save' => true, 'sortColumn' => 'SortType'],
                        ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '200px', 'add' => '', 'save' => true],
                        ['caption' => 'Einstellungen', 'name' => 'Settings', 'width' => 'auto', 'add' => '', 'save' => false],
                        ['caption' => 'Zustand', 'name' => 'State', 'width' => '340px', 'add' => '', 'save' => false],
                        ['caption' => 'Sortierung Raum', 'name' => 'SortRoom', 'width' => '10px', 'add' => '', 'save' => false, 'visible' => false],
                        ['caption' => 'Sortierung Art', 'name' => 'SortType', 'width' => '10px', 'add' => '', 'save' => false, 'visible' => false],
                        ['caption' => 'Typ', 'name' => 'Type', 'width' => '80px', 'add' => '', 'save' => true, 'visible' => false],
                        ['caption' => 'Kennung', 'name' => 'Uid', 'width' => '80px', 'add' => '', 'save' => true, 'visible' => false],
                        ['caption' => 'Filterstand', 'name' => 'Gen', 'width' => '80px', 'add' => '', 'save' => true, 'visible' => false],
                    ],
                    'form' => "return json_decode(AUTOMAT_UIEditFormRow(\$id, json_encode(\$Automations)), true);",
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'cfg_CopyRoom', 'caption' => 'Angehakte (☑︎) duplizieren — in Raum', 'width' => '320px', 'value' => '', 'options' => $this->copyRoomOptions()],
                    ['type' => 'Button', 'caption' => '📄 Duplizieren', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIDuplicate($id, json_encode($r), (string)$cfg_CopyRoom);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Label', 'caption' => 'Angehakte (☑︎) von Hand:'],
                    ['type' => 'Button', 'caption' => '▶ Jetzt ausführen', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIRunMarked($id, json_encode($r), \'\');'],
                    ['type' => 'Button', 'caption' => '⬇ Rollläden schließen', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIRunMarked($id, json_encode($r), \'down\');'],
                    ['type' => 'Button', 'caption' => '⬆ Rollläden öffnen', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIRunMarked($id, json_encode($r), \'up\');'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '📋 Übersicht anzeigen', 'onClick' => 'echo AUTOMAT_Overview($id);'],
                    ['type' => 'PopupButton', 'caption' => 'Wie lege ich einen Automatismus an?', 'width' => '420px', 'popup' => [
                        'caption' => 'Wie lege ich einen Automatismus an?',
                        'items' => [
                            ['type' => 'Label', 'caption' => '1. Am einfachsten über „🔎 Entdecken“: Vorschläge anhaken, „Angehakte in die Liste übernehmen“.'],
                            ['type' => 'Label', 'caption' => '2. Oder im „📚 Katalog“ einen Typ wählen und „Leeren Automatismus anlegen“.'],
                            ['type' => 'Label', 'caption' => '3. Oder „+“ an der Liste: Im Dialog zuerst die Art wählen, dann Geräte und Zeiten.'],
                            ['type' => 'Label', 'caption' => 'Danach mit ✏️ prüfen und unten „Übernehmen“ klicken — erst dann arbeitet der Automatismus. Rot markierte Zeilen sind unvollständig, die Spalte „Zustand“ sagt, was fehlt.'],
                        ],
                    ]],
                ]],
            ],
        ];
    }

    private function DiscoverPanel(): array
    {
        $src = $this->aStr('RoomSource');
        if ($src === '') {
            $src = count(@IPS_GetInstanceListByModuleID(self::RAUM_GUID) ?: []) > 0
                ? 'ℹ️ Raumstruktur gefunden — die Räume werden bei der Suche übernommen.'
                : 'ℹ️ Raumstruktur nicht installiert — Räume werden aus den Gerätenamen abgeleitet (z. B. „Licht Flur“ + „Bewegungsmelder Flur“ = Flur).';
        }
        $order = $this->pInt('cfg_SuggestOrder');
        [$sgRooms, $sgTypes] = $this->suggestFilterOptions();
        $n = count($this->suggestions());
        return [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔎  Entdecken — Vorschläge aus deinen Geräten',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Jetzt Vorschläge suchen', 'onClick' => 'echo AUTOMAT_Discover($id);'],
                ['type' => 'Label', 'name' => 'DiscoverHead', 'caption' => $this->discoverHeadLine()],
                ['type' => 'Label', 'name' => 'RoomSource', 'caption' => $src, 'color' => strpos($src, '🔗') !== false ? 0x2E8B3D : -1],
                ['type' => 'Select', 'name' => 'cfg_SuggestOrder', 'caption' => 'Ordnen nach', 'width' => '320px', 'onChange' => 'AUTOMAT_UISetSuggestOrder($id, $cfg_SuggestOrder);', 'options' => [
                    ['caption' => 'Raum, darin nach Art', 'value' => 0],
                    ['caption' => 'Art (Funktion), darin nach Raum', 'value' => 1],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'sg_text', 'caption' => '🔍 Suchen', 'width' => '260px', 'value' => '', 'onChange' => 'AUTOMAT_UIFilterSuggestions($id, (string)$sg_text, (string)$sg_room, (string)$sg_type);'],
                    ['type' => 'Select', 'name' => 'sg_room', 'caption' => 'Raum', 'width' => '170px', 'value' => '', 'options' => $sgRooms, 'onChange' => 'AUTOMAT_UIFilterSuggestions($id, (string)$sg_text, (string)$sg_room, (string)$sg_type);'],
                    ['type' => 'Select', 'name' => 'sg_type', 'caption' => 'Art', 'width' => '260px', 'value' => '', 'options' => $sgTypes, 'onChange' => 'AUTOMAT_UIFilterSuggestions($id, (string)$sg_text, (string)$sg_room, (string)$sg_type);'],
                ]],
                [
                    'type' => 'List', 'name' => 'SuggestList', 'caption' => 'Vorschläge (gelb = dieselben Geräte werden schon anderswo geschaltet)', 'rowCount' => max(5, min(15, $n)),
                    'add' => false, 'delete' => false, 'loadValuesFromConfiguration' => false, 'values' => $this->suggestionRows(), 'sort' => $this->suggestionSort($order),
                    'columns' => [
                        ['caption' => '✔', 'name' => 'Pick', 'width' => '45px', 'save' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Raum', 'name' => 'Room', 'width' => '120px', 'save' => false, 'sortColumn' => 'SortRoom'],
                        ['caption' => 'Art', 'name' => 'TypeLabel', 'width' => '230px', 'save' => false, 'sortColumn' => 'SortType'],
                        ['caption' => 'Vorschlag', 'name' => 'Title', 'width' => '240px', 'save' => false],
                        ['caption' => 'Warum', 'name' => 'Why', 'width' => 'auto', 'save' => false],
                        ['caption' => 'Hinweise', 'name' => 'Hints', 'width' => '320px', 'save' => false],
                        ['caption' => 'Sortierung Raum', 'name' => 'SortRoom', 'width' => '10px', 'save' => false, 'visible' => false],
                        ['caption' => 'Sortierung Art', 'name' => 'SortType', 'width' => '10px', 'save' => false, 'visible' => false],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '10px', 'save' => false, 'visible' => false],
                    ],
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '➕ Angehakte in die Liste übernehmen', 'onClick' => self::PICKED_KEYS . ' $r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIAdoptMany($id, json_encode($k), json_encode($r));'],
                    ['type' => 'Button', 'caption' => '🙈 Angehakte ausblenden', 'onClick' => self::PICKED_KEYS . ' echo AUTOMAT_DismissSuggestions($id, json_encode($k));'],
                ]],
                ['type' => 'Button', 'name' => 'RestoreSuggestionsBtn', 'caption' => 'Ausgeblendete Vorschläge wieder anzeigen', 'visible' => count($this->dismissed()) > 0, 'onClick' => 'echo AUTOMAT_RestoreSuggestions($id);'],
                ['type' => 'Label', 'caption' => 'ℹ️ Gesucht wird nach Bewegungsmeldern, Lichtern (Schalter/Dimmer), Fenster-/Türkontakten, Heizungs-Sollwerten, Helligkeitssensoren und Leistungsmessungen von Haushaltsgeräten. ⚠️ bzw. gelb heißt: dieselben Geräte werden schon anderswo geschaltet — Details mit „Öffnen“-Knopf im Bearbeiten-Dialog nach dem Übernehmen.'],
            ],
        ];
    }

    private function CatalogPanel(): array
    {
        $counts = json_decode($this->aStr('RoleCounts'), true);
        $items = [['type' => 'Label', 'caption' => 'Alle Arten von Automatismen. Ob passende Geräte da sind, steht nach „Jetzt Vorschläge suchen“ darunter.']];
        foreach (AUTOMATKatalog::CATEGORIES as $cat => $catLabel) {
            $items[] = ['type' => 'Label', 'caption' => $catLabel, 'bold' => true];
            foreach (AUTOMATKatalog::TYPES as $t => $def) {
                if ($def['cat'] !== $cat) {
                    continue;
                }
                $items[] = ['type' => 'Label', 'caption' => $def['icon'] . ' ' . $def['label'] . ' — ' . $def['desc']];
                $line = $this->catalogLine($t, is_array($counts) ? $counts : null);
                $items[] = ['type' => 'Label', 'name' => 'cat_' . $t, 'caption' => $line, 'visible' => $line !== ''];
            }
        }
        $opts = [['caption' => '— Art wählen —', 'value' => '']];
        foreach (AUTOMATKatalog::TYPES as $t => $def) {
            $opts[] = ['caption' => $def['icon'] . ' ' . $def['label'], 'value' => $t];
        }
        $items[] = ['type' => 'Select', 'name' => 'cfg_NewType', 'caption' => 'Neu anlegen', 'options' => $opts, 'value' => '', 'width' => '420px'];
        $items[] = ['type' => 'Button', 'caption' => '➕ Leeren Automatismus dieser Art anlegen', 'onClick' => '$r = []; foreach ($Automations as $x) { $r[] = $x; } echo AUTOMAT_UIAddType($id, (string)$cfg_NewType, json_encode($r));'];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📚  Katalog', 'items' => $items];
    }

    private function modeAutoLine(bool $auto): string
    {
        return $auto
            ? '✅ Automatik an — der Hausmodus wechselt nach den Einstellungen unten; eine Änderung von Hand gilt bis zum nächsten Wechsel.'
            : 'ℹ️ Automatik aus — der Hausmodus ändert sich nur von Hand, per Kachel oder Skript (Nachtwerte beim Licht greifen dann nur, wenn „Nacht“ von Hand gesetzt ist).';
    }

    /** AUTOMAT_UIModeAutoChanged($id, $auto) — Zustandszeile im offenen Formular nachführen (gespeichert wird mit „Übernehmen“). */
    public function UIModeAutoChanged(bool $auto): void
    {
        $this->UpdateFormField('ModeAutoState', 'caption', $this->modeAutoLine($auto) . ' (noch nicht übernommen)');
    }

    /**
     * AUTOMAT_UIRefChanged($id, $day, $eve, $night) — nur die zur Auswahl passenden Felder zeigen (Uhrzeit bzw.
     * Versatz) und die Infozeile zur Location Control nachführen.
     */
    public function UIRefChanged(int $day, int $eve, int $night): void
    {
        $this->UpdateFormField('cfg_DayFrom', 'visible', $day === 0);
        $this->UpdateFormField('cfg_DayOffset', 'visible', isset(self::DAY_EVENTS[$day]));
        $this->UpdateFormField('cfg_EveningFrom', 'visible', $eve === 1);
        $this->UpdateFormField('cfg_EveningOffset', 'visible', isset(self::EVE_EVENTS[$eve]));
        $this->UpdateFormField('cfg_NightFrom', 'visible', !isset(self::NIGHT_EVENTS[$night]));
        $this->UpdateFormField('cfg_NightOffset', 'visible', isset(self::NIGHT_EVENTS[$night]));
        // Der Helligkeitssensor ist zentral (auch für Rollläden und Dämmerung) und bleibt deshalb immer sichtbar.
        $line = $this->locationLine($day, $eve, $night);
        $this->UpdateFormField('LocationInfo', 'caption', $line);
        $this->UpdateFormField('LocationInfo', 'visible', $line !== '');
    }

    /** Helligkeitssensor, wenn er gewählt und für Tag oder Abend genutzt wird (sonst 0). */
    /** Zentraler Helligkeitssensor („🏠 Hausmodus“) — für Hausmodus, Rollläden und Dämmerung (0 = keiner). */
    private function lightVar(): int
    {
        $vid = $this->pInt('cfg_LightVar');
        return $vid >= AUTOMATKatalog::$minId && IPS_VariableExists($vid) ? $vid : 0;
    }

    /** Hell (true) / dunkel (false) nach dem Sensor, mit Haltezeit; null ohne Sensor. */
    private function lightDayNow(int $now): ?bool
    {
        $vid = $this->lightVar();
        if ($vid === 0) {
            return null;
        }
        $st = AUTOMATLogik::lightDay(json_decode($this->aStr('LightState'), true) ?: [], (float)GetValue($vid), $now,
            $this->pFloat('cfg_LightDay'), $this->pFloat('cfg_LightNight'), max(0, $this->pInt('cfg_LightHold')) * 60);
        $this->WriteAttributeString('LightState', json_encode($st));
        return (bool)$st['day'];
    }

    /** Einheit einer Variable (Darstellung oder Profil), z. B. „W/m²“ oder „lx“. */
    private function unitOf(int $vid): string
    {
        $v = IPS_GetVariable($vid);
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || count($pres) === 0) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        $suffix = is_array($pres) ? (string)($pres['SUFFIX'] ?? '') : '';
        // Profil: eigenes, Standard oder in der Darstellung eingebettet (Darstellung „Profil“)
        $profile = (string)(($v['VariableCustomProfile'] ?? '') ?: (($v['VariableProfile'] ?? '') ?: (is_array($pres) ? ($pres['PROFILE'] ?? '') : '')));
        if ($suffix === '' && $profile !== '' && IPS_VariableProfileExists($profile)) {
            $suffix = (string)(IPS_GetVariableProfile($profile)['Suffix'] ?? '');
        }
        return trim($suffix);
    }

    /**
     * Mögliche Helligkeits-/Einstrahlungssensoren (für den Hinweis im Formular), höchstens 3. Einstrahlung
     * (Name/Einheit W/m²) vor reinen Lux-Sensoren — die hängen oft drinnen an Bewegungsmeldern.
     */
    private function lightCandidates(): array
    {
        $out = [];
        $score = [];
        foreach (IPS_GetVariableList() as $vid) {
            $v = IPS_GetVariable($vid);
            if (!in_array((int)$v['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                continue;
            }
            $unit = mb_strtolower($this->unitOf($vid));
            $profile = mb_strtolower((string)(($v['VariableCustomProfile'] ?? '') ?: ($v['VariableProfile'] ?? '')));
            $name = mb_strtolower(IPS_GetName($vid));
            if (preg_match('#w/m|lx|lux#', $unit) || preg_match('/illumin|radiation|strahlung/', $profile)
                || preg_match('/einstrahlung|globalstrahlung|strahlung|solar ?radiation|helligkeit|illuminance/', $name) && $unit !== '%') {
                $label = $this->varLabel($vid) . ' (#' . $vid . ')';
                $out[$label] = (preg_match('/strahlung|radiation|solar/', $name) || strpos($unit, 'w/m') !== false ? 2 : 0)
                    + (preg_match('/wetter|weather|außen|aussen|outdoor/i', IPS_GetName(IPS_GetParent($vid)) . ' ' . $name) ? 1 : 0);
            }
        }
        arsort($out);
        return array_slice(array_keys($out), 0, 3);
    }

    /** Hinweis unter den Sensorfeldern: Vorschläge, solange keiner gewählt ist, und Richtwerte. */
    private function lightHint(): string
    {
        $text = 'Richtwerte: Einstrahlung (W/m²) hell ab 50, dunkel unter 20 · Helligkeit (lx) hell ab 1000, dunkel unter 300. '
            . 'Manche Wetterstationen zeigen die Einstrahlung mit „lx“ an, obwohl sie W/m² liefert — maßgeblich sind die Zahlen. '
            . 'Die Haltezeit verhindert, dass eine Wolke umschaltet.';
        $lv = $this->pInt('cfg_LightVar');
        if ($lv >= AUTOMATKatalog::$minId && IPS_VariableExists($lv)) {
            return $text;
        }
        $cand = $this->lightCandidates();
        return (count($cand) > 0 ? '💡 Passende Sensoren gefunden: ' . implode(', ', $cand) . ' — oben auswählen. ' : '') . $text;
    }

    /** Auswahl-Eintrag für einen Zeitpunkt der Location Control, mit der heutigen Uhrzeit. */
    private function eventOption(int $value, string $ident): array
    {
        $t = $this->sunOn($ident, date('Y-m-d', $this->now()));
        return ['caption' => self::EVENT_LABELS[$ident] . ($t !== null ? ' (heute ' . date('H:i', $t) . ' Uhr)' : ''), 'value' => $value];
    }

    /**
     * Was die gewählten Einstellungen aus der Location Control lesen — mit den heutigen Werten.
     * Leer, wenn nichts davon gebraucht wird (alles „zur Uhrzeit“).
     */
    private function locationLine(int $day, int $eve, int $night): string
    {
        $idents = array_values(array_filter([self::DAY_EVENTS[$day] ?? null, self::EVE_EVENTS[$eve] ?? null, self::NIGHT_EVENTS[$night] ?? null]));
        $needIsDay = $day === 2 || $eve === 2;
        $needLight = $day === 6 || $eve === 6;
        if (count($idents) === 0 && !$needIsDay && !$needLight) {
            return '';
        }
        $p = [];
        if ($needLight) {
            $vid = $this->pInt('cfg_LightVar');
            if ($vid >= AUTOMATKatalog::$minId && IPS_VariableExists($vid)) {
                $st = json_decode($this->aStr('LightState'), true) ?: [];
                $p[] = 'Sensor „' . $this->varLabel($vid) . '“ gerade ' . round((float)GetValue($vid), 1) . ' ' . $this->unitOf($vid)
                    . (isset($st['day']) ? ' (' . ($st['day'] ? 'hell' : 'dunkel') . ')' : '');
            } else {
                $p[] = '⚠️ Helligkeitssensor noch nicht gewählt — bis dahin gelten die Uhrzeiten';
            }
        }
        if (count($idents) === 0 && !$needIsDay) {
            return 'ℹ️ ' . implode(' · ', $p) . '.';
        }
        if ($this->locationVariable('Sunset') === 0) {
            return '⚠️ Keine Location-Control-Instanz gefunden — bis eine angelegt ist, gelten die Uhrzeiten aus den Einstellungen.' . (count($p) > 0 ? ' ' . implode(' · ', $p) . '.' : '');
        }
        $today = date('Y-m-d', $this->now());
        foreach (array_unique($idents) as $ident) {
            $t = $this->sunOn($ident, $today);
            $p[] = self::EVENT_LABELS[$ident] . ' heute ' . ($t !== null ? date('H:i', $t) . ' Uhr' : 'nicht vorhanden (dann gilt die Uhrzeit)');
        }
        if ($needIsDay) {
            $vid = $this->locationVariable('IsDay');
            $p[] = '„Ist es Tag“ gerade: ' . ($vid > 0 ? ((bool)GetValue($vid) ? 'ja' : 'nein') : 'nicht vorhanden');
        }
        $sensor = $needLight ? array_shift($p) : null;
        return 'ℹ️ Aus der Location Control: ' . implode(' · ', $p) . '.' . ($sensor !== null ? ' ' . $sensor . '.' : '');
    }

    private function ModePanel(): array
    {
        $dayRef = $this->pInt('cfg_DayRef');
        $eveRef = $this->pInt('cfg_EveningRef');
        $nightRef = $this->pInt('cfg_NightRef');
        $onRef = 'AUTOMAT_UIRefChanged($id, $cfg_DayRef, $cfg_EveningRef, $cfg_NightRef);';
        $dayOpts = [['caption' => 'zur Uhrzeit', 'value' => 0]];
        foreach (self::DAY_EVENTS as $v => $ident) {
            $dayOpts[] = $this->eventOption($v, $ident);
        }
        $dayOpts[] = ['caption' => 'wenn „Ist es Tag“ (Location Control) wahr wird', 'value' => 2];
        $dayOpts[] = ['caption' => 'wenn es hell wird (Helligkeits-/Einstrahlungssensor, z. B. Wetterstation)', 'value' => 6];
        $eveOpts = [];
        foreach (self::EVE_EVENTS as $v => $ident) {
            $eveOpts[] = $this->eventOption($v, $ident);
        }
        $eveOpts[] = ['caption' => 'zur Uhrzeit', 'value' => 1];
        $eveOpts[] = ['caption' => 'wenn „Ist es Tag“ (Location Control) falsch wird', 'value' => 2];
        $eveOpts[] = ['caption' => 'wenn es dunkel wird (Helligkeits-/Einstrahlungssensor, z. B. Wetterstation)', 'value' => 6];
        $useLight = true; // zentraler Sensor: immer zeigen
        $lightUnit = '';
        $lv = $this->pInt('cfg_LightVar');
        if ($lv >= AUTOMATKatalog::$minId && IPS_VariableExists($lv)) {
            $lightUnit = $this->unitOf($lv);
        }
        $lightHint = $useLight ? $this->lightHint() : '';
        $nightOpts = [['caption' => 'zur Uhrzeit', 'value' => 0]];
        foreach (self::NIGHT_EVENTS as $v => $ident) {
            $nightOpts[] = $this->eventOption($v, $ident);
        }
        $mode = AUTOMATKatalog::MODES[(int)$this->getOwn('Hausmodus', 0)] ?? '?';
        $auto = $this->pBool('cfg_ModeAuto');
        [, $presText] = $this->presenceSource();
        // Mit „Anwesenheit zusammenfassen“ ist das Feld überflüssig — nur zeigen, wenn dort eine fremde Quelle steht.
        $presField = $this->hasPresenceRow();
        $presShow = !$presField || $this->externalPresenceVar() > 0;
        $own = $this->pInt('cfg_PresenceVar');
        if ($own >= AUTOMATKatalog::$minId && IPS_VariableExists($own) && $this->isOwnPresenceVar($own)) {
            $presText .= ' Im Feld „Anwesenheits-Variable“ steht noch die Ergebnisvariable selbst — das ist nicht nötig, sie wird ohnehin genutzt.';
            $presShow = true;
        }
        $loc = $this->locationLine($dayRef, $eveRef, $nightRef);
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🏠  Hausmodus (aktuell: ' . $mode . ($auto ? ', wechselt automatisch' : ', Automatik aus') . ')',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Hausmodus ist eine Variable dieser Instanz (Tag, Abend, Nacht, Abwesend, Urlaub) — von Hand, per Kachel, per Skript oder hier automatisch gesetzt. Automatismen können auf Modi beschränkt werden; Licht nutzt bei „Nacht“ den Nachtwert.'],
                ['type' => 'CheckBox', 'name' => 'cfg_ModeAuto', 'caption' => 'Hausmodus automatisch nach Tageszeit umschalten: Tag, Abend, Nacht (wann genau, legst du darunter fest — z. B. Abend ab Sonnenuntergang)', 'onChange' => 'AUTOMAT_UIModeAutoChanged($id, $cfg_ModeAuto);'],
                ['type' => 'Label', 'name' => 'ModeAutoState', 'caption' => $this->modeAutoLine($auto)],
                ['type' => 'Select', 'name' => 'cfg_DayRef', 'caption' => 'Tag beginnt', 'width' => '520px', 'onChange' => $onRef, 'options' => $dayOpts],
                ['type' => 'SelectTime', 'name' => 'cfg_DayFrom', 'caption' => 'Tag beginnt um', 'visible' => $dayRef === 0],
                ['type' => 'NumberSpinner', 'name' => 'cfg_DayOffset', 'caption' => 'Versatz (− = früher, + = später)', 'suffix' => ' min', 'minimum' => -240, 'maximum' => 240, 'visible' => isset(self::DAY_EVENTS[$dayRef])],
                ['type' => 'Select', 'name' => 'cfg_EveningRef', 'caption' => 'Abend beginnt', 'width' => '520px', 'onChange' => $onRef, 'options' => $eveOpts],
                ['type' => 'SelectTime', 'name' => 'cfg_EveningFrom', 'caption' => 'Abend beginnt um', 'visible' => $eveRef === 1],
                ['type' => 'NumberSpinner', 'name' => 'cfg_EveningOffset', 'caption' => 'Versatz (− = früher, + = später)', 'suffix' => ' min', 'minimum' => -240, 'maximum' => 240, 'visible' => isset(self::EVE_EVENTS[$eveRef])],
                ['type' => 'Label', 'name' => 'LightHead', 'caption' => '— Helligkeit draußen (für Hausmodus, Rollläden „wenn es dunkel wird“ und Dämmerung) —', 'bold' => true, 'visible' => $useLight],
                ['type' => 'SelectVariable', 'name' => 'cfg_LightVar', 'caption' => 'Helligkeits- oder Einstrahlungssensor (z. B. Wetterstation, Globalstrahlung)', 'width' => '520px', 'visible' => $useLight],
                ['type' => 'NumberSpinner', 'name' => 'cfg_LightDay', 'caption' => 'Hell ab', 'digits' => 0, 'suffix' => $lightUnit !== '' ? ' ' . $lightUnit : '', 'minimum' => 0, 'maximum' => 200000, 'visible' => $useLight],
                ['type' => 'NumberSpinner', 'name' => 'cfg_LightNight', 'caption' => 'Dunkel unter', 'digits' => 0, 'suffix' => $lightUnit !== '' ? ' ' . $lightUnit : '', 'minimum' => 0, 'maximum' => 200000, 'visible' => $useLight],
                ['type' => 'NumberSpinner', 'name' => 'cfg_LightHold', 'caption' => 'Haltezeit (so lange muss der Wert jenseits der Schwelle bleiben)', 'suffix' => ' min', 'minimum' => 0, 'maximum' => 120, 'visible' => $useLight],
                ['type' => 'Label', 'name' => 'LightHint', 'caption' => $lightHint, 'visible' => $useLight],
                ['type' => 'Select', 'name' => 'cfg_NightRef', 'caption' => 'Nacht beginnt', 'width' => '520px', 'onChange' => $onRef, 'options' => $nightOpts],
                ['type' => 'SelectTime', 'name' => 'cfg_NightFrom', 'caption' => 'Nacht beginnt um', 'visible' => !isset(self::NIGHT_EVENTS[$nightRef])],
                ['type' => 'NumberSpinner', 'name' => 'cfg_NightOffset', 'caption' => 'Versatz (− = früher, + = später)', 'suffix' => ' min', 'minimum' => -240, 'maximum' => 240, 'visible' => isset(self::NIGHT_EVENTS[$nightRef])],
                ['type' => 'Label', 'name' => 'LocationInfo', 'caption' => $loc, 'visible' => $loc !== ''],
                ['type' => 'Select', 'name' => 'cfg_Bundesland', 'caption' => 'Feiertage wie Wochenende behandeln (Rollläden, Aufwachlicht, Zeitschaltuhr)', 'width' => '520px', 'options' => array_map(function ($k, $v) {
                    return ['caption' => $v, 'value' => (string)$k];
                }, array_keys(AUTOMATKatalog::LAENDER), AUTOMATKatalog::LAENDER)],
                ['type' => 'Label', 'caption' => $this->holidayLine()],
                ['type' => 'Label', 'caption' => '— Anwesenheit —', 'bold' => true],
                ['type' => 'Label', 'name' => 'PresenceSource', 'caption' => $presText, 'color' => strpos($presText, '🔗') !== false ? 0x2E8B3D : -1],
                ['type' => 'SelectVariable', 'name' => 'cfg_PresenceVar', 'caption' => $presField ? 'Andere Anwesenheits-Quelle (hat Vorrang vor dem Automatismus; Bool: wahr = jemand zu Hause)' : 'Anwesenheits-Variable (nur nötig ohne Automatismus „Anwesenheit zusammenfassen“; Bool: wahr = jemand zu Hause)', 'width' => '520px', 'visible' => $presShow],
                ['type' => 'CheckBox', 'name' => 'cfg_PresenceInvert', 'caption' => 'Variable ist umgekehrt (wahr = niemand da, z. B. „Abwesend“)', 'visible' => $presShow],
                ['type' => 'Label', 'caption' => 'ℹ️ Bei „niemand da“ wird automatisch „Abwesend“ gesetzt, beim Heimkommen wieder der Tageszeit-Modus. „Urlaub“ setzt und beendet nur der Nutzer.'],
            ],
        ];
    }

    private function NotifyPanel(): array
    {
        $ways = $this->ways();
        $names = array_column($ways, 'name', 'id');
        $std = array_column(array_filter($ways, function ($w) {
            return $w['std'];
        }), 'name');
        $who = [];
        foreach ($this->rows() as $r) {
            if (!empty($r['Enabled']) && !empty($r[self::NOTIFY_FIELD[$r['Type']] ?? ''])) {
                $who[] = '„' . $this->rowName($r) . '“ → ' . (implode(', ', array_column($this->waysFor($r), 'name')) ?: '— kein Weg —');
            }
        }
        $rows = [];
        foreach ($ways as $w) {
            $rows[] = ['Id' => $w['id'], 'Name' => $w['name'], 'Typ' => $w['typ'], 'Target' => $w['target'], 'To' => $w['to'], 'Std' => $w['std'], 'Quiet' => $w['quiet']];
        }
        $legacy = (json_decode($this->pStr('cfg_Ways'), true) ?: []) === [] && count($ways) > 0;
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🔔  Meldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '— Meldewege —', 'bold' => true],
                ['type' => 'Label', 'caption' => 'Lege hier fest, WIE gemeldet werden kann — beliebig viele Wege, z. B. „Mein Handy“ (Push an ein WebFront), „Kachel Küche“ (Push an eine Kachel-Visualisierung), „E-Mail Familie“ oder „Telegram“ (ein Skript, das die Meldung weiterreicht). Welcher Automatismus welchen Weg nutzt, wählst du in seinem Dialog (✏️); ohne Haken dort gelten die Standard-Wege.'],
                [
                    'type' => 'List', 'name' => 'cfg_Ways', 'caption' => 'Meldewege', 'rowCount' => max(3, count($rows) + 1), 'add' => true, 'delete' => true,
                    'loadValuesFromConfiguration' => false, 'values' => $rows,
                    'columns' => [
                        ['caption' => 'Kennung', 'name' => 'Id', 'width' => '0px', 'visible' => false, 'save' => true, 'add' => 'w' . substr(md5((string)microtime(true)), 0, 6)],
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '220px', 'add' => 'Neuer Meldeweg', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Art', 'name' => 'Typ', 'width' => '150px', 'add' => 'push', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Push', 'value' => 'push'],
                            ['caption' => 'E-Mail', 'value' => 'mail'],
                            ['caption' => 'Skript', 'value' => 'script'],
                        ]]],
                        ['caption' => 'Ziel (Push: Visualisierung, leer = alle · E-Mail: SMTP-Instanz, leer = erste · Skript)', 'name' => 'Target', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectObject']],
                        ['caption' => 'Empfänger (nur E-Mail, mehrere mit Komma)', 'name' => 'To', 'width' => '260px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Standard', 'name' => 'Std', 'width' => '90px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ruhezeit beachten', 'name' => 'Quiet', 'width' => '140px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
                ['type' => 'Label', 'caption' => $legacy
                    ? 'ℹ️ Diese Wege stammen aus deinen bisherigen Einstellungen (Push/E-Mail). Mit „Übernehmen“ werden sie als Liste gespeichert — danach kannst du sie umbenennen, ergänzen und je Automatismus wählen.'
                    : (count($ways) === 0 ? '⚠️ Kein Meldeweg — Meldungen stehen nur in „Letzte Meldung“ und im Protokoll.' : '✅ Standard-Wege: ' . (implode(', ', $std) ?: '— keiner (dann melden nur Automatismen mit eigener Wahl) —') . '.')],
                ['type' => 'Label', 'caption' => 'ℹ️ Skript-Wege bekommen $_IPS[\'TITLE\'], $_IPS[\'TEXT\'] und $_IPS[\'MEDIA\'] (ID eines Kamerabilds, z. B. von der Türklingel, sonst 0) — so lässt sich jede Benachrichtigung anbinden (Telegram, Pushover, Sprachausgabe …).'],
                ['type' => 'Label', 'caption' => '— Was wird gemeldet —', 'bold' => true],
                ['type' => 'Label', 'caption' => count($who) > 0
                    ? '🔔 ' . implode(' · ', $who) . '. Ein- und ausschalten und Wege wählen je Automatismus im Dialog (✏️).'
                    : 'ℹ️ Kein Automatismus meldet etwas. Melden können Wächter, „Gerät fertig“ und „Anwesenheit zusammenfassen“ — im Dialog (✏️) „Melden“ ankreuzen.'],
                ['type' => 'CheckBox', 'name' => 'cfg_NotifyFaults', 'caption' => 'Auch Störungen melden (ein Gerät ließ sich nicht schalten) — über die Standard-Wege'],
                ['type' => 'Label', 'caption' => '— Ruhezeit —', 'bold' => true],
                ['type' => 'CheckBox', 'name' => 'cfg_QuietEnabled', 'caption' => 'Ruhezeit: Wege mit „Ruhezeit beachten“ melden nachts nicht (die Meldung wird danach nachgeholt)'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'cfg_QuietFrom', 'caption' => 'Ruhezeit von', 'suffix' => ' Uhr', 'minimum' => 0, 'maximum' => 23],
                    ['type' => 'NumberSpinner', 'name' => 'cfg_QuietTo', 'caption' => 'bis', 'suffix' => ' Uhr', 'minimum' => 0, 'maximum' => 23],
                ]],
                ['type' => 'Button', 'caption' => '📨 Testmeldung über alle Meldewege', 'onClick' => 'echo AUTOMAT_TestMessage($id);'],
                ['type' => 'Label', 'caption' => 'ℹ️ Die Testmeldung nutzt die gespeicherten Einstellungen — nach Änderungen erst „Übernehmen“. Jede Meldung steht außerdem in der Variable „Letzte Meldung“.'],
            ],
        ];
    }

    private function ProtocolPanel(): array
    {
        [$stats, $idle] = $this->statsRows();
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📜  Protokoll und Kachel',
            'items' => [
                ['type' => 'Label', 'caption' => '— Statistik (30 Tage) —', 'bold' => true],
                ['type' => 'Label', 'caption' => count($idle) > 0 ? '💤 Seit 30 Tagen nichts geschaltet oder gemeldet: ' . implode(', ', $idle) . ' — noch gebraucht, oder stimmen die Geräte?' : 'ℹ️ Schaltungen und Meldungen je Automatismus; „💤“-Hinweise erscheinen, wenn ein aktiver Automatismus 30 Tage nichts getan hat.'],
                [
                    'type' => 'List', 'name' => 'StatsList', 'caption' => '', 'rowCount' => max(3, min(15, count($stats))), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false, 'values' => $stats,
                    'columns' => [
                        ['caption' => 'Automatismus', 'name' => 'Name', 'width' => 'auto', 'save' => false],
                        ['caption' => 'Schaltungen 7 Tage', 'name' => 'A7', 'width' => '150px', 'save' => false],
                        ['caption' => '30 Tage', 'name' => 'A30', 'width' => '90px', 'save' => false],
                        ['caption' => 'Meldungen 30 Tage', 'name' => 'M30', 'width' => '150px', 'save' => false],
                        ['caption' => 'Zuletzt', 'name' => 'Last', 'width' => '150px', 'save' => false],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'Protokoll (neueste zuerst) — warum etwas geschaltet oder gemeldet wurde:'],
                ['type' => 'Label', 'name' => 'ProtocolText', 'caption' => implode("\n", $this->protocolLines(30)) ?: '— noch leer —'],
                ['type' => 'CheckBox', 'name' => 'cfg_TileLight', 'caption' => 'Helle Darstellung der Kachel'],
            ],
        ];
    }

    // =====================================================================
    //  Formular: Aufbau (SUITE.md 3a, Referenz Batteriewächter)
    // =====================================================================

    private const LAYOUT_KEYS = ['automations', 'discover', 'catalog', 'mode', 'notify', 'protocol'];

    /** Reihenfolge und Zustand je Bereich: key => ['open' => std|open|closed|hidden, 'pos' => int]. */
    private function formLayout(): array
    {
        $by = [];
        foreach (json_decode($this->pStr('FormLayout'), true) ?: [] as $r) {
            if (is_array($r) && isset($r['Key'])) {
                $by[(string)$r['Key']] = $r;
            }
        }
        $out = [];
        foreach (self::LAYOUT_KEYS as $i => $k) {
            $r = $by[$k] ?? [];
            if (array_key_exists('Opened', $r) || array_key_exists('Closed', $r) || array_key_exists('Hidden', $r)) {
                // Kontrollkästchen; bei mehreren Haken gilt Ausgeblendet vor Zugeklappt vor Aufgeklappt (wie Batteriewächter)
                $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            } else {
                $open = (string)($r['Open'] ?? 'std'); // frühere Auswahlliste (0.1.24)
            }
            $out[$k] = [
                'open' => in_array($open, ['open', 'closed', 'hidden'], true) ? $open : 'std',
                'pos'  => isset($by[$k]['Pos']) ? max(1, min(99, (int)$by[$k]['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    private function layoutPanels(): array
    {
        return [
            'automations' => $this->AutomationsPanel(), 'discover' => $this->DiscoverPanel(),
            'catalog' => $this->CatalogPanel(), 'mode' => $this->ModePanel(), 'notify' => $this->NotifyPanel(), 'protocol' => $this->ProtocolPanel(),
        ];
    }

    private function LayoutPanel(array $layout, array $panels): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $k => $l) {
            $cap = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panels[$k]['caption'] ?? $k)));
            $rows[] = ['Key' => $k, 'Panel' => $cap, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed', 'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = $cap;
            }
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🪟  Formular: Aufbau' . (count($hidden) > 0 ? ' (' . count($hidden) . ' ausgeblendet)' : ''),
            'items' => [
                ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen aufgeklappt sind, in welcher Reihenfolge sie stehen und welche du gar nicht sehen willst — z. B. „Katalog“ oder „Entdecken“, wenn alles eingerichtet ist. Symcon merkt sich das Auf- und Zuklappen nicht selbst. Wirkt nach „Übernehmen“ beim nächsten Öffnen.'],
                ['type' => 'Label', 'caption' => 'Kein Haken heißt Standard (wie das Modul es vorsieht). Sind mehrere Haken gesetzt, gilt Ausgeblendet vor Zugeklappt vor Aufgeklappt. Oben (Wozu, Neu, Dokumentation & Hilfe) und unten (Aufbau, Rückmeldungen, Über dieses Modul) bleibt alles, wo es ist.'],
                ['type' => 'Label', 'caption' => count($hidden) > 0
                    ? '🙈 Ausgeblendet: ' . implode(', ', $hidden) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet“ entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.'
                    : 'ℹ️ Nichts ausgeblendet.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false, 'values' => $rows,
                    'columns' => [
                        ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => '', 'save' => false],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '120px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '120px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '140px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo AUTOMAT_ResetFormLayout($id);'],
            ],
        ];
    }

    /** AUTOMAT_ResetFormLayout($id): string — Aufbau im offenen Formular auf Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $panels = $this->layoutPanels();
        $rows = [];
        foreach (self::LAYOUT_KEYS as $i => $k) {
            $rows[] = ['Key' => $k, 'Panel' => trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panels[$k]['caption'] ?? $k))), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return 'ℹ️ Alle Bereiche wieder sichtbar und in Standardreihenfolge. Zum Speichern unten „Übernehmen“ klicken.';
    }

    private function ForumHint(): ?array
    {
        if ($this->aBool('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true, 'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Automat ist neu (Beta) — Fragen, Wünsche nach weiteren Automatismen und Fehler gerne im Forum-Thread. Besonders hilfreich sind Rückmeldungen zu Geräten, die ich selbst nicht habe (Rollläden, Taster, Wasser-/Rauchmelder, Lüftung).'],
                ['type' => 'Button', 'caption' => '💬 Zum Forum-Thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Zum Repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'AUTOMAT_AckForumHint($id);'],
            ],
        ];
    }

    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $base = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $panels = $this->layoutPanels();
        $layout = $this->formLayout();
        $middle = [];
        foreach ($layout as $k => $l) {
            $p = $panels[$k];
            if ($l['open'] === 'open') {
                $p['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $p['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // unsichtbar statt weggelassen: die Felder bleiben im Formular, gespeicherte Werte bleiben erhalten
                $p['visible'] = false;
            }
            $middle[] = $p;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel()],
            $middle,
            [$this->LayoutPanel($layout, $panels), $this->ForumHint(), $this->LicenseHint()]
        )));
        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $base['status'] ?? []]);
    }
}
