<?php
/**
 * Custom GCS-Backed Serverless CMS (FinOps Journal)
 * A lightweight, ultra-fast, auto-scaling flat-file CMS.
 * No databases, no background sync. Direct GCS read/write.
 * Backed by Google Sign-in OIDC for administration.
 */

require 'vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

// Start session for admin auth
session_start();

$bucket_name = getenv('GCS_BUCKET');
$media_bucket_name = getenv('GCS_MEDIA_BUCKET') ?: $bucket_name; // Fallback to main bucket if media bucket not set
$admin_password = getenv('ADMIN_PASSWORD') ?: 'admin'; // Default password (dev fallback)

// Google OAuth 2.0 Credentials
$google_client_id = getenv('GOOGLE_CLIENT_ID');
$google_client_secret = getenv('GOOGLE_CLIENT_SECRET');
$admin_email_hash = getenv('ADMIN_EMAIL_HASH');
$is_oauth_configured = !empty($google_client_id) && !empty($google_client_secret) && !empty($admin_email_hash);

// Initialize GCS client
// SDK automatically respects STORAGE_EMULATOR_HOST if set in local docker environment
try {
    $config = [];
    if (getenv('STORAGE_EMULATOR_HOST')) {
        $config['apiEndpoint'] = getenv('STORAGE_EMULATOR_HOST');
    }
    $storage = new StorageClient($config);
    $bucket = $storage->bucket($bucket_name);
} catch (Exception $e) {
    die("GCS Connection Error: " . $e->getMessage());
}

// Helper to determine GCS file public URL
function get_gcs_url($bucket_name, $filename) {
    if (getenv('STORAGE_EMULATOR_HOST')) {
        // Docker Host access port mapping for fake-gcs-server (localhost:4443)
        return "http://localhost:4443/{$bucket_name}/{$filename}";
    }
    return "https://storage.googleapis.com/{$bucket_name}/{$filename}";
}

// Load post metadata list from GCS (posts.json)
function load_posts_metadata($bucket) {
    $object = $bucket->object('posts.json');
    if ($object->exists()) {
        $content = $object->downloadAsString();
        return json_decode($content, true) ?: [];
    }
    return [];
}

// Save post metadata list back to GCS
function save_posts_metadata($bucket, $metadata) {
    $bucket->upload(
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ['name' => 'posts.json']
    );
}

// Google OAuth 2.0 Helpers
function get_oauth_redirect_uri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return "{$protocol}://{$host}/";
}

