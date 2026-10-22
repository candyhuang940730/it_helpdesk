<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$engineerId = requireEngineerLogin();

$ticketId = positiveInteger(
    $_GET['ticket_id'] ?? null
);

if ($ticketId === null) {
    http_response_code(400);
    exit('案件編號格式不正確。');
}

$engineerId = positiveInteger(
    $_SESSION['user_id'] ?? null
);

if ($engineerId === null) {
    header('Location: login.php', true, 302);
    exit;
}

/*
 * 從網址取得案件編號：
 * ticket_detail.php?ticket_id=1
 */
$ticketId = positiveInteger(
    $_GET['ticket_id'] ?? null
);

if ($ticketId === null) {
    http_response_code(400);
    exit('案件編號格式不正確。');
}

$ticketTypes = [
    'repair' => '報修',
    'maintenance' => '保養',
    'installation' => '安裝',
];

/*
 * 必須與 tickets.status 的實際值一致。
 */
$statuses = [
    'pending' => '待處理',
    'in_progress' => '處理中',
    'completed' => '已完成',
];

$categories = [
    'computer' => '電腦設備',
    'network' => '網路問題',
    'account' => '帳號問題',
    'software' => '軟體問題',
    'other' => '其他',
];

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$logs = [];
$logsLoadFailed = false;

$flashKey = 'ticket_detail_success_' . $ticketId;
$successMessage = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);

/*
 * 建立連線並讀取案件。
 */
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
            ticket_id,
            title,
            category,
            description,
            customer_name,
            contact_name,
            contact_phone,
            contact_email,
            requested_completion_date,
            ticket_type,
            status,
            created_at
         FROM tickets
         WHERE ticket_id = :ticket_id'
    );

    $statement->execute([
        'ticket_id' => $ticketId,
    ]);

    $ticket = $statement->fetch();
} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);
    exit('案件資料讀取失敗，請稍後再試。');
}

if ($ticket === false) {
    http_response_code(404);
    exit('找不到這筆案件。');
}

/*
 * 表單預設值
 */
$selectedType = (string) ($ticket['ticket_type'] ?? '');
$selectedStatus = (string) $ticket['status'];

$workContent = '';
$workMinutes = '0';

/*
 * 保存開啟頁面時的狀態，用來偵測常見的同時修改情境。
 */
$originalType = $selectedType;
$originalStatus = $selectedStatus;

/*
 * 處理工程師回報。
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = postString('csrf_token');

    $selectedType = postString('ticket_type');
    $selectedStatus = postString('status');

    $workContent = postString('work_content');
    $workMinutes = postString('work_minutes');

    $originalType = postString('original_type');
    $originalStatus = postString('original_status');

    if (
        $submittedToken === '' ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {
        $errors[] = '表單驗證失敗，請重新整理頁面後再試。';
    }

    if (!array_key_exists($selectedType, $ticketTypes)) {
        $errors[] = '請選擇有效的單別。';
    }

    if (!array_key_exists($selectedStatus, $statuses)) {
        $errors[] = '請選擇有效的案件狀態。';
    }

    if ($workContent === '') {
        $errors[] = '請填寫本次處理內容或修改原因。';
    } elseif (
        preg_match('/^.{1,5000}$/us', $workContent) !== 1
    ) {
        $errors[] = '處理內容不可超過 5,000 個字。';
    }

    /*
     * 本次工時使用整數分鐘。
     * 0 分鐘允許用於單純分類或狀態調整。
     * 單次上限 1,440 分鐘是本範例的業務規則。
     */
    $minutes = filter_var(
        $workMinutes,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 0,
                'max_range' => 1440,
            ],
        ]
    );

    if ($minutes === false) {
        $errors[] = '本次工時請填寫 0～1,440 的整數分鐘。';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'SELECT ticket_type, status
                 FROM tickets
                 WHERE ticket_id = :ticket_id
                 FOR UPDATE'
            );

            $statement->execute([
                'ticket_id' => $ticketId,
            ]);

            $currentTicket = $statement->fetch();

            if ($currentTicket === false) {
                throw new RuntimeException(
                    '案件已不存在，無法儲存。'
                );
            }

            /*
             * 若單別或狀態已被其他操作修改，先拒絕覆寫。
             * 這不是完整版本控制，但可處理常見的過期表單。
             */
            if (
                (string) ($currentTicket['ticket_type'] ?? '')
                    !== $originalType ||
                (string) $currentTicket['status']
                    !== $originalStatus
            ) {
                throw new RuntimeException(
                    '案件的單別或狀態已變更，請先保留處理內容，'
                    . '再重新開啟本頁確認後送出。'
                );
            }

            $statement = $pdo->prepare(
                'UPDATE tickets
                 SET
                    ticket_type = :ticket_type,
                    status = :status
                 WHERE ticket_id = :ticket_id'
            );

            $statement->execute([
                'ticket_type' => $selectedType,
                'status' => $selectedStatus,
                'ticket_id' => $ticketId,
            ]);

            $statement = $pdo->prepare(
                'INSERT INTO ticket_work_logs (
                    ticket_id,
                    engineer_id,
                    old_ticket_type,
                    new_ticket_type,
                    old_status,
                    new_status,
                    work_content,
                    work_minutes
                 )
                 VALUES (
                    :ticket_id,
                    :engineer_id,
                    :old_ticket_type,
                    :new_ticket_type,
                    :old_status,
                    :new_status,
                    :work_content,
                    :work_minutes
                 )'
            );

            $statement->execute([
                'ticket_id' => $ticketId,
                'engineer_id' => $engineerId,
                'old_ticket_type'
                    => $currentTicket['ticket_type'],
                'new_ticket_type' => $selectedType,
                'old_status' => $currentTicket['status'],
                'new_status' => $selectedStatus,
                'work_content' => $workContent,
                'work_minutes' => $minutes,
            ]);

            $pdo->commit();

            $_SESSION[$flashKey] = '案件與處理紀錄已儲存。';

            header(
                'Location: ticket_detail.php?ticket_id=' . $ticketId,
                true,
                303
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException &&
                !($e instanceof PDOException)) {
                $errors[] = $e->getMessage();
            } else {
                error_log($e->getMessage());
                $errors[] = '儲存失敗，請稍後再試。';
            }
        }
    }
}

