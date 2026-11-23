# holo-learn – Design

Stand: 2026-11-23 · Privatprojekt von @bestora · Fan-Projekt, nicht kommerziell

## Absicht

Eine Web-App, mit der man Hiragana, Katakana, Radikale, Kanji und Vokabeln lernt –
und zwar an echtem hololive-Material statt an Lehrbuchbeispielen.

Warum: Die Motivation trägt. „ね“ lernt man an 桃鈴ねね, an einem Songtitel, an einem
Clip – und bei jeder Wiederholung an einer anderen Fundstelle. Wichtig sind dem
Auftraggeber vor allem **Talent-Namen**, **Songtitel** (eigene Songs, Cover,
Karaoke-Streams) und **Begrüßungs-/Abschiedssätze**, außerdem Stellen aus Streams.
Der Ton gehört dazu: Man soll hören, wie das Talent es sagt oder singt.

Nutzer: der Auftraggeber und Freunde.

## Ziele

- Jedes Lernelement (Kana, Radikal, Kanji, Vokabel) hat viele hololive-Referenzen;
  bei jeder Abfrage erscheint eine andere.
- Die Referenz-Datenbank füllt sich **vollständig selbst**: algorithmisch, wo es
  geht, sonst über einen Claude-Agenten (Haiku, bei Bedarf Sonnet). Kein
  Eintragen von Hand.
- Referenzen mit Ton/Video werden als YouTube-Ausschnitt mit Start und Ende
  eingebettet.
- Wiederholung in wachsenden Abständen pro Nutzer und Lernelement.

## Nicht-Ziele

- Transkripte, YouTube-Untertitel, eigene Spracherkennung, „Most replayed“,
  Chat-Auswertung (bewusst verschoben, siehe Ansätze).
- Einreichen von Referenzen durch Nutzer.
- Grammatik-Lektionen, Satzbau-Übungen.
- Native Mobil-App, Offline-Betrieb.
- Bezahlfunktionen jeder Art.

## Anti-Ziele

- **Falsche Lesungen oder Bedeutungen lehren.** Lieber eine Referenz weniger als
  eine falsche. Ungeprüftes wird nicht angezeigt.
- Immer dieselbe Referenz zum selben Zeichen.
- hololive-Inhalte kopieren oder hosten statt einbetten/verlinken.
- Eine generische Karteikarten-App, bei der hololive nur Dekoration ist.

## Rahmenbedingungen

- **Holodex-API** (`https://holodex.net/api/v2`, Header `X-APIKEY`, Schlüssel in
  `.env` als `HOLODEX_API_KEY`). Bedingungen: Verweis auf Holodex mit Link, eine
  Datenschutzerklärung, kein Entgelt für Zugang oder abgeleitete Inhalte, kein
  übermäßiger Abruf. `limit` höchstens 50 pro Abruf.
- Am 2026-11-23 für Momosuzu Nene (`UCAWSyEs_Io8MtpY3m-zqILA`) getestet:
  - `GET /channels/{id}` → `name` („Nene Ch.桃鈴ねね“), `english_name`
    („Momosuzu Nene“), `org`, `group` („5th Generation (holoFive)“).
  - `GET /channels?org=Hololive&type=vtuber` → Liste aller Talente.
  - `POST /songs/latest` mit `{channel_id, paginated:true, limit, offset}` →
    `{total, items[]}`; je Eintrag `name`, `original_artist`, `start`, `end`
    (Sekunden), `video_id`, `itunesid`, `art`. Für Nene `total: 1894`.
    **Undokumentiert**, kann sich ändern.
  - `GET /videos?channel_id=…&topic=Music_Cover|Original_Song&include=songs` →
    42 Cover, 50 eigene Songs (Seitenlimit), jeweils mit `songs[]`.
  - `GET /channels/{id}/videos` → Stream-Titel, `topic_id`, `status`.
  - `GET /channels/{id}/clips?lang=ja` → japanische Clips mit `duration`, Titel.
  - `GET /videos/{id}?c=1` → `comments[].message`, Zeitstempel nur im Text.
    6–9 Kommentare pro Stream (zwei Streams geprüft); Begrüßungswörter kamen
    nicht vor. Teils „3:30 声入り“ (Sprechbeginn).
  - Song-`name` hat oft die Form `日本語 / Zweitform`; die Zweitform ist mal
    Umschrift (`Kyu-Kurarin`), mal Übersetzung (`Fake Meme`).
