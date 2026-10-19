<?php
declare(strict_types=1);

session_start();

/*
 * 正式使用前，在這裡接上工程師登入驗證。
 *
 * 必須由伺服器確認使用者已登入，
 * 不能只靠隱藏連結或按鈕限制存取。
 */

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

$ticketTypes = [
    'repair' => '報修',
    'maintenance' => '保養',
    'installation' => '安裝',
];

$categories = [
    'computer' => '電腦設備',
    'network' => '網路問題',
    'account' => '帳號問題',
    'software' => '軟體問題',
    'other' => '其他',
];

// 請依照資料庫實際使用的狀態值調整。
$statusLabels = [
    'pending' => '待處理',
    'assigned' => '已派單',
    'in_progress' => '處理中',
    'completed' => '已完成',
    'closed' => '已結案',
];

$errors = [];
$tickets = [];
$loadFailed = false;

$submittedTicketId = null;
$submittedTicketType = '';

$successMessage = $_SESSION['manage_success'] ?? null;
unset($_SESSION['manage_success']);

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);
    exit('資料庫連線失敗，請稍後再試。');
}

/*
 * 處理單別修改
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = postString('csrf_token');
    $action = postString('action');

    $ticketIdInput = postString('ticket_id');
    $submittedTicketType = postString('ticket_type');

    $validatedTicketId = filter_var(
        $ticketIdInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($validatedTicketId !== false) {
        $submittedTicketId = (int) $validatedTicketId;
    }

    if (
        $submittedToken === '' ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {
        $errors[] = '表單驗證失敗，請重新整理頁面後再試。';
    }

    if ($action !== 'update_ticket_type') {
        $errors[] = '無效的操作。';
    }

    if ($submittedTicketId === null) {
        $errors[] = '案件編號格式不正確。';
    }

    if (
        !array_key_exists(
            $submittedTicketType,
            $ticketTypes
        )
    ) {
        $errors[] = '請選擇有效的單別。';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            // 鎖定這筆案件，確認案件存在。
            $statement = $pdo->prepare(
                'SELECT ticket_id
                 FROM tickets
                 WHERE ticket_id = :ticket_id
                 FOR UPDATE'
            );

            $statement->execute([
                'ticket_id' => $submittedTicketId,
            ]);

            $ticket = $statement->fetch();

            if ($ticket === false) {
                $pdo->rollBack();
                $errors[] = '找不到這筆案件，可能已被刪除。';
            } else {
                $statement = $pdo->prepare(
                    'UPDATE tickets
                     SET ticket_type = :ticket_type
                     WHERE ticket_id = :ticket_id'
                );

                $statement->execute([
                    'ticket_type' => $submittedTicketType,
                    'ticket_id' => $submittedTicketId,
                ]);

                $pdo->commit();

                $_SESSION['manage_success'] = sprintf(
                    '案件 #%d 的單別已儲存為「%s」。',
                    $submittedTicketId,
                    $ticketTypes[$submittedTicketType]
                );

                header(
                    'Location: manage_table.php',
                    true,
                    303
                );
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = '單別儲存失敗，請稍後再試。';
        }
    }
}

/*
 * 讀取最近 100 筆案件
 */
