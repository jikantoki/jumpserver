<?php

// --- CORSを許可するヘッダーを追加 ---

// 方法A: 全てのオリジンからのアクセスを許可する (開発中は便利だが、本番では非推奨)
header("Access-Control-Allow-Origin: *", true);

// 方法B: 特定のオリジンのみ許可する (推奨)
// header("Access-Control-Allow-Origin: https://www.your-other-website.com");

// その他の必要なCORSヘッダー（POSTやカスタムヘッダーを使う場合）
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cookie");
header("Access-Control-Allow-Headers: Content-Type, id, password");
//header("Access-Control-Allow-Credentials: true"); // クッキーを転送する場合に必須

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // OPTIONSリクエストの場合は、ヘッダーだけ返して終了する
    http_response_code(204);
    exit();
}

/** ファイルパス */
$uri = $_SERVER['REQUEST_URI'];
// 転送先のURLを設定します
include './__target.php'; // $targetDomain が含まれている想定
$targetUrl = "{$targetDomain}{$uri}"; // ここを実際の転送先に変更してください

// --- cURLを使用してリクエストを転送 ---
$ch = curl_init($targetUrl);

// SSL証明書の検証を無効にする (開発・検証目的のみ)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

// 1. HTTPメソッドを元のリクエストと同じに設定
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);

// 2. POSTデータ（RAWボディ）をそのまま転送
// GETリクエストの場合は空になります
$rawPostData = file_get_contents('php://input');
curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPostData);

// 3. 元のヘッダーをほぼそのまま転送
// 'Host'は除外し、Content-LengthはPHPが自動付与するので除外（今回は後でそのまま転送するためOK）
$headers = getallheaders();
$forwardedHeaders = [];
foreach ($headers as $key => $value) {
    // Hostヘッダーは除外する
    if (strtolower($key) !== 'host') {
        $forwardedHeaders[] = "$key: $value";
    }
}

// *** ここからがクッキー転送のための重要な追加設定です ***

// ブラウザから受け取ったCookieヘッダーをそのままcURLに設定
$cookieHeader = '';
if (isset($headers['Cookie'])) {
    $cookieHeader = $headers['Cookie'];
} elseif (isset($_SERVER['HTTP_COOKIE'])) {
    $cookieHeader = $_SERVER['HTTP_COOKIE'];
}

if (!empty($cookieHeader)) {
    curl_setopt($ch, CURLOPT_COOKIE, $cookieHeader);
}

// Content-Typeヘッダー（POST時など）を確実に転送リストに含める
if (isset($headers['Content-Type'])) {
    // 既存の forwardedHeaders に追加されるため重複しない
    $forwardedHeaders[] = 'Content-Type: ' . $headers['Content-Type'];
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardedHeaders);

// 4. cURL実行結果を文字列で取得し、ヘッダーも取得する設定
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);

// リクエスト実行
$response = curl_exec($ch);
$err = curl_error($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($err) {
    http_response_code(500);
    echo "cURL Error: " . $err;
} else {
    // レスポンスをヘッダーとボディに分割
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // 5. 返ってきたHTTPステータスコードをクライアントに設定
    http_response_code($httpcode);

    // 6. 返ってきたヘッダー（Content-TypeやSet-Cookie、Content-Length含む）をクライアントに転送
    $headerLines = explode("\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        $headerLine = trim($headerLine);
        // --- ここが重要な修正箇所です ---
        // 転送先サーバーが返してきた Access-Control-Allow-Origin ヘッダーを無視する
        if (stripos($headerLine, 'access-control-allow-origin:') === 0) {
            header("Access-Control-Allow-Origin: *", true);
            continue; // このヘッダーはスキップして次へ
        }
        if (!empty($headerLine)) {
            // Content-Lengthも含め、返ってきたヘッダーを全てそのまま出力
            header($headerLine, false); // falseで同じヘッダー名の上書きを防ぐ
        }
    }

    // 7. 返ってきたボディをそのままクライアントに出力（画像/動画/JSONなど）
    echo $responseBody;
}

curl_close($ch);
?>
