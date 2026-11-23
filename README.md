# holo-learn

Japanisch lernen (Kana, Radikale, Kanji, Vokabeln) anhand von hololive-Referenzen.

## Wörterbuchdaten

Die Wörterbuchtabellen (`dict_words`, `dict_kanji`) werden aus den JSON-Releases von
[jmdict-simplified](https://github.com/scriptin/jmdict-simplified/releases) befüllt:
`jmdict-eng`, `jmnedict-all`, `kanjidic2-en` und `kradfile`. Die Dateien werden nach
`storage/app/dict/` entpackt (nicht versioniert) und importiert:

```sh
gh release download --repo scriptin/jmdict-simplified --dir storage/app/dict \
  -p 'jmdict-eng-[0-9]*.json.tgz' -p 'jmnedict-all-*.json.tgz' \
  -p 'kanjidic2-en-*.json.tgz' -p 'kradfile-*.json.tgz'
for f in storage/app/dict/*.tgz; do tar xzf "$f" -C storage/app/dict && rm "$f"; done
php artisan dict:import storage/app/dict
```

Kana-Elemente: `php artisan db:seed --class=KanaSeeder`.

Lernstufe und Spezial-Kennzeichen der Elemente (`items.level`, `items.is_special`) leiten
sich aus JLPT-Stufe, Schulstufe und „häufig“-Markierung ab (`App\Learning\ItemLevel`).
Nach einem neuen Wörterbuch-Import neu berechnen: `php artisan items:relevel`.

### Lizenz und Quellenangabe

Diese Anwendung verwendet die Dateien [JMdict](https://www.edrdg.org/wiki/index.php/JMdict-EDICT_Dictionary_Project),
[JMnedict](https://www.edrdg.org/enamdict/enamdict_doc.html),
[KANJIDIC2](https://www.edrdg.org/wiki/index.php/KANJIDIC_Project) und
[KRADFILE](https://www.edrdg.org/krad/kradinf.html). Diese Dateien sind Eigentum der
[Electronic Dictionary Research and Development Group](https://www.edrdg.org/) und werden
gemäß der [Lizenz der Gruppe](https://www.edrdg.org/edrdg/licence.html) unter
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) verwendet. Die
JSON-Umwandlung stammt aus [jmdict-simplified](https://github.com/scriptin/jmdict-simplified).

Die Lizenz verlangt eine regelmäßige Aktualisierung der Daten auf die neueste Version
(Import oben erneut ausführen) und einen Quellenhinweis in der Anwendung selbst.
