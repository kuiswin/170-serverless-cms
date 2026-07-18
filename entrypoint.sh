#!/bin/bash
set -e

echo "=== Initializing Serverless CMS (GCS Flat-File) ==="

# Reflect Apache port configuration from the environment variable (default to 80 for local docker compose)
TARGET_PORT=${PORT:-80}
echo "[Apache] Configuring virtual host to listen on port: ${TARGET_PORT}..."
sed -i "s/Listen 80/Listen ${TARGET_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${TARGET_PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Create emulator buckets if environment variables are set and running locally
if [ -n "$STORAGE_EMULATOR_HOST" ]; then
    echo "Waiting for GCS emulator to start at ${STORAGE_EMULATOR_HOST}..."
    until curl -s "${STORAGE_EMULATOR_HOST}/storage/v1/b" > /dev/null; do
        sleep 1
    done
    echo "GCS emulator is up. Creating buckets..."

    if [ -n "$GCS_BUCKET" ]; then
        echo "[GCS Emulator] Creating local data bucket: $GCS_BUCKET..."
        curl -s -X POST --data "{\"name\":\"$GCS_BUCKET\"}" \
             -H "Content-Type: application/json" \
             "${STORAGE_EMULATOR_HOST}/storage/v1/b?project=${GOOGLE_CLOUD_PROJECT:-local-project}" || true
    fi

    if [ -n "$GCS_MEDIA_BUCKET" ]; then
        echo "[GCS Emulator] Creating local media bucket: $GCS_MEDIA_BUCKET..."
        curl -s -X POST --data "{\"name\":\"$GCS_MEDIA_BUCKET\"}" \
             -H "Content-Type: application/json" \
             "${STORAGE_EMULATOR_HOST}/storage/v1/b?project=${GOOGLE_CLOUD_PROJECT:-local-project}" || true
    fi
    echo "Buckets initialized."
fi

echo "[CMS] Starting Apache Web Server..."
exec apache2-foreground