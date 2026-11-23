# holo-learn Stufe 1 – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine deployte Lern-App, in der man Kana, Kanji, Radikale und Vokabeln an
Talent-Namen und Songtiteln von hololive lernt, mit selbstfüllender
Referenz-Datenbank.

**Architecture:** Laravel importiert nächtlich Talente und Songs von Holodex in
eine Referenz-Tabelle. Claude Code annotiert offene Texte (`/annotate`) und legt
das Ergebnis als JSONL ins Repo; der Server liest es ein, prüft es rein
algorithmisch gegen Wörterbuchdaten und Umschrift und verknüpft nur Geprüftes mit
Lernelementen. Die Lernschleife (Inertia/React) fragt fällige Elemente an
wechselnden Referenzen ab und bettet YouTube-Ausschnitte ein.

**Tech Stack:** PHP 8.4, Laravel (aktuelles React-Starter-Kit: Inertia, React,
TypeScript, Tailwind), SQLite, Pest, Docker (FrankenPHP), Caddy als Reverse Proxy.

**Spec:** `docs/superpowers/specs/2026-11-23-holo-learn-design.md` – vor jeder
Aufgabe lesen. Dieser Plan deckt nur Stufe 1 der Bau-Reihenfolge ab; Stufe 2
(Stream-Titel, Clips) und Stufe 3 (Begrüßungen, Momente) bekommen eigene Pläne.

## Global Constraints

- Commits als `Bestora <dennisrehbehn@googlemail.com>` (im Repo konfiguriert),
  Remote `git@github.com:Bestora/holo-learn.git`, Branch `main`.
- `.env` wird nie committet und ihr Inhalt nie ausgegeben. Sie enthält bereits
  `HOLODEX_API_KEY`; beim Anlegen des Projekts erhalten, nicht überschreiben.
- Der Server ruft nie ein Sprachmodell auf. Kein `ANTHROPIC_API_KEY`.
- Eloquent mit Relationen (`whereHas`, `withCount`, `with`) statt
  Query-Builder-Joins, `DB::table()` oder Roh-SQL.
- Ungeprüftes wird nie abgefragt: Nur Referenzen mit `status=verified` erzeugen
  `item_reference`-Zeilen.
- hololive-Inhalte werden eingebettet oder verlinkt, nie heruntergeladen.
- Holodex: Header `X-APIKEY`, Basis `https://holodex.net/api/v2`, `limit` ≤ 50,
  zwischen zwei Abrufen mindestens 1 Sekunde Pause.
- Oberfläche auf Deutsch, Bedeutungen auf Englisch, Bezeichner im Code englisch.
- Fußzeile jeder Seite: Link „Daten von Holodex“ (`https://holodex.net`),
  „Wörterbuchdaten: EDRDG“, „Fan-Projekt, nicht mit COVER Corp. verbunden“.
- Bibliotheks-Fragen (Laravel, Inertia, Fortify, FrankenPHP) vor dem Schreiben
  in der aktuellen Doku nachschlagen statt aus dem Gedächtnis.
- Abstände der Wiederholung, exakt: 4 h, 8 h, 1 T, 2 T, 7 T, 14 T, 30 T, 120 T.

## Review Focus

1. Holodex antwortet mit 403/429/5xx oder ohne erwartetes Feld → nur diese Quelle
   für dieses Talent bricht ab und wird protokolliert, der Lauf geht weiter (Task 4).
2. Annotation-JSONL enthält kaputte Zeilen, unbekannte Schlüssel oder Lesungen mit
   Nicht-Kana → Zeile wird übersprungen und gezählt, der Import läuft weiter (Task 6).
3. Songnamen ohne Schrägstrich, mit mehreren Schrägstrichen oder Zusätzen wie
   `(ft. Mio) [3D Live]`, `(Instrumental)` → sauberer japanischer Text, Zusätze
   entfernt, rein englische Titel übersprungen (Task 5).
4. Antworten in Katakana, Großbuchstaben, mit Leerzeichen oder direkt als Kana
   getippt → werden gleich gewertet (Task 3, Task 9).
5. Element, dessen Referenzen alle abgelehnt oder dessen Fundstellen alle tot
   sind → wird nicht neu angeboten bzw. erscheint als reiner Text, kein Fehler
   (Task 8, Task 9).

---

## Dateistruktur

```
app/Models/            Talent, Reference, Occurrence, Annotation, Item, Review,
                       Invitation, DictWord, DictKanji
app/Japanese/          Kana.php (Umrechnung, Normalisierung, Vergleich)
                       TextCleaner.php (Kanalname, Songname)
app/Holodex/           Client.php, TalentSync.php, SongSync.php
app/Annotations/       Exporter.php, Importer.php, Verifier.php, Decomposer.php
app/Learning/          Srs.php, CardPicker.php, AnswerChecker.php
app/Console/Commands/  dict:import, holodex:sync, annotations:export,
                       annotations:import, invite:create
config/hololearn.php   Vorrangliste, Schwellen, Abstände
data/annotations/      *.jsonl (vom Agenten erzeugt, versioniert)
.claude/commands/annotate.md
resources/js/pages/learn/…, resources/js/components/youtube-clip.tsx
docker/Dockerfile, compose.yaml
```

