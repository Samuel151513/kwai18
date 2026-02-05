<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Arquivo para salvar dados dos webhooks recebidos
define('WEBHOOKS_LOG_FILE', __DIR__ . '/webhooks_received.json');
define('PAYMENTS_FILE', __DIR__ . '/generated_payments.json');

// Função para obter dados do pagamento gerado
function getPaymentData($transactionId) {
    if (!file_exists(PAYMENTS_FILE)) {
        return null;
    }
    
    $data = json_decode(file_get_contents(PAYMENTS_FILE), true);
    return $data[$transactionId] ?? null;
}

// Função para salvar log do webhook
function saveWebhookLog($webhookData) {
    $logs = [];
    if (file_exists(WEBHOOKS_LOG_FILE)) {
        $logs = json_decode(file_get_contents(WEBHOOKS_LOG_FILE), true);
    }
    
    $logs[] = [
        'data' => $webhookData,
        'received_at' => date('Y-m-d H:i:s')
    ];
    
    // Manter apenas os últimos 100 webhooks
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    file_put_contents(WEBHOOKS_LOG_FILE, json_encode($logs, JSON_PRETTY_PRINT));
}

// Receber dados do webhook
$input = file_get_contents('php://input');
$webhookData = json_decode($input, true);

// Log do webhook recebido
error_log("🔔 WEBHOOK RECEBIDO:");
error_log("   Raw data: " . $input);

if (!$webhookData) {
    http_response_code(400);
    echo json_encode(['received' => false, 'error' => 'Invalid JSON']);
    exit();
}

// Salvar webhook no log
saveWebhookLog($webhookData);

// Extrair dados do webhook
$transactionId = $webhookData['id'] ?? '';
$status = $webhookData['status'] ?? '';
$amount = $webhookData['total_amount'] ?? 0;
$externalId = $webhookData['external_id'] ?? '';

error_log("   └─ Transaction ID: {$transactionId}");
error_log("   └─ Status: {$status}");
error_log("   └─ Amount: R$ {$amount}");

// Se o status for AUTHORIZED (pago)
if ($status === 'AUTHORIZED') {
    
    // Buscar dados do pagamento
    $paymentData = getPaymentData($transactionId);
    
    if ($paymentData) {
        error_log("✅ PAGAMENTO CONFIRMADO VIA WEBHOOK!");
        error_log("   └─ UserID: {$paymentData['user_id']}");
        error_log("   └─ PackageID: {$paymentData['package_id']}");
        error_log("   └─ Créditos: {$paymentData['credits']}");
        
        // AQUI VOCÊ PODE ADICIONAR LÓGICA PARA PROCESSAR NO BANCO
        // Exemplo:
        /*
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=seu_banco", "usuario", "senha");
            $stmt = $pdo->prepare("UPDATE users SET credits = credits + :credits WHERE id = :user_id");
            $stmt->execute([
                ':credits' => $paymentData['credits'],
                ':user_id' => $paymentData['user_id']
            ]);
            error_log("   └─ Créditos adicionados no banco!");
        } catch (PDOException $e) {
            error_log("   └─ Erro ao adicionar créditos: " . $e->getMessage());
        }
        */
    } else {
        error_log("⚠️  Dados do pagamento não encontrados para TransactionID: {$transactionId}");
    }
}

// Sempre responder com 200 OK para confirmar recebimento
http_response_code(200);
echo json_encode([
    'received' => true,
    'transaction_id' => $transactionId,
    'status' => $status,
    'processed_at' => date('Y-m-d H:i:s')
]);
?>