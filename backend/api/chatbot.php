<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Gemini AI API key
define('GEMINI_API_KEY', 'your-gemini-api-key');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['message'])) {
            throw new Exception('Thiếu nội dung tin nhắn');
        }

        $response = callGeminiAPI($data['message']);
        
        echo json_encode([
            'status' => 'success',
            'data' => $response
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function callGeminiAPI($message) {
    // Chuẩn bị context cho chatbot
    $context = "Bạn là trợ lý ảo của Nakki Store - cửa hàng bán giày và phụ kiện. " .
              "Hãy trả lời các câu hỏi của khách hàng một cách thân thiện và chuyên nghiệp bằng tiếng Việt. " .
              "Tập trung vào việc tư vấn về sản phẩm, size giày, cách chọn giày phù hợp, " .
              "và các chính sách của cửa hàng như đổi trả, bảo hành, giao hàng. " .
              "Luôn giữ giọng điệu thân thiện và nhiệt tình.";

    // Thêm một số thông tin cơ bản về cửa hàng
    $storeInfo = [
        'Chính sách đổi trả' => 'Đổi trả miễn phí trong vòng 30 ngày',
        'Chính sách bảo hành' => 'Bảo hành 6 tháng với lỗi nhà sản xuất',
        'Phương thức thanh toán' => 'Chấp nhận thanh toán qua MoMo, VietQR và COD',
        'Thời gian giao hàng' => '2-3 ngày trong nội thành, 3-5 ngày cho các tỉnh khác',
        'Size giày' => 'Có các size từ 35-45, tư vấn chọn size phù hợp'
    ];

    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $context . "\n\nThông tin cửa hàng:\n" . 
                                 json_encode($storeInfo, JSON_UNESCAPED_UNICODE) . 
                                 "\n\nKhách hàng: " . $message
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 1024,
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ]
        ]
    ];

    $ch = curl_init(GEMINI_API_URL . '?key=' . GEMINI_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Lỗi khi gọi Gemini API: ' . $response);
    }

    $responseData = json_decode($response, true);
    
    // Xử lý và định dạng phản hồi từ Gemini
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        // Lưu log chat để phân tích và cải thiện
        saveChatLog($message, $responseData['candidates'][0]['content']['parts'][0]['text']);
        
        return [
            'message' => $responseData['candidates'][0]['content']['parts'][0]['text'],
            'type' => 'text'
        ];
    } else {
        throw new Exception('Không thể xử lý phản hồi từ Gemini API');
    }
}

// Hàm lưu log chat
function saveChatLog($userMessage, $botResponse) {
    global $pdo;
    
    try {
        $query = "INSERT INTO chat_logs (user_message, bot_response, created_at) 
                 VALUES (:user_message, :bot_response, NOW())";
                 
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':user_message' => $userMessage,
            ':bot_response' => $botResponse
        ]);
        
    } catch(PDOException $e) {
        // Log lỗi nhưng không throw exception để không ảnh hưởng đến phản hồi chat
        error_log('Lỗi lưu chat log: ' . $e->getMessage());
    }
}
