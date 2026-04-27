<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting
if (!checkRateLimit('pyrus_tasks', 60, 60)) { // 60 запросов в минуту
    exit;
}

$user = authenticateUser();
if ($user === null) {
    exit;
}

$filterRestaurant = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';

// ==================== ЗАГРУЗКА ТАБЛИЦЫ ЗАДАЧ ИЗ PYRUS ====================
try {
    $register = pyrusFetchRegister(PYRUS_TASKS_FORM_ID);
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
?><?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting
if (!checkRateLimit('pyrus_tasks', 60, 60)) { // 60 запросов в минуту
    exit;
}

$user = authenticateUser();
if ($user === null) {
    exit;
}

$filterRestaurant = isset($_GET['restaurant']) ? trim($_GET['restaurant']) : '';

// ==================== ЗАГРУЗКА ТАБЛИЦЫ ЗАДАЧ ИЗ PYRUS ====================
try {
    $register = pyrusFetchRegister(PYRUS_TASKS_FORM_ID);
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