#!/bin/sh
# Update auf dem Server: sh docker/deploy.sh
# Baut das neue Image, während die alte Instanz weiterläuft, und tauscht erst dann die Container.
# Während des Tauschs (wenige Sekunden) hält Caddy Anfragen zurück (lb_try_duration), statt 502 zu liefern.
set -eu
cd "$(dirname "$0")/.."
git pull --ff-only
docker compose build app
docker compose up -d
i=0
until [ "$(docker inspect -f '{{.State.Health.Status}}' holo-learn-app-1)" = healthy ]; do
    i=$((i + 1))
    [ "$i" -gt 30 ] && { echo "app wird nicht healthy:" >&2; docker compose logs --tail 30 app >&2; exit 1; }
    sleep 3
done
docker image prune -f >/dev/null
echo "Deploy fertig: $(git log --oneline -1)"