Tabellen (Modell `Reference` nutzt Tabelle `refs`, weil `references` in SQL
reserviert ist):

```
talents         id, holodex_channel_id unique, name_ja, name_en, group_name,
                photo_url, inactive bool, is_secondary bool, priority int null
refs            id, kind (name|song), text_ja, text_key (sha1 von text_ja),
                alt_text null, status (new|annotated|verified|rejected),
                unique(kind, text_key)
occurrences     id, reference_id, talent_id, video_id null, start_s null,
                end_s null, source_id unique, dead_at null
annotations     id, reference_id unique, reading_kana, meaning_en, words json,
                alt_kind (romaji|translation|none), model, check_errors json null
items           id, kind (hiragana|katakana|radical|kanji|vocab), characters,
                readings json, meanings json, unique(kind, characters)
item_reference  item_id, reference_id, position, context_reading null
reviews         id, user_id, item_id, stage (0–8), due_at, last_reference_id null,
                unique(user_id, item_id)
favorites       user_id, talent_id
invitations     id, token unique, is_admin bool, used_at null
dict_words      id, spelling, reading, glosses json, is_name bool,
                index(spelling, reading)
dict_kanji      character primary, readings json, meanings json, radicals json
users           + is_admin bool
```

---

### Task 1: Projekt anlegen, Konten nur per Einladung

**Files:**
- Create: Laravel-Projekt im Repo-Wurzelverzeichnis (React-Starter-Kit),
  `app/Models/Invitation.php`, `app/Console/Commands/InviteCreate.php`,
  Migrationen für `invitations` und `users.is_admin`
- Modify: Registrierungs-Route/-Action des Starter-Kits, Layout-Fußzeile
- Test: `tests/Feature/InvitationTest.php`

**Interfaces:**
- Produces: `php artisan invite:create {--admin}` gibt eine URL
  `/register?invite=<token>` aus. `User::$is_admin` (bool).

- [ ] **Step 1:** Starter-Kit in ein leeres Nachbarverzeichnis erzeugen
  (`composer create-project laravel/react-starter-kit`), dann alles außer
  `.git` ins Repo verschieben. Die vorhandene `.env` behalten und die fehlenden
  Schlüssel aus `.env.example` ergänzen (`APP_KEY` per `php artisan key:generate`),
  `DB_CONNECTION=sqlite`. `.gitignore` des Kits übernehmen, `.env` bleibt ausgeschlossen.
- [ ] **Step 2:** `php artisan migrate && php artisan test && npm install && npm run build` – alles grün.
- [ ] **Step 3: Failing tests** in `tests/Feature/InvitationTest.php`:

```php
it('rejects registration without invite', function () {
    $this->post('/register', validUserData())->assertSessionHasErrors('invite');
    expect(User::count())->toBe(0);
});
it('registers with a valid invite and marks it used', function () {
    $inv = Invitation::create(['token' => 'abc', 'is_admin' => true]);
    $this->post('/register', validUserData() + ['invite' => 'abc'])->assertRedirect();
    expect(User::first()->is_admin)->toBeTrue()
        ->and($inv->fresh()->used_at)->not->toBeNull();
});
it('rejects a used invite', function () { /* used_at gesetzt → Fehler 'invite' */ });
it('prints an invite url', function () {
    $this->artisan('invite:create')->expectsOutputToContain('/register?invite=')->assertSuccessful();
});
```

- [ ] **Step 4:** Tests laufen lassen, sie schlagen fehl.
- [ ] **Step 5:** Umsetzen: Einladung wird in derselben Transaktion wie das
  Anlegen des Nutzers verbraucht; das Registrierungsformular reicht `invite`
  aus der Query als verstecktes Feld durch. Fußzeile laut Global Constraints.
- [ ] **Step 6:** `php artisan test` grün. Commit „Scaffold app with invite-only registration“.

### Task 2: Wörterbuchdaten und Kana-Elemente

**Files:**
- Create: Migrationen `items`, `dict_words`, `dict_kanji`; Modelle `Item`,
  `DictWord`, `DictKanji`; `app/Console/Commands/DictImport.php`;
  `database/seeders/KanaSeeder.php`
- Test: `tests/Feature/DictImportTest.php` mit Mini-Fixtures unter `tests/fixtures/dict/`

**Interfaces:**
- Produces: `DictWord::query()->where('spelling', …)->where('reading', …)`,
  `DictKanji::find('桃')` mit `readings`, `meanings`, `radicals` als Arrays.
  `Item` für alle Hiragana und Katakana (Grundzeichen, Dakuten, Handakuten;
  `kind`, `characters`, `readings=['ne']` als Hepburn-Romaji).
  `php artisan dict:import {dir}`.

- [ ] **Step 1:** Quelle festlegen: die JSON-Ausgaben des Projekts
  `scriptin/jmdict-simplified` (JMdict Englisch, JMnedict, Kanjidic2, Kradfile).
  Aktuelle Release-Dateinamen und die EDRDG-Lizenzbedingungen nachlesen; Lizenz-
  hinweis in `README.md` aufnehmen. Dateien liegen unter `storage/app/dict/`
  (nicht versioniert).
