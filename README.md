# Serverless Journal (GCS Flat-File CMS)

Google Cloud 実践検証シリーズ【第170弾】のソースコード実体です。

Cloud Run（フラクショナル 0.08 vCPU）× Google Cloud Storage（GCS 世代番号による楽観的ロック）× Gemini API による、アクセスゼロなら維持費完全0円のサーバーレスCMS検証環境です。

## 📖 詳しい解説・チュートリアル
本リポジトリの設計思想、ローカル検証（Docker Compose / fake-gcs-server）、および Google Cloud 本番デプロイ手順の詳細解説は、Qiita および技術ブログにて公開しています：

👉 **Qiita 記事一覧**: [https://qiita.com/kuis](https://qiita.com/kuis)  
👉 **Author Blog**: [https://kuis.win](https://kuis.win)

---

## 🚀 クイックスタート (ローカル検証)

```bash
# コンテナ起動 (fake-gcs-server + PHP CMS)
docker compose up -d

# ブラウザでアクセス
open http://localhost:8080/
```

---

* 📜 **License**: MIT License (Copyright (c) 2026 kuiswin)
