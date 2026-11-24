# Deploy (u0x.de)

Docker Compose mit drei Diensten aus einem Image (`docker/Dockerfile`, FrankenPHP/PHP 8.4):
`app` (Web, `127.0.0.1:8520`), `queue`, `scheduler`. Der Caddy des Hosts terminiert TLS.
Nur `app` führt beim Start `migrate --force` und die Caches aus.

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
nano .env    # Inhalt siehe unten, APP_KEY zunächst leer lassen
docker compose run --rm app php artisan key:generate --show   # Ausgabe als APP_KEY eintragen
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
cd /opt/holo-learn
docker compose run --rm -v /tmp/dict:/dict:ro app php artisan dict:import /dict
rm -rf /tmp/dict

docker compose exec app php artisan db:seed --class=KanaSeeder --force
docker compose exec app php artisan holodex:sync          # kann Stunden dauern
docker compose exec app php artisan annotations:import
docker compose exec app php artisan invite:create --admin # gibt den Registrierungslink aus
```

`holodex:sync` läuft danach täglich um 03:30 (Sonntag 04:30 voll) über den `scheduler`.

## Update

```sh
cd /opt/holo-learn && git pull && docker compose up -d --build
```

Nach neuen Annotationen:

```sh
cd /opt/holo-learn && git pull && docker compose exec app php artisan annotations:import
```

Weitere Einladung: `docker compose exec app php artisan invite:create`

## Wörterbuch monatlich aktualisieren

Die EDRDG-Lizenz verlangt aktuelle Daten. Den Block „Wörterbuch“ aus der Erstbefüllung erneut
ausführen; `dict:import` ersetzt die Wörter vollständig und aktualisiert die Kanji.

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
docker compose logs -f app queue scheduler
docker compose exec app tail -f storage/logs/laravel.log
docker compose exec scheduler php artisan schedule:list
```
