<?php
// create_alcohol_category.php - скрипт для створення категорії "Алкоголь і куріння"

header("Content-Type: application/json; charset=utf-8");

// --- Параметри підключення до БД ---
$servername = "localhost"; 
$username = "thebl526_1"; 
$password = "06020209Vit"; 
$dbname = "thebl526_1"; 

try {
    // Підключення до БД
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    // Створюємо таблицю categories якщо її немає
    $sql_create_table = "CREATE TABLE IF NOT EXISTS `categories` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL UNIQUE,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($sql_create_table)) {
        throw new Exception("Error creating categories table: " . $conn->error);
    }

    // Перевіряємо, чи існує категорія "Алкоголь і куріння"
    $category_name = "Алкоголь і куріння";
    $stmt_check = $conn->prepare("SELECT id FROM categories WHERE name = ?");
    $stmt_check->bind_param("s", $category_name);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $existing_id = $result_check->fetch_assoc()['id'];
        $stmt_check->close();
        echo json_encode([
            "status" => "success", 
            "message" => "Категорія 'Алкоголь і куріння' вже існує!",
            "category_id" => (int)$existing_id,
            "existing" => true
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $stmt_check->close();
        
        // Створюємо нову категорію
        $stmt_insert = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt_insert->bind_param("s", $category_name);
        
        if ($stmt_insert->execute()) {
            $new_id = $conn->insert_id;
            $stmt_insert->close();
            echo json_encode([
                "status" => "success", 
                "message" => "Категорію 'Алкоголь і куріння' успішно створено!",
                "category_id" => $new_id,
                "existing" => false
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $stmt_insert->close();
            throw new Exception("Помилка створення категорії: " . $stmt_insert->error);
        }
    }
    
    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>