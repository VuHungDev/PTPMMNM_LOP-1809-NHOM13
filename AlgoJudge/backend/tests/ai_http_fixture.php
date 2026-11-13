<?php
/** Called only by ai_http_tests.cjs. Creates a NEW disposable schema; never loads .env. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('AJ_DB_NAME') ?: '';
$port = filter_var(getenv('AJ_DB_PORT'), FILTER_VALIDATE_INT);
if (!preg_match('/^algojudge_test_ai_[a-zA-Z0-9_]+$/D', $name) || !$port) exit("Refusing non-test database.\n");
$db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$check->execute([$name]);
if ($check->fetchColumn()) exit("Refusing existing database.\n");
$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$sql = str_replace(['CREATE DATABASE IF NOT EXISTS algojudge ', 'USE algojudge;'], ["CREATE DATABASE `$name` ", "USE `$name`;"], $sql);
$db->exec($sql);
$ins = $db->prepare('INSERT INTO users (username,email,password_hash,full_name,role) VALUES (?,?,?,?,?)');
foreach (['admin', 'teacher', 'student'] as $role) {
    $ins->execute(['ai_test_' . $role, 'ai_test_' . $role . '@example.test', password_hash('Ai-test-only-2026', PASSWORD_DEFAULT), 'Synthetic ' . $role, $role]);
}
echo "Isolated AI HTTP fixture created.\n";
