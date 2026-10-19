<?php
declare(strict_types=1);

session_start();

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

$email = '';
$errors = [];

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = postString('email');

    // 密碼不做 trim，避免改變使用者實際輸入的內容。
    $password = postString('password', false);

    $submittedToken = postString('csrf_token');

    if (
        $submittedToken === '' ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {
        $errors[] = '表單驗證失敗，請重新整理頁面後再試。';
    }

    if ($email === '') {
        $errors[] = '請填寫 Email。';
    } elseif (
        strlen($email) > 254 ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = '請填寫有效的 Email。';
    }

    if ($password === '') {
        $errors[] = '請填寫密碼。';
    } elseif (strlen($password) > 1024) {
        $errors[] = '密碼輸入過長。';
    }

    if ($errors === []) {
        try {
            require __DIR__ . '/db.php';

            $pdo->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

            $pdo->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC
            );

            $statement = $pdo->prepare(
                'SELECT
                    user_id,
                    name,
                    email,
                    password_hash,
                    role,
                    is_active
                FROM users
                WHERE email = :email
                LIMIT 1'
            );

            $statement->execute([
                'email' => $email,
            ]);

            $user = $statement->fetch();

            /*
             * 帳號不存在時仍執行一次密碼驗證，
             * 減少「有沒有這個帳號」造成的明顯時間差。
             * 此雜湊只用於替代驗證，不是可登入的預設帳號。
             */
            $dummyHash =
                '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';

            $storedHash = (
                $user !== false &&
                is_string($user['password_hash']) &&
                $user['password_hash'] !== ''
            )
            ? $user['password_hash']
            : $dummyHash;
            
            $passwordMatches = password_verify(
                $password,
                $storedHash
            );

            $userId = $user !== false
                ? filter_var(
                    $user['user_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                )
                : false;

            $canLogin =
                $user !== false &&
                $userId !== false &&
                (int) $user['is_active'] === 1 &&
                $user['role'] === 'engineer' &&
                is_string($user['password_hash']) &&
                $user['password_hash'] !== '' &&
                $passwordMatches;

            if (!$canLogin) {
                $errors[] = '帳號或密碼錯誤，或帳號無法使用。';
            } else {
                // 驗證成功後更換 session ID。
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException(
                        'Unable to regenerate session ID.'
                    );
                }

                // 清除舊 session 資料，再建立新的登入狀態。
                $_SESSION = [];
                $_SESSION['user_id'] = (int) $userId;
                $_SESSION['name'] = (string) $user['name'];
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['role'] = (string) $user['role'];
                $_SESSION['login_time'] = time();

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header(
                    'Location: manage_table.php',
                    true,
                    303
                );
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());

            $errors[] = '登入服務暫時無法使用，請稍後再試。';
        } catch (RuntimeException $e) {
            error_log($e->getMessage());

            $errors[] = '無法建立登入狀態，請稍後再試。';
        }
    }

    // 不將密碼放回表單或存入 session。
    unset($password);
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

    <title>工程師登入｜IT 報修系統</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 64px 16px;
            font-family: Arial, "Microsoft JhengHei", sans-serif;
            color: #243247;
            background: #f3f6fa;
        }

        main {
            max-width: 440px;
            margin: 0 auto;
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
        }

        h1 {
            margin-top: 0;
        }

        .intro {
            color: #65758a;
            line-height: 1.6;
        }

        .field {
            margin-top: 22px;
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

        .error {
            margin: 20px 0;
            padding: 14px;
            color: #8f2020;
            background: #fdeaea;
            border-radius: 6px;
            line-height: 1.6;
        }

        .error ul {
            margin: 0;
            padding-left: 22px;
        }

        .footer {
            margin-bottom: 0;
            text-align: center;
        }

        a {
            color: #285ea8;
        }
    </style>
</head>
<body>
<main>
    <h1>工程師登入</h1>

    <p class="intro">
        登入後可查看報修案件、設定單別與填寫處理紀錄。
    </p>

    <?php if ($errors !== []): ?>
        <div class="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="login.php" method="post">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

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
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit">登入</button>
    </form>

    <p class="footer">
        <a href="ticket_create.php">前往客戶報修頁</a>
    </p>
</main>
</body>
</html>