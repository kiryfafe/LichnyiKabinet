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
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
    exit;
}

$filterRestaurant = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';

// ==================== ЗАГРУЗКА ТАБЛИЦЫ ЗАДАЧ ИЗ PYRUS ====================
try {
    $register = pyrusFetchRegister(1463678);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Pyrus fetch error: " . $e->getMessage()]);
    exit;
}

$columnMap = pyrusBuildColumnMap($register);
$restaurantColId = isset($columnMap['Ресторан']) ? $columnMap['Ресторан'] : null;
if (!$restaurantColId) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Pyrus: column 'Ресторан' not found"]);
    exit;
}

$tasks = array();
$rows = isset($register['rows']) ? $register['rows'] : array();
foreach ($rows as $row) {
    $assoc = pyrusRowToAssoc($row, $columnMap);
    $name = isset($assoc['Ресторан']) ? trim($assoc['Ресторан']) : '';
    if ($filterRestaurant !== '' && strcasecmp($filterRestaurant, $name) !== 0) {
        continue;
    }

    // Берем первую непустую строковую ячейку как заголовок.
    $title = '';
    foreach ($assoc as $colName => $value) {
        if ($colName === 'Ресторан') {
            continue;
        }
        if (is_string($value) && trim($value) !== '') {
            $title = trim($value);
            break;
        }
    }

    $id = null;
    if (isset($row['id'])) {
        $id = $row['id'];
    } elseif (isset($row['task_id'])) {
        $id = $row['task_id'];
    } else {
        $id = uniqid('task_');
    }

    $tasks[] = array(
        "id" => $id,
        "task_id" => isset($row['task_id']) ? $row['task_id'] : null,
        "restaurant" => $name,
        "title" => $title,
        "fields" => $assoc
    );
}

echo json_encode(["success" => true, "tasks" => $tasks], JSON_UNESCAPED_UNICODE);
?>