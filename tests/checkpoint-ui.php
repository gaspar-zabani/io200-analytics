<?php
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$authenticated = true;
$activity = 'admin';
$photoTab = 'views';
$checkpointCsrf = 'test-csrf';
$checkpointReady = true;
$checkpointError = null;
$period = 'checkpoint';
$checkpointSelection = '2';
$checkpoints = [['id' => '2', 'name' => '<script>alert(1)</script>', 'created_at' => '2026-10-10 12:00:00']];
$statisticsStart = $checkpoints[0];
$range = ['constrained' => true, 'empty' => false];
$reportingLabel = 'Checkpoint · Since ' . $statisticsStart['name'];
ob_start();
require __DIR__ . '/../checkpoint-controls.php';
$html = ob_get_clean();
$dom = new DOMDocument;
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
if ($xpath->query('//form[@method="post"]')->length !== 4
    || $xpath->query('//input[@name="checkpoint_csrf" and @value="test-csrf"]')->length !== 4
    || $xpath->query('//a[@data-checkpoint-choice and @aria-current="true"]')->length !== 1
    || $xpath->query('//input[@type="datetime-local" or @name="created_at"]')->length !== 0
    || $xpath->query('//input[@name="confirm_delete" and @required]')->length !== 1
    || str_contains($html, '<script>alert(1)</script>')) throw new RuntimeException('Checkpoint UI safety/selection');
$checkpoints = [];
$statisticsStart = null;
$range = ['constrained' => false, 'empty' => true];
ob_start(); require __DIR__ . '/../checkpoint-controls.php'; $empty = ob_get_clean();
if (!str_contains($empty, 'No checkpoints yet') || !str_contains($empty, 'Latest checkpoint')) throw new RuntimeException('Empty checkpoint UI');
if (!str_contains($html, 'checkpoint=2&amp;activity=admin&amp;photo_tab=views')
    || $xpath->query('//div[@class="checkpoint-menu-actions"]/button')->length !== 2
    || $xpath->query('//nav[@class="checkpoint-menu-list"]')->length !== 1
    || str_contains($html, 'Statistics start limits this range')
    || str_contains($html, 'Visit details show complete episodes')) throw new RuntimeException('Menu layout/navigation');
echo "Checkpoint UI escaping, POST forms, selection and empty state cases passed\n";