/*
 * 讀取處理歷程。
 */
try {
    $statement = $pdo->prepare(
        'SELECT
            l.log_id,
            l.engineer_id,
            u.name AS engineer_name,
            l.old_ticket_type,
            l.new_ticket_type,
            l.old_status,
            l.new_status,
            l.work_content,
            l.work_minutes,
            l.created_at
        FROM ticket_work_logs AS l
        LEFT JOIN users AS u
            ON u.user_id = l.engineer_id
        WHERE l.ticket_id = :ticket_id
        ORDER BY l.created_at DESC, l.log_id DESC'
    );

    $statement->execute([
        'ticket_id' => $ticketId,
    ]);

    $logs = $statement->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());

    $logsLoadFailed = true;
    $errors[] = '處理歷程讀取失敗，請確認紀錄表是否已建立。';
}

$totalMinutes = array_sum(
    array_column($logs, 'work_minutes')
);

$currentTypeLabel = $ticket['ticket_type'] === null
    ? '未分類'
    : ($ticketTypes[$ticket['ticket_type']] ?? '未知單別');

$currentStatusLabel = $statuses[$ticket['status']]
    ?? (string) $ticket['status'];

$categoryLabel = $categories[$ticket['category']]
    ?? (string) $ticket['category'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>案件 #<?= $ticketId ?>｜IT 報修系統</title>

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
            max-width: 960px;
            margin: 0 auto;
        }

        .card {
            margin-bottom: 24px;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .05);
        }

        h1, h2 {
            margin-top: 0;
        }

        a {
            color: #285ea8;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 24px;
        }

        dl {
            margin: 0;
        }

        dt {
            margin-bottom: 6px;
            color: #65758a;
        }

        dd {
            margin: 0;
            overflow-wrap: anywhere;
        }

        .content {
            line-height: 1.8;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .field {
            margin-top: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        input, select, textarea, button {
            font: inherit;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #c5ceda;
            border-radius: 6px;
        }

        textarea {
            min-height: 150px;
            resize: vertical;
        }

        button {
            margin-top: 22px;
            padding: 12px 22px;
            color: #fff;
            background: #285ea8;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }

        .hint, .meta {
            color: #65758a;
            font-size: 14px;
            line-height: 1.6;
        }

        .message {
            margin-bottom: 20px;
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

        .log {
            padding: 18px 0;
            border-top: 1px solid #e3e8ef;
        }

        @media (max-width: 600px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>
<main>
    <button onclick="location.href='manage_table.php'" class="back">
        ← 返回案件列表
    </button>
    
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

    <section class="card">
        <h1>
            案件 #<?= $ticketId ?>：
            <?= escape((string) $ticket['title']) ?>
        </h1>

        <div class="grid">
            <dl>
                <dt>問題類別</dt>
                <dd><?= escape($categoryLabel) ?></dd>
            </dl>

            <dl>
                <dt>目前單別</dt>
                <dd><?= escape($currentTypeLabel) ?></dd>
            </dl>

            <dl>
                <dt>目前狀態</dt>
                <dd><?= escape($currentStatusLabel) ?></dd>
            </dl>

            <dl>
                <dt>客戶期望完成日期</dt>
                <dd><?= escape(displayValue(
                    $ticket['requested_completion_date']
                )) ?></dd>
            </dl>

            <dl>
                <dt>報修單位／部門</dt>
                <dd><?= escape(displayValue(
                    $ticket['customer_name']
                )) ?></dd>
            </dl>

            <dl>
                <dt>聯繫人</dt>
                <dd><?= escape(displayValue(
                    $ticket['contact_name']
                )) ?></dd>
            </dl>

            <dl>
                <dt>聯絡電話／分機</dt>
                <dd><?= escape(displayValue(
                    $ticket['contact_phone']
                )) ?></dd>
            </dl>

            <dl>
                <dt>電子郵件</dt>
                <dd><?= escape(displayValue(
                    $ticket['contact_email']
                )) ?></dd>
            </dl>

            <dl>
                <dt>建立時間</dt>
                <dd><?= escape(
                    (string) $ticket['created_at']
                ) ?></dd>
            </dl>
        </div>

        <h2 style="margin-top: 26px;">問題描述</h2>

        <div class="content"><?= escape(
            (string) $ticket['description']
        ) ?></div>
    </section>

    <section class="card">
        <h2>工程師處理</h2>

        <p class="hint">
            每次儲存都會新增一筆紀錄。
            單純修改分類或狀態時，也請填寫原因。
        </p>

        <form
            action="ticket_detail.php?ticket_id=<?= $ticketId ?>"
            method="post"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escape($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="original_type"
                value="<?= escape($originalType) ?>"
            >

            <input
                type="hidden"
                name="original_status"
                value="<?= escape($originalStatus) ?>"
            >

            <div class="grid">
                <div class="field">
                    <label for="ticket_type">單別</label>

                    <select
                        id="ticket_type"
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
                            請選擇單別
                        </option>

                        <?php foreach ($ticketTypes as $value => $label): ?>
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
                </div>

                <div class="field">
                    <label for="status">案件狀態</label>

                    <select id="status" name="status" required>
                        <?php if (!isset($statuses[$selectedStatus])): ?>
                            <option value="" selected disabled>
                                請選擇狀態
                            </option>
                        <?php endif; ?>

                        <?php foreach ($statuses as $value => $label): ?>
                            <option
                                value="<?= escape($value) ?>"
                                <?= $selectedStatus === $value
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="work_content">
                    本次處理內容／修改原因
                </label>

                <textarea
                    id="work_content"
                    name="work_content"
                    maxlength="5000"
                    placeholder="例如：確認網路線鬆脫，重新接妥後測試連線正常。"
                    required
                ><?= escape($workContent) ?></textarea>
            </div>

            <div class="field">
                <label for="work_minutes">
                    本次工時（分鐘）
                </label>

                <input
                    type="number"
                    id="work_minutes"
                    name="work_minutes"
                    min="0"
                    max="1440"
                    step="1"
                    value="<?= escape($workMinutes) ?>"
                    required
                >

                <p class="hint">
                    例如半小時填 30。
                    只做分類或狀態調整時，可以填 0。
                    請填本次工時，不是累計工時。
                </p>
            </div>

            <button type="submit">
                儲存案件與處理紀錄
            </button>
        </form>
    </section>

    <section class="card">
        <h2>處理歷程</h2>

        <?php if ($logsLoadFailed): ?>
            <p>目前無法載入處理歷程。</p>
        <?php else: ?>
            <p class="hint">
                累計工時：
                <?= (int) $totalMinutes ?> 分鐘
                （<?= number_format(
                    $totalMinutes / 60,
                    2
                ) ?> 小時）
            </p>

            <?php if ($logs === []): ?>
                <p>目前尚無處理紀錄。</p>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $oldTypeLabel =
                        $log['old_ticket_type'] === null
                        ? '未分類'
                        : (
                            $ticketTypes[$log['old_ticket_type']]
                            ?? $log['old_ticket_type']
                        );

                    $newTypeLabel =
                        $ticketTypes[$log['new_ticket_type']]
                        ?? $log['new_ticket_type'];

                    $oldStatusLabel =
                        $statuses[$log['old_status']]
                        ?? $log['old_status'];

                    $newStatusLabel =
                        $statuses[$log['new_status']]
                        ?? $log['new_status'];
                    ?>

                    <article class="log">
                        <p class="meta">
                            <?= escape(
                                (string) $log['created_at']
                            ) ?>
                            ｜工程師 #<?= escape(
                                $log['engineer_name']
                                    ?? 'ID: ' . (int) $log['engineer_id']
                            ) ?>
                            ｜本次 <?= (int) $log['work_minutes'] ?> 分鐘
                        </p>

                        <p>
                            單別：
                            <?= escape((string) $oldTypeLabel) ?>
                            →
                            <?= escape((string) $newTypeLabel) ?>
                        </p>

                        <p>
                            狀態：
                            <?= escape((string) $oldStatusLabel) ?>
                            →
                            <?= escape((string) $newStatusLabel) ?>
                        </p>

                        <div class="content"><?= escape(
                            (string) $log['work_content']
                        ) ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>