try {
    $statement = $pdo->query(
        'SELECT
            ticket_id,
            title,
            category,
            status,
            requested_completion_date,
            ticket_type,
            created_at
         FROM tickets
         ORDER BY created_at DESC, ticket_id DESC
         LIMIT 100'
    );

    $tickets = $statement->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());

    $loadFailed = true;
    $errors[] = '案件列表讀取失敗，請確認資料表欄位是否正確。';
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

    <title>案件管理｜IT 報修系統</title>

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
            max-width: 1280px;
            margin: 0 auto;
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        h1 {
            margin: 0 0 12px;
        }

        .intro,
        .hint {
            color: #65758a;
            line-height: 1.6;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin: 24px 0;
        }

        a {
            color: #285ea8;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #e3e8ef;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f5f7fb;
            white-space: nowrap;
        }

        .title {
            min-width: 180px;
            max-width: 320px;
            overflow-wrap: anywhere;
        }

        .nowrap {
            white-space: nowrap;
        }

        .type-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        select,
        button {
            padding: 9px 12px;
            font: inherit;
            border-radius: 6px;
        }

        select {
            min-width: 115px;
            border: 1px solid #c5ceda;
            background: #fff;
        }

        button {
            border: 0;
            color: #fff;
            background: #285ea8;
            cursor: pointer;
            white-space: nowrap;
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

        .empty {
            padding: 32px;
            text-align: center;
            color: #65758a;
        }

        .badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            background: #edf2f7;
            white-space: nowrap;
        }

        .unclassified {
            color: #8a5a00;
            background: #fff3cd;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>
<main>
    <h1>案件管理</h1>

    <p class="intro">
        客戶送單後，由工程師確認案件內容，
        並設定為報修、保養或安裝。
    </p>

    <?php if (is_string($successMessage)): ?>
        <div class="message success" role="status">
            <?= escape($successMessage) ?>
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

    <div class="toolbar">
        <span class="hint">
            顯示最近 <?= count($tickets) ?> 筆案件，最多 100 筆。
        </span>

        <a href="ticket_create.php">新增報修單</a>
    </div>

    <div class="table-wrap">
        <table>
            <caption class="sr-only">
                報修案件列表與單別設定
            </caption>

            <thead>
                <tr>
                    <th scope="col">操作</th>
                    <th scope="col">編號</th>
                    <th scope="col">案件標題</th>
                    <th scope="col">問題類別</th>
                    <th scope="col">狀態</th>
                    <th scope="col">期望完成日期</th>
                    <th scope="col">目前單別</th>
                    <th scope="col">設定單別</th>
                    <th scope="col">建立時間</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($loadFailed): ?>
                <tr>
                    <td colspan="9" class="empty">
                        無法載入案件列表。
                    </td>
                </tr>
            <?php elseif ($tickets === []): ?>
                <tr>
                    <td colspan="9" class="empty">
                        目前沒有報修案件。
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <?php
                    $ticketId = (int) $ticket['ticket_id'];

                    $currentType = $ticket['ticket_type'];

                    $currentTypeLabel = $currentType === null
                        ? '未分類'
                        : ($ticketTypes[$currentType] ?? '未知單別');

                    $selectedType = $currentType ?? '';

                    // 驗證失敗時保留該列提交的有效選項。
                    if (
                        $submittedTicketId === $ticketId &&
                        array_key_exists(
                            $submittedTicketType,
                            $ticketTypes
                        )
                    ) {
                        $selectedType = $submittedTicketType;
                    }

                    $category = (string) $ticket['category'];
                    $status = (string) $ticket['status'];

                    $categoryLabel = $categories[$category]
                        ?? $category;

                    $statusLabel = $statusLabels[$status]
                        ?? $status;

                    $requestedDate =
                        $ticket['requested_completion_date']
                        ?? '未填寫';
                    ?>

                    <tr>
                        <td class="nowrap">
                            #<?= $ticketId ?>
                        </td>

                        <td class="title">
                            <?= escape((string) $ticket['title']) ?>
                        </td>

                        <td class="nowrap">
                            <?= escape($categoryLabel) ?>
                        </td>

                        <td>
                            <span class="badge">
                                <?= escape($statusLabel) ?>
                            </span>
                        </td>

                        <td class="nowrap">
                            <?= escape((string) $requestedDate) ?>
                        </td>

                        <td>
                            <span
                                class="badge <?= $currentType === null
                                    ? 'unclassified'
                                    : '' ?>"
                            >
                                <?= escape($currentTypeLabel) ?>
                            </span>
                        </td>

                        <td>
                            <form
                                action="manage_table.php"
                                method="post"
                                class="type-form"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= escape(
                                        $_SESSION['csrf_token']
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_ticket_type"
                                >

                                <input
                                    type="hidden"
                                    name="ticket_id"
                                    value="<?= $ticketId ?>"
                                >

                                <label
                                    class="sr-only"
                                    for="ticket_type_<?= $ticketId ?>"
                                >
                                    案件 #<?= $ticketId ?> 的單別
                                </label>

                                <select
                                    id="ticket_type_<?= $ticketId ?>"
                                    name="ticket_type"
                                    required
                                >
                                    <option
                                        value=""
                                        disabled
                                        <?= $selectedType === ''
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        請選擇
                                    </option>

                                    <?php
                                    foreach (
                                        $ticketTypes as $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= escape($value) ?>"
                                            <?= $selectedType === $value
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= escape($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit">
                                    儲存
                                </button>
                            </form>
                        </td>

                        <td class="nowrap">
                            <?= escape(
                                (string) $ticket['created_at']
                            ) ?>
                        </td>

                        <td class="nowrap">
                            <a href="ticket_detail.php?ticket_id=<?= $ticketId ?>">
                                查看／處理
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>