- **Wörterbuchdaten** der EDRDG (KANJIDIC2, JMdict, KRADFILE): frei nutzbar mit
  Namensnennung; Lizenztext vor dem Einbinden prüfen.
- **Cover-Richtlinien für Fan-Werke** und die Holodex-Bedingungen vor dem ersten
  Freischalten für Freunde einmal vollständig lesen.
- **Kein Claude-API-Schlüssel.** Der Agent läuft in Claude Code (im Abo des
  Auftraggebers), nicht auf dem Server. Der Server ruft nie ein Sprachmodell auf.
- Hausregeln des Auftraggebers: Eloquent-Relationen statt Query-Builder-Joins
  oder Roh-SQL.

## Ansätze

**A – Holodex-Text plus Agent (gewählt).** Import nur über die Holodex-API; ein
Agent ergänzt Lesung, Bedeutung und Zerlegung. Ton über eingebettete
YouTube-Ausschnitte an den Stellen, die Holodex schon kennt (Song-Start/-Ende,
Clips, Zeitstempel-Kommentare). Offizieller Zugang, billig, robust. Schwäche:
Gesprochene Sätze gibt es nur, wo Fans sie markiert haben.

**B – A plus YouTube-Untertitel.** Agent liest automatische Untertitel und findet
Sätze selbst. Deckt jeden Stream ab, aber kein offizieller Abrufweg, und die
Spracherkennung versagt gerade bei Kunstwörtern wie „こんねね“. Zurückgestellt;
nachrüsten, falls gesprochene Referenzen mit A zu dünn bleiben.

**C – eigene Spracherkennung auf heruntergeladenem Ton.** Verworfen: Download
verstößt gegen die YouTube-Bedingungen, Aufwand zu hoch.

Gewählt wurde A, mit **Musik als Hauptquelle** – der Test zeigte, dass Songtitel
mit Abstand am meisten liefern (tausende Einträge pro Talent, mit Zeitmarken).

## Was der Auftraggeber entschieden hat

- Laravel; Frontend-Wahl dem Entwurf überlassen („was mehr Sinn ergibt“); der
  `frontend-design`-Skill wird für die Oberfläche verwendet.
- Datenbank füllt sich komplett selbst, algorithmisch oder per Sonnet-/Haiku-Agent.
- Für ihn und Freunde, komplett fan-made, nicht kommerziell.
- Wichtigste Inhalte: Talent-Namen, Songtitel (inkl. Cover), Begrüßungs- und
  Abschiedssätze, Stellen aus Streams.
- Viele verschiedene Referenzen pro Lernelement, nicht immer dieselbe.

## Vorschläge des Entwurfs (nicht vom Auftraggeber bestätigt)

### Technik

- Laravel mit Inertia und React (offizielles React-Starter-Kit). Grund: Abfrage
  per Tastatur, Animationen und ein YouTube-Player, der mit der Karte synchron
  läuft, sind viel Zustand im Browser.
- SQLite für den Anfang; Wechsel auf MySQL/Postgres, sobald das Hosting es
  verlangt.
- Konten nur per Einladungslink; keine offene Registrierung.
- Import, Prüfung und Zerlegung als Queue-Jobs, ausgelöst vom Laravel-Scheduler
  (nächtlich) bzw. beim Deploy. Der Agent läuft getrennt davon in Claude Code.
- Oberfläche auf Deutsch.

### Datenmodell (Kern)

```
Talent        holodex_channel_id, name_ja, name_en, generation, foto
Reference     talent_id, art, text_ja, video_id?, start_s?, end_s?,
              quelle_id (eindeutig je Art), status
              art: name | song | stream_titel | clip | moment | gruss
              status: neu → annotiert → geprüft | abgelehnt
Annotation    reference_id, lesung_kana, bedeutung, modell, prüfergebnis
Item          art (hiragana|katakana|radikal|kanji|vokabel), zeichen,
              lesungen, bedeutungen            ← aus Kana-Tabelle und EDRDG
item_reference  item_id, reference_id, position, lesung_im_kontext?
Review        user_id, item_id, stufe, fällig_am, zuletzt_reference_id
```

Songtitel werden nach Text dedupliziert: Ein Titel wird einmal annotiert, kann
aber viele Fundstellen (Talent, Video, Start/Ende) haben. Dafür trägt eine
Referenz ihre Fundstellen als eigene Zeilen (`Occurrence`: reference_id,
talent_id, video_id, start_s, end_s) – so entsteht die Vielfalt, ohne den Agenten
mehrfach zu bezahlen.

