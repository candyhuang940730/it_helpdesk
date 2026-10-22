<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store');

/*
 * 登出只接受 POST。
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('請使用頁面上的登出按鈕。');
}

/*
 * 驗證 CSRF token。
 */
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $submittedToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('登出驗證失敗，請重新整理原頁面後再試。');
}

/*
 * 清空目前 session 變數。
 */
$_SESSION = [];

/*
 * 刪除瀏覽器的 session cookie。
 * 使用原本的 cookie 設定，確保名稱、路徑與網域一致。
 */
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? '',
        ]
    );
}

/*
 * 銷毀伺服器端 session。
 */
if (!session_destroy()) {
    error_log('Logout: session_destroy() failed.');

    http_response_code(500);
    exit('登出未能完成，請稍後再試。');
}

/*
 * 登出後回到登入頁。
 */
header('Location: login.php?logged_out=1', true, 303);
exit;