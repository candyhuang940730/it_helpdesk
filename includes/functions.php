<?php
    declare(strict_types=1);

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

    function positiveInteger(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $result === false ? null : (int) $result;
    }

    function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '未填寫';
        }

        return (string) $value;
    }
?>