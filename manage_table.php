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


$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) || $userId < 1) {
    header('Location: login.php', true, 302);
    exit;
}

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
 * 讀取最近 100 筆案件
 */
try {
    $statement = $pdo->query(
        'SELECT
            t.ticket_id,
            t.title,
            t.category,
            t.status,
            t.requested_completion_date,
            t.ticket_type,
            t.created_at,
            latest_log.engineer_id AS last_engineer_id,
            latest_log.created_at AS last_modified_at,
            u.name AS last_engineer_name
        FROM tickets AS t
        LEFT JOIN ticket_work_logs AS latest_log
            ON latest_log.log_id = (
                SELECT l.log_id
                FROM ticket_work_logs AS l
                WHERE l.ticket_id = t.ticket_id
                ORDER BY l.created_at DESC, l.log_id DESC
                LIMIT 1
            )
        LEFT JOIN users AS u
            ON u.user_id = latest_log.engineer_id
        ORDER BY t.created_at DESC, t.ticket_id DESC
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
        
        .badge-completed {
            color: #185c35;
            background: #e7f6ec;
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
        查看報修案件、目前單別與最後修改工程師。
        如需修改單別或填寫處理紀錄，請點選「查看／處理」。
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
                報修案件列表與最後修改工程師
            </caption>

            <thead>
                <tr>
                    <th scope="col">編號</th>
                    <th scope="col">案件標題</th>
                    <th scope="col">問題類別</th>
                    <th scope="col">狀態</th>
                    <th scope="col">期望完成日期</th>
                    <th scope="col">目前單別</th>
                    <th scope="col">最後修改工程師</th>
                    <th scope="col">建立時間</th>
                    <th scope="col">操作</th>
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
                            <span class="badge <?= $status === 'completed'
                                    ? 'badge-completed'
                                    : '' ?>">
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

                        <td class="nowrap">
                            <?php if ($ticket['last_engineer_id'] === null): ?>
                                尚無修改紀錄
                            <?php else: ?>
                                <div>
                                    <?= escape(
                                        (string) (
                                            $ticket['last_engineer_name']
                                            ?? (
                                                '人員 #'
                                                . (int) $ticket['last_engineer_id']
                                            )
                                        )
                                    ) ?>
                                </div>

                                <small class="hint">
                                    <?= escape(
                                        (string) $ticket['last_modified_at']
                                    ) ?>
                                </small>
                            <?php endif; ?>
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