<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configurações da API Genesys Finance
define('API_BASE_URL', 'https://api.genesys.finance');
define('API_SECRET', 'sk_0b48f8282a629e4d68c43c6fa22baf705df5a0955f9950b11a3ac864134837c1d4c1907312cc3cec8e5a94a4b6ba3b40884ef7cf132e2b926d1e2d974a444396');
define('XTRACKY_API_URL', 'https://api.xtracky.com/api/integrations/api');

// URL do verificarstatus.php
define('VERIFICAR_STATUS_URL', 'https://vagaconectada.fun/api/verificarstatus.php');

// Arquivo para salvar dados dos pagamentos gerados
define('GENERATED_PAYMENTS_FILE', __DIR__ . '/generated_payments.json');

// ====================================
// MAPEAMENTO DE PACOTES
// ====================================
// Função para obter créditos do pacote baseado no packageId ou valor
function getPackageCredits($packageId, $amount) {
    // ====================================
    // CONFIGURE SEUS PACOTES AQUI:
    // ====================================
    // ATENÇÃO (bug comum em PHP): chaves numéricas com float viram INT.
    // Ex.: 9.90 vira 9, então a comparação falha e retorna 0 créditos.
    // Por isso, usamos as chaves como STRING e convertemos na hora de comparar.
    $valueToCredits = [
        '9.90'   => 10,
        '24.90'  => 25,
        '47.90'  => 50,
        '89.90'  => 100,
        '169.90' => 250,
        '279.90' => 350,
        '379.90' => 500,
        '579.90' => 800,
        '799.90' => 1200,
        '999.90' => 2000
    ];
    
    // Procura valor exato (tolerância de R$ 0.01)
    foreach ($valueToCredits as $priceStr => $credits) {
        $price = floatval(str_replace(',', '.', $priceStr));
        if (abs($amount - $price) < 0.01) {
            return $credits;
        }
    }
    
    // Se não encontrou valor exato, loga erro e retorna 0
    error_log("⚠️  PACOTE NÃO CONFIGURADO - Valor: R$ {$amount}");
    error_log("   → Adicione este valor no mapeamento \$valueToCredits");
    error_log("   → Linha ~27 do generate-pix.php");
    
    // Retorna 0 para você saber que precisa configurar
    return 0;
}

// Função para validar CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

// Função para gerar CPF válido
function gerarCPFValido() {
    $n1 = rand(0, 9); $n2 = rand(0, 9); $n3 = rand(0, 9);
    $n4 = rand(0, 9); $n5 = rand(0, 9); $n6 = rand(0, 9);
    $n7 = rand(0, 9); $n8 = rand(0, 9); $n9 = rand(0, 9);
    
    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - ($d1 % 11);
    if ($d1 >= 10) $d1 = 0;
    
    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - ($d2 % 11);
    if ($d2 >= 10) $d2 = 0;
    
    return sprintf('%d%d%d%d%d%d%d%d%d%d%d', $n1, $n2, $n3, $n4, $n5, $n6, $n7, $n8, $n9, $d1, $d2);
}

// Função para gerar dados do cliente
function getUserData($userId) {
    return [
        'name' => 'Cliente VagaConectada',
        'email' => 'cliente_' . substr(md5($userId), 0, 8) . '@vagaconectada.fun',
        'phone' => '11999999999',
        'document' => gerarCPFValido()
    ];
}

// Função para enviar para XTracky
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
    
    error_log("XTracky enviado - OrderID: {$orderId}, Status: {$status}, Response: " . $response);
    
    return ['success' => $httpCode >= 200 && $httpCode < 300];
}

// Função para fazer requisições à API
function makeApiRequest($endpoint, $method = 'GET', $data = null) {
    $url = API_BASE_URL . $endpoint;
    
    $headers = [
        'api-secret: ' . API_SECRET,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Erro na requisição: ' . $error, 'http_code' => $httpCode];
    }
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'data' => json_decode($response, true),
        'http_code' => $httpCode
    ];
}