### Befüllung

```
nächtlich:
  Talente abgleichen                        → Talent, Referenz art=name
  je Talent:
    /songs/latest seitenweise bis bekannt   → art=song + Occurrence
    Cover / Original_Song                   → art=song + Occurrence
    neue Streams                            → art=stream_titel
    japanische Clips ≤ 60 s                 → art=clip
  je neue Referenz (status=neu):
    Text bereinigen (algorithmisch: Kanalname → Talentname, 【…】, Hashtags)
    Agent (Haiku): Lesung in Kana, Bedeutung, Wortgrenzen,
                   Zweitform = Umschrift oder Übersetzung?
    Prüfung (algorithmisch) → geprüft | abgelehnt
    Zerlegung (algorithmisch) → item_reference
```

**Prüfung, bevor etwas sichtbar wird:**

- Lesung enthält nur Kana und hat für jedes Kana im Originaltext dasselbe Kana
  an passender Stelle.
- Gibt es eine Umschrift (`english_name`, Song-Zweitform, vom Agenten als
  Umschrift eingestuft): Umschrift → Kana umrechnen und mit der Lesung
  vergleichen (Langvokale und Schreibvarianten tolerant).
- **Wort-Ebene statt Einzelzeichen:** Der Agent liefert Wortgrenzen und je Wort
  die Lesung. Ein Wort mit Kanji gilt nur als geprüft, wenn es samt dieser
  Lesung in JMdict steht (bei `art=name`: in JMnedict, oder die
  Umschrift-Prüfung besteht). Das deckt Rendaku, Verdopplung und Sonderlesungen
  wie 今日 ab, die eine Prüfung je Kanji fälschlich ablehnen würde.
- Nur geprüfte Wörter erzeugen Verknüpfungen zu Kanji-, Radikal- und
  Vokabel-Elementen. Wörter, die durchfallen (Kunstwörter, künstlerische
  Lesungen wie 宇宙＝そら), erzeugen keine – ihre Kana werden trotzdem verknüpft,
  denn Kana brauchen keine Lesungsprüfung. Eine Referenz kann also teilweise
  nutzbar sein.
- Führt JMdict mehrere Lesungen (明日: あす/あした) und gibt es keine Umschrift,
  wird die Agent-Lesung übernommen und in der Abfrage auch die andere
  akzeptiert.
- Die Lesung je Wort wird an der Verknüpfung gespeichert
  (`item_reference.lesung_im_kontext`).
- Durchgefallen → einmal mit Sonnet wiederholen; fällt es erneut durch, bleibt
  es `abgelehnt` und unsichtbar. Eine einfache Admin-Liste zeigt Abgelehntes.

**Zerlegung:** Kana und Kanji direkt aus den Zeichen des Textes; Radikale über
KRADFILE aus den Kanji; Vokabeln aus den vom Agenten gelieferten Wortgrenzen,
gefiltert durch JMdict.

**Robustheit:** Holodex-Antworten werden vor dem Speichern auf die erwarteten
Felder geprüft; fehlt etwas (undokumentierte Schnittstelle geändert), bricht nur
diese Quelle ab und meldet es, der Rest läuft weiter. Importe sind wiederholbar
ohne Dubletten (`quelle_id`).

### Lernen

- Abfrage: Die Referenz wird gezeigt, das abgefragte Element hervorgehoben.
  Danach Auflösung mit Player-Ausschnitt, falls vorhanden. Was gefragt wird:
  - **Kana:** Lesung tippen (Romaji-Eingabe wird zu Kana).
  - **Kanji:** Lesung des Wortes, in dem es in dieser Referenz steht
    (`lesung_im_kontext`), danach die Bedeutung des Kanji. Bei Namen steht
    dabei „Lesung in diesem Namen“, damit niemand eine Namenslesung für die
    übliche hält.
  - **Vokabel:** Lesung, danach Bedeutung.
  - **Radikal:** keine Lesung; Bedeutung aus vier Auswahlmöglichkeiten.
  - **Bedeutung tippen:** richtig, wenn die Eingabe einer Wörterbuch-Bedeutung
    entspricht (Groß-/Kleinschreibung und kleine Tippfehler egal). Der Nutzer
    kann „war doch richtig“ wählen.
