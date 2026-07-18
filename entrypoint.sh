#!/bin/bash
set -e

# Apacheのポート設定を環境変数から反映 (Cloud Run対応)
sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT:-8080}>/g" /etc/apache2/sites-available/000-default.conf

# ローカル検証（エミュレータ接続時）のみ自動バケット作成
if [ -n "$STORAGE_EMULATOR_HOST" ]; then
    echo "Waiting for GCS emulator to start..."
    until curl -s "http://${STORAGE_EMULATOR_HOST}/storage/v1/b" > /dev/null; do
        sleep 1
    done
    echo "GCS emulator is up. Creating buckets..."
    
    # バケット作成 API をコール
    curl -X POST "http://${STORAGE_EMULATOR_HOST}/storage/v1/b?project=${GOOGLE_CLOUD_PROJECT:-kym-ramen-project}" \
         -H "Content-Type: application/json" \
         -d "{\"name\": \"${GCS_BUCKET:-serverless-cms-data}\"}" || true

    curl -X POST "http://${STORAGE_EMULATOR_HOST}/storage/v1/b?project=${GOOGLE_CLOUD_PROJECT:-kym-ramen-project}" \
         -H "Content-Type: application/json" \
         -d "{\"name\": \"${GCS_MEDIA_BUCKET:-serverless-cms-media}\"}" || true

    echo "Buckets initialized."
fi

# Apacheの起動
exec apache2-foreground