- [ ] **Step 2: Failing tests:** Fixture mit 3 Wörtern, 2 Namen, 2 Kanji →
  `dict:import tests/fixtures/dict` erzeugt `DictWord('今日','きょう')` mit
  `is_name=false`, `DictWord('桃鈴','ももすず', is_name=true)`,
  `DictKanji('花')->radicals` enthält `艹`; zweiter Lauf erzeugt keine Dubletten.
  `KanaSeeder` erzeugt `Item(hiragana,'ね')` und `Item(katakana,'ネ')`, je 71 Zeichen.
- [ ] **Step 3:** Umsetzen. Import streamend (Dateien sind mehrere hundert MB),
  in Blöcken von 1000 per `upsert`. Ein Wort mit mehreren Schreibweisen und
  Lesungen ergibt eine Zeile je gültiger Kombination.
- [ ] **Step 4:** Tests grün, dann echter Import lokal; Zeilenzahlen notieren. Commit.

### Task 3: Kana-Werkzeuge und Textbereinigung

**Files:**
- Create: `app/Japanese/Kana.php`, `app/Japanese/TextCleaner.php`
- Test: `tests/Unit/KanaTest.php`, `tests/Unit/TextCleanerTest.php`

**Interfaces:**
- Produces:
  - `Kana::toHiragana(string): string` (Katakana → Hiragana)
  - `Kana::fromRomaji(string): string` (Hepburn inkl. `shi/si`, `chi/ti`, `tsu/tu`,
    `fu/hu`, `ji/zi`, Doppelkonsonant → っ, `n` vor Konsonant/Ende → ん;
    Nicht-Buchstaben werden verworfen, Großschreibung egal)
  - `Kana::loose(string): string` (Hiragana, ohne ー, ohne Leerzeichen;
    `おう→お`, `おお→お`, `えい→え`, `うう→う`, `ゅう→ゅ`, `ょう→ょ`)
  - `Kana::looselyEqual(string $a, string $b): bool`
  - `Kana::isKanaOnly(string): bool`, `Kana::hasJapanese(string): bool`,
    `Kana::kanjiIn(string): array`
  - `TextCleaner::talentName(string $channelName, string $englishName): ?string`
  - `TextCleaner::songName(string $name): array{text_ja: ?string, alt: ?string}`

- [ ] **Step 1: Failing tests**, exakt diese Fälle:

```php
expect(Kana::looselyEqual(Kana::fromRomaji('Momosuzu Nene'), 'ももすずねね'))->toBeTrue();
expect(Kana::looselyEqual(Kana::fromRomaji('Kyu-Kurarin'), 'きゅうくらりん'))->toBeTrue();
expect(Kana::looselyEqual(Kana::fromRomaji('AMATTARU'), 'あまったる'))->toBeTrue();
expect(Kana::looselyEqual(Kana::fromRomaji('Hai Yorokonde'), 'はいよろこんで'))->toBeTrue();
expect(Kana::looselyEqual(Kana::fromRomaji('Uchiagehanabi'), 'うちあげはなび'))->toBeTrue();
expect(Kana::looselyEqual(Kana::fromRomaji('Fake Meme'), 'うそみーむ'))->toBeFalse();
expect(Kana::looselyEqual('ネネ', 'ねね'))->toBeTrue();
expect(Kana::kanjiIn('桃鈴ねね'))->toBe(['桃', '鈴']);

expect(TextCleaner::talentName('Nene Ch.桃鈴ねね', 'Momosuzu Nene'))->toBe('桃鈴ねね');
expect(TextCleaner::talentName('Towa Ch. 常闇トワ', 'Tokoyami Towa'))->toBe('常闇トワ');
expect(TextCleaner::talentName('Laplus ch. ラプラス・ダークネス - holoX -', 'La+ Darknesss'))->toBe('ラプラス・ダークネス');
expect(TextCleaner::talentName('Sopia Ch. 宙科そぴあ ‐ アソビ★まわり隊!', 'Sorashina Sopia'))->toBe('宙科そぴあ');
expect(TextCleaner::talentName('Suisei Channel', 'Hoshimachi Suisei'))->toBeNull();

expect(TextCleaner::songName('花に亡霊 / Ghost In A Flower'))->toBe(['text_ja' => '花に亡霊', 'alt' => 'Ghost In A Flower']);
expect(TextCleaner::songName('フレンズ / Friends (ft. Mio) [3D Live]'))->toBe(['text_ja' => 'フレンズ', 'alt' => 'Friends']);
expect(TextCleaner::songName('ももいろの砂時計 (Instrumental)'))->toBe(['text_ja' => 'ももいろの砂時計', 'alt' => null]);
expect(TextCleaner::songName('Ahoy!! 我ら宝鐘海賊団☆ / Ahoy!! Warera Houshou Kaizokudan☆')['text_ja'])->toBe('Ahoy!! 我ら宝鐘海賊団☆');
expect(TextCleaner::songName('Ridin\' on Dreams'))->toBe(['text_ja' => null, 'alt' => null]);
```

