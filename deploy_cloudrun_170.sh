#!/bin/bash
set -e

source /root/google-cloud-sdk/path.bash.inc

PROJECT_ID="qiita-app-170"
REGION="us-central1"
SERVICE_NAME="serverless-cms"
BUCKET_NAME="${PROJECT_ID}-cms-data"
MEDIA_BUCKET_NAME="${PROJECT_ID}-cms-media"
SA_NAME="cms-sa"
SA_EMAIL="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"

PROJECT_NUMBER=$(gcloud projects describe ${PROJECT_ID} --format="value(projectNumber)" 2>/dev/null || true)

# OAuth 2.0 クライアントIDの設定 (自動取得できる場合は自動設定、不可の場合は既定値を使用)
OAUTH_CLIENT_JSON=$(gcloud alpha iap oauth-clients list projects/${PROJECT_ID}/brands/${PROJECT_NUMBER} --format="json" 2>/dev/null || true)
AUTO_CLIENT_ID=$(echo "${OAUTH_CLIENT_JSON}" | jq -r '.[0].name' 2>/dev/null | awk -F'/' '{print $NF}' || true)

if [ -n "${AUTO_CLIENT_ID}" ] && [ "${AUTO_CLIENT_ID}" != "null" ]; then
    GOOGLE_CLIENT_ID="${AUTO_CLIENT_ID}"
else
    GOOGLE_CLIENT_ID="757378940562-djqh3remqbljj40obqcf8iet1sq77cs8.apps.googleusercontent.com"
fi
MY_EMAIL=$(gcloud config get-value account)
ADMIN_EMAIL_HASH=$(echo -n "${MY_EMAIL}" | tr '[:upper:]' '[:lower:]' | sha256sum | awk '{print $1}')

echo "=== 1. ビルド用サービスアカウントの権限修正 ==="
PROJECT_NUMBER=$(gcloud projects describe ${PROJECT_ID} --format="value(projectNumber)")
gcloud projects add-iam-policy-binding ${PROJECT_ID} \
    --member="serviceAccount:${PROJECT_NUMBER}-compute@developer.gserviceaccount.com" \
    --role="roles/storage.admin" || true

gcloud projects add-iam-policy-binding ${PROJECT_ID} \
    --member="serviceAccount:${PROJECT_NUMBER}@cloudbuild.gserviceaccount.com" \
    --role="roles/cloudbuild.builds.builder" || true

echo "=== 2. Cloud Run 再デプロイ ==="
gcloud run deploy ${SERVICE_NAME} \
    --source . \
    --region ${REGION} \
    --allow-unauthenticated \
    --service-account=${SA_EMAIL} \
    --set-env-vars="GCS_BUCKET=${BUCKET_NAME},GCS_MEDIA_BUCKET=${MEDIA_BUCKET_NAME},GOOGLE_CLIENT_ID=${GOOGLE_CLIENT_ID},ADMIN_EMAIL_HASH=${ADMIN_EMAIL_HASH}" \
    --max-instances 5 \
    --concurrency 1 \
    --cpu 0.08 \
    --memory 256Mi

echo "=== デプロイ完了 ==="
SERVICE_URL=$(gcloud run services describe ${SERVICE_NAME} --region=${REGION} --format="value(status.url)")
echo "========================================================"
echo "🎉 デプロイ完了！あなたのブログURL: ${SERVICE_URL}"
echo "※ Google ログインの 'redirect_uri_mismatch' エラーを防ぐため、"
echo "   上記URLを Google Auth Platform の『承認済みの JavaScript 生成元』"
echo "   および『承認済みのリダイレクト URI』に追加して保存してください。"
echo "========================================================"