// Função para salvar dados do pagamento gerado
function saveGeneratedPayment($transactionId, $userId, $packageId, $packageName, $credits, $amount) {
    $data = [];
    if (file_exists(GENERATED_PAYMENTS_FILE)) {
        $data = json_decode(file_get_contents(GENERATED_PAYMENTS_FILE), true);
    }
    
    $data[$transactionId] = [
        'user_id' => $userId,
        'package_id' => $packageId,
        'package_name' => $packageName,
        'credits' => $credits,
        'amount' => $amount,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents(GENERATED_PAYMENTS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// Função para obter dados do pagamento gerado
function getGeneratedPayment($transactionId) {
    if (!file_exists(GENERATED_PAYMENTS_FILE)) {
        return null;
    }
    
    $data = json_decode(file_get_contents(GENERATED_PAYMENTS_FILE), true);
    return $data[$transactionId] ?? null;
}

// Função para verificar status via verificarstatus.php
function checkStatusViaVerificarStatus($transactionId, $userId, $packageId, $packageName, $credits, $utmSource) {
    $data = [
        'transaction_id' => $transactionId,
        'user_id' => $userId,
        'package_id' => $packageId,
        'package_name' => $packageName,
        'credits' => $credits,
        'utm_source' => $utmSource
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, VERIFICAR_STATUS_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Erro ao verificar status: ' . $error];
    }
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'data' => json_decode($response, true)
    ];
}

// Receber dados
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit();
}

$action = $input['action'] ?? null;

// ACTION: GENERATE
if ($action === 'generate') {
    $amount = floatval($input['amount'] ?? 0);
    $packageId = $input['packageId'] ?? '';
    $userId = $input['userId'] ?? '';
    $packageName = $input['packageName'] ?? 'Pacote';
    $creditsFromJS = intval($input['credits'] ?? 0);
    $utmSource = $input['utm_source'] ?? '';
    
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valor inválido']);
        exit();
    }
    
    // Detectar créditos: primeiro tenta usar o que veio do JS, senão calcula
    $credits = $creditsFromJS > 0 ? $creditsFromJS : getPackageCredits($packageId, $amount);
    
    $userData = getUserData($userId);
    $phone = preg_replace('/[^0-9]/', '', $userData['phone']);
    if (strlen($phone) < 10) $phone = '11999999999';
    
    $transactionData = [
        'external_id' => 'pkg_' . $packageId . '_' . time(),
        'total_amount' => $amount,
        'payment_method' => 'PIX',
        'webhook_url' => 'https://vagaconectada.fun/api/webhook-pix.php',
        'items' => [
            [
                'id' => $packageId,
                'title' => $packageName,
                'description' => 'Pacote de vagas - ' . $packageName,
                'price' => $amount,
                'quantity' => 1,
                'is_physical' => false
            ]
        ],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'customer' => [
            'name' => $userData['name'],
            'email' => $userData['email'],
            'phone' => $phone,
            'document_type' => 'CPF',
            'document' => $userData['document']
        ]
    ];
    
    error_log("📦 Gerando PIX:");
    error_log("   └─ UserID: {$userId}");
    error_log("   └─ PackageID: {$packageId}");
    error_log("   └─ Package: {$packageName}");
    error_log("   └─ Créditos: {$credits} " . ($creditsFromJS > 0 ? "(do JS)" : "(auto-detectado)"));
    error_log("   └─ Valor: R$ {$amount}");
    
    $result = makeApiRequest('/v1/transactions', 'POST', $transactionData);
    
    if ($result['success'] && isset($result['data']['id'])) {
        $responseData = $result['data'];
        $transactionId = $responseData['id'];
        
        // Salvar dados do pagamento
        saveGeneratedPayment($transactionId, $userId, $packageId, $packageName, $credits, $amount);
        
        // XTracky
        sendToXTracky($transactionId, $amount, 'waiting_payment', $utmSource);

        // Dispara uma primeira chamada para o verificarstatus.php (não bloqueante)
        // Isso garante que o verificarstatus salve os metadados do pagamento (user/package/credits)
        // mesmo que o front ainda não esteja fazendo o polling.
        try {
            $chWarmup = curl_init();
            curl_setopt($chWarmup, CURLOPT_URL, VERIFICAR_STATUS_URL);
            curl_setopt($chWarmup, CURLOPT_POST, true);
            curl_setopt($chWarmup, CURLOPT_POSTFIELDS, json_encode([
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'package_id' => $packageId,
                'package_name' => $packageName,
                'credits' => $credits,
                'amount' => $amount,
                'utm_source' => $utmSource
            ]));
            curl_setopt($chWarmup, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chWarmup, CURLOPT_TIMEOUT, 2);
            curl_setopt($chWarmup, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($chWarmup);
            curl_close($chWarmup);
        } catch (Throwable $e) {
            // Ignorar: é apenas warm-up
        }
        
        error_log("✅ PIX Gerado - TransactionID: {$transactionId}");
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $transactionId,
                'qr_code' => $responseData['pix']['payload'] ?? '',
                'status' => strtolower($responseData['status'] ?? 'pending'),
                'amount' => $responseData['total_value'] ?? $amount,
                'external_id' => $responseData['external_id'] ?? '',
                'user_id' => $userId,
                'package_id' => $packageId,
                'package_name' => $packageName,
                'credits' => $credits // ← SEMPRE retorna créditos corretos
            ]
        ]);
    } else {
        http_response_code($result['http_code'] ?? 500);
        echo json_encode([
            'success' => false,
            'error' => $result['data']['error']['message'] ?? 'Erro ao criar transação',
            'details' => $result['data'] ?? null
        ]);
    }
}

// ACTION: STATUS - Redireciona para verificarstatus.php
elseif ($action === 'status') {
    $transactionId = $input['id'] ?? '';
    
    if (empty($transactionId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID da transação não fornecido']);
        exit();
    }
    
    // Buscar dados salvos do pagamento
    $paymentData = getGeneratedPayment($transactionId);
    
    if ($paymentData) {
        // Chama verificarstatus.php com os dados completos
        $result = checkStatusViaVerificarStatus(
            $transactionId,
            $paymentData['user_id'],
            $paymentData['package_id'],
            $paymentData['package_name'],
            $paymentData['credits'],
            $input['utm_source'] ?? ''
        );
        
        if ($result['success']) {
            echo json_encode($result['data']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Erro ao consultar transação']);
        }
    } else {
        // Fallback: chama sem dados (menos ideal)
        error_log("⚠️  Dados do pagamento não encontrados para TransactionID: {$transactionId}");
        
        $result = checkStatusViaVerificarStatus(
            $transactionId,
            $input['userId'] ?? '',
            $input['packageId'] ?? '',
            $input['packageName'] ?? '',
            $input['credits'] ?? 0,
            $input['utm_source'] ?? ''
        );
        
        if ($result['success']) {
            echo json_encode($result['data']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao consultar transação']);
        }
    }
}

// ACTION inválida
else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida. Use "generate" ou "status"']);
}
?>