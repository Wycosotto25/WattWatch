<?php
// Simulan ang output buffering para maiwasan ang anumang stray PHP notices/warnings sa JSON output
ob_start();

// Set HTTP response headers para sa JSON at CORS support
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-TOKEN, X-Requested-With');

// Isara agad ang OPTIONS preflight request para sa CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

// Simulan ang session para sa user authentication kung hindi pa nakasimula
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Siguraduhing umiiral ang ApiController file
$controllerFile = __DIR__ . '/../src/ApiController.php';
if (!file_exists($controllerFile)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'ApiController.php file not found at src/ApiController.php'
    ]);
    exit;
}

require_once $controllerFile;

try {
    // Kumuha at mag-parse ng raw JSON body mula sa Fetch API o ESP32 HTTP POST
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    if (!is_array($jsonData)) {
        $jsonData = [];
    }

    // Pagsamahin ang GET, POST, at JSON payload
    $requestData = array_merge($_GET, $_POST, $jsonData);

    // Kunin ang hininging action (e.g. ?action=dashboard_stats o ?action=post_reading)
    $action = $requestData['action'] ?? $_GET['action'] ?? '';

    if (empty($action)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'No API action specified.'
        ]);
        exit;
    }

    // I-instantiate ang ApiController
    $controller = new ApiController();

    // Convert snake_case sa camelCase (halimbawa: dashboard_stats -> dashboardStats)
    $camelAction = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $action))));

    $response = null;

    // Isagawa ang pagtawag sa naaangkop na method sa ApiController
    if (method_exists($controller, $action)) {
        $response = $controller->$action($requestData);
    } elseif (method_exists($controller, $camelAction)) {
        $response = $controller->$camelAction($requestData);
    } elseif (method_exists($controller, 'handleRequest')) {
        $response = $controller->handleRequest($action, $requestData);
    } else {
        ob_end_clean();
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => "Action '{$action}' is not implemented in ApiController."
        ]);
        exit;
    }

    // Dagdag na validation: Siguraduhing may totoong hourly chart readings para sa dashboard_stats
    if (in_array($action, ['dashboard_stats', 'dashboardStats']) && is_array($response)) {
        if (!isset($response['data']['chart']) || empty($response['data']['chart']['values'])) {
            // Detektahin ang PDO instance mula sa ApiController
            $pdo = property_exists($controller, 'pdo') ? $controller->pdo : 
                  (property_exists($controller, 'db') ? $controller->db : 
                  (property_exists($controller, 'conn') ? $controller->conn : null));

            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT 
                            DATE_FORMAT(read_at, '%h:00 %p') AS time_label,
                            ROUND(AVG(power_watts), 2) AS avg_power
                        FROM readings 
                        WHERE DATE(read_at) = CURDATE()
                        GROUP BY HOUR(read_at), DATE_FORMAT(read_at, '%h:00 %p')
                        ORDER BY MIN(read_at) ASC
                    ");
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($rows)) {
                        $response['data']['chart'] = [
                            'labels' => array_column($rows, 'time_label'),
                            'values' => array_map('floatval', array_column($rows, 'avg_power'))
                        ];
                    }
                } catch (Exception $e) {
                    // Manatiling ligtas sa anumang query issues
                }
            }
        }
    }

    // Linisin ang output buffer bago mag-print ng JSON
    ob_end_clean();

    // I-output ang resulta
    http_response_code(200);
    if (is_array($response) || is_object($response)) {
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } else {
        echo $response;
    }

} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}