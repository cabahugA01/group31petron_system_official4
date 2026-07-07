<?php
require_once __DIR__ . '/public/db_connect.php';
try {
    echo "Creating test table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS test_innodb (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255)) ENGINE=InnoDB");
    echo "Inserting test record...\n";
    $pdo->exec("INSERT INTO test_innodb (name) VALUES ('test')");
    $stmt = $pdo->query("SELECT * FROM test_innodb");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "Dropping test table...\n";
    $pdo->exec("DROP TABLE test_innodb");
    echo "InnoDB engine works fine!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
