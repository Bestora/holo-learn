#!/bin/sh
# Legt die Produktions-.env an: erzeugt APP_KEY und fragt den Holodex-Schlüssel ab (ohne Echo).
# Aufruf im Repo-Verzeichnis auf dem Server: sh docker/make-env.sh
set -eu
[ -e .env ] && { echo ".env existiert schon, nichts geändert." >&2; exit 1; }
printf 'Holodex-API-Key: ' >&2
stty -echo; read -r holodex; stty echo; echo >&2
[ -n "$holodex" ] || { echo "Leerer Schlüssel, abgebrochen." >&2; exit 1; }
umask 077
cat > .env <<EOF
APP_NAME=holo-learn
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://holo-learn.u0x.de

DB_CONNECTION=sqlite
DB_DATABASE=/db/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

HOLODEX_API_KEY=$holodex

MAIL_MAILER=log
LOG_CHANNEL=stderr
LOG_LEVEL=warning
EOF
echo ".env angelegt." >&2
