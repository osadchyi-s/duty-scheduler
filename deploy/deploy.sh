#!/usr/bin/env bash
# Deployment of the production and staging stacks on one server (compose.prod.yml).
# Both environments use the same images; a tag tested on staging is promoted to
# production as is. Images are built locally, so no registry is needed.
#
# Usage:
#   deploy/deploy.sh build [tag]            build wfm-app:<tag> and wfm-web:<tag> (default tag: current git commit)
#   deploy/deploy.sh staging [tag]          deploy to staging (builds the tag when missing)
#   deploy/deploy.sh prod <tag>             deploy an already built tag to production (backs up the database first)
#   deploy/deploy.sh <env> create-admin     create the first administrator
#   deploy/deploy.sh <env> backup           dump the database to deploy/backups/
#   deploy/deploy.sh <env> import <dump>    load a dump (pg_dump --no-owner --no-privileges, .sql or .sql.gz)
#                                           into the environment's empty database
#   deploy/deploy.sh <env> status|logs|down|artisan <command...>
#   deploy/deploy.sh key                    print a new APP_KEY for an env file
#
# <env> is "prod" or "staging"; its settings are read from deploy/<env>.env.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

die() { echo "Error: $*" >&2; exit 1; }

default_tag() {
    local tag
    tag="$(git rev-parse --short HEAD)"
    # Uncommitted changes get their own tag, so a dirty build never overwrites a clean one.
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        tag="${tag}-dirty-$(date +%Y%m%d%H%M%S)"
    fi
    echo "$tag"
}

image_exists() { docker image inspect "$1" >/dev/null 2>&1; }

build() {
    local tag="$1"
    echo "Building wfm-app:$tag and wfm-web:$tag ..."
    docker build --target prod -t "wfm-app:$tag" .
    docker build --target web -t "wfm-web:$tag" .
}

env_file() {
    local env="$1" file="$ROOT/deploy/$1.env"
    [ -f "$file" ] || die "$file not found. Create it from deploy/$env.env.example."
    grep -q '^APP_KEY=base64:' "$file" || die "APP_KEY is empty in $file. Generate one with: deploy/deploy.sh key"
    echo "$file"
}

current_tag() {
    local file="$ROOT/deploy/.$1.tag"
    [ -f "$file" ] || die "$1 has not been deployed yet."
    cat "$file"
}

# Runs docker compose for an environment: its own project name keeps containers,
# networks and volumes separate from the other environment and from development.
compose() {
    local env="$1" tag="$2"; shift 2
    local file; file="$(env_file "$env")"
    WFM_ENV_FILE="$file" WFM_TAG="$tag" \
        docker compose -p "wfm_$env" -f "$ROOT/compose.prod.yml" --env-file "$file" "$@"
}

backup() {
    local env="$1" tag="$2"
    if [ -z "$(compose "$env" "$tag" ps -q db 2>/dev/null)" ]; then
        echo "No running database to back up."
        return
    fi
    mkdir -p "$ROOT/deploy/backups"
    local target
    target="$ROOT/deploy/backups/$env-$(date +%Y%m%d-%H%M%S).sql.gz"
    compose "$env" "$tag" exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$target"
    echo "Database backup: $target"
}

# Loads existing data (e.g. from the development database) before the first
# deployment. Refuses to touch a database that already has tables.
import_dump() {
    local env="$1" dump="$2" tag
    [ -f "$dump" ] || die "$dump not found."
    tag="$(cat "$ROOT/deploy/.$env.tag" 2>/dev/null || default_tag)"

    compose "$env" "$tag" up -d --wait db
    local tables
    tables="$(compose "$env" "$tag" exec -T db sh -c \
        'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select count(*) from information_schema.tables where table_schema = '"'"'public'"'"'"')"
    [ "$tables" = "0" ] || die "The $env database is not empty ($tables tables). Import only works into an empty database."

    echo "Importing $dump into the $env database ..."
    if [[ "$dump" == *.gz ]]; then
        gunzip -c "$dump"
    else
        cat "$dump"
    fi | compose "$env" "$tag" exec -T db sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    echo "Done. Now deploy the application: deploy/deploy.sh $env [tag]"
}

deploy() {
    local env="$1" tag="$2"
    env_file "$env" >/dev/null

    if ! image_exists "wfm-app:$tag" || ! image_exists "wfm-web:$tag"; then
        [ "$env" = staging ] || die "Images for tag $tag are missing. Build and test them on staging first."
        build "$tag"
    fi

    if [ "$env" = prod ] && [ -f "$ROOT/deploy/.prod.tag" ]; then
        backup prod "$(current_tag prod)"
    fi

    echo "Deploying $tag to $env ..."
    compose "$env" "$tag" up -d --wait db redis
    compose "$env" "$tag" run --rm app php artisan migrate --force
    compose "$env" "$tag" up -d --wait --remove-orphans

    echo "$tag" > "$ROOT/deploy/.$env.tag"
    echo "Done: $env runs $tag on port $(grep '^APP_PORT=' "$(env_file "$env")" | cut -d= -f2)."
}

command="${1:-}"
case "$command" in
    build)
        build "${2:-$(default_tag)}"
        ;;
    key)
        echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
        ;;
    staging|prod)
        env="$command"
        action="${2:-}"
        case "$action" in
            create-admin)
                compose "$env" "$(current_tag "$env")" run --rm app php artisan wfm:create-admin
                ;;
            backup)
                backup "$env" "$(current_tag "$env")"
                ;;
            import)
                [ -n "${3:-}" ] || die "Usage: deploy/deploy.sh $env import <dump.sql[.gz]>"
                import_dump "$env" "$3"
                ;;
            status)
                echo "Tag: $(current_tag "$env")"
                compose "$env" "$(current_tag "$env")" ps
                ;;
            logs)
                compose "$env" "$(current_tag "$env")" logs -f --tail=100
                ;;
            down)
                # Volumes (database, uploads) are kept.
                compose "$env" "$(current_tag "$env")" down
                ;;
            artisan)
                shift 2
                compose "$env" "$(current_tag "$env")" exec app php artisan "$@"
                ;;
            "")
                [ "$env" = staging ] || die "Specify the tag to promote: deploy/deploy.sh prod <tag> (the tag running on staging: $(cat "$ROOT/deploy/.staging.tag" 2>/dev/null || echo none))"
                deploy "$env" "$(default_tag)"
                ;;
            *)
                deploy "$env" "$action"
                ;;
        esac
        ;;
    *)
        # Prints the header comment of this file.
        awk 'NR > 1 && !/^#/ { exit } NR > 1 { sub(/^# ?/, ""); print }' "$0"
        exit 1
        ;;
esac