- Übersetzung der ganzen Referenz: Bei Songs die Zweitform von Holodex, sonst
  die Agent-Übersetzung, sichtbar als „automatisch übersetzt“ markiert. Sie ist
  Kontext und wird nie abgefragt; abgefragt wird nur Geprüftes.
- Nicht einbettbare Videos (privat, gelöscht, nur für Mitglieder): Der Player
  meldet den Fehler, die Fundstelle wird als tot markiert und nicht mehr
  gewählt. Eine Referenz ohne lebende Fundstelle erscheint als reiner Text und
  zählt weiter. Kein zusätzlicher YouTube-API-Schlüssel.

  ```
    ね                       [ Antwort: __ ]

    ▶ 桃鈴ねね · ももすず ねね · Momosuzu Nene
    ♪ 花に亡霊 – gesungen von Nene (1:12:16–1:16:20)
    noch 11 weitere Referenzen für ね
  ```

- Referenzwahl: zufällig unter den geprüften Referenzen des Elements, nie die
  zuletzt gezeigte; bevorzugt Talente, die der Nutzer als Favoriten markiert hat.
- Wiederholung: feste Stufen mit wachsenden Abständen (4 h, 8 h, 1 T, 2 T, 1 W,
  2 W, 1 M, 4 M); richtig = eine Stufe hoch, falsch = zwei runter.
- Reihenfolge neuer Elemente: Hiragana → Katakana → Radikale → Kanji → Vokabeln,
  innerhalb einer Art nach Häufigkeit in der Referenz-Datenbank – was bei
  hololive oft vorkommt, kommt zuerst.
- Ein Element wird erst angeboten, wenn es mindestens drei geprüfte Referenzen
  hat (Schwelle einstellbar).

### Bau-Reihenfolge

1. **Grundgerüst und Lernschleife:** Konten per Einladung, Kana-/Kanji-/
   Vokabel-Stammdaten, Import von Talent-Namen und Songs, Agent mit Prüfung,
   Abfrage mit Wiederholung und Player.
2. **Mehr Quellen:** Stream-Titel und japanische Clips.
3. **Gesprochenes:** Begrüßungs-/Abschiedssätze und Zeitstempel-Momente.

Jede Stufe ist für sich benutzbar.

### Begrüßungen (Stufe 3, mit Vorbehalt)

Pro Talent ein fester Satz, einmalig vom Agenten recherchiert (Sonnet mit
Websuche, Quelle wird gespeichert). Sprungmarke: Zeitstempel eines
„声入り“-Kommentars, sonst Stream-Anfang. **Ungeprüft:** ob diese Sprungmarke die
Begrüßung zuverlässig trifft. Für die Lesung gibt es hier keine Umschrift zum
Gegenprüfen; deshalb gilt ein Gruß erst als geprüft, wenn zwei unabhängige
Agent-Läufe denselben Text und dieselbe Lesung liefern.

Zeitstempel-Momente (`art=moment`): Der Text ist der japanische Fan-Kommentar
zur Stelle, nicht das Gesprochene, und wird als „Fan-Kommentar“ gekennzeichnet.
Stufe 3 beginnt deshalb mit einem kurzen Versuch an drei Talenten; erst wenn
der genug Brauchbares liefert, wird sie gebaut.

### Kosten des Agenten

Der Agent läuft in Claude Code statt über die API (Vorgabe des Auftraggebers):

```
Server, nächtlich:   Holodex-Import → Referenzen mit status=neu
Claude Code:         /annotate
  offene Texte vom Server holen (artisan-Export über SSH)
  in Paketen an Haiku-Subagenten geben → Annotationen als JSONL
  JSONL nach data/annotations/ im Repo schreiben, committen, pushen
Server, beim Deploy: Annotationen einlesen → Prüfung → Zerlegung
```

- Die Annotationen liegen als Dateien im Repo, mit dem bereinigten Text als
  Schlüssel. Sie sind das teure Ergebnis: Die Datenbank lässt sich daraus
  jederzeit neu aufbauen, ohne den Agenten erneut zu bemühen.
- Prüfung und Zerlegung bleiben reiner Code auf dem Server; der Agent kann
  nichts direkt veröffentlichen.
- Jeder Text wird nur einmal annotiert (Songtitel sind über alle Talente
  dedupliziert). Ein Lauf bearbeitet eine feste Höchstzahl an Texten, damit er
  das Abo-Kontingent nicht aufbraucht; der Rest wartet auf den nächsten Lauf.
