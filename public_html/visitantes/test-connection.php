<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

try {
  $config = require __DIR__ . '/config.php';
  $host = $config['HOSTINGER_DB_HOST'];
  $port = $config['HOSTINGER_DB_PORT'] ?: '3306';
  $db = $config['HOSTINGER_DB_NAME'];
  $user = $config['HOSTINGER_DB_USER'];
  $pass = $config['HOSTINGER_DB_PASSWORD'];
  
  echo json_encode([
    'status' => 'config_loaded',
    'host' => $host,
    'port' => $port,
    'db' => $db,
    'user' => $user,
    'password_set' => !empty($pass)
  ], JSON_PRETTY_PRINT);
  
  $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  
  $stmt = $pdo->query('SELECT 1 as test');
  $result = $stmt->fetch();
  
  echo "\n\n";
  echo json_encode([
    'status' => 'success',
    'connection' => 'ok',
    'test_query' => $result
  ], JSON_PRETTY_PRINT);
  
  // Test table
  $stmt = $pdo->query('SELECT COUNT(*) as total FROM visitantes');
  $count = $stmt->fetch();
  
  echo "\n\n";
  echo json_encode([
    'table_test' => 'ok',
    'total_visitantes' => $count['total']
  ], JSON_PRETTY_PRINT);
  
} catch (PDOException $e) {
  echo "\n\n";
  echo json_encode([
    'status' => 'error',
    'type' => 'PDOException',
    'message' => $e->getMessage(),
    'code' => $e->getCode()
  ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
  echo "\n\n";
  echo json_encode([
    'status' => 'error',
    'type' => 'Exception',
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine()
  ], JSON_PRETTY_PRINT);
}

