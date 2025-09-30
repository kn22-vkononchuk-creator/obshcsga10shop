<?php
// products_api.php

// --- Налаштування помилок ---
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

header("Content-Type: application/json"); 
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// --- Параметри підключення до БД ---
$servername = "localhost"; 
$username = "thebl526_1"; 
$password = "06020209Vit"; 
$dbname = "thebl526_1"; 

// --- Налаштування Telegram Bot API та директорії завантажень ---
define('TELEGRAM_BOT_TOKEN', '8346536291:AAFP8GoxLgF9VPrKveiIGCkqGM4v-9Hr98U');
define('ADMIN_TELEGRAM_CHAT_ID', '650910476');
define('UPLOAD_DIR', 'uploads/');
define('WEBSITE_BASE_URL', 'https://www.obshchaga10shop.website/'); // <--- Змініть на ваш домен

// --- Підключення до БД ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]);
    exit();
}
$conn->set_charset("utf8mb4");

// --- Глобальна змінна для JSON-даних ---
$request_data = [];

// --- Визначення action ---
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Спробуємо отримати 'action' з POST-даних (для form-data)
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
    } else {
        // Якщо не знайдено в $_POST, спробуємо отримати з JSON-тіла запиту
        $input = file_get_contents('php://input');
        // Додаємо перевірку на валідність JSON
        $decoded_input = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $request_data = $decoded_input;
            if (isset($request_data['action'])) {
                $action = $request_data['action'];
            }
        } else {
            // Логуємо помилку, якщо вхідні дані не є валідним JSON
            error_log("JSON Decode Error: " . json_last_error_msg() . " Input: " . $input);
            // Можна повернути помилку клієнту, якщо це критично для дії
            // http_response_code(400);
            // echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
            // exit();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Для GET-запитів 'action' завжди в URL
    $action = isset($_GET['action']) ? $_GET['action'] : '';
}

// --- Telegram helper functions ---
/**
 * Відправляє текстове повідомлення в Telegram.
 * @param string $chat_id ID чату отримувача.
 * @param string $message Текст повідомлення (підтримує Markdown).
 * @return bool True у разі успіху, false у разі помилки.
 */
function sendTelegramMessage($chat_id, $message) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown' // Дозволяє використовувати форматування Markdown
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true, // Важливо для отримання відповіді при помилках
        ],
    ];
    $context  = stream_context_create($options);
    
    // Використовуємо @ для придушення попереджень, якщо file_get_contents не може підключитися
    $result = @file_get_contents($url, false, $context); 

    if ($result === FALSE) {
        error_log("Telegram API call failed. Check network or bot token. Message: " . $message);
        return false;
    }
    
    $response_data = json_decode($result, true);
    if (isset($response_data['ok']) && $response_data['ok'] === false) {
        error_log("Telegram API error: " . $response_data['description'] . ". Chat ID: " . $chat_id . ". Message: " . $message);
        return false;
    }

    return true; // Повідомлення успішно відправлено
}

/**
 * Відправляє фото з підписом в Telegram.
 * @param string $chat_id ID чату отримувача.
 * @param string $photo_path Локальний шлях до файлу фото.
 * @param string|null $caption Підпис до фото (підтримує Markdown).
 * @return bool True у разі успіху, false у разі помилки.
 */
