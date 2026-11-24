# Deploy (u0x.de)

Docker Compose mit drei Diensten aus einem Image (`docker/Dockerfile`, FrankenPHP/PHP 8.4):
`app` (Web, `127.0.0.1:8520`), `queue`, `scheduler`. Der Caddy des Hosts terminiert TLS.
Nur `app` führt beim Start `migrate --force` und die Caches aus. Manuelle Befehle laufen im
`scheduler` (384 MB statt 256 MB) oder in einem Einmal-Container, nicht im Web-Container.

Persistente Daten liegen in Docker-Volumes und überstehen Rebuilds:
`holo-learn_db` (SQLite unter `/db/database.sqlite`) und `holo-learn_storage` (`storage/`).
`./data` (Annotationen aus Git) ist nur lesend unter `/app/data` eingebunden.

## Erstes Deploy

```sh
# Der Server hat keine GitHub-Zugangsdaten: per HTTPS klonen
sudo mkdir /opt/holo-learn && sudo chown ubuntu: /opt/holo-learn
git clone https://github.com/Bestora/holo-learn.git /opt/holo-learn
cd /opt/holo-learn
git checkout stufe-1
sh docker/make-env.sh    # legt .env an (Inhalt siehe unten), erzeugt APP_KEY, fragt den Holodex-Key ab
docker compose up -d --build
docker compose ps    # app: healthy
```

`.env`:

```dotenv
APP_NAME=holo-learn
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://holo-learn.u0x.de

DB_CONNECTION=sqlite
DB_DATABASE=/db/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

HOLODEX_API_KEY=...

# Kein Mailserver: Mails werden verworfen (der log-Mailer schreibt nur auf Stufe debug).
# Wer sich mit Einladung registriert, ist sofort bestätigt.
MAIL_MAILER=log
LOG_CHANNEL=stderr
LOG_LEVEL=warning
# Mit SMTP stattdessen:
# MAIL_MAILER=smtp
# MAIL_HOST=...
# MAIL_PORT=587
# MAIL_USERNAME=...
# MAIL_PASSWORD=...
# MAIL_FROM_ADDRESS=holo-learn@u0x.de
# MAIL_FROM_NAME=holo-learn
```

## Caddy

In `/etc/caddy/Caddyfile` anhängen (vorher Sicherung `Caddyfile.bak-<JJJJMMTT-HHMMSS>`):

```caddy
holo-learn.u0x.de {
	reverse_proxy 127.0.0.1:8520
}
```

```sh
sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl reload caddy
```

## Erstbefüllung

Reihenfolge einhalten: Annotationen brauchen die Referenzen aus `holodex:sync`.

```sh
cd /opt/holo-learn
# Wörterbuch (jmdict-simplified, neueste Version)
rm -rf /tmp/dict && mkdir /tmp/dict && cd /tmp/dict
curl -s https://api.github.com/repos/scriptin/jmdict-simplified/releases/latest \
  | grep -oE 'https://[^"]+/(jmdict-eng-[0-9]|jmnedict-all-|kanjidic2-en-|kradfile-)[^"/]*\.json\.tgz' \
  | xargs -n1 curl -sSLO
for f in *.tgz; do tar xzf "$f" && rm "$f"; done
ls *.json | wc -l   # muss 4 sein (jmdict-eng, jmnedict-all, kanjidic2-en, kradfile)
cd /opt/holo-learn
docker compose run --rm -v /tmp/dict:/dict:ro app php artisan dict:import /dict
rm -rf /tmp/dict

docker compose exec scheduler php artisan db:seed --class=KanaSeeder --force
docker compose exec scheduler php artisan holodex:sync          # kann Stunden dauern
docker compose exec scheduler php artisan annotations:import
docker compose exec scheduler php artisan invite:create --admin # gibt den Registrierungslink aus
```

`holodex:sync` läuft danach Montag–Samstag um 03:30 und sonntags um 04:30 voll über den `scheduler`.
Die Zeiten gelten in Europe/Berlin, weil `compose.yaml` `TZ` und `SCHEDULE_TIMEZONE` setzt;
ohne diese Variablen wären es UTC. Zeitstempel in der Datenbank bleiben UTC.

## Update

```sh
cd /opt/holo-learn && git pull && docker compose up -d --build
```

Nach neuen Annotationen:

```sh
cd /opt/holo-learn && git pull && docker compose exec scheduler php artisan annotations:import
```

Weitere Einladung: `docker compose exec scheduler php artisan invite:create` (Gruppenlink z. B. `--uses=10 --days=14`; Admin-Einladungen bleiben einmalig).
Übersicht aller Einladungen: `docker compose exec scheduler php artisan invite:list`
Passwort zurücksetzen (kein Mailserver, daher kein „Passwort vergessen“): `docker compose exec scheduler php artisan tinker --execute='App\Models\User::where("email", "name@example.com")->firstOrFail()->update(["password" => "neues-passwort"]);'`

## Wörterbuch monatlich aktualisieren

Die EDRDG-Lizenz verlangt aktuelle Daten. Den Block „Wörterbuch“ aus der Erstbefüllung erneut
ausführen; `dict:import` ersetzt die Wörter vollständig und aktualisiert die Kanji.
Der Import sperrt die Datenbank für seine ganze Laufzeit (Schreibzugriffe warten bzw. scheitern):
nachts ausführen oder vorher `docker compose exec app php artisan down` (danach `up`).

## Backup

```sh
cd /opt/holo-learn
docker compose exec app sh -c 'rm -f /db/backup.sqlite && php -r "(new PDO(\"sqlite:/db/database.sqlite\"))->exec(\"VACUUM INTO \x27/db/backup.sqlite\x27\");"'
docker compose cp app:/db/backup.sqlite ~/holo-learn-$(date +%F).sqlite
docker compose exec app rm /db/backup.sqlite
```

`VACUUM INTO` erzeugt eine konsistente Kopie bei laufender App.

## Logs

```sh
docker compose logs -f app queue scheduler     # inkl. Laravel-Log (LOG_CHANNEL=stderr)
docker compose logs --since 24h scheduler       # z. B. nächtlicher holodex:sync
docker compose exec scheduler php artisan schedule:list
```

Docker rotiert die Logs (json-file, 3 × 10 MB je Dienst), im Volume wächst nichts.