function build_oauth_url($state, $client_id, $redirect_uri) {
    $params = [
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'scope'         => 'openid email profile',
        'state'         => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function exchange_oauth_code($code, $client_id, $client_secret, $redirect_uri) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    return $body ? json_decode($body, true) : null;
}

function verify_id_token($id_token, $client_id) {
    $info_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
    $info_json = @file_get_contents($info_url);
    if ($info_json === false) {
        return null;
    }

    $verify = json_decode($info_json, true);
    if (!is_array($verify)) {
        return null;
    }

    $aud_ok = isset($verify['aud']) && $verify['aud'] === $client_id;
    $iss_ok = isset($verify['iss']) && in_array($verify['iss'], ['https://accounts.google.com', 'accounts.google.com'], true);
    $exp_ok = isset($verify['exp']) && ((int)$verify['exp'] > time());

    if (!($aud_ok && $iss_ok && $exp_ok)) {
        return null;
    }

    return $verify;
}

// Generate featured image using Vertex AI (Gemini 3.1 Flash Image)
function generate_featured_image($title, $post_id, $media_bucket_name, $storage) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Metadata-Flavor: Google\r\n",
            'timeout' => 2
        ]
    ];
    $context = stream_context_create($opts);
    
    // Get Project ID from Metadata Server
    $project_id = @file_get_contents('http://metadata.google.internal/computeMetadata/v1/project/project-id', false, $context);
    if ($project_id === false) {
        error_log('Vertex AI: Not running on Google Cloud. Skipping image generation.');
        return null;
    }
    $project_id = trim($project_id);

    // Get OAuth Access Token from Metadata Server
    $token_json = @file_get_contents('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token', false, $context);
    if ($token_json === false) {
        error_log('Vertex AI: Failed to retrieve access token from metadata server. Skipping image generation.');
        return null;
    }
    $token_data = json_decode($token_json, true);
    $access_token = $token_data['access_token'] ?? '';

    if (empty($project_id) || empty($access_token)) {
        return null;
    }

    $region = 'us-central1';
    $api_url = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$project_id}/locations/{$region}/publishers/google/models/gemini-3.1-flash-image:generateContent";
    $prompt = "A high-quality, professional, modern blog post featured image, visually representing the topic: \"" . $title . "\". Aesthetic flat vector style, no text, no letters, no words, 16:9 aspect ratio.";

    $body = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $opts_post = [
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$access_token}\r\nContent-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 30
        ]
    ];
    $context_post = stream_context_create($opts_post);
    $response = @file_get_contents($api_url, false, $context_post);

    if ($response === false) {
        error_log('Vertex AI: API request failed.');
        return null;
    }

    $response_data = json_decode($response, true);
    $base64_image = '';
    
    if (isset($response_data['candidates'][0]['content']['parts'])) {
        foreach ($response_data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['inlineData']['data'])) {
                $base64_image = $part['inlineData']['data'];
                break;
            }
        }
    }

    if (empty($base64_image)) {
        error_log('Vertex AI: No image bytes returned.');
        return null;
    }

    $image_bytes = base64_decode($base64_image);
    $image_filename = "media/{$post_id}.jpg";
    
    try {
        $media_bucket = $storage->bucket($media_bucket_name);
        $media_bucket->upload($image_bytes, [
            'name' => $image_filename,
            'metadata' => ['contentType' => 'image/jpeg']
        ]);
        error_log("Vertex AI: Successfully generated and uploaded featured image: {$image_filename}");
        return $image_filename;
    } catch (Exception $e) {
        error_log("Vertex AI: Failed to save image to GCS: " . $e->getMessage());
        return null;
    }
}

// Router actions
$action = $_GET['action'] ?? '';
$error = '';
$is_logged_in = $_SESSION['logged_in'] ?? false;

// Google OAuth callback processing (intercepts normal requests)
if (isset($_GET['code']) && isset($_GET['state'])) {
    if (!$is_oauth_configured) {
        $error = 'このインスタンスでは Google ログインが設定されていません。';
    } else {
        $expected_csrf = $_SESSION['oauth_csrf'] ?? null;
        $got_csrf = $_GET['state'];
        
        if ($expected_csrf && $got_csrf && hash_equals($expected_csrf, $got_csrf)) {
            unset($_SESSION['oauth_csrf']);
            $token_data = exchange_oauth_code($_GET['code'], $google_client_id, $google_client_secret, get_oauth_redirect_uri());
            $id_token = $token_data['id_token'] ?? null;
            
            if ($id_token) {
                $payload = verify_id_token($id_token, $google_client_id);
                if ($payload && !empty($payload['email_verified'])) {
                    $email = $payload['email'] ?? '';
                    $email_hash = hash('sha256', strtolower(trim($email)));
                    $target_hash = strtolower(trim($admin_email_hash));
                    if ($email_hash === $target_hash) {
                        $_SESSION['logged_in'] = true;
                        $_SESSION['admin_email'] = $email;
                        header('Location: index.php?action=admin');
                        exit;
                    } else {
                        $error = 'アクセス拒否: この Google アカウント (' . htmlspecialchars($email) . ') には管理権限がありません。';
                    }
                } else {
                    $error = 'ID トークンの検証に失敗しました。';
                }
            } else {
                $error = '認可コードからアクセストークンへの変換に失敗しました。';
            }
        } else {
            $error = 'セキュリティチェックエラー (CSRF トークンの不一致)。';
        }
    }
    $action = 'login';
}

