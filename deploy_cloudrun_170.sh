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
    --set-env-vars="GCS_BUCKET=${BUCKET_NAME},GCS_MEDIA_BUCKET=${MEDIA_BUCKET_NAME},ADMIN_EMAIL_HASH=${ADMIN_EMAIL_HASH}" \
    --max-instances 5 \
    --concurrency 1 \
    --cpu 0.08 \
    --memory 256Mi

echo "=== デプロイ完了 ==="
