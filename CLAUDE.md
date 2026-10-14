# CLAUDE.md — Automat (DG65-Toolkit)

Konventionen: `/Users/dietmar/Nextcloud/Claude/TOOLKIT.md` und `SUITE.md` (Formular-Optik, Store-Review-Checkliste,
Stolpersteine). Kickoff mit Recherche (Symcon-Ökosystem + 11 andere Systeme): `/Users/dietmar/Nextcloud/Claude/Automat-Kickoff.md`.

- Anzeigename „DG65-Toolkit Automat" (Bindestrich, Dietmar 13.10.2026), Store-Name „Automat". Technischer Name
  Klasse `Automat`, Präfix `AUTOMAT`, Repo `DG65/Automat` — eingefroren, sobald eine Instanz läuft.
- Entscheidungen ausschließlich in `Automat/AUTOMATLogik.php` (reine Funktionen, ohne IPS); Typen und Felder in
  `AUTOMATKatalog.php`; Erkennung in `AUTOMATErkennung.php`. `module.php` sammelt Werte, schaltet, meldet, zeigt an.
  Klassen tragen den Präfix AUTOMAT (globale Klassennamen kollidieren sonst zwischen Modulen).
- Neuer Typ: Katalog (Felder mit eindeutigem Kürzel, z. B. `zl_`), Logik (`step()`-Zweig), Prüfstand-Block.
  Dialogfelder dürfen weder untereinander noch mit Formularnamen kollidieren — der Prüfstand prüft das.
- Echo-Erkennung (`Sent`/`consumeEcho`) ist der Kern von „Handbedienung hat Vorrang": jeder Schaltbefehl geht über
  `switchVariable()`. Prüfstand C2 enthält den Fall, der ohne Echo-Erkennung kaputt geht (Vorwarnung).
- Formular: Liste mit `loadValuesFromConfiguration:false` und eingespeisten Werten; Bearbeiten-Dialog als PHP-Code
  (`AUTOMAT_UIEditForm`), alle Typen im Dialog, sichtbar nur der gewählte. „Übernehmen"-Buttons schreiben nur per
  `UpdateFormField` in die offene Liste (Store-Regel 1). Ob die Liste im `onClick` als `foreach`-fähige Zeilen
  ankommt, ist NICHT live geprüft — `insertRow()` fällt sonst auf den gespeicherten Stand zurück und sagt das.
- „Neu bis Version" erst ab der ersten veröffentlichten Version (`NEWS_VERSIONS` bleibt bis dahin leer).
- Logging nur `$this->LogMessage`, nie `IPS_LogMessage`; Datum sichtbar TT.MM.JJJJ; keine `±86400`-Rechnung.
- Vor jedem Commit: `php .tools/test-automat.php`, `php .tools/check-standalone.php`, `php -l`.
- Branch: nur `beta`; `main` erst nach Live-Bewährung (Dietmar entscheidet).
- Nicht live geprüft (Stand 0.1.0): alles. Insbesondere: Dialog mit Typwechsel per `UpdateFormField`, `$Automations`
  im onClick, Echo-Laufzeiten echter Aktoren (Z-Wave), Push-Zustellung, Kachel in der echten Visualisierung.
- Ab 0.1.31: Alarm, Rollladen, Standby, Lüften, Heizung nach Hausmodus, Taster, Aufwachlicht sind nur am Prüfstand
  getestet — Dietmar hat diese Geräte nicht. Rückmeldungen anderer Nutzer ernst nehmen und zuerst am Prüfstand nachbauen.
- Ab 0.1.35 ebenfalls nur am Prüfstand: Gehen-Check, Türklingel, Sommer-Lüften, Ausfall-Wächter, Zeitschaltuhr,
  Duplizieren, Probelauf, Feiertage, Statistik, Gewohnheitsvorschläge (Archivabfrage wochenweise, höchstens 25 Lichter).
