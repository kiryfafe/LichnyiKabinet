<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting
if (!checkRateLimit('restaurants', 60, 60)) {
    exit;
}

$user = authenticateUser();
if ($user === null) {
    exit;
}

// ==================== ЗАГРУЗКА ЗАВЕДЕНИЙ ИЗ PYRUS ====================
try {
    $register = pyrusFetchRegister(PYRUS_RESTAURANTS_FORM_ID);
} catch (Exception $e) {
    logError("Pyrus fetch error: " . $e->getMessage());
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

// Список ресторанов из профиля пользователя
$allowedNames = [];
if (!empty($user["network"])) {
    $parts = preg_split('/[,\\n]+/', $user["network"]);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $allowedNames[] = $p;
        }
    }
}

// Если ничего не указано в профиле, вернем пусто
if (empty($allowedNames)) {
    echo json_encode(["success" => true, "restaurants" => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurants = [];
$rows = isset($register['rows']) ? $register['rows'] : array();
foreach ($rows as $row) {
    $assoc = pyrusRowToAssoc($row, $columnMap);
    $name = isset($assoc['Ресторан']) ? trim($assoc['Ресторан']) : '';
    if ($name === '') {
        continue;
    }
    foreach ($allowedNames as $needle) {
        if (strcasecmp($needle, $name) == 0) {
            $id = null;
            if (isset($row['id'])) {
                $id = $row['id'];
            } elseif (isset($row['task_id'])) {
                $id = $row['task_id'];
            } else {
                $id = uniqid('rest_');
            }
            $restaurants[] = array(
                "id" => $id,
                "name" => $name
            );
            // Не break — нужны все строки с совпадающим названием
        }
    }
}

echo json_encode(["success" => true, "restaurants" => $restaurants], JSON_UNESCAPED_UNICODE);
?>