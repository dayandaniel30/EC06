#!/usr/bin/env sh
# =============================================================================
# Point d'entree du conteneur Laravel
# =============================================================================
# Attend que la base soit joignable, applique les migrations puis passe la main
# a la commande du conteneur (`artisan serve` pour l'API, `schedule:work` pour
# l'ordonnanceur).
# =============================================================================
set -e

DB_HOST="${DB_HOST:-laravel-db}"
DB_PORT="${DB_PORT:-3306}"
WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-120}"

log() {
    echo "[entrypoint] $*"
}

# --- 1. Attente de la base ------------------------------------------------
# `depends_on: service_healthy` couvre deja ce cas sous Docker Compose, mais
# l'image doit aussi demarrer correctement hors compose (Kubernetes, run manuel).
log "Attente de la base sur ${DB_HOST}:${DB_PORT} (timeout ${WAIT_TIMEOUT}s)..."

elapsed=0
until nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; do
    if [ "$elapsed" -ge "$WAIT_TIMEOUT" ]; then
        log "ERREUR : base injoignable apres ${WAIT_TIMEOUT}s." >&2
        exit 1
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

log "Base joignable apres ${elapsed}s."

# --- 2. Migrations --------------------------------------------------------
# Un seul conteneur doit les appliquer : l'ordonnanceur passe SKIP_MIGRATIONS.
if [ "${SKIP_MIGRATIONS:-false}" = "true" ]; then
    log "SKIP_MIGRATIONS=true : migrations ignorees."
else
    log "Application des migrations..."
    php artisan migrate --force
fi

# --- 3. Caches ------------------------------------------------------------
# Les caches sont vides plutot que reconstruits : la configuration provient de
# variables d'environnement qui peuvent changer d'un demarrage a l'autre.
log "Nettoyage des caches..."
php artisan config:clear
php artisan route:clear

log "Demarrage : $*"
exec "$@"
