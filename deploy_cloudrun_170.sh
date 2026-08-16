#!/bin/bash
set -e

source /root/google-cloud-sdk/path.bash.inc 2>/dev/null || true
cd "$(dirname "$0")"

PROJECT_ID=$(gcloud config get-value project 2>/dev/null || echo "qiita-app-170")
REGION="us-central1"
SERVICE_NAME="serverless-cms"
BUCKET_NAME="${PROJECT_ID}-cms-data"
MEDIA_BUCKET_NAME="${PROJECT_ID}-cms-media"
SA_NAME="cms-sa"
SA_EMAIL="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"

PROJECT_NUMBER=$(gcloud projects describe ${PROJECT_ID} --format="value(projectNumber)" 2>/dev/null || echo "")

# OAuth 2.0 クライアントIDの設定 (自動取得できる場合は自動設定、不可の場合は既定値を使用)
AUTO_CLIENT_ID=""
if [ -n "${PROJECT_NUMBER}" ]; then
    OAUTH_CLIENT_JSON=$(gcloud alpha iap oauth-clients list projects/${PROJECT_ID}/brands/${PROJECT_NUMBER} --format="json" 2>/dev/null || echo "[]")
    AUTO_CLIENT_ID=$(echo "${OAUTH_CLIENT_JSON}" | jq -r '.[0].name' 2>/dev/null | awk -F'/' '{print $NF}' || echo "")
fi

if [ -n "${AUTO_CLIENT_ID}" ] && [ "${AUTO_CLIENT_ID}" != "null" ]; then
    GOOGLE_CLIENT_ID="${AUTO_CLIENT_ID}"
else
    GOOGLE_CLIENT_ID="${GOOGLE_CLIENT_ID:-YOUR_GOOGLE_CLIENT_ID}"
fi
GOOGLE_CLIENT_SECRET="${GOOGLE_CLIENT_SECRET:-YOUR_GOOGLE_CLIENT_SECRET}"
MY_EMAIL=$(gcloud config get-value account 2>/dev/null || echo "user@example.com")
ADMIN_EMAIL_HASH=$(echo -n "${MY_EMAIL}" | tr '[:upper:]' '[:lower:]' | sha256sum | awk '{print $1}')

echo "=== 1. ビルド用サービスアカウントの権限修正 ==="
if [ -n "${PROJECT_NUMBER}" ]; then
    gcloud projects add-iam-policy-binding ${PROJECT_ID} \
        --member="serviceAccount:${PROJECT_NUMBER}-compute@developer.gserviceaccount.com" \
        --role="roles/storage.admin" || true

    gcloud projects add-iam-policy-binding ${PROJECT_ID} \
        --member="serviceAccount:${PROJECT_NUMBER}@cloudbuild.gserviceaccount.com" \
        --role="roles/cloudbuild.builds.builder" || true
fi

echo "=== 2. Cloud Run 再デプロイ ==="
gcloud run deploy ${SERVICE_NAME} \
    --source . \
    --region ${REGION} \
    --allow-unauthenticated \
    --service-account=${SA_EMAIL} \
    --set-env-vars="GCS_BUCKET=${BUCKET_NAME},GCS_MEDIA_BUCKET=${MEDIA_BUCKET_NAME},GOOGLE_CLIENT_ID=${GOOGLE_CLIENT_ID},GOOGLE_CLIENT_SECRET=${GOOGLE_CLIENT_SECRET},ADMIN_EMAIL_HASH=${ADMIN_EMAIL_HASH},ADMIN_PASSWORD=\!qaz2wsx" \
    --max-instances 5 \
    --concurrency 1 \
    --cpu 0.08 \
    --memory 256Mi

echo "=== デプロイ完了 ==="