- „Füllt sich selbst“ heißt damit: Der Import läuft von allein, die Annotation
  läuft, sobald ein Claude-Code-Lauf stattfindet – von Hand gestartet oder
  zeitgesteuert ohne Oberfläche. Wie der zeitgesteuerte Lauf eingerichtet wird,
  ist beim Bau zu klären.
- Stream-Titel (Stufe 2) werden nicht vollständig annotiert, sondern nur so
  weit, wie Elemente noch unter der Referenz-Schwelle liegen.
- Zweiter Versuch bei durchgefallener Prüfung: im nächsten Lauf mit Sonnet.

## Dem Bauenden überlassen

- Genaue Tabellen- und Spaltennamen, Indizes, Job-Zuschnitt.
- Romaji↔Kana-Umrechnung: vorhandene Bibliothek oder eigene Tabelle.
- Format, in dem die EDRDG-Dateien eingelesen werden.
- Aussehen der Oberfläche (über den `frontend-design`-Skill).
- Abrufrate gegen Holodex (zurückhaltend; erster Vollimport darf Tage dauern).
- Tests: mindestens die Prüfregeln und die Stufenlogik der Wiederholung.

## Nachträglich vom Auftraggeber entschieden

- **Bedeutungen auf Englisch.** (Oberfläche auf Deutsch bleibt Vorschlag des
  Entwurfs.)
- **Alle hololive-Talente**, bevorzugt aber die, die Englisch und Japanisch
  sprechen – genannt wurden FUWAMOCO, Calli, IRyS, Baelz und Kiara.
  Umsetzung (Vorschlag des Entwurfs): eine Vorrangliste in der Konfiguration.
  Talente darauf werden zuerst importiert und zuerst annotiert, und ihre
  Referenzen werden bei der Abfrage bevorzugt, solange der Nutzer keine eigenen
  Favoriten gesetzt hat. Bei EN-Talenten sind viele Stream-Titel englisch;
  Texte ohne japanische Zeichen werden übersprungen.
- **Vorrangliste zum Start** (vom Auftraggeber genannt, Kanal-IDs am
  2026-11-23 aus Holodex; Ina, Kronii und Haachama sind Vorschlag des Entwurfs):

  | Talent | Holodex-Kanal |
  |---|---|
  | Fuwawa & Mococo Abyssgard | `UCt9H_RpQzhxzlyBxFqrdHqA` |
  | Mori Calliope | `UCL_qhgtOy0dy1Agp8vkySQg` |
  | IRyS | `UC8rcEBzJSleTkf_-agPM20g` |
  | Hakos Baelz | `UCgmPnx-EEeOrZSg5Tiw7ZRQ` |
  | Takanashi Kiara | `UCHsx4Hqa-1ORjQTh9TYDhww` |
  | Kureiji Ollie | `UCYz_5n-uDuChHtLo7My1HnQ` |
  | Minato Aqua (inaktiv) | `UC1opHUrw8rvnsadT-iGp7Cg` |
  | Hoshimachi Suisei | `UC5CwaMl1eIgY8h02uZw7u8A` |
  | Sakamata Chloe (inaktiv) | `UCIBY1ollUsauvVi4hW4cumw` |
  | Tokoyami Towa | `UC1uv2Oq6kNxgATlCiez59hw` |
  | Juufuutei Raden | `UCdXAk5MpyLD8594lm_OvtGQ` |
  | Achichi Mela | `UC8eitCE9Z6EwUCs-VUi1blg` |
  | Tsunomaki Watame | `UCqm3BQLlJfvkTsX_hvm0UmA` |
  | Momosuzu Nene | `UCAWSyEs_Io8MtpY3m-zqILA` |
  | La+ Darknesss | `UCENwRMx5Yh42zWpzURebzTw` |
  | Kazama Iroha | `UC_vMYWcDjmfdpH6r4TTn1MQ` |
  | Sorashina Sopia | `UCROQtXcp2loQEmvpe5rhJzQ` |
  | Kiryu Coco (inaktiv) | `UCS9uQI-jC3DE0L4IpXyvr6w` |
  | Ninomae Ina'nis | `UCMwGHR0BTZuLsmjY_NT5Pwg` |
  | Ouro Kronii | `UCmbs8T6MWqUHP1tIQvSgKrg` |
  | Akai Haato | `UC1CfXB_kRs3C-zaeTG3oGyg` |

