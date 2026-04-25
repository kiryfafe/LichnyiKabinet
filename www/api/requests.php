<?php
require_once __DIR__ . '/../config.php';
header("Content-Type: application/json; charset=utf-8");

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB connection error"]);
    exit;
}

// ==================== ПРОВЕРКА ТОКЕНА ====================
$headers = getallheaders();
if (!isset($headers["Authorization"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Missing token"]);
    exit;
}
list($type, $token) = explode(" ", $headers["Authorization"], 2);
if (strtolower($type) !== "bearer" || !$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid token"]);
    exit;
}

$stmt = $pdo->prepare("SELECT u.* FROM user_sessions s 
                       JOIN users u ON u.id = s.user_id
                       WHERE s.token = :token AND s.expires_at > NOW()
                       LIMIT 1");
$stmt->execute([":token" => $token]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
    exit;
}

// ==================== ОПРЕДЕЛЕНИЕ МЕТОДА ====================
$method = $_SERVER["REQUEST_METHOD"];

// ==================== GET: список заявок ====================
if ($method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE user_id = :uid ORDER BY created_at DESC");
    $stmt->execute([":uid" => $currentUser["id"]]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "requests" => $requests], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== POST: создать заявку ====================
if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $title   = trim($input["title"] ?? "");
    $desc    = trim($input["description"] ?? "");
    $est     = trim($input["establishment"] ?? "");

    if (!$title) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Missing title"]);
        exit;
    }

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
    exit;
}

// ==================== Если метод не поддерживается ====================
http_response_code(405);
echo json_encode(["success" => false, "error" => "Method not allowed"]);
?>