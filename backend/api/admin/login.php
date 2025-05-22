<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['username']) || !isset($data['password'])) {
            throw new Exception('Thiếu thông tin đăng nhập');
        }

        // Kiểm tra thông tin đăng nhập
        $query = "SELECT * FROM users WHERE username = :username AND role = 'admin'";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':username' => $data['username']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($data['password'], $user['password'])) {
            throw new Exception('Tên đăng nhập hoặc mật khẩu không đúng');
        }

        // Tạo JWT token
        $token = generateJWTToken($user['id']);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ]
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'error' => 'Method not allowed'
    ]);
}

function generateJWTToken($userId) {
    $secret_key = "your-secret-key"; // Thay thế bằng một key phức tạp và bảo mật
    $issued_at = time();
    $expiration = $issued_at + (60 * 60 * 24); // Token hết hạn sau 24 giờ

    $payload = [
        'iat' => $issued_at,
        'exp' => $expiration,
        'user_id' => $userId
    ];

    // Header
    $header = json_encode([
        'typ' => 'JWT',
        'alg' => 'HS256'
    ]);

    // Encode Header
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));

    // Encode Payload
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));

    // Create Signature
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret_key, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    // Create JWT
    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    return $jwt;
}