- [ ] **Step 2:** Tests schlagen fehl. **Step 3:** Umsetzen (eigene
  Romaji-Tabelle, keine neue Abhängigkeit). `talentName` nimmt den längsten
  zusammenhängenden Abschnitt mit japanischen Zeichen vor dem ersten ` - `/` ‐ `.
  **Step 4:** Tests grün. Commit.

### Task 4: Holodex-Client und Talente

**Files:**
- Create: `config/hololearn.php`, `app/Holodex/Client.php`,
  `app/Holodex/TalentSync.php`, Modelle/Migrationen `Talent`, `Reference`,
  `Occurrence`, `app/Console/Commands/HolodexSync.php`
- Test: `tests/Feature/TalentSyncTest.php` (HTTP per `Http::fake`)

**Interfaces:**
- Consumes: `TextCleaner::talentName`
- Produces:
  - `Client::get(string $path, array $query = []): array`,
    `Client::post(string $path, array $body): array`; wirft
    `HolodexException` bei Nicht-2xx oder Nicht-JSON; 1 s Pause je Abruf.
  - `TalentSync::run(): void`
  - `Reference::firstOrCreateFor(string $kind, string $textJa, ?string $alt): Reference`
    (Schlüssel `sha1($textJa)`, Status `new`)
  - `Reference` Relationen `occurrences`, `annotation`, `items`; `Talent::references()`.
  - `config('hololearn.priority_channels')`: die 21 Kanal-IDs aus dem Spec in
    Reihenfolge; `config('hololearn.min_references')` = 3;
    `config('hololearn.new_items_per_day')` = 10;
    `config('hololearn.intervals_hours')` = `[4, 8, 24, 48, 168, 336, 720, 2880]`.
  - `php artisan holodex:sync {--talents} {--songs} {--channel=}` (ohne Option: beides).

- [ ] **Step 1: Failing tests:**
  - Zwei Seiten à 50/3 Kanäle gefälscht (ohne HOLOSTARS) → 53 Talente; zweiter
    Lauf: weiterhin 53.
  - `Nene Ch.桃鈴ねね` → Talent `name_ja=桃鈴ねね`, eine `Reference(kind=name,
    text_ja=桃鈴ねね, alt_text='Momosuzu Nene')` mit einer `Occurrence`
    (`source_id='name:UCAWSyEs_Io8MtpY3m-zqILA'`, kein Video).
  - `Takanashi Kiara SubCh.` und `hololive ReGLOSS` → `is_secondary=true`, keine
    Namens-Referenz (Regel: `english_name` leer, oder Name enthält `Sub`,
    oder `talentName()` liefert `null`).
  - HOLOSTARS wird nicht importiert: Kanäle, deren `group`, `name` oder
    `english_name` ohne Beachtung der Großschreibung `holostars` enthält
    (z. B. Gruppe `HOLOSTARS UPROAR!!`, Kanal `HOLOSTARS English`), erzeugen
    kein Talent.
  - Kanal aus der Vorrangliste bekommt `priority` = Position in der Liste.
  - Antwort 403 → `HolodexException`, bereits gespeicherte Talente bleiben.
  - Antwort ohne Feld `id` in einem Eintrag → dieser Eintrag wird übersprungen
    und protokolliert, die übrigen gespeichert.
- [ ] **Step 2–4:** fehlschlagen lassen, umsetzen (Seiten holen, bis eine Seite
  weniger als 50 liefert), grün. Lokal `php artisan holodex:sync --talents`
  gegen die echte API: 108 Talente erwartet (138 Kanäle minus 30 HOLOSTARS,
  Stand 2026-11-23). Commit.

### Task 5: Songs importieren

**Files:**
- Create: `app/Holodex/SongSync.php`
- Modify: `app/Console/Commands/HolodexSync.php`, `routes/console.php` (Scheduler)
- Test: `tests/Feature/SongSyncTest.php`

**Interfaces:**
- Consumes: `Client`, `TextCleaner::songName`, `Reference::firstOrCreateFor`
- Produces: `SongSync::run(Talent $talent): int` (Anzahl neuer Fundstellen).
  Scheduler: `holodex:sync` täglich 03:30.

- [ ] **Step 1: Failing tests** (gefälschte Antworten in der Form aus dem Spec:
  `POST /songs/latest` mit `{channel_id, paginated: true, limit: 50, offset}` →
  `{total, items: [{name, original_artist, start, end, video_id, …}]}`):
  - `花に亡霊 / Ghost In A Flower` bei Video `c-ujFpxFD3k`, 4336–4580 →
    `Reference(kind=song, text_ja=花に亡霊, alt_text='Ghost In A Flower')` und
    `Occurrence(video_id, start_s=4336, end_s=4580,
    source_id='song:c-ujFpxFD3k:4336')`.
  - Derselbe Titel bei einem zweiten Talent → dieselbe Referenz, zweite Fundstelle.
  - `Ridin' on Dreams` (kein Japanisch) → nichts gespeichert.
  - Zweiter Lauf → keine neuen Zeilen; Abruf endet, sobald eine ganze Seite nur
    bekannte `source_id` enthält.
  - Eintrag ohne `start` → Fundstelle mit `start_s=null`, `source_id='song:<video>:0'`.
  - Antwort hat kein `items` → `HolodexException`; im Befehl gefangen, nächstes
    Talent läuft weiter, Exit-Code ≠ 0 am Ende.