- Holodex führt unter `org=Hololive` 138 Kanäle, darunter HOLOSTARS, mekPark,
  Zweitkanäle („Takanashi Kiara SubCh.“) und offizielle Gruppenkanäle
  („hololive ReGLOSS“). **HOLOSTARS bleibt draußen** (Entscheidung des
  Auftraggebers): 28 Kanäle mit Gruppe `HOLOSTARS…` und die zwei offiziellen
  HOLOSTARS-Kanäle; es bleiben 108. Vorschlag des Entwurfs für den Rest:
  Alle werden importiert;
  Zweit- und Gruppenkanäle liefern Songs, Titel und Clips, aber keine
  Namens-Referenz. Inaktive Talente bleiben mit ihrem Archiv dabei.
## Lernweg (nachträglich vom Auftraggeber, 2026-11-23)

Vorgabe: Der Auftraggeber ist Anfänger. Er will vor allem die **Grundlagen**
lernen und nur zum Spaß Spezialfälle – „wie Duolingo, nur cooler, mit hololive
und weniger repetitiv mit denselben Referenzen“.

Umsetzung (Vorschlag des Entwurfs):

- **Grundlagen zuerst.** Reihenfolge neuer Elemente: Hiragana → Katakana → dann
  Kanji und Vokabeln nach allgemeiner Wichtigkeit (JLPT-Stufe N5 zuerst, dann
  Schulstufe und Häufigkeit aus KANJIDIC2; bei Vokabeln die als „häufig“
  markierten Einträge aus JMdict zuerst). Die Häufigkeit bei hololive
  entscheidet nur noch innerhalb derselben Stufe. Das ersetzt die frühere
  Regel „innerhalb einer Art nach Häufigkeit in der Referenz-Datenbank“.
- **Spezial ist Bonus.** Seltene Kanji und Wörter (z. B. 鐘 aus einem Namen)
  gelten als „Spezial“: gekennzeichnet, höchstens jedes fünfte neue Element,
  und abschaltbar. Sie halten den Grundlagen-Fortschritt nie auf.
- **Erst zeigen, dann fragen.** Ein neues Element wird zuerst vorgestellt
  (Zeichen, Lesung, Romaji, Bedeutung, eine Referenz mit Clip), bevor es
  abgefragt wird.
- **Leichter Einstieg je Element.** In den ersten beiden Stufen Auswahl aus
  vier Antworten, ab Stufe 2 selbst tippen.
- **Kurze Lektionen.** Eine Lektion hat 10 Karten und mischt Neues mit
  Wiederholung; danach eine kurze Zusammenfassung. Dazu ein Tageszähler in
  Folge (Streak) und eine Übersicht des Fortschritts je Art.
- **Abwechslung.** Jede Karte zeigt eine andere Referenz als beim letzten Mal;
  innerhalb einer Lektion erscheint keine Referenz zweimal.
- Radikale kommen erst zusammen mit dem ersten Kanji, das sie enthält, nicht
  als eigener Block davor.

## Betrieb (vom Auftraggeber vorgegeben)

- Repo: `https://github.com/Bestora/holo-learn`, Commits als
  @bestora `<dennisrehbehn@googlemail.com>`.
- Server: `ubuntu@u0x.de`, Checkout unter `/opt/holo-learn`, Caddy als
  Webserver, erreichbar unter `holo-learn.u0x.de`.
- Stand des Servers am 2026-11-23 (nur gelesen): Ubuntu 22.04, System-PHP 8.1
  ohne Composer (zu alt für aktuelles Laravel), kein MySQL/Postgres, Docker
  und Caddy vorhanden, fast alle Dienste unter `/opt` laufen in Docker.
  Arbeitsspeicher knapp (rund 1 GB verfügbar von 11 GB), Platte frei (127 GB).
- Vorschlag des Entwurfs: Die App läuft als Docker-Container (PHP-Laufzeit,
  Queue-Worker und Scheduler im selben Compose-Projekt, SQLite-Datei und `.env`
  als Volume); der vorhandene Caddy leitet `holo-learn.u0x.de` per
  `reverse_proxy` an den Container weiter. Das System-PHP bleibt unberührt.
  Vor jeder Änderung an `/etc/caddy/Caddyfile` wird eine Sicherung angelegt,
  wie dort schon üblich.
