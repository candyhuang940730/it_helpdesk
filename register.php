<?php
declare(strict_types=1);

session_start();

/*
 * 僅供本機開發／展示的註冊設定。
 *
 * 1. 要建立帳號時，暫時改成 true。
 * 2. 註冊碼請自行填入至少 24 字元的隨機值。
 * 3. 帳號建立完，改回 false。
 *
 * 不要將真實註冊碼提交到公開 Git 儲存庫。
 * 正式部署應改由環境變數或私有設定檔提供。
 */
const REGISTRATION_ENABLED = true;
const ENGINEER_REGISTRATION_CODE = 'e8143683d9217a65f26593eee1330ce194bdc53c59d4c436';

function escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function postString(string $key, bool $trim = true): string
{
    $value = $_POST[$key] ?? '';

    if (!is_string($value)) {
        return '';
    }

    return $trim ? trim($value) : $value;
}

/*
 * 預設拒絕註冊。
 */
if (!REGISTRATION_ENABLED) {
    http_response_code(403);
    exit('目前未開放工程師註冊。');
}

if (strlen(ENGINEER_REGISTRATION_CODE) < 24) {
    http_response_code(503);
    exit('工程師註冊碼尚未完成設定。');
}

$name='';
$email = '';
$errors = [];

$successMessage = $_SESSION['register_success'] ?? null;
unset($_SESSION['register_success']);

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = postString('name');
    $email = postString('email');

    // 密碼不做 trim，避免改變密碼本身。
    $password = postString('password', false);
    $passwordConfirmation = postString(
        'password_confirmation',
        false
    );

    $registrationCode = postString(
        'registration_code',
        false
    );

    $submittedToken = postString('csrf_token');

    /*
     * 驗證 CSRF token。
     */
    if (
        $submittedToken === '' ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {
        $errors[] = '表單驗證失敗，請重新整理頁面後再試。';
    }

    /*
     * 驗證工程師註冊碼。
     */
    if (
        !hash_equals(
            ENGINEER_REGISTRATION_CODE,
            $registrationCode
        )
    ) {
        $errors[] = '工程師註冊碼不正確。';
    }

    /*
     * 此範例限定帳號為：
     * 英文字母、數字、底線，長度 3～50 字元。
     */
    if ($name === '') {
        $errors[] = '請填寫姓名。';
    } elseif (preg_match('/^.{1,50}$/us', $name) !== 1) {
        $errors[] = '姓名不可超過 50 個字，且不可包含換行。';
    }

    $allowedEmailDomains = [
        'example.com',
    ];

    if ($email === '') {
        $errors[] = '請填寫 Email。';
    } elseif (
        strlen($email) > 254 ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = '請填寫有效的 Email。';
    } else {
        // 從最後一個 @ 取得網域。
        $atPosition = strrpos($email, '@');

        $localPart = substr($email, 0, $atPosition);
        $emailDomain = strtolower(
            substr($email, $atPosition + 1)
        );

        // 僅將網域轉為小寫，保留 @ 前面的原始內容。
        $email = $localPart . '@' . $emailDomain;

        if (
            !in_array(
                $emailDomain,
                $allowedEmailDomains,
                true
            )
        ) {
            $errors[] = '請使用指定公司／學校的 Email 註冊。';
        }
    }

    /*
     * 本範例密碼政策：
     * 15～64 個 ASCII 可列印字元，可包含空白。
     *
     * 明確限制位元組長度，避免目前 bcrypt
     * 對超長輸入的截斷問題。
     */
    if (
        preg_match(
            '/\A[\x20-\x7E]{15,64}\z/',
            $password
        ) !== 1
    ) {
        $errors[] =
            '密碼需為 15～64 字元，可使用英文字母、數字、符號與空白。';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = '兩次輸入的密碼不一致。';
    }

    if ($errors === []) {
        try {
            require __DIR__ . '/db.php';

            $pdo->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

            /*
             * email 必須有 UNIQUE 限制，
             * 由資料庫保證不會出現重複帳號。
             */
            $statement = $pdo->prepare(
                'INSERT INTO users (
                    name,
                    email,
                    password_hash,
                    role,
                    is_active,
                    created_at
                )
                VALUES (
                    :name,
                    :email,
                    :password_hash,
                    :role,
                    1,
                    CURRENT_TIMESTAMP
                )'
            );

            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                'role' => 'engineer',
            ]);

            $_SESSION['register_success'] =
                '工程師帳號已建立，請前往登入。';

            /*
             * 註冊後不自動登入。
             * 使用重新導向避免重新整理重複送出。
             */
            header(
                'Location: register.php',
                true,
                303
            );
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());

            $mysqlErrorCode = (int) (
                $e->errorInfo[1] ?? 0
            );

            if ($mysqlErrorCode === 1062) {
                $errors[] = '此 Email 無法使用，請更換後再試。';
            } else {
                $errors[] = '註冊失敗，請確認資料表設定或稍後再試。';
            }
        }
    }

    unset(
        $password,
        $passwordConfirmation,
        $registrationCode
    );
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>工程師註冊｜IT 報修系統</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 48px 16px;
            font-family: Arial, "Microsoft JhengHei", sans-serif;
            color: #243247;
            background: #f3f6fa;
        }

        main {
            max-width: 480px;
            margin: 0 auto;
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
        }

        h1 {
            margin-top: 0;
        }

        .intro,
        .hint {
            color: #65758a;
            line-height: 1.6;
        }

        .hint {
            display: block;
            margin-top: 6px;
            font-size: 14px;
        }

        .field {
            margin-top: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        input,
        button {
            width: 100%;
            padding: 12px;
            font: inherit;
            border-radius: 6px;
        }

        input {
            border: 1px solid #c5ceda;
        }

        button {
            margin-top: 26px;
            color: #fff;
            background: #285ea8;
            border: 0;
            cursor: pointer;
        }

        button:hover {
            background: #204d8b;
        }

        .message {
            margin: 20px 0;
            padding: 14px;
            border-radius: 6px;
            line-height: 1.6;
        }

        .error {
            color: #8f2020;
            background: #fdeaea;
        }

        .success {
            color: #185c35;
            background: #e7f6ec;
        }

        .error ul {
            margin: 0;
            padding-left: 22px;
        }

        a {
            color: #285ea8;
        }

        .footer {
            margin-bottom: 0;
            text-align: center;
        }
    </style>
</head>
<body>
<main>
    <h1>工程師註冊</h1>

    <p class="intro">
        此頁僅供獲授權的工程師建立帳號，
        請向系統維護者取得註冊碼。
    </p>

    <?php if (is_string($successMessage)): ?>
        <div class="message success" role="status">
            <?= escape($successMessage) ?>

            <a href="login.php">前往登入</a>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="message error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="register.php" method="post">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

        <div class="field">
            <label for="name">工程師姓名</label>

            <input
                type="text"
                id="name"
                name="name"
                maxlength="50"
                value="<?= escape($name) ?>"
                autocomplete="name"
                required
            >
        </div>

        <div class="field">
            <label for="email">Email／登入帳號</label>

            <input
                type="email"
                id="email"
                name="email"
                maxlength="254"
                value="<?= escape($email) ?>"
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                required
            >
        </div>
        
        <div class="field">
            <label for="password">密碼</label>

            <input
                type="password"
                id="password"
                name="password"
                minlength="15"
                maxlength="64"
                autocomplete="new-password"
                required
            >

            <small class="hint">
                15～64 字元，可使用英文字母、數字、符號與空白。
            </small>
        </div>

        <div class="field">
            <label for="password_confirmation">
                再次輸入密碼
            </label>

            <input
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                minlength="15"
                maxlength="64"
                autocomplete="new-password"
                required
            >
        </div>

        <div class="field">
            <label for="registration_code">
                工程師註冊碼
            </label>

            <input
                type="password"
                id="registration_code"
                name="registration_code"
                autocomplete="off"
                required
            >

            <small class="hint">
                這不是登入密碼，僅用於確認建立帳號的授權。
            </small>
        </div>

        <button type="submit">建立工程師帳號</button>
    </form>

    <p class="footer">
        已有帳號？<a href="login.php">前往登入</a>
    </p>
</main>
</body>
</html>