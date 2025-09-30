<?php
// test_api.php - простий тест для перевірки PHP
header("Content-Type: application/json; charset=utf-8");

try {
    // Тест базової роботи
    echo json_encode([
        "status" => "success", 
        "message" => "PHP працює коректно!",
        "timestamp" => date('Y-m-d H:i:s'),
        "method" => $_SERVER['REQUEST_METHOD'],
        "post_data" => $_POST,
        "get_data" => $_GET
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>