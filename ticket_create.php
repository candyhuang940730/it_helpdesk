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

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

$categories = [
    'computer' => '電腦設備',
    'network' => '網路問題',
    'account' => '帳號問題',
    'software' => '軟體問題',
    'other' => '其他',
];

$title = '';
$category = 'other';
$description = '';
$requestedCompletionDate = '';
$customer_name = '';
$contact_name = '';
$contact_phone = '';
$contact_email = '';

$errors = [];

$successTicketId = $_SESSION['success_ticket_id'] ?? null;
unset($_SESSION['success_ticket_id']);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = postString('title');
    $category = postString('category');
    $description = postString('description');
    $submittedToken = postString('csrf_token');
    $requestedCompletionDate = postString('requested_completion_date');
    $customer_name = postString('customer_name');
    $contact_name = postString('contact_name');
    $contact_phone = postString('contact_phone');
    $contact_email = postString('contact_email');

    if (
        $submittedToken === '' ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $errors[] = '表單驗證失敗，請重新整理頁面後再試。';
    }

    if ($title === '') {
        $errors[] = '請填寫案件標題。';
    } elseif (preg_match('/^.{1,150}$/us', $title) !== 1) {
        $errors[] = '案件標題不可超過 150 個字，且不可包含換行。';
    }

    if (!array_key_exists($category, $categories)) {
        $errors[] = '請選擇有效的問題類別。';
    }

    if ($description === '') {
        $errors[] = '請填寫問題描述。';
    } elseif (preg_match('/^.{1,5000}$/us', $description) !== 1) {
        $errors[] = '問題描述不可超過 5,000 個字。';
    }
    
    //日期填寫
    if ($requestedCompletionDate === '') {
        $errors[] = '請填寫客戶期望完成日期。';
    } else {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $requestedCompletionDate
        );

        if (
            $date === false ||
            $date->format('Y-m-d') !== $requestedCompletionDate
        ) {
            $errors[] = '請填寫有效的客戶期望完成日期。';
        }
    }

    if ($customer_name === '') {
        $errors[] = '請填寫報修單位或部門。';
    } elseif (preg_match('/^.{1,30}$/us', $customer_name) !== 1) {
        $errors[] = '報修單位或部門不可超過 30 個字，且不可包含換行。';
    }

    if ($contact_name === '') {
        $errors[] = '請填寫聯繫人姓名。';
    } elseif (preg_match('/^.{1,30}$/us', $contact_name) !== 1) {
        $errors[] = '聯繫人姓名不可超過 30 個字，且不可包含換行。';
    }

    if ($contact_phone === '') {
        $errors[] = '請填寫聯絡電話/分機號碼。';
    } elseif (preg_match('/^.{1,150}$/us', $contact_phone) !== 1) {
        $errors[] = '聯絡電話/分機號碼不可超過 150 個字，且不可包含換行。';
    }

    if ($contact_email === '') {
        $errors[] = '請填寫電子郵件。';
    } elseif (preg_match('/^.{1,150}$/us', $contact_email) !== 1) {
        $errors[] = '電子郵件不可超過 150 個字，且不可包含換行。';
    }

    if ($errors === []) {
        require __DIR__ . '/db.php';

        try {
            $sql = '
                INSERT INTO tickets (
                    title,
                    category,
                    description,
<<<<<<< HEAD
                    requested_completion_date,
                    customer_name,
                    contact_phone,
                    contact_email           
=======
                    requested_completion_date
>>>>>>> b7b262f389bd9b7ef9a94722e965cf7968dd5b58
                )
                VALUES (
                    :title,
                    :category,
                    :description,
                    :requested_completion_date,
                    :customer_name,
                    :contact_phone,
                    :contact_email
                )
            ';

            $statement = $pdo->prepare($sql);

            $statement->execute([
                'title' => $title,
                'category' => $category,
                'description' => $description,
                'requested_completion_date' => $requestedCompletionDate,
                'customer_name' => $customer_name,
                'contact_phone' => $contact_phone,
                'contact_email' => $contact_email
            ]);

            $_SESSION['success_ticket_id'] = $pdo->lastInsertId();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: ticket_create.php', true, 303);
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = '案件新增失敗，請稍後再試。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>新增報修單｜IT 報修系統</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px 16px;
            font-family: Arial, "Microsoft JhengHei", sans-serif;
            color: #243247;
            background: #f3f6fa;
        }

        main {
            max-width: 680px;
            margin: 0 auto;
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        h1 {
            margin-top: 0;
        }

        .intro {
            color: #58677a;
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
        select,
        textarea {
            width: 100%;
            padding: 12px;
            font: inherit;
            border: 1px solid #c5ceda;
            border-radius: 6px;
        }

        textarea {
            min-height: 180px;
            resize: vertical;
        }

        button {
            margin-top: 24px;
            padding: 12px 24px;
            color: #fff;
            font: inherit;
            background: #285ea8;
            border: 0;
            border-radius: 6px;
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

        .success {
            color: #185c35;
            background: #e7f6ec;
        }

        .error {
            color: #8f2020;
            background: #fdeaea;
        }

        .error ul {
            margin: 0;
            padding-left: 22px;
        }

        .hint {
            display: block;
            margin-top: 6px;
            color: #65758a;
            font-size: 14px;
        }
    </style>
</head>
<body>
<main>
    <h1>新增報修單</h1>

    <p class="intro">
        請描述遇到的問題。這是本機展示版本，請使用虛構測試資料，
        不要填寫帳號密碼或個人敏感資訊。
    </p>

    <?php if ($successTicketId !== null): ?>
        <div class="message success" role="status">
            報修單已成功建立！
            案件編號：
            <?= escape((string) $successTicketId) ?>
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

    <form action="ticket_create.php" method="post">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

        <div class="field">
            <label for="title">案件標題</label>

            <input
                type="text"
                id="title"
                name="title"
                maxlength="150"
                value="<?= escape($title) ?>"
                placeholder="例如：辦公室電腦無法連線"
                required
            >
        </div>

        <div class="field">
            <label for="category">問題類別</label>

            <select id="category" name="category" required>
                <?php foreach ($categories as $value => $label): ?>
                    <option
                        value="<?= escape($value) ?>"
                        <?= $category === $value ? 'selected' : '' ?>
                    >
                        <?= escape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="description">問題描述</label>

            <textarea
                id="description"
                name="description"
                maxlength="5000"
                placeholder="請填寫發生情況、錯誤訊息及已嘗試的處理方式。"
                required
            ><?= escape($description) ?></textarea>

            <small class="hint">最多 5,000 個字。</small>
        </div>

        <div class="field">
        <label for="requested_completion_date">
            客戶期望完成日期
        </label>

        <input
            type="date"
            id="requested_completion_date"
            name="requested_completion_date"
            value="<?= escape($requestedCompletionDate) ?>"
            required
        >

            <small class="hint">
                請填寫希望完成的日期，實際處理時程仍待確認。
            </small>
        </div>

        <div class="field">
            <label for="description">聯絡資訊</label>

            <input
                type="text"
                id="customer_name"
                name="customer_name"
                maxlength="30"
                placeholder="請填寫報修單位或部門。"
                required
            ><?= escape($customer_name) ?></input>
            
            <input
                type="text"
                id="contact_name"
                name="contact_name"
                maxlength="30"
                placeholder="請填寫聯繫人姓名。"
                required
            ><?= escape($contact_name) ?></input>

            <input
                type="text"
                id="contact_phone"
                name="contact_phone"
                maxlength="30"
                placeholder="請填寫聯絡電話/分機號碼。"
                required
            ><?= escape($contact_phone) ?></input>

            <input
                type="email"
                id="contact_email"
                name="contact_email"
                maxlength="60"
                placeholder="請填寫電子郵件。"
                required
            ><?= escape($contact_email) ?></input>
        </div>

            <button type="submit">送出報修單</button>
        </form>
</main>
</body>
</html>