// Google OAuth error processing
if (isset($_GET['error'])) {
    $error = 'Google ログインエラー: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    $action = 'login';
}

// 1. Handle Login
if ($action === 'login') {
    $use_fallback = isset($_GET['fallback']) || !$is_oauth_configured;
    
    if ($use_fallback) {
        // Fallback static password authentication
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['password'] ?? '') === $admin_password) {
                $_SESSION['logged_in'] = true;
                header('Location: index.php?action=admin');
                exit;
            }
            $error = 'パスワードが正しくありません。';
        }
    } else {
        // Redirect to Google Accounts authorization screen
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['oauth_csrf'] = $csrf;
        $auth_url = build_oauth_url($csrf, $google_client_id, get_oauth_redirect_uri());
        header('Location: ' . $auth_url);
        exit;
    }
}

// 2. Handle Logout
if ($action === 'logout') {
    $_SESSION['logged_in'] = false;
    unset($_SESSION['admin_email']);
    header('Location: index.php');
    exit;
}

// 3. Handle Create Post POST
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in) {
    $title = trim($_POST['title'] ?? '');
    $markdown = trim($_POST['markdown'] ?? '');
    
    if (!empty($title) && !empty($markdown)) {
        $post_id = time(); // Simple timestamp as unique ID
        $filename = "posts/{$post_id}.md";
        
        try {
            // Upload Markdown file directly to GCS
            $bucket->upload($markdown, [
                'name' => $filename,
                'metadata' => ['contentType' => 'text/markdown']
            ]);
            
            // Try to generate AI featured image
            $image_path = generate_featured_image($title, $post_id, $media_bucket_name, $storage);
            
            // Update posts.json metadata list
            $posts = load_posts_metadata($bucket);
            array_unshift($posts, [
                'id' => $post_id,
                'title' => $title,
                'date' => date('Y-m-d H:i:s'),
                'image_path' => $image_path
            ]);
            save_posts_metadata($bucket, $posts);
            
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $error = '記事の保存に失敗しました: ' . $e->getMessage();
        }
    } else {
        $error = 'タイトルとMarkdown本文は必須項目です。';
    }
}

// 4. Handle Delete Post
if ($action === 'delete' && isset($_GET['id']) && $is_logged_in) {
    $delete_id = $_GET['id'];
    try {
        // Delete markdown file
        $object = $bucket->object("posts/{$delete_id}.md");
        if ($object->exists()) {
            $object->delete();
        }
        
        // Delete image file if exists
        $media_bucket = $storage->bucket($media_bucket_name);
        $img_object = $media_bucket->object("media/{$delete_id}.jpg");
        if ($img_object->exists()) {
            $img_object->delete();
        }
        
        // Update posts.json metadata list
        $posts = load_posts_metadata($bucket);
        $posts = array_filter($posts, function($post) use ($delete_id) {
            return $post['id'] != $delete_id;
        });
        save_posts_metadata($bucket, array_values($posts));
        
        header('Location: index.php?action=admin');
        exit;
    } catch (Exception $e) {
        $error = '記事の削除に失敗しました: ' . $e->getMessage();
    }
}

