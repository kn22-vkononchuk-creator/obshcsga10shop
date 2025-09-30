<?php
// system_check.php - перевірка системи та налаштувань

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Перевірка системи ObshchagaSHOP</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #059669; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .info { color: #3b82f6; font-weight: bold; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Перевірка системи ObshchagaSHOP</h1>
        
        <div class="test-section">
            <h2>1. Перевірка PHP</h2>
            <?php
            echo "<p class='success'>✅ PHP працює! Версія: " . phpversion() . "</p>";
            echo "<p class='info'>📊 Поточний час: " . date('Y-m-d H:i:s') . "</p>";
            ?>
        </div>

        <div class="test-section">
            <h2>2. Підключення до MySQL</h2>
            <?php
            $servername = "localhost"; 
            $username = "thebl526_1"; 
            $password = "06020209Vit"; 
            $dbname = "thebl526_1"; 

            try {
                $conn = new mysqli($servername, $username, $password, $dbname);
                if ($conn->connect_error) {
                    throw new Exception($conn->connect_error);
                }
                $conn->set_charset("utf8mb4");
                echo "<p class='success'>✅ MySQL підключення успішне!</p>";
                echo "<p class='info'>📊 Версія MySQL: " . $conn->server_info . "</p>";
                
                // Перевіряємо існування таблиць
                $tables_check = [
                    'categories' => "SHOW TABLES LIKE 'categories'",
                    'products' => "SHOW TABLES LIKE 'products'",
                    'orders' => "SHOW TABLES LIKE 'orders'",
                    'telegram_users' => "SHOW TABLES LIKE 'telegram_users'"
                ];
                
                echo "<h3>Перевірка таблиць:</h3>";
                foreach ($tables_check as $table => $query) {
                    $result = $conn->query($query);
                    if ($result && $result->num_rows > 0) {
                        echo "<p class='success'>✅ Таблиця <code>{$table}</code> існує</p>";
                    } else {
                        echo "<p class='error'>❌ Таблиця <code>{$table}</code> не існує</p>";
                    }
                }
                
                // Перевіряємо категорії
                echo "<h3>Існуючі категорії:</h3>";
                $result = $conn->query("SELECT id, name FROM categories ORDER BY name");
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $is_alcohol = ($row['name'] === 'Алкоголь і куріння');
                        $class = $is_alcohol ? 'info' : 'success';
                        echo "<p class='{$class}'>📂 ID: {$row['id']} - {$row['name']}" . ($is_alcohol ? ' 🍺' : '') . "</p>";
                    }
                } else {
                    echo "<p class='warning'>⚠️ Категорій не знайдено або таблиця порожня</p>";
                }
                
                $conn->close();
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Помилка MySQL: " . $e->getMessage() . "</p>";
            }
            ?>
        </div>

        <div class="test-section">
            <h2>3. Тест API categories</h2>
            <?php
            echo "<p class='info'>🔗 Тестуємо: <code>products_api.php?action=get_categories</code></p>";
            
            // Виконуємо внутрішній запит до API
            $api_url = $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/products_api.php?action=get_categories';
            $api_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $api_url;
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true
                ]
            ]);
            
            $api_response = @file_get_contents($api_url, false, $context);
            
            if ($api_response === false) {
                echo "<p class='error'>❌ Не вдалося підключитися до API</p>";
            } else {
                $decoded = json_decode($api_response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    echo "<p class='success'>✅ API повертає коректний JSON</p>";
                    echo "<p class='info'>📋 Відповідь: <code>" . htmlspecialchars($api_response) . "</code></p>";
                } else {
                    echo "<p class='error'>❌ API повертає некоректний JSON</p>";
                    echo "<p class='error'>🔍 Відповідь: <code>" . htmlspecialchars($api_response) . "</code></p>";
                    echo "<p class='error'>📝 JSON помилка: " . json_last_error_msg() . "</p>";
                }
            }
            ?>
        </div>

        <div class="test-section">
            <h2>4. Рекомендації</h2>
            <div style="background: #fef3c7; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                <h3>Якщо є проблеми:</h3>
                <ol>
                    <li><strong>Помилка підключення MySQL:</strong> Перевірте параметри бази даних в <code>products_api.php</code></li>
                    <li><strong>Таблиці не існують:</strong> Запустіть <code>create_alcohol_category.php</code> для створення</li>
                    <li><strong>API не працює:</strong> Перевірте права доступу до файлів та PHP модулі</li>
                    <li><strong>JSON помилки:</strong> Перевірте логи сервера в <code>php-error.log</code></li>
                </ol>
            </div>
        </div>

        <div class="test-section">
            <h2>5. Швидкі дії</h2>
            <p><a href="create_alcohol_category.php" style="background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">🚀 Створити категорію автоматично</a></p>
            <p><a href="test_api.php" style="background: #059669; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">🧪 Протестувати API</a></p>
        </div>

    </div>
</body>
</html>