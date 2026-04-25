<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting
if (!checkRateLimit('requests', 30, 60)) { // 30 запросов в минуту
    exit;
}

$currentUser = authenticateUser();
if ($currentUser === null) {
    exit;
}

// ==================== ОПРЕДЕЛЕНИЕ МЕТОДА ====================
$method = $_SERVER["REQUEST_METHOD"];

// ==================== GET: список заявок ====================
if ($method === "GET") {
    try {
        $pdo = createPdoUtf8();
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE user_id = :uid ORDER BY created_at DESC");
        $stmt->execute([":uid" => $currentUser["id"]]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "requests" => $requests], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        logError("Requests fetch error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Database error"]);
    }
    exit;
}

// ==================== POST: создать заявку ====================
if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Валидация
    $validation = validateInput($input, ['title']);
    if ($validation !== true) {
        http_response_code(400);
        echo json_encode(["success" => false, "errors" => $validation]);
        exit;
    }
    
    $title   = sanitizeString(trim($input["title"] ?? ""));
    $desc    = sanitizeString(trim($input["description"] ?? ""));
    $est     = sanitizeString(trim($input["establishment"] ?? ""));

    try {
        $pdo = createPdoUtf8();
        $stmt = $pdo->prepare("
            INSERT INTO requests (external_id, user_id, title, description, establishment, status, created_at, updated_at, synced_at)
            VALUES (NULL, :user_id, :title, :description, :establishment, 'Новая', NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ":user_id" => $currentUser["id"],
            ":title"   => $title,
            ":description" => $desc,
            ":establishment" => $est
        ]);

        $newId = $pdo->lastInsertId();
        echo json_encode([
            "success" => true,
            "request" => [
                "id"            => $newId,
                "title"         => $title,
                "description"   => $desc,
                "status"        => "Новая",
                "establishment" => $est
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        logError("Create request error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to create request"]);
    }
    exit;
}

// ==================== Если метод не поддерживается ====================
http_response_code(405);
echo json_encode(["success" => false, "error" => "Method not allowed"]);
?>