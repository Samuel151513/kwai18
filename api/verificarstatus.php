<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configurações
define('API_BASE_URL', 'https://api.genesys.finance');
define('API_SECRET', 'sk_0b48f8282a629e4d68c43c6fa22baf705df5a0955f9950b11a3ac864134837c1d4c1907312cc3cec8e5a94a4b6ba3b40884ef7cf132e2b926d1e2d974a444396');
define('XTRACKY_API_URL', 'https://api.xtracky.com/api/integrations/api');

// Arquivo para armazenar status e dados das transações
define('STATUS_FILE', __DIR__ . '/transaction_status.json');
define('PENDING_PAYMENTS_FILE', __DIR__ . '/pending_payments.json');

// Função para fazer requisições à API Genesys
function checkTransactionStatus($transactionId) {
    $url = API_BASE_URL . '/v1/transactions/' . $transactionId;
    
    $headers = [
        'api-secret: ' . API_SECRET,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'Erro na requisição: ' . $error
        ];
    }
    
    $decoded = json_decode($response, true);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'data' => $decoded,
        'http_code' => $httpCode
    ];
}

// Função para enviar evento para XTracky
function sendToXTracky($orderId, $amount, $status, $utmSource) {
    $data = [
        'orderId' => $orderId,
        'amount' => (int)($amount * 100),
        'status' => $status,
        'utm_source' => $utmSource ?? ''
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, XTRACKY_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("XTracky - OrderID: {$orderId}, Status: {$status}, Response: " . $response);
    
    return ['success' => $httpCode >= 200 && $httpCode < 300];
}

// Função para obter dados do pagamento pendente
function getPendingPayment($transactionId) {
    if (!file_exists(PENDING_PAYMENTS_FILE)) {
        return null;
    }
    
    $data = json_decode(file_get_contents(PENDING_PAYMENTS_FILE), true);
    return $data[$transactionId] ?? null;
}

// Função para salvar dados do pagamento pendente
function savePendingPayment($transactionId, $userId, $packageId, $packageName, $credits, $amount) {
    $data = [];
    if (file_exists(PENDING_PAYMENTS_FILE)) {
        $data = json_decode(file_get_contents(PENDING_PAYMENTS_FILE), true);
    }
    
    $data[$transactionId] = [
        'user_id' => $userId,
        'package_id' => $packageId,
        'package_name' => $packageName,
        'credits' => $credits,
        'amount' => $amount,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents(PENDING_PAYMENTS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Função para obter status anterior
function getPreviousStatus($transactionId) {
    if (!file_exists(STATUS_FILE)) {
        return null;
    }
    
    $data = json_decode(file_get_contents(STATUS_FILE), true);
    return $data[$transactionId] ?? null;
}

// Função para salvar status
function saveTransactionStatus($transactionId, $status, $amount, $paymentData = null) {
    $data = [];
    if (file_exists(STATUS_FILE)) {
        $data = json_decode(file_get_contents(STATUS_FILE), true);
    }
    
    $statusData = [
        'status' => $status,
        'amount' => $amount,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if ($paymentData) {
        $statusData = array_merge($statusData, $paymentData);
    }
    
    $data[$transactionId] = $statusData;
    
    file_put_contents(STATUS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Receber dados
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit();
}

$transactionId = $input['transaction_id'] ?? ($input['id'] ?? '');
$userId = $input['user_id'] ?? '';
$packageId = $input['package_id'] ?? '';
$packageName = $input['package_name'] ?? '';
$credits = intval($input['credits'] ?? 0);
$utmSource = $input['utm_source'] ?? '';

if (empty($transactionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'transaction_id é obrigatório']);
    exit();
}

// Se recebeu dados do pagamento (primeira verificação), salvar
if (!empty($userId) && !empty($packageId)) {
    $amountFromInput = floatval($input['amount'] ?? 0);
    savePendingPayment($transactionId, $userId, $packageId, $packageName, $credits, $amountFromInput);
}

// Verificar status na API
$result = checkTransactionStatus($transactionId);

if (!$result['success']) {
    http_response_code($result['http_code'] ?? 500);
    echo json_encode(['success' => false, 'error' => 'Erro ao consultar transação']);
    exit();
}

$transaction = $result['data'];
$currentStatus = $transaction['status'] ?? 'UNKNOWN';
$amount = $transaction['amount'] ?? ($transaction['total_value'] ?? 0);

// Obter dados do pagamento pendente
$pendingData = getPendingPayment($transactionId);
if ($pendingData) {
    $userId = $userId ?: $pendingData['user_id'];
    $packageId = $packageId ?: $pendingData['package_id'];
    $packageName = $packageName ?: $pendingData['package_name'];
    $credits = $credits ?: $pendingData['credits'];
}

// Obter status anterior
$previousData = getPreviousStatus($transactionId);
$previousStatus = $previousData['status'] ?? null;

error_log("🔍 Verificando - ID: {$transactionId}, Status: {$currentStatus}, Anterior: {$previousStatus}, UserID: {$userId}, Créditos: {$credits}");

// Status mudou para AUTHORIZED (pago)
if ($currentStatus === 'AUTHORIZED' && $previousStatus !== 'AUTHORIZED') {
    
    $paymentData = [
        'user_id' => $userId,
        'package_id' => $packageId,
        'package_name' => $packageName,
        'credits' => $credits,
        'paid_at' => date('Y-m-d H:i:s')
    ];
    
    // Salvar status
    saveTransactionStatus($transactionId, 'AUTHORIZED', $amount, $paymentData);
    
    // XTracky
    sendToXTracky($transactionId, $amount, 'paid', $utmSource);
    
    error_log("✅ PAGAMENTO CONFIRMADO!");
    error_log("   └─ TransactionID: {$transactionId}");
    error_log("   └─ UserID: {$userId}");
    error_log("   └─ PackageID: {$packageId}");
    error_log("   └─ Package: {$packageName}");
    error_log("   └─ Créditos: {$credits}");
    error_log("   └─ Valor: R$ {$amount}");
    
    echo json_encode([
        'success' => true,
        'status' => 'paid',
        'paid' => true,
        'status_changed' => true,
        'transaction_id' => $transactionId,
        'user_id' => $userId,
        'package_id' => $packageId,
        'package_name' => $packageName,
        'credits' => $credits,
        'amount' => $amount,
        'message' => 'Pagamento confirmado! 🎉'
    ]);
    
} else {
    // Status não mudou ou é primeira verificação
    if ($previousStatus === null && !empty($userId)) {
        saveTransactionStatus($transactionId, $currentStatus, $amount, [
            'user_id' => $userId,
            'package_id' => $packageId,
            'package_name' => $packageName,
            'credits' => $credits
        ]);
    }
    
    $isPaid = $currentStatus === 'AUTHORIZED';
    
    echo json_encode([
        'success' => true,
        'status' => strtolower($currentStatus === 'AUTHORIZED' ? 'paid' : $currentStatus),
        'paid' => $isPaid,
        'status_changed' => false,
        'transaction_id' => $transactionId,
        'amount' => $amount,
        'message' => 'Status: ' . $currentStatus
    ]);
}
?>