- [ ] **Step 2–4:** fehlschlagen lassen, umsetzen, grün. Reihenfolge der Talente
  im Befehl: `priority` aufsteigend, dann der Rest. Commit.
- [ ] **Step 5:** Lokal `php artisan holodex:sync --songs --channel=UCAWSyEs_Io8MtpY3m-zqILA`;
  erwartet rund 1.900 Fundstellen. Anzahl verschiedener Referenzen notieren.
  Prüfen, ob Cover und eigene Songs enthalten sind (Fundstelle mit
  `video_id=31TxuKtAxF0`, きゅうくらりん). Fehlen sie, in `SongSync::run`
  zusätzlich `GET /videos?channel_id=…&topic=Music_Cover&include=songs` und
  `topic=Original_Song` seitenweise abrufen und deren `songs[]` gleich
  behandeln (mit Test).
  Für lange Läufe Fortschritt nach `~/.claude/progress/<session_id>` schreiben
  (`aktuell gesamt Songs`), danach Datei löschen.

### Task 6: Annotationen exportieren und einlesen

**Files:**
- Create: `app/Annotations/Exporter.php`, `app/Annotations/Importer.php`,
  Modell/Migration `Annotation`, Befehle `annotations:export`, `annotations:import`
- Test: `tests/Feature/AnnotationRoundtripTest.php`

**Interfaces:**
- Produces:
  - `php artisan annotations:export {--limit=200} {--retry-rejected}` schreibt
    JSONL nach stdout, eine Zeile je Referenz mit `status=new`, Vorrang-Talente
    zuerst:
    `{"key":"<kind>:<text_key>","kind":"song","text":"花に亡霊","alt":"Ghost In A Flower"}`
  - Annotation-Zeile (vom Agenten, in `data/annotations/*.jsonl`):

    ```json
    {"key":"song:<text_key>","reading":"はなにぼうれい","meaning":"Ghost in a flower",
     "alt_kind":"translation","model":"haiku",
     "words":[{"surface":"花","reading":"はな"},{"surface":"に","reading":"に"},
              {"surface":"亡霊","reading":"ぼうれい"}]}
    ```

  - `php artisan annotations:import {path=data/annotations}` liest alle Dateien,
    legt/aktualisiert `Annotation`, setzt `status=annotated` und ruft danach
    `Verifier` und `Decomposer` (Task 7) auf. Ausgabe:
    `imported N, skipped M, verified V, rejected R`.
  - `Importer::importLine(string $json): bool`

- [ ] **Step 1: Failing tests:** Export enthält nur `new`, höchstens `--limit`,
  Vorrang-Talente zuerst. Import einer gültigen Zeile → `Annotation` vorhanden.
  Übersprungen und gezählt, ohne Abbruch: kaputtes JSON; unbekannter `key`;
  `reading` mit Nicht-Kana; fehlendes `words`; `alt_kind` außerhalb der drei
  Werte. Zweimaliger Import derselben Datei ändert nichts. Eine bereits
  `verified` Referenz wird nicht überschrieben.
- [ ] **Step 2–4:** fehlschlagen lassen, umsetzen, grün. Commit.

### Task 7: Prüfung und Zerlegung

**Files:**
- Create: `app/Annotations/Verifier.php`, `app/Annotations/Decomposer.php`
- Test: `tests/Feature/VerifierTest.php`, `tests/Feature/DecomposerTest.php`

**Interfaces:**
- Consumes: `Kana::*`, `DictWord`, `DictKanji`, `Annotation`
- Produces:
  - `Verifier::verify(Reference $ref): array` – Liste von Fehlercodes; leer heißt
    bestanden. Setzt `status` auf `verified` oder `rejected` und speichert
    `check_errors`. Zusätzlich markiert es je Wort in `annotations.words`
    `"ok": true|false`.
  - `Decomposer::decompose(Reference $ref): void` – ersetzt die
    `item_reference`-Zeilen der Referenz.

Regeln (aus dem Spec, hier verbindlich):

```
Referenz-Ebene (Fehler → rejected):
  reading_not_kana     Lesung enthält Nicht-Kana
  surface_mismatch     Wort-Oberflächen aneinandergereiht ≠ text_ja
                       (Leerzeichen und Satzzeichen ignoriert)
  reading_mismatch     Wort-Lesungen aneinandergereiht ≠ reading
  kana_mismatch        ein Kana-Wort hat eine Lesung ≠ sich selbst (loose)
  romaji_mismatch      alt_kind=romaji bzw. kind=name, und
                       !looselyEqual(fromRomaji(alt_text), reading)
                       (bei kind=name auch in umgekehrter Namensreihenfolge geprüft)
Wort-Ebene (nur ok=false, kein Ablehnen der Referenz):
  Wort enthält Kanji und es gibt kein DictWord(spelling, reading)
  – bei kind=name zählt auch is_name=true, und ein bestandener Romaji-Vergleich
    der ganzen Referenz macht alle Wörter ok.
```

Zerlegung einer `verified` Referenz:

```
jedes Hiragana/Katakana im Text      → Item(hiragana|katakana), context_reading null
je Wort mit ok=true und Kanji:
  Wort selbst                        → Item(vocab), context_reading = Wort-Lesung
                                       (nicht bei kind=name)
  jedes Kanji darin                  → Item(kanji), context_reading = Wort-Lesung
  jedes Radikal jedes Kanji          → Item(radical)
Items für Kanji/Vokabel/Radikal werden bei Bedarf aus dict_* angelegt.
```

- [ ] **Step 1: Failing tests**, mindestens:
  - `花に亡霊` mit richtiger Annotation → `verified`; Items: Hiragana `に`, Kanji
    `花`, `亡`, `霊`, Vokabeln `花`, `亡霊`, Radikale von `花`; `context_reading`
    für `亡` = `ぼうれい`.
  - `今日` / `きょう` → Wort ok (Sonderlesung, steht in `dict_words`).
  - `宇宙` mit Lesung `そら` → Referenz `verified`, Wort `ok=false`, keine
    Kanji-/Vokabel-Verknüpfung.
  - `桃鈴ねね`, `alt_text='Momosuzu Nene'`, Lesung `ももすずねね` → `verified`,
    Kanji `桃`, `鈴` verknüpft, keine Vokabel. Mit Lesung `とうりんねね` →
    `rejected` mit `romaji_mismatch`.
  - `きゅうくらりん`, `alt_kind=romaji`, `alt_text='Kyu-Kurarin'` → `verified`,
    nur Kana-Items.
  - Lesung `はなにボウレイx` → `rejected` mit `reading_not_kana`.
  - Erneutes `decompose` nach geänderter Annotation hinterlässt keine alten Zeilen.
- [ ] **Step 2–4:** fehlschlagen lassen, umsetzen, grün. Commit.

### Task 8: `/annotate` in Claude Code

**Files:**
- Create: `.claude/commands/annotate.md`, `data/annotations/.gitkeep`
- Create: `app/Console/Commands/AnnotationsLint.php` (`annotations:lint {file}`)
- Test: `tests/Feature/AnnotationsLintTest.php`

**Interfaces:**
- Consumes: `annotations:export`, Annotation-Zeilenformat aus Task 6
- Produces: `annotations:lint` prüft nur das Format (dieselben Regeln wie
  `Importer::importLine`, ohne Datenbank), Exit-Code 1 bei Fehlern.

- [ ] **Step 1:** Test für `annotations:lint`: gültige Datei → 0, Datei mit
  einer kaputten Zeile → 1 und Zeilennummer in der Ausgabe. Umsetzen, grün.
- [ ] **Step 2:** `.claude/commands/annotate.md` schreiben. Der Befehl legt fest:
  1. Offene Texte holen: lokal `php artisan annotations:export --limit=$ARGUMENTS`
     (Standard 200), bzw. nach dem Deploy über
     `ssh ubuntu@u0x.de 'cd /opt/holo-learn && docker compose exec -T app php artisan annotations:export --limit=200'`.
  2. In Pakete zu 25 Zeilen teilen; je Paket ein Subagent mit `model: haiku`,
     mehrere Pakete parallel.
  3. Auftrag an den Subagenten, wörtlich: Für jede Zeile die Lesung in Hiragana
     (Katakana-Wörter in Katakana belassen ist ein Fehler: alles Hiragana), eine
     kurze englische Bedeutung, die Zerlegung in Wörter mit Lesung je Wort, und
     `alt_kind`: `romaji`, wenn `alt` die Aussprache des japanischen Textes
     wiedergibt, `translation`, wenn es eine Übersetzung ist, sonst `none`.
     Bei Unsicherheit über eine Lesung die Zeile weglassen statt raten. Antwort
     nur als JSONL im Format aus Task 6.
  4. Ergebnis nach `data/annotations/<JJJJ-MM-TT-HHMM>.jsonl`, dann
     `php artisan annotations:lint` – bei Fehlern die betroffenen Zeilen
     entfernen, nicht reparieren.
  5. Commit „Add annotations <Datum> (<n> Zeilen)“, Push, dann Import auslösen
     (lokal `php artisan annotations:import`; nach dem Deploy siehe Task 11).
  6. Abschlussmeldung: exportiert, annotiert, verified, rejected.
  Mit `--retry-rejected` und `model: sonnet` wird Abgelehntes einmal neu versucht.
- [ ] **Step 3:** Probelauf lokal mit `/annotate 50` auf den Nene-Daten aus
  Task 5. Erwartung: mindestens 35 von 50 `verified`. Die abgelehnten Zeilen mit
  Fehlercode ansehen; liegt der Fehler in einer Prüfregel statt in der
  Annotation, die Regel in Task 7 mit einem neuen Testfall korrigieren. Commit.

### Task 9: Lernlogik

**Files:**
- Create: `app/Learning/Srs.php`, `app/Learning/CardPicker.php`,
  `app/Learning/AnswerChecker.php`, Modell/Migration `Review`, `favorites`,
  `app/Http/Controllers/LearnController.php`, `FavoriteController.php`, Routen
- Test: `tests/Unit/SrsTest.php`, `tests/Feature/CardPickerTest.php`,
  `tests/Feature/LearnFlowTest.php`

