#!/bin/bash
set -e

# 各種パラメータの設定
PROJECT_ID="your-google-cloud-project-id"
REGION="asia-northeast1"
SERVICE_NAME="serverless-cms"
BUCKET_NAME="${PROJECT_ID}-cms-data"
MEDIA_BUCKET_NAME="${PROJECT_ID}-cms-media"

echo "🚀 Google Cloud本番デプロイシーケンスを開始します..."

# 1. プロジェクトのアクティブ化
gcloud config set project ${PROJECT_ID}

# 2. GCS バケットの作成
# ① 記事データ（JSON/Markdown）を格納するGCSバケットを作成します
if ! gcloud storage buckets describe gs://${BUCKET_NAME} &>/dev/null; then
    gcloud storage buckets create gs://${BUCKET_NAME} \
        --location=${REGION} \
        --uniform-bucket-level-access
fi

# ② メディアファイルを格納するGCSバケットを作成します
if ! gcloud storage buckets describe gs://${MEDIA_BUCKET_NAME} &>/dev/null; then
    gcloud storage buckets create gs://${MEDIA_BUCKET_NAME} \
        --location=${REGION} \
        --uniform-bucket-level-access
fi

# メディアバケットをパブリック公開するポリシーを付与
gcloud storage buckets add-iam-policy-binding gs://${MEDIA_BUCKET_NAME} \
    --member="allUsers" \
    --role="roles/storage.objectViewer" || true

# 3. Artifact Registryの作成
gcloud artifacts repositories create ${SERVICE_NAME}-repo \
    --repository-format=docker \
    --location=${REGION} \
    --description="Serverless CMS docker repository" || true

# 4. 専用サービスアカウントの作成と最小権限の付与 (セキュリティのベストプラクティス)
echo "🔒 専用サービスアカウントを作成し、最小権限を付与します..."
SA_NAME="cms-sa"
SA_EMAIL="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
gcloud iam service-accounts create ${SA_NAME} --display-name="Serverless CMS SA" || true

# GCSバケットへのアクセス権限
gcloud storage buckets add-iam-policy-binding gs://${BUCKET_NAME} --member="serviceAccount:${SA_EMAIL}" --role="roles/storage.objectAdmin"
gcloud storage buckets add-iam-policy-binding gs://${MEDIA_BUCKET_NAME} --member="serviceAccount:${SA_EMAIL}" --role="roles/storage.objectAdmin"

# Vertex AI へのアクセス権限
gcloud projects add-iam-policy-binding ${PROJECT_ID} --member="serviceAccount:${SA_EMAIL}" --role="roles/aiplatform.user"

# 5. コンテナのビルドとPush (Google Cloud Build)
gcloud builds submit --tag ${REGION}-docker.pkg.dev/${PROJECT_ID}/${SERVICE_NAME}-repo/${SERVICE_NAME}:latest .

# 6. Cloud Runへのデプロイ
# 作成した専用SAを割り当て、セッションアフィニティ、スロットリング有効状態でデプロイ
gcloud run deploy ${SERVICE_NAME} \
    --image ${REGION}-docker.pkg.dev/${PROJECT_ID}/${SERVICE_NAME}-repo/${SERVICE_NAME}:latest \
    --platform managed \
    --region ${REGION} \
    --allow-unauthenticated \
    --service-account=${SA_EMAIL} \
    --set-env-vars="GCS_BUCKET=${BUCKET_NAME},GCS_MEDIA_BUCKET=${MEDIA_BUCKET_NAME},GOOGLE_CLIENT_ID=your-google-client-id,GOOGLE_CLIENT_SECRET=your-google-client-secret,ADMIN_EMAIL_HASH=your-email-sha256-hash" \
    --max-instances 5 \
    --session-affinity \
    --concurrency 100 \
    --cpu 1 \
    --memory 1Gi

echo "✅ デプロイが完了しました！"