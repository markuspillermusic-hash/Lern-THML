<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class FeedbackRegistry
{
    public static function task(string $moduleSlug, string $taskId): ?array
    {
        $file = dirname(__DIR__) . '/registry/feedback-' . preg_replace('/[^a-z0-9._-]/i', '', $moduleSlug) . '.php';
        if (!is_file($file)) return null;
        $tasks = require $file;
        if (!is_array($tasks) || !isset($tasks[$taskId]) || !is_array($tasks[$taskId])) return null;
        $task = $tasks[$taskId];
        $task['id'] = $taskId;
        $task['module'] = $moduleSlug;
        return $task;
    }
}