**Interfaces:**
- Consumes: `Item`, `Reference`, `Occurrence`, Config aus Task 4, `Kana`
- Produces:
  - `Srs::next(int $stage, bool $correct): array{stage:int, due_at:CarbonImmutable}`
    – richtig: +1 (höchstens 8), falsch: −2 (mindestens 0);
    `due_at = now + intervals_hours[stage-1]`, bei Stufe 0 sofort fällig.
  - `CardPicker::next(User $user): ?Card` – `Card` hat `item`, `reference`,
    `occurrence` (oder `null`), `prompt` (`reading|meaning|radical_choice`),
    `choices` (nur Radikal), `remaining_references` (int).
  - `AnswerChecker::check(Item $item, Reference $ref, string $field, string $answer): bool`
  - Routen: `GET /learn` (Inertia-Seite `learn/index`, Prop `card`),
    `POST /learn/answer` (`item_id`, `reference_id`, `reading?`, `meaning?`,
    `choice?`, `override?`) → `{correct, expected, next_due}`,
    `POST /occurrences/{id}/dead`, `GET|PUT /favorites`.

Regeln:

```
Kartenwahl:  fällige Reviews (due_at ≤ jetzt, ältestes zuerst)
             sonst neues Item, wenn heute < new_items_per_day neu begonnen:
               Art-Reihenfolge hiragana → katakana → radical → kanji → vocab,
               innerhalb nach Anzahl verified Referenzen absteigend,
               nur Items mit ≥ min_references verified Referenzen
             sonst null („nichts fällig“)
Referenz:    zufällig unter den verified Referenzen des Items, nicht
             last_reference_id (außer es gibt nur eine); bevorzugt (Gewicht 3:1)
             Referenzen mit Fundstelle bei Favoriten des Nutzers, ohne Favoriten
             bei Talenten mit priority
Fundstelle:  zufällig unter den nicht toten der bevorzugten Talente, sonst
             beliebige nicht tote, sonst null (reiner Text)
Antwort:     Lesung: Kana::fromRomaji oder direkte Kana, loose-Vergleich mit
               context_reading (Kanji/Vokabel) bzw. Item-Lesung (Kana);
               bei Vokabeln zählt jede dict_words-Lesung der Schreibweise
             Bedeutung: klein geschrieben, getrimmt, ohne führendes „to “/„a “/
               „the “; richtig bei Gleichheit mit einer Bedeutung oder
               Levenshtein ≤ 1 (≤ 2 ab 8 Zeichen)
             Radikal: gewählte Bedeutung = richtige; 3 falsche aus anderen Radikalen
             Kanji/Vokabel sind nur richtig, wenn Lesung und Bedeutung stimmen
             override=true wertet eine falsche Antwort nachträglich als richtig
```

- [ ] **Step 1: Failing tests:** Stufenfolge 0→1 fällig in 4 h, 7→8 in 120 T,
  8 richtig bleibt 8, 1 falsch → 0. Picker: fälliges vor neuem; Item mit 2
  Referenzen wird nie neu angeboten; mit 5 Referenzen kommt in 20 Zügen nie
  zweimal hintereinander dieselbe; alle Fundstellen tot → `occurrence === null`;
  alle Referenzen eines begonnenen Items `rejected` → Karte wird übersprungen,
  kein Fehler; Tageslimit greift. Antworten `NE`, `ne `, `ね`, `ネ` für `ね` alle
  richtig; `ghost` ≈ `ghosts`; `override` setzt Stufe wie bei richtig.
  `POST /occurrences/{id}/dead` setzt `dead_at`. Gäste werden auf Login umgeleitet.
- [ ] **Step 2–4:** fehlschlagen lassen, umsetzen, grün. Commit.

### Task 10: Lern-Oberfläche

**Files:**
- Create: `resources/js/pages/learn/index.tsx`,
  `resources/js/components/youtube-clip.tsx`,
  `resources/js/pages/favorites/index.tsx`, `resources/js/pages/privacy.tsx`,
  `resources/js/pages/admin/rejected.tsx` + `AdminController`
- Modify: Navigation/Startseite des Starter-Kits (Dashboard → `/learn`)
- Test: `tests/Feature/PagesTest.php`

**Interfaces:**
- Consumes: Routen und Props aus Task 9.
- Produces: `GET /admin/rejected` (nur `is_admin`, paginierte abgelehnte
  Referenzen mit Fehlercodes), `GET /privacy`.

- [ ] **Step 1:** Den Skill `frontend-design:frontend-design` laden und die
  Gestaltung der Lernkarte daran ausrichten. Vorgabe aus dem Spec: Das
  abgefragte Element groß und hervorgehoben innerhalb der Referenz; darunter
  Talent, Art der Referenz und „noch N weitere Referenzen“.
- [ ] **Step 2:** `PagesTest`: `/learn`, `/favorites`, `/privacy` liefern 200 für
  angemeldete Nutzer; `/admin/rejected` 403 ohne `is_admin`; Fußzeilen-Texte
  aus den Global Constraints sind vorhanden.
