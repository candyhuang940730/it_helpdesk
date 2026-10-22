<?php
    declare(strict_types=1);

    require_once __DIR__ . '/bootstrap.php';

    function requireEngineerLogin(): int
    {
        header('Cache-Control: no-store');

        $userId = positiveInteger(
            $_SESSION['user_id'] ?? null
        );

        if ($userId === null) {
            header('Location: login.php', true, 302);
            exit;
        }

        if (($_SESSION['role'] ?? null) !== 'engineer') {
            http_response_code(403);
            exit('此頁面僅供工程師使用。');
        }

        return $userId;
    }
?>