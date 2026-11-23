---
description: Offene Referenztexte von Subagenten annotieren lassen, prüfen, committen und lokal importieren
argument-hint: "[limit=200] [--retry-rejected] [--push]"
---

# /annotate

Annotiert offene Referenztexte (Talentnamen, Songtitel) mit Subagenten und legt das Ergebnis als
`data/annotations/<YYYY-MM-DD-HHMM>.jsonl` ab. Der Server ruft nie ein Sprachmodell auf; diese Session
erzeugt die Daten, der Server importiert und prüft sie.

Argumente: `$ARGUMENTS`

- erste Zahl = Limit (Standard `200`)
- `--retry-rejected` = abgelehnte Referenzen erneut versuchen, Subagenten mit Modell `sonnet` statt `haiku`
- `--push` = nach dem Commit den aktuellen Branch pushen

## Regeln für diesen Lauf

- `<scratchpad>` ist dein Scratchpad-Verzeichnis aus dem System-Prompt. Alle Zwischendateien liegen dort.
  Platzhalter wie `<n>`, `<scratchpad>` und `<stamp>` in jedem Befehl wörtlich einsetzen: Shell-Variablen
  bleiben zwischen Bash-Aufrufen nicht erhalten.
- Paketinhalte und Subagenten-Ergebnisse NICHT in deinen eigenen Kontext holen (kein `cat`/`Read` auf
  Pakete oder Ergebnisdateien). Zählen mit `wc -l` ist erlaubt.
- Fortschritt für die Statuszeile: nach jedem fertigen Paket eine Zeile `<fertige Pakete> <Pakete gesamt> Annotieren`
  nach `~/.claude/progress/<session_id>-annotate` schreiben (`<session_id>` steht im Scratchpad-Pfad).
  Am Ende (auch bei Abbruch) `rm -f ~/.claude/progress/<session_id>-annotate`.
- Nie den Import auf dem Server ausführen, auch nicht per `ssh`.
- Ein Paket, das nichts liefert (Subagent scheitert oder schreibt 0 Zeilen), wird übersprungen, nicht im
  selben Lauf wiederholt.

## Schritte

### 1. Export

```bash
php artisan annotations:export --limit=<n> [--retry-rejected] > <scratchpad>/pending.jsonl
wc -l < <scratchpad>/pending.jsonl
```

Alternative gegen die Server-Datenbank (nach dem Deploy):

```bash
ssh ubuntu@u0x.de 'cd /opt/holo-learn && docker compose exec -T app php artisan annotations:export --limit=<n>' > <scratchpad>/pending.jsonl
```

Ist die Datei leer: dem Nutzer „nichts offen“ melden und aufhören.

### 2. Pakete und Subagenten

```bash
mkdir -p <scratchpad>/packs <scratchpad>/out
split -l 25 <scratchpad>/pending.jsonl <scratchpad>/packs/pack-
ls <scratchpad>/packs
```

Je Paket ein Subagent (Agent-Tool), Modell `haiku` (mit `--retry-rejected`: `sonnet`). Mehrere Pakete
parallel starten (mehrere Agent-Aufrufe in einer Nachricht). Jeder Subagent schreibt in seine eigene
Datei `<scratchpad>/out/<paketname>.jsonl` und antwortet nur mit einer Zählzeile.

### 3. Auftrag an jeden Subagenten

Wörtlich übergeben, `<PACK>` = absoluter Pfad des Pakets, `<OUT>` = absoluter Pfad der Ergebnisdatei:

```
Du annotierst japanische Texte (hololive-Talentnamen und Songtitel) für eine Lern-App. Lies die Datei <PACK>. Jede Zeile ist ein JSON-Objekt mit key, kind (name|song), text, alt.
Schreibe für jede Zeile, bei der du dir sicher bist, genau eine JSON-Zeile in die Datei <OUT> (JSONL, eine Zeile pro Objekt, kein anderer Text):
{"key": <unverändert>, "reading": <Lesung des ganzen Textes in Hiragana>, "meaning": <kurze englische Bedeutung, höchstens 80 Zeichen>, "alt_kind": "romaji"|"translation"|"none", "model": "<dein Modellname, z. B. haiku>", "words": [{"surface": …, "reading": …}, …]}
Regeln:
- reading und alle Wort-Lesungen NUR in Hiragana (auch für Katakana-Wörter: ミーム → みーむ; ー bleibt ー). Keine Leerzeichen, keine Satzzeichen.
- words zerlegt den Text lückenlos von links nach rechts in Wörter. Satzzeichen, Symbole (☆, !, ・, 、) und Leerzeichen gehören zu keinem Wort und werden ausgelassen. Lateinische Wörter und Zahlen im Text sind eigene Wörter mit ihrer japanischen Aussprache in Hiragana als reading (Ahoy → あほい, 3 → さん).
- Aneinandergehängt müssen die surface-Werte den Text ergeben (ohne die ausgelassenen Zeichen) und die Wort-Lesungen genau reading.
- Partikel sind eigene Wörter mit ihrer geschriebenen Kana als reading: は → は, へ → へ, を → を (nicht わ/え/お).
- Zerlege in Wörterbuch-Wörter: Substantive, Verben samt Endung, Adjektive samt Endung, Partikel einzeln. Zusammengesetzte Wörter, die als Ganzes im Wörterbuch stehen (海賊団), bleiben ein Wort.
- Steht ein Verb oder Adjektiv nicht in der Grundform, gib zusätzlich "lemma" (Grundform in normaler Schreibung) und "lemma_reading" (Hiragana) an: 歌った → lemma 歌う, lemma_reading うたう. Bei Grundform und bei Substantiven keine lemma-Felder.
- Bei kind=name: Familienname und Vorname sind getrennte Wörter (桃鈴 / ねね). Die Lesung richtet sich nach alt, wenn alt der Name in lateinischer Schrift ist; lange Vokale schreibst du aus, auch wenn alt sie weglässt (Kiryu → きりゅう). meaning ist bei Namen der Name in lateinischer Schrift.
- alt_kind: "romaji", wenn alt die Aussprache des japanischen Textes in lateinischer Schrift wiedergibt; "translation", wenn alt eine Übersetzung oder ein englischer Titel ist; "none", wenn alt null ist oder nichts davon.
- Wenn alt eine Umschrift ist, muss deine Lesung dazu passen. Künstlerische Sonderlesungen (宇宙 als そら) nur, wenn alt sie belegt.
- Bist du bei einer Lesung unsicher, lass die Zeile WEG. Raten ist schlimmer als Auslassen.
Antworte am Ende nur mit: "<Anzahl geschriebener Zeilen> von <Anzahl gelesener Zeilen>".
```

### 4. Zusammenführen und prüfen

```bash
date +%Y-%m-%d-%H%M   # Ausgabe = <stamp>
mkdir -p data/annotations
cat <scratchpad>/out/*.jsonl > data/annotations/<stamp>.jsonl
php artisan annotations:lint data/annotations/<stamp>.jsonl --fix
php artisan annotations:lint data/annotations/<stamp>.jsonl   # muss mit Exit 0 enden
wc -l < data/annotations/<stamp>.jsonl
```

Liegt in `<scratchpad>/out/` keine Datei, gleich mit einem Bericht aufhören. Fehlerhafte Zeilen werden
entfernt, nicht repariert. Ist die Datei danach leer: löschen und mit einem
Bericht an den Nutzer aufhören.

### 5. Lokal importieren

```bash
php artisan annotations:import data/annotations/<stamp>.jsonl -v
```

Festhalten: `imported / skipped / verified / rejected` aus der Schlusszeile sowie die abgelehnten Zeilen
mit ihren Fehlercodes.

### 6. Commit

Nur diese eine Datei committen:

```bash
git add data/annotations/<stamp>.jsonl
git commit -m "Add annotations <YYYY-MM-DD> (<n> Zeilen)"
```

Pushen (`git push` des aktuellen Branches) nur, wenn der Aufruf `--push` enthielt; sonst bleibt der Commit
lokal, und das sagst du dem Nutzer.

Hinweis für den Nutzer (nicht ausführen): Nach dem Deploy importiert der Server mit
`git pull && docker compose exec app php artisan annotations:import`.

### 7. Abschlussbericht (Deutsch)

```bash
php artisan annotations:export --limit=100000 | wc -l   # noch offene Referenzen
rm -f ~/.claude/progress/<session_id>-annotate
```

Melde: exportiert, annotiert (Zeilen aus den Subagenten), beim Lint entfernt, verified, rejected (Anzahl
je Fehlercode), übersprungene Pakete, noch offen; und ob gepusht wurde.