// Load posts list for display
$posts = load_posts_metadata($bucket);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serverless & FinOps Journal</title>
    <!-- Premium Fonts: Lora (Serif) & Plus Jakarta Sans (Sans-serif) -->
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400..700;1,400..700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #fcfbfa; /* Warm off-white paper tone */
            --bg-card: #ffffff;
            --border-color: #efeae4;
            --text-primary: #1c1d1f; /* Premium dark slate ink */
            --text-secondary: #6e7075;
            --text-muted: #9ba0a6;
            --accent-color: #c2410c; /* Burnt Terracotta orange */
            --accent-hover: #9a3412;
            --accent-light: #ffedd5;
            --shadow-soft: 0 4px 24px rgba(28, 29, 31, 0.03), 0 1px 2px rgba(28, 29, 31, 0.02);
            --shadow-hover: 0 12px 40px rgba(28, 29, 31, 0.07), 0 1px 3px rgba(28, 29, 31, 0.03);
            --radius-default: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.75;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        header {
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 2px rgba(0,0,0,0.01);
        }

        .logo {
            font-family: 'Lora', Georgia, serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .logo span {
            color: var(--accent-color);
        }

        .nav-btn {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            text-decoration: none;
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
        }

        .nav-btn:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            transform: translateY(-1px);
        }

        .nav-btn.primary {
            background-color: var(--accent-color);
            color: #ffffff;
            border-color: var(--accent-color);
        }

        .nav-btn.primary:hover {
            background-color: var(--accent-hover);
            border-color: var(--accent-hover);
            color: #ffffff;
        }

        main {
            flex: 1;
            width: 100%;
            max-width: 800px;
            margin: 3rem auto;
            padding: 0 1.5rem;
        }

        /* Sophisticated UI Cards */
        .glass-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-default);
            padding: 2.5rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
            transition: box-shadow 0.3s ease, border-color 0.3s ease;
        }

        h1, h2, h3 {
            font-family: 'Lora', Georgia, serif;
            font-weight: 700;
            line-height: 1.3;
        }

        /* Hero Header styling */
        .hero {
            text-align: center;
            margin-bottom: 4rem;
            padding: 1.5rem 0;
        }

        .hero h1 {
            font-size: 3rem;
            color: var(--text-primary);
            margin-bottom: 1.2rem;
            letter-spacing: -0.75px;
        }

        .hero p {
            color: var(--text-secondary);
            font-size: 1.15rem;
            max-width: 600px;
            margin: 0 auto;
            font-weight: 400;
            line-height: 1.6;
        }

        /* Article Cards in Feed */
        .posts-list {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .post-card {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.75rem;
            text-decoration: none;
            color: inherit;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-default);
            padding: 2rem;
            background-color: var(--bg-card);
            box-shadow: var(--shadow-soft);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (min-width: 640px) {
            .post-card {
                grid-template-columns: 220px 1fr;
            }
        }

        .post-card:hover {
            border-color: #d1c8bd;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .post-card:hover .post-title {
            color: var(--accent-color);
        }

        .post-thumb {
            width: 100%;
            height: 150px;
            border-radius: 8px;
            object-fit: cover;
            background-color: #f5f3f0;
            border: 1px solid var(--border-color);
            transition: opacity 0.2s ease;
        }

        .post-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .post-title {
            font-size: 1.6rem;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
            transition: color 0.2s ease;
        }

        .post-desc {
            color: var(--text-secondary);
            font-size: 0.95rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        /* Detail Reading Layout */
        .post-detail {
            border: none;
            box-shadow: none;
            background: transparent;
            padding: 0;
        }

        .post-detail h1 {
            font-size: 2.75rem;
            letter-spacing: -0.5px;
            margin-bottom: 1rem;
        }

        .post-detail-meta {
            margin-bottom: 2.5rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1.25rem;
            font-size: 0.9rem;
        }

        .post-content {
            font-size: 1.05rem;
            line-height: 1.85;
            color: #2d2f33;
        }

        .post-content p {
            margin-bottom: 1.75rem;
        }

        .post-content h2 {
            font-size: 1.8rem;
            margin: 3rem 0 1.2rem 0;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .post-content h3 {
            font-size: 1.35rem;
            margin: 2rem 0 1rem 0;
            color: var(--text-primary);
        }

        .post-content ul, .post-content ol {
            margin-bottom: 1.75rem;
            padding-left: 1.5rem;
        }

        .post-content li {
            margin-bottom: 0.5rem;
        }

        .post-content blockquote {
            border-left: 3px solid var(--accent-color);
            padding-left: 1.5rem;
            margin: 1.75rem 0;
            font-style: italic;
            color: var(--text-secondary);
        }

        /* Editor Forms and Settings */
        .form-title {
            font-size: 1.8rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px var(--accent-light);
        }

        .btn-submit {
            width: 100%;
            padding: 0.85rem;
            border-radius: 6px;
            border: none;
            background-color: var(--accent-color);
            color: #ffffff;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--accent-hover);
        }

        .error-alert {
            background-color: #fdf2f2;
            border: 1px solid #f8b4b4;
            color: #9b1c1c;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        /* Auth styling */
        .google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
            padding: 0.75rem;
            background-color: #ffffff;
            border: 1px solid #dadce0;
            border-radius: 6px;
            color: #3c4043;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background-color .15s, border-color .15s, box-shadow .15s;
        }

        .google-btn:hover {
            background-color: #f8f9fa;
            border-color: #c2cfdf;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.1), 0 1px 3px 1px rgba(60,64,67,0.05);
        }

        .google-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Admin Table */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }

        .admin-table th, .admin-table td {
            padding: 1rem 0.5rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .admin-table th {
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .admin-table td {
            font-size: 0.95rem;
        }

        .btn-delete {
            color: #c53030;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            border: 1px solid #feb2b2;
            background-color: #fff5f5;
            transition: all 0.2s ease;
        }

        .btn-delete:hover {
            background-color: #fed7d7;
            border-color: #fc8181;
        }

        footer {
            padding: 2.5rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.8rem;
            border-top: 1px solid var(--border-color);
            margin-top: 5rem;
            background-color: #ffffff;
        }
    </style>
</head>
<body>

    <header>
        <a href="index.php" class="logo">Serverless<span>.Journal</span></a>
        <div>
            <?php if ($is_logged_in): ?>
                <a href="index.php?action=admin" class="nav-btn" style="margin-right: 0.5rem;">管理画面</a>
                <a href="index.php?action=logout" class="nav-btn">ログアウト</a>
            <?php else: ?>
                <a href="index.php?action=login" class="nav-btn primary">ログイン</a>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <?php if (!empty($error)): ?>
            <div class="error-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($action === 'login'): ?>
            <!-- LOGIN SCREEN -->
            <div class="glass-card" style="max-width: 460px; margin: 4rem auto;">
                <h2 class="form-title" style="text-align: center; border-bottom: none; margin-bottom: 1rem;">管理者認証</h2>
                
                <!-- Unified Authentication Explanation -->
                <div style="background-color: #fcfbfa; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: 8px; font-size: 0.82rem; margin-bottom: 2rem; line-height: 1.6; color: var(--text-secondary);">
                    <p style="font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
                        <span>🛡️</span> 認証システムの設計と動作条件
                    </p>
                    <div style="margin-bottom: 0.75rem; padding-bottom: 0.75rem; border-bottom: 1px dashed var(--border-color);">
                        <strong style="color: #a27b38; display: flex; align-items: center; gap: 0.25rem; margin-bottom: 0.25rem;">
                            <span>💻</span> ローカル開発環境 (現在)
                        </strong>
                        <ul style="padding-left: 1.25rem; margin: 0;">
                            <li><code>docker-compose.yml</code> 内の環境変数 <code>ADMIN_PASSWORD</code> (デフォルト: <code>admin</code>) とフォームに入力された値を照合してログインします。</li>
                        </ul>
                    </div>
                    <div>
                        <strong style="color: #1a73e8; display: flex; align-items: center; gap: 0.25rem; margin-bottom: 0.25rem;">
                            <span>☁️</span> Google Cloud (本番) 環境
                        </strong>
                        <ul style="padding-left: 1.25rem; margin: 0;">
                            <li>Googleアカウントを用いたセキュアな認証（OIDC）に自動で切り替わります。</li>
                            <li><strong>GCPを契約しているオーナー（あなた）のGoogleアカウント</strong>からのアクセスのみが許可されます。</li>
                            <li>ログインしたメールアドレスの SHA-256 ハッシュ値が、コンテナの環境変数 <code>ADMIN_EMAIL_HASH</code> に設定されたハッシュ値と一致するか検証されます（これにより、メールアドレスを生のテキストとして設定やソースコードに露出させずに安全に保護できます）。</li>
                        </ul>
                    </div>
                </div>

                <?php if (isset($_GET['fallback']) || !$is_oauth_configured): ?>
                    <!-- Local Dev Password Fallback -->
                    <form action="index.php?action=login&fallback=1" method="POST">
                        <div class="form-group">
                            <label for="password">セキュリティパスワード</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autofocus>
                        </div>
                        <button type="submit" class="btn-submit">ログイン (パスワード認証)</button>
                    </form>
                    <?php if ($is_oauth_configured): ?>
                        <div style="text-align: center; margin-top: 1.5rem;">
                            <a href="index.php?action=login" style="font-size: 0.85rem; color: var(--accent-color); text-decoration: none; font-weight: 500;">← Google ログインに戻る</a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Google OIDC Sign-in Button -->
                    </div>

                    <a href="index.php?action=login" class="google-btn">
                        <svg viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path fill="#EA4335" d="M9 7.3v3.6h5.1c-.2 1.2-.9 2.3-2 3.1l3.3 2.6C16.8 15.1 18 12.3 18 9c0-.6-.1-1.2-.2-1.7H9z"/>
                            <path fill="#34A853" d="M3.9 10.7L3.2 11.3 1 13.1C2.5 15.8 5.5 17.5 9 17.5c2.4 0 4.5-.8 6-2.5l-3.3-2.6C10.9 13.3 10 13.7 9 13.7c-2.3 0-4.3-1.5-5.1-3.6z"/>
                            <path fill="#FBBC05" d="M1 4.9C.4 5.9.1 7 .1 8.1c0 1.1.3 2.2.9 3.2 0 0 2-1.6 2.9-2.3-.3-.8-.3-1.7 0-2.5L1 4.9z"/>
                            <path fill="#4285F4" d="M9 3.4c1.3 0 2.4.4 3.3 1.3l2.5-2.5C13.5.8 11.4 0 9 0 5.5 0 2.5 1.7 1 4.4l2.9 2.3C4.7 4.9 6.7 3.4 9 3.4z"/>
                        </svg>
                        Google アカウントでログイン
                    </a>
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <a href="index.php?action=login&fallback=1" style="font-size: 0.8rem; color: var(--text-secondary); text-decoration: none;">ローカル検証用：パスワードログインを使用する</a>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($action === 'admin' && $is_logged_in): ?>
            <!-- ADMIN CREATE & MANAGE POSTS SCREEN -->
            <div class="glass-card">
                <h2 class="form-title" style="text-align: left; margin-bottom: 1.5rem;">新規記事の投稿</h2>
                <form action="index.php?action=create" method="POST">
                    <div class="form-group">
                        <label for="title">記事タイトル</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="記事のタイトルを入力してください" required>
                    </div>
                    <div class="form-group">
                        <label for="markdown">本文 (Markdown形式)</label>
                        <textarea id="markdown" name="markdown" class="form-control" rows="12" placeholder="Markdown形式で本文を入力してください..." required></textarea>
                    </div>
                    <button type="submit" class="btn-submit">記事を保存して公開する (AIアイキャッチ自動生成)</button>
                </form>
            </div>

            <div class="glass-card">
                <h2 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">公開済みの記事一覧</h2>
                <?php if (empty($posts)): ?>
                    <p style="color: var(--text-secondary);">公開済みの記事はありません。</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>記事タイトル</th>
                                <th>公開日時</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td><a href="index.php?id=<?= $post['id'] ?>" style="color: var(--text-primary); text-decoration: none; font-weight: 500;" target="_blank"><?= htmlspecialchars($post['title']) ?></a></td>
                                    <td style="color: var(--text-secondary);"><?= htmlspecialchars($post['date']) ?></td>
                                    <td>
                                        <a href="index.php?action=delete&id=<?= $post['id'] ?>" class="btn-delete" onclick="return confirm('本当にこの記事を削除してもよろしいですか？');">削除</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif (isset($_GET['id'])): ?>
            <!-- DETAIL PAGE -->
            <?php
            $post_id = $_GET['id'];
            $current_post = null;
            foreach ($posts as $post) {
                if ($post['id'] == $post_id) {
                    $current_post = $post;
                    break;
                }
            }

            if ($current_post):
                // Read article content from GCS
                try {
                    $filename = "posts/{$post_id}.md";
                    $markdown = $bucket->object($filename)->downloadAsString();
                    $parsedown = new Parsedown();
                    $html_content = $parsedown->text($markdown);
                } catch (Exception $e) {
                    $html_content = "<p style='color: #ef4444;'>記事本文の取得に失敗しました: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            ?>
                <article class="glass-card post-detail">
                    <?php if (!empty($current_post['image_path'])): ?>
                        <img src="<?= get_gcs_url($media_bucket_name, $current_post['image_path']) ?>" class="post-thumb" style="height: 380px; margin-bottom: 2rem; border-radius: 12px;" alt="Featured Image">
                    <?php endif; ?>
                    <h1><?= htmlspecialchars($current_post['title']) ?></h1>
                    <div class="post-detail-meta">
                        公開日: <?= htmlspecialchars($current_post['date']) ?>
                    </div>
                    <div class="post-content">
                        <?= $html_content ?>
                    </div>
                    <div style="margin-top: 3.5rem;">
                        <a href="index.php" class="nav-btn">← 記事一覧に戻る</a>
                    </div>
                </article>
            <?php else: ?>
                <div class="glass-card" style="text-align: center; padding: 4rem;">
                    <h2>404 Not Found</h2>
                    <p style="color: var(--text-secondary); margin-top: 1rem;">指定された記事が見つかりませんでした。</p>
                    <div style="margin-top: 2rem;">
                        <a href="index.php" class="nav-btn">トップページに戻る</a>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- TOP PAGE (POSTS LIST) -->
            <div class="hero">
                <h1>Serverless & FinOps</h1>
                <p>Cloud Run と GCS（Google Cloud Storage）だけで構築された、アクセスゼロなら維持費完全0円の極軽量サーバーレスブログ。</p>
            </div>

            <?php if (empty($posts)): ?>
                <div class="glass-card" style="text-align: center; padding: 4rem;">
                    <p style="color: var(--text-secondary); font-size: 1.1rem;">まだ投稿された記事はありません。</p>
                    <?php if ($is_logged_in): ?>
                        <div style="margin-top: 2rem;">
                            <a href="index.php?action=admin" class="nav-btn primary">最初の記事を投稿する</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="posts-list">
                    <?php foreach ($posts as $post): ?>
                        <a href="index.php?id=<?= $post['id'] ?>" class="glass-card post-card">
                            <?php if (!empty($post['image_path'])): ?>
                                <img src="<?= get_gcs_url($media_bucket_name, $post['image_path']) ?>" class="post-thumb" alt="Featured Image">
                            <?php else: ?>
                                <div class="post-thumb" style="display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 0.9rem;">No Image</div>
                            <?php endif; ?>
                            <div style="display: flex; flex-direction: column; justify-content: center;">
                                <div class="post-meta"><?= date('Y年m月d日', strtotime($post['date'])) ?></div>
                                <h2 class="post-title"><?= htmlspecialchars($post['title']) ?></h2>
                                <p class="post-desc">この記事を読んで、サーバーレス技術がいかに効率的で無駄のないシステムデザインと極限のFinOpsコスト削減を実現するかを学びましょう。</p>
                                <div>
                                    <span class="nav-btn" style="padding: 0.4rem 1.1rem; font-size: 0.8rem; border-color: var(--accent-color); color: var(--accent-color);">記事を読む →</span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> Serverless & FinOps Journal. Built with Cloud Run & Storage.</p>
    </footer>

</body>
</html>
