# OPNsense AI Security Wizard: Der praktische Leitfaden

**Entwurf v0.1 — Daniel Freeman (danfreeus702)**
*Begleitlektüre zum Open-Source-Plugin: https://github.com/danfreeus702/os-ai-opensense*

> Dies ist ein Arbeitsentwurf. Kapitel 1–2 sind fertig, die übrigen Kapitel sind
> skizziert und werden aus den Testdokumentationen des Projekts ergänzt.

---

## Inhaltsverzeichnis

1. Warum es diesen Assistenten gibt
2. Schnellstart: installieren und zum ersten Mal anwenden
3. Die drei Stufen im Detail
4. Verschlüsseltes DNS unter OPNsense 26.7 (warum DoT statt DoH)
5. Der KI-Assistent: offizielle Dokumentation und Hardware
6. Änderungen in Klartext lesen
7. Rückgängigmachen: Snapshots und Undo mit einem Klick
8. Den Assistenten offline nutzen
9. Verifikation: wie das Plugin getestet wurde
10. Fehlersuche und reale Einschränkungen
11. Anhang A — configd-Aktionen und Dateipfade
12. Anhang B — aktualisieren und deinstallieren

---

## Kapitel 1 — Warum es diesen Assistenten gibt

Eine frisch installierte Firewall ist eine leere Leinwand. Das ist ihre Stärke
und ihre Schwäche. Sie kann alles, aber die Person vor der Oberfläche muss
entscheiden *was*, *wo* und *in welcher Reihenfolge*. Bei OPNsense bedeutet das,
mehr als 150 Menüpunkte zwischen Firewall, DNS, VPN, Diensten und System zu
durchsuchen. Die meisten hören bei einer halb konfigurierten Maschine auf oder
kopieren Einstellungen, die sie nicht verstehen.

Der Sicherheitsassistent stellt eine andere Frage: *Wie viel Schutz möchten
Sie?* Danach erzeugt er einen Plan, den Sie prüfen können. Sie sehen weiterhin
jede Änderung, bevor sie passiert, einschließlich des exakten Menüpfads, über
den Sie sie manuell vornehmen könnten. Der Assistent ist keine Blackbox, sondern
eine Abkürzung mit einem Protokoll.

### Entwurfsregeln

Vier Regeln haben das Projekt geprägt:

1. **Nichts ändert sich ohne Bestätigung.** Jede ändernde Aktion erfordert ein
   ausdrückliches `confirm=yes`, und die Oberfläche zeigt zuerst die
   Änderungen in Klartext.
2. **Keine Geheimnisse in config.xml.** Der Schlüssel des Cloud-Modells liegt
   in einer Datei mit root-Rechten, `/conf/aiopensense.key` (Modus 600), damit
   er nicht in Sicherungen kopiert wird.
3. **Jede Anwendung ist rückgängig zu machen.** Vor der ersten Änderung wird
   ein timestampbasierter Konfigurations-Snapshot geschrieben; ein einziger
   Klick auf „Undo last change" stellt ihn wieder her.
4. **Nur Cloud-Modelle.** Es ist kein lokales Modell enthalten, deshalb
   funktioniert das Plugin auch auf kleinen Geräten mit wenig Speicher.

### Für wen ist es gedacht

- Privatnutzer, die in wenigen Minuten eine sichere Basis möchten.
- Kleine Büros ohne eigenen Netzwerkingenieur.
- Alle, die OPNsense lernen und sehen möchten, *wie eine korrekte
  Konfiguration aussieht*, bevor sie sie manuell erstellen.

Wenn Sie ein komplexes Setup mit mehreren WANs, BGP und einer vollständigen
IDS-Umgebung betreiben, ersetzt dieser Assistent nicht Ihr Design — er ist ein
sauberer Ausgangspunkt.

---

## Kapitel 2 — Schnellstart: installieren und zum ersten Mal anwenden

### Voraussetzungen

- **OPNsense 26.7** oder neuer (getestet auf 26.7.9_3).
- Internetzugang von der Firewall für Blocklisten und das Cloud-Modell.
- Ein API-Schlüssel für OpenRouter (primär) oder NVIDIA NIM (Ersatz).

### Paket installieren

Laden Sie `os-ai-opensense-0.1_1.pkg` von der GitHub-Releaseseite herunter,
kopieren Sie die Datei auf die Firewall und installieren Sie sie als root:

```sh
pkg add /tmp/os-ai-opensense-0.1_1.pkg
```

Die Installations-Hooks starten configd neu und laden das neue Template.
Prüfen Sie mit:

```sh
pkg info os-ai-opensense
```

### API-Schlüssel hinterlegen (nur root)

```sh
printf '%s\n' 'your-key-here' > /conf/aiopensense.key
chmod 600 /conf/aiopensense.key
```

Legen Sie den Schlüssel niemals in `config.xml` ab — diese Datei landet in
Sicherungen und Support-Paketen.

### Assistenten öffnen

Wählen Sie in der Weboberfläche **OPNsense AI → Security Wizard**. Es erscheinen
drei Karten. Wählen Sie eine Stufe; das Plan-Panel öffnet sich mit einer Liste
von Schritten. Darunter befindet sich die Tabelle **What will change** mit vier
Spalten:

- der Änderung,
- dem aktuellen Wert,
- dem Wert nach dem Anwenden,
- dem exakten GUI-Pfad, in dem diese Einstellung liegt.

Lesen Sie diese Tabelle. Wenn etwas nicht stimmt, schließen Sie die Seite —
bis dahin wurde nichts geändert.

### Anwenden

Aktivieren Sie die Optionen (der Tailscale-Exit-Node existiert nur in Stufe 3)
und klicken Sie auf **Apply selected level**. Der Bestätigungsdialog weist
darauf hin, dass automatisch ein Snapshot für die Rücknahme gespeichert wird.
Nach wenigen Sekunden sehen Sie ein grünes Häkchen pro Schritt. Im
Ergebnisbereich erscheint außerdem der Hinweis zum Snapshot.

### Prüfen

```sh
tail -20 /var/log/aiopensense-security.log
pfctl -sr | grep -E 'domain|https|domain-s'
```

Die DNS- und HTTPS-Regeln des Assistenten sollten unter den aktiven pf-Regeln
stehen, und im Audit-Log sollte für jede Aktion eine `OK`-Zeile stehen.

### Rückgängigmachen

Klicken Sie im Ergebnisbereich auf **Undo last change**. Der Controller stellt
den letzten Snapshot wieder her und lädt die Firewall-Regeln neu. Prüfen Sie
anschließend die angewandte Stufe:

```sh
grep -o '<security_level>[0-9]*</security_level>' /conf/config.xml
```

---

*Kapitel 3–12: oben skizziert. Als Nächstes: „Die drei Stufen im Detail" und
„Verschlüsseltes DNS unter OPNsense 26.7 (warum DoT statt DoH)" — direkt aus
NOTES.md und den Konstanten RESOLVERS/BLOCKLISTS des Modells.*