- [ ] **Step 3:** Lernseite umsetzen. Ablauf: Eingabefeld hat Fokus; Enter
  sendet; Romaji wird beim Tippen zu Kana; nach der Antwort erscheint die
  Auflösung (richtig/falsch, erwartete Lesung, Bedeutung, automatische
  Übersetzung mit Kennzeichnung „automatisch übersetzt“ bzw. Holodex-Zweitform),
  der Clip startet, Enter lädt die nächste Karte. Knopf „war doch richtig“ bei
  falscher Bedeutung. Bei Namen steht „Lesung in diesem Namen“.
- [ ] **Step 4:** `youtube-clip.tsx`: YouTube-IFrame-Player-API mit `start` und
  `end`; bei Player-Fehler (Codes 100, 101, 150) `POST /occurrences/{id}/dead`
  und die Karte als reinen Text zeigen. Der Clip wird erst nach der Antwort
  geladen, damit der Ton die Lösung nicht verrät.
- [ ] **Step 5:** Favoriten-Seite (Talente mit Foto, an/aus), Datenschutz-Seite
  (welche Daten gespeichert werden: Konto, Lernstand; Einbettung von YouTube;
  Daten von Holodex), Admin-Liste.
- [ ] **Step 6:** `npm run build`, `php artisan test`. Dann im Browser prüfen,
  jeweils Desktop, Tablet, Mobil: eine Kana-, eine Kanji- und eine Radikal-Karte
  beantworten, Clip springt an die richtige Stelle. Screenshots ablegen. Commit.

### Task 11: Deploy auf u0x.de

**Files:**
- Create: `docker/Dockerfile`, `compose.yaml`, `docker/entrypoint.sh`, `DEPLOY.md`
- Modify: `/etc/caddy/Caddyfile` auf dem Server (ein neuer Block)

**Interfaces:**
- Produces: `https://holo-learn.u0x.de`; `DEPLOY.md` mit den Befehlen für
  Deploy, Annotation-Import und Einladung.

- [ ] **Step 1:** Auf dem Server nur lesend prüfen: `uname -m`, freier Speicher,
  belegte Ports (`ss -ltnp`), wie bestehende Blöcke im Caddyfile aussehen. Einen
  freien Port auf `127.0.0.1` wählen. DNS: `holo-learn.u0x.de` muss auf den
  Server zeigen (`dig +short`); fehlt der Eintrag, den Auftraggeber bitten, ihn
  anzulegen, und erst danach Caddy ändern.
- [ ] **Step 2:** `docker/Dockerfile` mehrstufig: Node baut `public/build`,
  Composer installiert ohne Dev-Pakete, Laufzeit auf FrankenPHP mit PHP 8.4
  (`pdo_sqlite`, `intl`, `mbstring`). `compose.yaml`: Dienste `app`
  (Port `127.0.0.1:<port>`), `queue` (`queue:work`), `scheduler`
  (`schedule:work`), gleiches Image; Volumes für `storage/`, `database/` und
  `./data` (nur lesend), `.env` per `env_file`. `entrypoint.sh` führt
  `migrate --force` und `config:cache` aus. Speichergrenze je Dienst 256 MB.
- [ ] **Step 3:** Lokal `docker compose up --build`, `/up` liefert 200, Login geht.
- [ ] **Step 4:** Auf dem Server: Repo nach `/opt/holo-learn` klonen, `.env` für
  Produktion anlegen (`APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://holo-learn.u0x.de`, `HOLODEX_API_KEY` vom Auftraggeber dort
  eintragen lassen oder per `scp` der lokalen `.env` – Schlüssel nie ausgeben).
  `docker compose up -d --build`. Scheitert der Frontend-Build am Speicher, das
  Image lokal für die Server-Architektur bauen und per `docker save | ssh … docker load`
  übertragen.
- [ ] **Step 5:** Caddy: Sicherung `Caddyfile.bak-<JJJJMMTT-HHMMSS>` anlegen (wie
  dort üblich), Block `holo-learn.u0x.de { reverse_proxy 127.0.0.1:<port> }`
  anhängen, `caddy validate`, dann `systemctl reload caddy`. Danach prüfen, dass
  zwei bestehende Seiten des Servers weiter antworten.
- [ ] **Step 6:** Auf dem Server: Wörterbuchdaten laden und `dict:import`,
  `db:seed --class=KanaSeeder`, `holodex:sync` (Vorrang-Talente zuerst; darf
  Stunden laufen), `annotations:import`, `invite:create --admin`. Die
  Einladungs-URL dem Auftraggeber geben.
- [ ] **Step 7:** `https://holo-learn.u0x.de` im Browser: registrieren, eine
  Karte beantworten, Clip läuft. `DEPLOY.md` schreiben (Deploy =
  `git pull && docker compose up -d --build`; nach neuen Annotationen =
  `git pull && docker compose exec app php artisan annotations:import`).
  `.claude/commands/annotate.md` auf die Server-Befehle umstellen. Commit, Push.

---

## Nicht in diesem Plan

Stream-Titel und Clips (Stufe 2), Begrüßungen und Momente (Stufe 3), der
zeitgesteuerte `/annotate`-Lauf ohne Oberfläche. Vor dem Freischalten für
Freunde: Cover-Richtlinien für Fan-Werke und Holodex-Bedingungen vollständig lesen.