function sendTelegramPhoto($chat_id, $photo_path, $caption = null) {
    if (!file_exists($photo_path)) {
        error_log("Telegram API: Photo file not found at " . $photo_path);
        return false;
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendPhoto";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Для відправки файлів використовуємо CURLFile
    $post_fields = [
        'chat_id' => $chat_id,
        'photo' => new CURLFile(realpath($photo_path)),
        'parse_mode' => 'Markdown'
    ];

    if ($caption) {
        $post_fields['caption'] = $caption;
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Telegram API cURL error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $response_data = json_decode($result, true);
    if (isset($response_data['ok']) && $response_data['ok'] === false) {
        error_log("Telegram API error (sendPhoto): " . $response_data['description'] . ". Chat ID: " . $chat_id);
        return false;
    }

    return true;
}

/**
 * Обробляє вхідні повідомлення від Telegram Bot API (Webhook).
 * Реєструє користувачів, які надсилають /start.
 * @param mysqli $conn Об'єкт підключення до бази даних.
 */
function handleTelegramWebhook($conn) {
    $update = json_decode(file_get_contents('php://input'), true);

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'];
        $username = isset($message['from']['username']) ? $message['from']['username'] : null;

        if ($text === '/start') {
            if ($username) {
                // Check if user already registered
                $stmt = $conn->prepare("SELECT telegram_chat_id FROM telegram_users WHERE telegram_username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    // User already registered
                    sendTelegramMessage($chat_id, "Ви вже зареєстровані в боті, @{$username}! Ваш Chat ID: `{$chat_id}`. Ви будете отримувати сповіщення.");
                } else {
                    // Register new user
                    $stmt = $conn->prepare("INSERT INTO telegram_users (telegram_username, telegram_chat_id) VALUES (?, ?)");
                    $stmt->bind_param("ss", $username, $chat_id);
                    if ($stmt->execute()) {
                        sendTelegramMessage($chat_id, "Вітаємо, @{$username}! Ви успішно зареєстровані. Ваш Chat ID: `{$chat_id}`. Тепер ви будете отримувати сповіщення від ObshchagaSHOP.");

                        // Notify admin about new user registration
                        $admin_message = "🆕 *Новий користувач зареєстрований у боті!*\n\n";
                        $admin_message .= "Username: @{$username}\n";
                        $admin_message .= "Chat ID: `{$chat_id}`";
                        sendTelegramMessage(ADMIN_TELEGRAM_CHAT_ID, $admin_message);
                    } else {
                        sendTelegramMessage($chat_id, "Виникла помилка при реєстрації. Спробуйте ще раз або зверніться до адміністратора.");
                        error_log("Error registering Telegram user: " . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                sendTelegramMessage($chat_id, "Будь ласка, встановіть ім'я користувача (username) у своєму профілі Telegram, щоб я міг вас ідентифікувати.");
            }
        } elseif ($text === '/usercount') {
            // Only allow admin to get user count
            if ($chat_id == ADMIN_TELEGRAM_CHAT_ID) {
                $result = $conn->query("SELECT COUNT(*) as cnt FROM telegram_users");
                $count = 0;
                if ($result) {
                    $row = $result->fetch_assoc();
                    $count = $row['cnt'];
                }
                sendTelegramMessage($chat_id, "Кількість зареєстрованих користувачів: *{$count}*");
            } else {
                sendTelegramMessage($chat_id, "Вибачте, ця команда доступна лише адміністратору.");
            }
        } elseif ($text === '/myorders') { // NEW: Command to view user's orders
            $stmt = $conn->prepare("SELECT id, total_amount, status, created_at FROM orders WHERE customer_telegram_chat_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("s", $chat_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $message_text = "📜 *Ваші замовлення:*\n\n";
                while ($order = $result->fetch_assoc()) {
                    $message_text .= "Замовлення №`{$order['id']}`\n";
                    $message_text .= "Сума: *{$order['total_amount']} грн*\n";
                    $message_text .= "Статус: *{$order['status']}*\n";
                    $message_text .= "Дата: " . date('d.m.Y H:i', strtotime($order['created_at'])) . "\n";
                    
                    // Додаємо кнопку скасування, якщо замовлення нове
                    if ($order['status'] === 'New') {
                        $message_text .= "Щоб скасувати, натисніть: /cancel\_order\_" . $order['id'] . "\n";
                    }
                    $message_text .= "--------------------\n";
                }
                sendTelegramMessage($chat_id, $message_text);
            } else {
                sendTelegramMessage($chat_id, "У вас ще немає замовлень.");
            }
            $stmt->close();
        } elseif (strpos($text, '/cancel_order_') === 0) { // NEW: Command to cancel an order
            $order_id = (int)str_replace('/cancel_order_', '', $text);

            // Перевіряємо, чи замовлення належить цьому користувачу і має статус 'New'
            $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ? AND customer_telegram_chat_id = ?");
            $stmt->bind_param("is", $order_id, $chat_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $order = $result->fetch_assoc();
                if ($order['status'] === 'New') {
                    // Скасовуємо замовлення
                    $conn->begin_transaction();
                    try {
                        // Повертаємо товари на склад
                        $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                        $stmt_items->bind_param("i", $order_id);
                        $stmt_items->execute();
                        $items_result = $stmt_items->get_result();

                        while ($item = $items_result->fetch_assoc()) {
                            $stmt_update_stock = $conn->prepare("UPDATE products SET in_stock = in_stock + ? WHERE id = ?");
                            $stmt_update_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                            $stmt_update_stock->execute();
                            $stmt_update_stock->close();
                        }
                        $stmt_items->close();

                        // Оновлюємо статус замовлення
                        $stmt_update_order = $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE id = ?");
                        $stmt_update_order->bind_param("i", $order_id);
                        $stmt_update_order->execute();
                        $stmt_update_order->close();

                        $conn->commit();
                        sendTelegramMessage($chat_id, "✅ Ваше замовлення №`{$order_id}` успішно скасовано. Товари повернено на склад.");
                        sendTelegramMessage(ADMIN_TELEGRAM_CHAT_ID, "⚠️ *Замовлення №`{$order_id}` скасовано покупцем* (@{$username}). Товари повернено на склад.");

                    } catch (Exception $e) {
                        $conn->rollback();
                        sendTelegramMessage($chat_id, "❌ Виникла помилка при скасуванні замовлення №`{$order_id}`. Спробуйте ще раз або зверніться до адміністратора.");
                        error_log("Error cancelling order from Telegram: " . $e->getMessage());
                    }
                } else {
                    sendTelegramMessage($chat_id, "Замовлення №`{$order_id}` не може бути скасовано, оскільки його статус: *{$order['status']}*.");
                }
            } else {
                sendTelegramMessage($chat_id, "Замовлення №`{$order_id}` не знайдено або ви не є його власником.");
            }
            $stmt->close();
        }
        else {
            sendTelegramMessage($chat_id, "Я бот ObshchagaSHOP. Для реєстрації та отримання сповіщень, будь ласка, надішліть команду /start. Щоб переглянути ваші замовлення, надішліть /myorders.");
        }
    }
    // Always return 200 OK to prevent Telegram from resending updates
    http_response_code(200);
    exit();
}


// Перевіряємо, чи є запит від Telegram Webhook
if (isset($_GET['webhook']) && $_GET['webhook'] === 'telegram') {
    handleTelegramWebhook($conn);
}

// --- Обробка GET-запитів ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {
        case 'get_categories':
            $sql = "SELECT id, name FROM categories ORDER BY name ASC";
            $result = $conn->query($sql);
            $categories = [];
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
            echo json_encode(["status" => "success", "data" => $categories]);
            break;

        case 'get_products':
            $category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
            $sort = isset($_GET['sort']) ? $_GET['sort'] : null;
            $search_term = isset($_GET['search']) ? '%' . $conn->real_escape_string($_GET['search']) . '%' : null; // Додано пошуковий термін

            $where_clauses = [];
            $bind_types = "";
            $bind_values = [];

            if ($category_id !== null) {
                $where_clauses[] = "category_id = ?";
                $bind_types .= "i";
                $bind_values[] = $category_id;
            }
            
            // Додано умову пошуку
            if ($search_term !== null) {
                $where_clauses[] = "(name LIKE ? OR description LIKE ?)";
                $bind_types .= "ss";
                $bind_values[] = $search_term;
                $bind_values[] = $search_term;
            }

            $order_by = " ORDER BY created_at DESC ";
            if ($sort === 'price_asc') {
                $order_by = " ORDER BY price ASC ";
            } elseif ($sort === 'price_desc') {
                $order_by = " ORDER BY price DESC ";
            } elseif ($sort === 'date_asc') {
                $order_by = " ORDER BY created_at ASC ";
            } elseif ($sort === 'date_desc') {
                $order_by = " ORDER BY created_at DESC ";
            }

            $sql = "SELECT id, name, description, price, image_url, in_stock, discount_percentage, is_on_sale, category_id, created_at FROM products";
            if (!empty($where_clauses)) {
                $sql .= " WHERE " . implode(" AND ", $where_clauses);
            }
            $sql .= $order_by;

            $stmt = $conn->prepare($sql);
            if (!empty($bind_values)) {
                $stmt->bind_param($bind_types, ...$bind_values);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $products = [];
            while($row = $result->fetch_assoc()) {
                $row['price'] = (float)$row['price'];
                $row['in_stock'] = (int)$row['in_stock'];
                $row['discount_percentage'] = (int)$row['discount_percentage'];
                $row['is_on_sale'] = (bool)$row['is_on_sale'];
                $products[] = $row;
            }
            $stmt->close();

            echo json_encode(["status" => "success", "data" => $products]);
            break;

        case 'get_telegram_users': // NEW ACTION
            $sql = "SELECT telegram_username, telegram_chat_id FROM telegram_users ORDER BY telegram_username ASC";
            $result = $conn->query($sql);
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            echo json_encode(["status" => "success", "data" => $users]);
            break;

        case 'get_orders': // NEW ACTION: Get all orders for admin panel
            $sql = "SELECT o.id, o.customer_name, o.customer_telegram, o.customer_phone, o.customer_address, o.needs_delivery, o.total_amount, o.status, o.created_at, o.customer_telegram_chat_id,
                           GROUP_CONCAT(CONCAT(oi.product_name, ' (', oi.product_price, ' грн) x ', oi.quantity, ' шт') SEPARATOR '; ') AS items_summary
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    GROUP BY o.id
                    ORDER BY o.created_at DESC";
            $result = $conn->query($sql);
            $orders = [];
            while ($row = $result->fetch_assoc()) {
                $row['total_amount'] = (float)$row['total_amount'];
                $row['needs_delivery'] = (bool)$row['needs_delivery'];
                $orders[] = $row;
            }
            echo json_encode(["status" => "success", "data" => $orders]);
            break;

        default:
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Unknown GET action."]);
            break;
    }
    $conn->close();
    exit();
}

// --- Обробка POST-запитів ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'admin_login':
            if (isset($_POST['username']) && isset($_POST['password'])) {
                $username = $conn->real_escape_string($_POST['username']);
                $password = $_POST['password'];

                $stmt = $conn->prepare("SELECT password_hash FROM admins WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $hashed_password = $row['password_hash'];

                    if (password_verify($password, $hashed_password)) {
                        echo json_encode(["status" => "success", "authenticated" => true]);
                    } else {
                        echo json_encode(["status" => "error", "message" => "Неправильне ім'я користувача або пароль.", "authenticated" => false]);
                    }
                } else {
                    echo json_encode(["status" => "error", "message" => "Неправильне ім'я користувача або пароль.", "authenticated" => false]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing username or password for admin login."]);
            }
            break;

        case 'add_category':
            if (isset($_POST['name']) && !empty(trim($_POST['name']))) {
                $name = $conn->real_escape_string(trim($_POST['name']));
                $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->bind_param("s", $name);
                if ($stmt->execute()) {
                    echo json_encode(["status" => "success", "id" => $conn->insert_id]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Помилка додавання категорії: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Ім'я категорії не може бути порожнім."]);
            }
            break;

        case 'update_category':
            if (isset($_POST['id']) && isset($_POST['name'])) {
                $id = (int)$_POST['id'];
                $name = $conn->real_escape_string(trim($_POST['name']));
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Ім'я категорії не може бути порожнім."]);
                    break;
                }
                $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $name, $id);
                if ($stmt->execute()) {
                    echo json_encode(["status" => "success"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Помилка оновлення категорії: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутні обов'язкові поля для оновлення категорії."]);
            }
            break;

        case 'delete_category':
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];

                // Перевірка наявності товарів у категорії
                $stmt_check = $conn->prepare("SELECT COUNT(*) as cnt FROM products WHERE category_id = ?");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $count = $result_check->fetch_assoc()['cnt'];
                $stmt_check->close();

                if ($count > 0) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Неможливо видалити категорію, бо в ній є товари."]);
                    break;
                }

                $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    echo json_encode(["status" => "success"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Помилка видалення категорії: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутній ID категорії для видалення."]);
            }
            break;

        case 'add_product':
            if (isset($_POST['name'], $_POST['price'], $_POST['in_stock'], $_POST['discount_percentage'], $_POST['is_on_sale'])) {
                $name = $conn->real_escape_string($_POST['name']);
                $description = isset($_POST['description']) ? $conn->real_escape_string($_POST['description']) : null;
                $price = (float)$_POST['price'];
                $in_stock = (int)$_POST['in_stock'];
                $discount_percentage = (int)$_POST['discount_percentage'];
                $is_on_sale = (bool)$_POST['is_on_sale'];
                $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;

                $image_url_to_db = null;

                // Обробка завантаження файлу зображення
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['image_file'];
                    $file_name = uniqid() . '_' . basename($file['name']); // Генеруємо унікальне ім'я файлу
                    $target_file = UPLOAD_DIR . $file_name;

                    // Створюємо директорію, якщо її немає
                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0777, true); // 0777 - повні права, для продакшену краще 0755
                    }

                    // Перевіряємо тип файлу (тільки зображення)
                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                    if (!in_array($file_extension, $allowed_extensions)) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Недопустимий тип файлу. Дозволені: JPG, PNG, GIF."]);
                        break; // Виходимо з switch
                    }

                    // Перевіряємо розмір файлу (максимум 5MB)
                    if ($file['size'] > 5 * 1024 * 1024) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Файл занадто великий. Максимум 5MB."]);
                        break; // Виходимо з switch
                    }

                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $image_url_to_db = $conn->real_escape_string($file_name); // Зберігаємо тільки ім'я файлу
                    } else {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "Помилка завантаження файлу."]);
                        break; // Виходимо з switch
                    }
                } elseif (isset($_POST['image_url']) && !empty($_POST['image_url'])) {
                    // Якщо файл не завантажено, але є URL
                    $image_url_to_db = $conn->real_escape_string($_POST['image_url']);
                } else {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Будь ласка, завантажте зображення або вкажіть URL."]);
                    break; // Виходимо з switch
                }

                $stmt = $conn->prepare("INSERT INTO products (name, description, price, image_url, in_stock, discount_percentage, is_on_sale, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsiiii", $name, $description, $price, $image_url_to_db, $in_stock, $discount_percentage, $is_on_sale, $category_id);

                if ($stmt->execute()) {
                    echo json_encode(["status" => "success", "id" => $conn->insert_id]);
                } else {
                    // Видаляємо файл, якщо виникла помилка в БД
                    if ($image_url_to_db && !filter_var($image_url_to_db, FILTER_VALIDATE_URL) && file_exists(UPLOAD_DIR . $image_url_to_db)) {
                        unlink(UPLOAD_DIR . $image_url_to_db);
                    }
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Помилка додавання товару: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутні обов'язкові поля для додавання товару."]);
            }
            break;

        case 'update_product':
            if (isset($_POST['id'], $_POST['name'], $_POST['price'], $_POST['in_stock'], $_POST['discount_percentage'], $_POST['is_on_sale'])) {
                $id = (int)$_POST['id'];
                $name = $conn->real_escape_string($_POST['name']);
                $description = isset($_POST['description']) ? $conn->real_escape_string($_POST['description']) : null;
                $price = (float)$_POST['price'];
                $in_stock = (int)$_POST['in_stock'];
                $discount_percentage = (int)$_POST['discount_percentage'];
                $is_on_sale = (bool)$_POST['is_on_sale'];
                $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;

                $update_fields = [
                    "name = ?",
                    "description = ?",
                    "price = ?",
                    "in_stock = ?",
                    "discount_percentage = ?",
                    "is_on_sale = ?",
                    "category_id = ?"
                ];
                $bind_types = "ssdsiii";
                $bind_values = [$name, $description, $price, $in_stock, $discount_percentage, $is_on_sale, $category_id];

                $current_image_url = null;
                $stmt_get_image = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
                $stmt_get_image->bind_param("i", $id);
                $stmt_get_image->execute();
                $result_get_image = $stmt_get_image->get_result();
                if ($result_get_image->num_rows > 0) {
                    $row = $result_get_image->fetch_assoc();
                    $current_image_url = $row['image_url'];
                }
                $stmt_get_image->close();

                $image_updated = false;
                $image_url_to_db = $current_image_url;

                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['image_file'];
                    $file_name = uniqid() . '_' . basename($file['name']);
                    $target_file = UPLOAD_DIR . $file_name;

                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0777, true);
                    }

                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                    if (!in_array($file_extension, $allowed_extensions)) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Недопустимий тип файлу."]);
                        break;
                    }

                    if ($file['size'] > 5 * 1024 * 1024) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Файл занадто великий."]);
                        break;
                    }

                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        // Видаляємо старе зображення, якщо воно було локальним файлом
                        if ($current_image_url && !filter_var($current_image_url, FILTER_VALIDATE_URL) && file_exists(UPLOAD_DIR . $current_image_url)) {
                            unlink(UPLOAD_DIR . $current_image_url);
                        }
                        $image_url_to_db = $conn->real_escape_string($file_name);
                        $image_updated = true;
                    } else {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "Помилка завантаження нового файлу зображення."]);
                        break;
                    }
                } elseif (isset($_POST['image_url_changed']) && $_POST['image_url_changed'] === 'true') {
                    // Якщо URL зображення було змінено (або очищено)
                    $new_image_url = trim($_POST['image_url']);
                    // Видаляємо старе зображення, якщо воно було локальним файлом
                    if ($current_image_url && !filter_var($current_image_url, FILTER_VALIDATE_URL) && file_exists(UPLOAD_DIR . $current_image_url)) {
                        unlink(UPLOAD_DIR . $current_image_url);
                    }
                    $image_url_to_db = empty($new_image_url) ? null : $conn->real_escape_string($new_image_url);
                    $image_updated = true;
                }

                if ($image_updated) {
                    $update_fields[] = "image_url = ?";
                    $bind_types .= "s";
                    $bind_values[] = $image_url_to_db;
                }

                $sql = "UPDATE products SET " . implode(", ", $update_fields) . " WHERE id = ?";
                $bind_types .= "i"; // Додаємо тип для ID
                $bind_values[] = $id; // Додаємо ID в кінець масиву значень

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($bind_types, ...$bind_values);

                if ($stmt->execute()) {
                    echo json_encode(["status" => "success"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Помилка оновлення товару: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутні обов'язкові поля для оновлення товару."]);
            }
            break;

        case 'delete_product':
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];

                // Отримуємо ім'я файлу зображення для видалення
                $stmt = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $image_to_delete = null;
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $image_to_delete = $row['image_url'];
                }
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    // Видаляємо файл зображення з сервера, якщо це локальний файл
                    if ($image_to_delete && !filter_var($image_to_delete, FILTER_VALIDATE_URL) && file_exists(UPLOAD_DIR . $image_to_delete)) {
                        unlink(UPLOAD_DIR . $image_to_delete);
                    }
                    echo json_encode(["status" => "success"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error deleting product: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing ID for deletion."]);
            }
            break;
            
        case 'update_product_stock':
            if (isset($_POST['id']) && isset($_POST['in_stock'])) {
                $id = (int)$_POST['id'];
                $in_stock = (int)$_POST['in_stock'];

                $stmt = $conn->prepare("UPDATE products SET in_stock = ? WHERE id = ?");
                $stmt->bind_param("ii", $in_stock, $id);

                if ($stmt->execute()) {
                    echo json_encode(["status" => "success"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error updating product stock: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing ID or in_stock for updating product stock."]);
            }
            break;

        case 'notify_stock_update':
            if (isset($_POST['product_id']) && isset($_POST['product_name']) && isset($_POST['new_stock']) && isset($_POST['website_url'])) {
                $product_id = (int)$_POST['product_id'];
                $product_name = $conn->real_escape_string($_POST['product_name']);
                $new_stock = (int)$_POST['new_stock'];
                $website_url = $conn->real_escape_string($_POST['website_url']); // Отримуємо URL з JS

                $message = "✨ *Оновлення наявності в ObshchagaSHOP!* ✨\n\n";
                $message .= "Товар: *{$product_name}*\n";
                if ($new_stock > 0) {
                    $message .= "Тепер в наявності: *{$new_stock} шт.* 🎉\n";
                } else {
                    $message .= "На жаль, товар *закінчився* 😔\n";
                }
                $message .= "\n[Переглянути товари на сайті]({$website_url}#products)\n"; // Додаємо посилання на сторінку товарів
                $message .= "Поспішайте зробити замовлення!";

                // Отримуємо всі chat_id зареєстрованих користувачів
                $stmt = $conn->prepare("SELECT telegram_chat_id FROM telegram_users");
                $stmt->execute();
                $result = $stmt->get_result();

                $sent_count = 0;
                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        if (sendTelegramMessage($row['telegram_chat_id'], $message)) {
                            $sent_count++;
                        }
                    }
                }
                $stmt->close();

                echo json_encode(["status" => "success", "sent_notifications" => $sent_count]);

            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing product_id, product_name, new_stock or website_url for notification."]);
            }
            break;

        case 'send_custom_notification':
            if (isset($_POST['message_text'])) {
                $message_text = trim($_POST['message_text']);

                $stmt = $conn->prepare("SELECT telegram_chat_id FROM telegram_users");
                $stmt->execute();
                $result = $stmt->get_result();

                $photo_path = null;
                if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['image_file'];
                    $file_name = uniqid() . '_' . basename($file['name']);
                    $target_file = UPLOAD_DIR . $file_name;

                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0777, true);
                    }

                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                    if (!in_array($file_extension, $allowed_extensions)) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Недопустимий тип файлу зображення."]);
                        exit();
                    }

                    if ($file['size'] > 5 * 1024 * 1024) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "Файл зображення занадто великий."]);
                        exit();
                    }

                    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "Помилка завантаження файлу зображення."]);
                        exit();
                    }
                    $photo_path = $target_file;
                }

                $sent_count = 0;
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $chat_id = $row['telegram_chat_id'];
                        $full_message = "*Оголошення від ObshchagaSHOP*\n\n";
                        $full_message .= $message_text;
                        $full_message .= "\n\n[Відвідайте наш сайт](" . WEBSITE_BASE_URL . ")";

                        if ($photo_path) {
                            $sent = sendTelegramPhoto($chat_id, $photo_path, $full_message);
                            if ($sent) $sent_count++;
                        } else {
                            $sent = sendTelegramMessage($chat_id, $full_message);
                            if ($sent) $sent_count++;
                        }
                    }
                }
                $stmt->close();

                if ($photo_path && file_exists($photo_path)) {
                    unlink($photo_path);
                }

                echo json_encode(["status" => "success", "sent_notifications" => $sent_count]);
                exit();
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутній текст повідомлення."]);
                exit();
            }
            break;

        case 'place_order':
            // Використовуємо $request_data, яка вже містить декодовані JSON-дані
            if (isset($request_data['total_amount']) && isset($request_data['items']) && is_array($request_data['items']) && isset($request_data['customer'])) {
                $total_amount = (float)$request_data['total_amount'];
                $items = $request_data['items'];
                $customer_info = $request_data['customer'];

                $customer_name = $conn->real_escape_string($customer_info['name']);
                $customer_telegram = $conn->real_escape_string($customer_info['telegram']);
                $customer_phone = $conn->real_escape_string($customer_info['phone']);
                $customer_address = $conn->real_escape_string($customer_info['address']);
                $needs_delivery = $customer_info['needs_delivery'] ? 1 : 0;
                
                // Перевірка, чи зареєстрований користувач Telegram
                $stmt = $conn->prepare("SELECT telegram_chat_id FROM telegram_users WHERE telegram_username = ?");
                $stmt->bind_param("s", $customer_telegram);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows === 0) {
                    http_response_code(403); // Forbidden
                    echo json_encode(["status" => "error", "message" => "Будь ласка, зареєструйтесь у нашому Telegram боті (@obschaga10shop_bot) командою /start, щоб оформити замовлення."]);
                    $stmt->close();
                    break; // Виходимо з switch
                }
                $customer_telegram_chat_id = $result->fetch_assoc()['telegram_chat_id'];
                $stmt->close();

                // 1. Створення запису в таблиці orders
                $stmt = $conn->prepare("INSERT INTO orders (total_amount, status, customer_name, customer_telegram, customer_phone, customer_address, needs_delivery, customer_telegram_chat_id) VALUES (?, 'New', ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("dssssis", $total_amount, $customer_name, $customer_telegram, $customer_phone, $customer_address, $needs_delivery, $customer_telegram_chat_id);
                
                if (!$stmt->execute()) {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error creating order: " . $stmt->error]);
                    $stmt->close();
                    break; // Виходимо з switch
                }
                $order_id = $conn->insert_id;
                $stmt->close();

                // 2. Додавання товарів в order_items та зменшення in_stock
                $stmt_insert_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_price, quantity) VALUES (?, ?, ?, ?, ?)"); // Додано product_id
                $stmt_update_stock = $conn->prepare("UPDATE products SET in_stock = in_stock - ? WHERE id = ? AND in_stock >= ?");

                foreach ($items as $item) {
                    $product_id = (int)$item['id']; // Отримуємо ID товару для оновлення наявності
                    $product_name = $conn->real_escape_string($item['name']);
                    $product_price = (float)$item['price'];
                    $quantity = (int)$item['quantity'];
                    
                    // Вставляємо елемент замовлення
                    $stmt_insert_item->bind_param("iisdi", $order_id, $product_id, $product_name, $product_price, $quantity); // Додано product_id
                    $stmt_insert_item->execute();

                    // Оновлюємо наявність товару
                    $stmt_update_stock->bind_param("iii", $quantity, $product_id, $quantity);
                    $stmt_update_stock->execute();
                }
                $stmt_insert_item->close();
                $stmt_update_stock->close();

                // 3. Відправка повідомлення в Telegram адміністратору
                $telegram_message_admin = "*НОВЕ ЗАМОВЛЕННЯ №{$order_id}*\n\n";
                $telegram_message_admin .= "*Покупець:*\n";
                $telegram_message_admin .= "Ім'я: {$customer_name}\n";
                $telegram_message_admin .= "Telegram: @{$customer_telegram}\n";
                $telegram_message_admin .= "Телефон: {$customer_phone}\n";
                $telegram_message_admin .= "Доставка: " . ($needs_delivery ? "Так (+50 грн)" : "Ні") . "\n";
                if ($needs_delivery && !empty($customer_address)) {
                    $telegram_message_admin .= "Кімната: {$customer_address}\n";
                }
                $telegram_message_admin .= "\n*Деталі замовлення:*\n";
                foreach ($items as $item) {
                    $telegram_message_admin .= "— {$item['name']} ({$item['price']} грн) x {$item['quantity']} шт\n";
                }
                $telegram_message_admin .= "\n*Загальна сума:* {$total_amount} грн\n";
                $telegram_message_admin .= "\n[Переглянути товари на сайті](" . WEBSITE_BASE_URL . "#products)"; // Посилання на товари

                if (!sendTelegramMessage(ADMIN_TELEGRAM_CHAT_ID, $telegram_message_admin)) {
                    error_log("Failed to send Telegram notification to admin for order ID: " . $order_id);
                }

                // 4. Відправка повідомлення в Telegram покупцю
                $telegram_message_customer = "🎉 *Ваше замовлення №{$order_id} успішно оформлено!* 🎉\n\n";
                $telegram_message_customer .= "Дякуємо за покупку в ObshchagaSHOP!\n";
                $telegram_message_customer .= "Ми зв'яжемося з вами найближчим часом для підтвердження деталей.\n\n";
                $telegram_message_customer .= "*Деталі замовлення:*\n";
                foreach ($items as $item) {
                    $telegram_message_customer .= "— {$item['name']} ({$item['price']} грн) x {$item['quantity']} шт\n";
                }
                $telegram_message_customer .= "\n*Загальна сума:* {$total_amount} грн\n";
                $telegram_message_customer .= "\n[Переглянути наші товари](" . WEBSITE_BASE_URL . "#products)"; // Посилання на товари

                if (!sendTelegramMessage($customer_telegram_chat_id, $telegram_message_customer)) {
                    error_log("Failed to send Telegram notification to customer for order ID: " . $order_id);
                }

                echo json_encode(["status" => "success", "order_id" => $order_id]);

            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing required fields for placing order."]);
            }
            break;

        case 'update_order_status': // NEW ACTION: Update order status
            if (isset($_POST['order_id']) && isset($_POST['status'])) {
                $order_id = (int)$_POST['order_id'];
                $new_status = $conn->real_escape_string($_POST['status']);

                // Отримуємо поточний статус та дані замовлення
                $stmt_get_order = $conn->prepare("SELECT status, customer_telegram_chat_id, customer_telegram FROM orders WHERE id = ?");
                $stmt_get_order->bind_param("i", $order_id);
                $stmt_get_order->execute();
                $result_get_order = $stmt_get_order->get_result();
                if ($result_get_order->num_rows === 0) {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Замовлення не знайдено."]);
                    $stmt_get_order->close();
                    break;
                }
                $order_data = $result_get_order->fetch_assoc();
                $current_status = $order_data['status'];
                $customer_chat_id = $order_data['customer_telegram_chat_id'];
                $customer_telegram_username = $order_data['customer_telegram'];
                $stmt_get_order->close();

                // Перевірка на валідність нового статусу
                $allowed_statuses = ['New', 'Confirmed', 'Cancelled'];
                if (!in_array($new_status, $allowed_statuses)) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Недійсний статус замовлення."]);
                    break;
                }

                // Якщо статус змінюється на 'Cancelled' і він не був 'Cancelled' раніше, повертаємо товари
                if ($new_status === 'Cancelled' && $current_status !== 'Cancelled') {
                    $conn->begin_transaction();
                    try {
                        $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                        $stmt_items->bind_param("i", $order_id);
                        $stmt_items->execute();
                        $items_result = $stmt_items->get_result();

                        while ($item = $items_result->fetch_assoc()) {
                            $stmt_update_stock = $conn->prepare("UPDATE products SET in_stock = in_stock + ? WHERE id = ?");
                            $stmt_update_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                            $stmt_update_stock->execute();
                            $stmt_update_stock->close();
                        }
                        $stmt_items->close();

                        $stmt_update_order = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
                        $stmt_update_order->bind_param("si", $new_status, $order_id);
                        $stmt_update_order->execute();
                        $stmt_update_order->close();

                        $conn->commit();
                        sendTelegramMessage($customer_chat_id, "❌ *Ваше замовлення №`{$order_id}` скасовано адміністратором.* Товари повернено на склад.");
                        echo json_encode(["status" => "success", "message" => "Замовлення скасовано, товари повернено на склад."]);

                    } catch (Exception $e) {
                        $conn->rollback();
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "Помилка скасування замовлення та повернення товарів: " . $e->getMessage()]);
                    }
                } 
                // Якщо статус змінюється з 'Cancelled' на інший, це не дозволено або потрібно інша логіка
                elseif ($current_status === 'Cancelled' && $new_status !== 'Cancelled') {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Неможливо змінити статус скасованого замовлення."]);
                }
                // Звичайна зміна статусу (наприклад, New -> Confirmed)
                else {
                    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $order_id);

                    if ($stmt->execute()) {
                        $message_to_customer = "";
                        if ($new_status === 'Confirmed') {
                            $message_to_customer = "✅ *Ваше замовлення №`{$order_id}` підтверджено!* Ми готуємо його до видачі/доставки.";
                        } elseif ($new_status === 'New') {
                             $message_to_customer = "ℹ️ *Статус вашого замовлення №`{$order_id}` змінено на 'Нове'.*";
                        }
                        
                        if (!empty($message_to_customer)) {
                            sendTelegramMessage($customer_chat_id, $message_to_customer);
                        }
                        echo json_encode(["status" => "success"]);
                    } else {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "Помилка оновлення статусу замовлення: " . $stmt->error]);
                    }
                    $stmt->close();
                }
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутні ID замовлення або новий статус."]);
            }
            break;

        case 'import_products_csv':
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['csv_file'];
                $file_tmp_name = $file['tmp_name'];

                // Перевірка типу файлу
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($file_extension !== 'csv') {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Недопустимий тип файлу. Дозволені тільки CSV."]);
                    exit();
                }

                $added_count = 0;
                $updated_count = 0;
                $errors = [];
                $line_num = 1; // Починаємо з 1 для заголовка

                if (($handle = fopen($file_tmp_name, "r")) !== FALSE) {
                    // Пропускаємо перший рядок (заголовки)
                    fgetcsv($handle); 
                    $line_num++;

                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        // Очікуваний формат: Назва продукту,ціна за штуку,кількість,акція якшо є (1/0),відсоток знижки,опис,URL зображення
                        // Змінено: тепер очікуємо 7 стовпців
                        if (count($data) >= 7) {
                            $name = trim($data[0]);
                            $price = filter_var(trim($data[1]), FILTER_VALIDATE_FLOAT);
                            $in_stock = filter_var(trim($data[2]), FILTER_VALIDATE_INT);
                            $is_on_sale = filter_var(trim($data[3]), FILTER_VALIDATE_INT); // 1 або 0
                            $discount_percentage = filter_var(trim($data[4]), FILTER_VALIDATE_INT);
                            $description = trim($data[5]);
                            $image_url = trim($data[6]); // Нове поле для URL зображення

                            // Валідація даних
                            if (empty($name)) {
                                $errors[] = "Рядок {$line_num}: Назва продукту не може бути порожньою.";
                                $line_num++;
                                continue;
                            }
                            if ($price === false || $price <= 0) {
                                $errors[] = "Рядок {$line_num}: Невірна ціна для товару '{$name}'.";
                                $line_num++;
                                continue;
                            }
                            if ($in_stock === false || $in_stock < 0) {
                                $errors[] = "Рядок {$line_num}: Невірна кількість в наявності для товару '{$name}'.";
                                $line_num++;
                                continue;
                            }
                            if ($is_on_sale === false || ($is_on_sale !== 0 && $is_on_sale !== 1)) {
                                $errors[] = "Рядок {$line_num}: Невірне значення акції (очікується 0 або 1) для товару '{$name}'.";
                                $line_num++;
                                continue;
                            }
                            if ($discount_percentage === false || $discount_percentage < 0 || $discount_percentage > 100) {
                                $errors[] = "Рядок {$line_num}: Невірний відсоток знижки (очікується 0-100) для товару '{$name}'.";
                                $line_num++;
                                continue;
                            }
                            // Додаткова валідація URL зображення, якщо воно є
                            if (!empty($image_url) && !filter_var($image_url, FILTER_VALIDATE_URL)) {
                                // Якщо це не валідний URL, можливо, це локальне ім'я файлу.
                                // Наразі ми дозволяємо це, але можна додати більш сувору перевірку, якщо потрібно.
                            }


                            // Перевіряємо, чи існує товар з такою назвою
                            $stmt_check = $conn->prepare("SELECT id FROM products WHERE name = ?");
                            $stmt_check->bind_param("s", $name);
                            $stmt_check->execute();
                            $result_check = $stmt_check->get_result();

                            if ($result_check->num_rows > 0) {
                                // Товар існує, оновлюємо його
                                $row = $result_check->fetch_assoc();
                                $product_id = $row['id'];
                                $stmt_update = $conn->prepare("UPDATE products SET price = ?, in_stock = ?, is_on_sale = ?, description = ?, discount_percentage = ?, image_url = ? WHERE id = ?");
                                $stmt_update->bind_param("diisiisi", $price, $in_stock, $is_on_sale, $description, $discount_percentage, $image_url, $product_id);
                                if ($stmt_update->execute()) {
                                    $updated_count++;
                                } else {
                                    $errors[] = "Рядок {$line_num}: Помилка оновлення товару '{$name}': " . $stmt_update->error;
                                    error_log("CSV Import Error (Update): " . $stmt_update->error . " for product '{$name}' on line {$line_num}");
                                }
                                $stmt_update->close();
                            } else {
                                // Товар не існує, додаємо новий
                                $stmt_insert = $conn->prepare("INSERT INTO products (name, description, price, in_stock, discount_percentage, is_on_sale, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt_insert->bind_param("ssdiiss", $name, $description, $price, $in_stock, $discount_percentage, $is_on_sale, $image_url);
                                if ($stmt_insert->execute()) {
                                    $added_count++;
                                } else {
                                    $errors[] = "Рядок {$line_num}: Помилка додавання товару '{$name}': " . $stmt_insert->error;
                                    error_log("CSV Import Error (Insert): " . $stmt_insert->error . " for product '{$name}' on line {$line_num}");
                                }
                                $stmt_insert->close();
                            }
                            $stmt_check->close();
                        } else {
                            $errors[] = "Рядок {$line_num}: Невірна кількість стовпців (очікується 7) у рядку: " . implode(", ", $data);
                            error_log("CSV Import Error: Incorrect column count on line {$line_num}. Data: " . implode(", ", $data));
                        }
                        $line_num++;
                    }
                    fclose($handle);
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Не вдалося відкрити CSV-файл для читання."]);
                    exit();
                }

                if (empty($errors)) {
                    echo json_encode(["status" => "success", "message" => "Імпорт завершено.", "added_count" => $added_count, "updated_count" => $updated_count]);
                } else {
                    // Повертаємо 200 OK, але з помилками в повідомленні, щоб JS міг розпарсити JSON
                    echo json_encode(["status" => "warning", "message" => "Імпорт завершено з помилками. Деталі: " . implode("; ", $errors), "added_count" => $added_count, "updated_count" => $updated_count, "errors" => $errors]);
                }

            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Відсутній CSV-файл для імпорту або помилка завантаження."]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Unknown POST action."]);
            break;
    }
    $conn->close();
    exit();
}

// Якщо дія не визначена або метод не відповідає
http_response_code(400);
echo json_encode(["status" => "error", "message" => "Invalid request method or missing action parameter."]);
$conn->close();
?>
