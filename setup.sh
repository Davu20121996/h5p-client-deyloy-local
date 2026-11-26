#!/usr/bin/env bash
set -euo pipefail

pause=false
case "${1:-}" in
  '') ;;
  --pause) pause=true ;;
  *) echo 'Usage: ./setup.sh [--pause]' >&2; exit 2 ;;
esac

finish() {
  local status=$?
  if (( status != 0 )); then
    printf '\nSetup failed (exit code %s). Check the message above.\n' "$status" >&2
  fi
  if [[ $pause == true && -t 0 ]]; then
    read -r -p 'Press Enter to close...' || true
  fi
  return "$status"
}
trap finish EXIT

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
umask 077

# Set these before the first run to configure a deployment.
HTTP_PORT=${HTTP_PORT:-9000}
HTTP_BIND=${HTTP_BIND:-127.0.0.1}
APP_URL=${APP_URL:-http://localhost:$HTTP_PORT}
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-secure-lti}

if [[ -f .env.docker ]]; then
  DB_PASSWORD=$(sed -n 's/^DB_PASSWORD=//p' .env.docker)
  APP_URL=$(sed -n 's/^APP_URL=//p' .env.docker)
else
  DB_PASSWORD=$(openssl rand -hex 32)
fi

[[ $APP_URL =~ ^https?://[a-zA-Z0-9.-]+(:[0-9]+)?$ &&
   $DB_PASSWORD =~ ^[a-f0-9]{64}$ &&
   $HTTP_PORT =~ ^[0-9]{1,5}$ && $HTTP_BIND =~ ^[0-9.]+$ &&
   $COMPOSE_PROJECT_NAME =~ ^[a-z0-9][a-z0-9_-]*$ ]] || {
  echo 'Invalid deployment settings.' >&2
  exit 1
}

if [[ ! -f .env.docker ]]; then
  secure=false
  same_site=lax
  if [[ $APP_URL == https://* ]]; then
    secure=true
    same_site=none
  fi
  (set -o noclobber; cat > .env.docker <<EOF
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
HTTP_BIND=$HTTP_BIND
HTTP_PORT=$HTTP_PORT
APP_URL=$APP_URL
DB_PASSWORD=$DB_PASSWORD
SESSION_SECURE_COOKIE=$secure
SESSION_SAME_SITE=$same_site
EOF
  )
fi

if [[ ! -f source/.env ]]; then
  APP_KEY=$(openssl rand -base64 32)
  (set -o noclobber; cat > source/.env <<EOF
APP_NAME="Secure LTI"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$APP_KEY
APP_URL=$APP_URL
APP_LOCALE=vi
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=lti
DB_USERNAME=lti
DB_PASSWORD=$DB_PASSWORD
HASH_DRIVER=argon2id
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=$([[ $APP_URL == https://* ]] && echo true || echo false)
SESSION_SAME_SITE=$([[ $APP_URL == https://* ]] && echo none || echo lax)
CACHE_STORE=database
LOG_CHANNEL=stderr
EOF
  )
fi
compose=(docker compose --env-file .env.docker -f docker/compose.yaml)
if ! docker info --format '{{.ServerVersion}}' >/dev/null; then
  echo 'Start Docker Desktop and wait until the engine is ready, then rerun setup.' >&2
  exit 1
fi

echo 'Starting Docker services...'
if ! "${compose[@]}" up -d --build --wait; then
  "${compose[@]}" ps -a || true
  "${compose[@]}" logs --tail 40 nginx app db || true
  exit 1
fi
"${compose[@]}" exec -T nginx nginx -t

echo 'Installing Laravel dependencies...'
"${compose[@]}" exec -T app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

if ! grep -Eq '^APP_KEY=.+$' source/.env; then
  "${compose[@]}" exec -T app php artisan key:generate --force
fi

echo 'Preparing the database...'
"${compose[@]}" exec -T app php artisan migrate --force
"${compose[@]}" exec -T app php artisan lti:rotate-key --if-missing

"${compose[@]}" exec -T app php artisan db:seed --force

printf '\nSetup complete. Open %s\n' "$APP_URL"
