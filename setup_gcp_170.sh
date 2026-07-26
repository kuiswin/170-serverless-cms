#!/bin/bash
set -e

source /root/google-cloud-sdk/path.bash.inc

PROJECT_ID="qiita-app-170"
REGION="us-central1"
SERVICE_NAME="serverless-cms"
BUCKET_NAME="${PROJECT_ID}-cms-data"
MEDIA_BUCKET_NAME="${PROJECT_ID}-cms-media"
MY_EMAIL=$(gcloud config get-value account)

echo "=== 1. 設定 & API有効化 ==="
gcloud config set project ${PROJECT_ID}
gcloud services enable run.googleapis.com storage.googleapis.com artifactregistry.googleapis.com aiplatform.googleapis.com iap.googleapis.com

echo "=== 2. GCS バケット作成 ==="
gcloud storage buckets create gs://${BUCKET_NAME} --location=${REGION} --uniform-bucket-level-access || true
gcloud storage buckets create gs://${MEDIA_BUCKET_NAME} --location=${REGION} --uniform-bucket-level-access || true

echo "=== 3. メディアバケット一般公開設定 ==="
gcloud storage buckets add-iam-policy-binding gs://${MEDIA_BUCKET_NAME} \
    --member="allUsers" \
    --role="roles/storage.objectViewer" || true

echo "=== 4. サービスアカウント作成 & 権限付与 ==="
SA_NAME="cms-sa"
SA_EMAIL="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
gcloud iam service-accounts create ${SA_NAME} --display-name="Serverless CMS SA" || true

gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} --member="serviceAccount:${SA_EMAIL}" --role="roles/storage.objectAdmin" || true
gcloud storage buckets add-iam-policy-binding gs://${MEDIA_BUCKET_NAME} --member="serviceAccount:${SA_EMAIL}" --role="roles/storage.objectAdmin" || true
gcloud projects add-iam-policy-binding ${PROJECT_ID} --member="serviceAccount:${SA_EMAIL}" --role="roles/aiplatform.user" || true

echo "=== 5. OAuth同意画面 & OAuth 2.0クライアントIDの完全自動生成 ==="
PROJECT_NUMBER=$(gcloud projects describe ${PROJECT_ID} --format="value(projectNumber)")

# alpha components install
gcloud components install alpha --quiet || true

# OAuth Brand Create (Requires Organization)
gcloud alpha iap oauth-brands create \
    --support_email="${MY_EMAIL}" \
    --application_title="Serverless CMS" --quiet || true

# OAuth Client Create
gcloud alpha iap oauth-clients create \
    projects/${PROJECT_ID}/brands/${PROJECT_NUMBER} \
    --display_name="Serverless CMS Client" --quiet || true

echo "=== 完了 ==="
