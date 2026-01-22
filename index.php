<?php

/** ファイルパス */
$uri = $_SERVER['REQUEST_URI'];
// 転送先のURLを設定します
include './__target.php';
$targetUrl = "{$targetDomain}{$uri}"; // ここを実際の転送先に変更してください

// --- cURLを使用してリクエストを転送 ---
$ch = curl_init($targetUrl);

// 1. HTTPメソッドを元のリクエストと同じに設定
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);

// 2. POSTデータ（RAWボディ）をそのまま転送
// GETリクエストの場合は空になります
$rawPostData = file_get_contents('php://input');
curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPostData);

// 3. 元のヘッダーをほぼそのまま転送
// 'Host'や一部のシステム依存ヘッダーは除外します
$headers = getallheaders();
$forwardedHeaders = [];
foreach ($headers as $key => $value) {
    if (strtolower($key) !== 'host' && strtolower($key) !== 'content-length') {
        $forwardedHeaders[] = "$key: $value";
    }
}

// *** ここからがクッキー転送のための重要な追加設定です ***

// ブラウザから受け取ったCookieヘッダーをそのままcURLに設定
// getallheaders()がCookieヘッダーを含まない場合は $_SERVER['HTTP_COOKIE'] を使用
$cookieHeader = '';
if (isset($headers['Cookie'])) {
    $cookieHeader = $headers['Cookie'];
} elseif (isset($_SERVER['HTTP_COOKIE'])) {
    $cookieHeader = $_SERVER['HTTP_COOKIE'];
}

if (!empty($cookieHeader)) {
    // CURLOPT_COOKIEは、Cookie: ヘッダー全体を設定するのに最適
    curl_setopt($ch, CURLOPT_COOKIE, $cookieHeader);
}

// Content-Typeヘッダーが抜けないように再度設定
if (isset($headers['Content-Type'])) {
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
    // エラーハンドリング
    http_response_code(500);
    echo "cURL Error: " . $err;
} else {
    // レスポンスをヘッダーとボディに分割
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // 5. 返ってきたHTTPステータスコードをクライアントに設定
    // Note: header() 関数を呼ぶ前にステータスコードを設定します
    http_response_code($httpcode);

    // 6. 返ってきたヘッダー（特に Content-Type）をクライアントに転送
    // ヘッダー行ごとに処理して出力
    $headerLines = explode("\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        // 余分な空白をトリムし、不要な行を除外
        $headerLine = trim($headerLine);
        if (!empty($headerLine) && strpos($headerLine, 'Content-Length') === false) {
            header($headerLine, false); // falseで同じヘッダー名の上書きを防ぐ
        }
    }

    // 7. 返ってきたボディをそのままクライアントに出力（画像/動画/JSONなど）
    echo $responseBody;
    if($httpcode != 200) {
      echo $targetUrl;
      echo "\n";
      echo $httpcode;
    }
}

curl_close($ch);
?>
