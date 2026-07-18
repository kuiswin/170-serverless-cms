<?php
require 'vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

$project_id = getenv('GOOGLE_CLOUD_PROJECT') ?: 'kym-ramen-project';
$bucket_name = getenv('GCS_BUCKET') ?: 'serverless-cms-data';
$media_bucket_name = getenv('GCS_MEDIA_BUCKET') ?: 'serverless-cms-media';
$admin_password = getenv('ADMIN_PASSWORD') ?: 'admin';

// GCS接続の初期化 (エミュレータ対応)
$storage_options = [];
if (getenv('STORAGE_EMULATOR_HOST')) {
    $storage_options['apiEndpoint'] = 'http://' . getenv('STORAGE_EMULATOR_HOST');
}
$storage = new StorageClient($storage_options);
$bucket = $storage->bucket($bucket_name);
$media_bucket = $storage->bucket($media_bucket_name);

// 認証処理 (簡易ログイン)
session_start();
$is_admin = isset($_SESSION['admin']) && $_SESSION['admin'] === true;

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin'] = true;
        header('Location: /');
        exit;
    }
}
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin']);
    header('Location: /');
    exit;
}

// 記事投稿処理
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $post_id = time();
    
    // 1. 画像生成 (Gemini API / Gemini Enterprise Agent Platform)
    $image_url = '';
    $region = 'asia-northeast1';
    if (!getenv('STORAGE_EMULATOR_HOST')) {
        // 本番環境のみ画像生成を実行
        $api_url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$project_id}/locations/{$region}/publishers/google/models/gemini-3.1-flash-image:generateContent";
        $payload = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "A professional featured image for: " . $title]]]
            ]
        ]);
        
        // cURL を用いたサービスアカウントの認証トークン取得とリクエスト
        // ここでは認証トークンはメタデータサーバーから自動取得 (ADC)
        $token_url = "http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token";
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Metadata-Flavor: Google'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $token_response = curl_exec($ch);
        curl_close($ch);
        
        if ($token_response) {
            $token_data = json_decode($token_response, true);
            $access_token = $token_data['access_token'];
            
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$access_token}",
                "Content-Type: application/json"
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            
            if ($res) {
                $res_data = json_decode($res, true);
                // レスポンスのパースと画像をGCSへ保存
                if (isset($res_data['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
                    $img_base64 = $res_data['candidates'][0]['content']['parts'][0]['inlineData']['data'];
                    $img_data = base64_decode($img_base64);
                    
                    $media_bucket->upload($img_data, [
                        'name' => "images/{$post_id}.png",
                        'metadata' => ['contentType' => 'image/png']
                    ]);
                    $image_url = "https://storage.googleapis.com/{$media_bucket_name}/images/{$post_id}.png";
                }
            }
        }
    } else {
        // ローカル環境（エミュレータ）時はエラー自動回避としてモック画像パスを設定
        $image_url = 'https://via.placeholder.com/800x400.png?text=Local+Gemini+Mock';
    }

    // 2. Markdownファイルのアップロード
    $bucket->upload($content, [
        'name' => "posts/{$post_id}.md",
        'metadata' => ['contentType' => 'text/markdown']
    ]);

    // 3. インデックス posts.json の更新
    $posts_object = $bucket->object('posts.json');
    $posts = [];
    if ($posts_object->exists()) {
        $posts = json_decode($posts_object->downloadAsString(), true) ?: [];
    }
    array_unshift($posts, [
        'id' => $post_id,
        'title' => $title,
        'image' => $image_url,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    $bucket->upload(json_encode($posts), [
        'name' => 'posts.json',
        'metadata' => ['contentType' => 'application/json']
    ]);

    header('Location: /');
    exit;
}

// 記事の読み込みと一覧表示
$posts_object = $bucket->object('posts.json');
$posts = [];
if ($posts_object->exists()) {
    $posts = json_decode($posts_object->downloadAsString(), true) ?: [];
}

$parsedown = new Parsedown();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Serverless CMS</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; line-height: 1.6; }
        .post { border-bottom: 1px solid #ccc; padding-bottom: 20px; margin-bottom: 20px; }
        .post img { max-width: 100%; height: auto; border-radius: 8px; }
        .admin-form { background: #f4f4f4; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        input[type="text"], textarea { width: 100%; padding: 10px; margin-bottom: 10px; box-sizing: border-box; }
        input[type="submit"] { background: #0084c7; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <header style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Serverless GCS CMS</h1>
        <?php if ($is_admin): ?>
            <a href="?action=logout">ログアウト</a>
        <?php else: ?>
            <form method="POST" style="display:flex; gap:10px;">
                <input type="hidden" name="action" value="login">
                <input type="password" name="password" placeholder="パスワード">
                <input type="submit" value="ログイン">
            </form>
        <?php endif; ?>
    </header>

    <?php if ($is_admin): ?>
        <div class="admin-form">
            <h2>新規投稿</h2>
            <form method="POST">
                <input type="text" name="title" placeholder="記事タイトル" required>
                <textarea name="content" rows="10" placeholder="記事本文 (Markdown)" required></textarea>
                <input type="submit" value="投稿する">
            </form>
        </div>
    <?php endif; ?>

    <main>
        <?php if (empty($posts)): ?>
            <p>記事がありません。</p>
        <?php else: ?>
            <?php foreach ($posts as $post): 
                $content_object = $bucket->object("posts/{$post['id']}.md");
                $html_content = '';
                if ($content_object->exists()) {
                    $html_content = $parsedown->text($content_object->downloadAsString());
                }
            ?>
                <article class="post">
                    <h2><?= htmlspecialchars($post['title']) ?></h2>
                    <small>投稿日: <?= $post['created_at'] ?></small>
                    <?php if ($post['image']): ?>
                        <div style="margin: 15px 0;"><img src="<?= htmlspecialchars($post['image']) ?>" alt="アイキャッチ"></div>
                    <?php endif; ?>
                    <div><?= $html_content ?></div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
