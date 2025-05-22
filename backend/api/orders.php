<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../lib/phpmailer/PHPMailer.php';
require_once '../lib/phpmailer/SMTP.php';
require_once '../lib/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'POST':
        createOrder();
        break;
    case 'GET':
        if(isset($_GET['id'])) {
            getOrder($_GET['id']);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// Hàm tạo đơn hàng mới
function createOrder() {
    global $pdo;
    
    try {
        // Lấy dữ liệu từ request
        $data = json_decode(file_get_contents('php://input'), true);
        
        if(!$data) {
            throw new Exception('Dữ liệu không hợp lệ');
        }
        
        // Validate dữ liệu
        if(empty($data['items']) || empty($data['shipping_address']) || empty($data['shipping_phone'])) {
            throw new Exception('Thiếu thông tin đơn hàng');
        }
        
        // Bắt đầu transaction
        $pdo->beginTransaction();
        
        // Tạo đơn hàng mới
        $query = "INSERT INTO orders (
                    user_id,
                    total_amount,
                    payment_method,
                    shipping_address,
                    shipping_phone
                ) VALUES (
                    :user_id,
                    :total_amount,
                    :payment_method,
                    :shipping_address,
                    :shipping_phone
                )";
                
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':total_amount' => calculateTotal($data['items']),
            ':payment_method' => $data['payment_method'],
            ':shipping_address' => $data['shipping_address'],
            ':shipping_phone' => $data['shipping_phone']
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        // Thêm chi tiết đơn hàng
        foreach($data['items'] as $item) {
            $query = "INSERT INTO order_items (
                        order_id,
                        product_id,
                        quantity,
                        price
                    ) VALUES (
                        :order_id,
                        :product_id,
                        :quantity,
                        :price
                    )";
                    
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $item['id'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price']
            ]);
            
            // Cập nhật số lượng sản phẩm
            updateProductStock($item['id'], $item['quantity']);
        }
        
        // Xử lý thanh toán
        $paymentResult = processPayment($data['payment_method'], $orderId, calculateTotal($data['items']));
        
        if($paymentResult['success']) {
            // Cập nhật trạng thái thanh toán
            $query = "UPDATE orders SET payment_status = 'paid' WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':id' => $orderId]);
            
            // Gửi email xác nhận
            sendOrderConfirmationEmail($orderId);
            
            // Commit transaction
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'order_id' => $orderId,
                    'payment' => $paymentResult
                ]
            ]);
        } else {
            throw new Exception('Thanh toán thất bại: ' . $paymentResult['message']);
        }
        
    } catch(Exception $e) {
        // Rollback nếu có lỗi
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Hàm tính tổng tiền đơn hàng
function calculateTotal($items) {
    $total = 0;
    foreach($items as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

// Hàm cập nhật số lượng sản phẩm
function updateProductStock($productId, $quantity) {
    global $pdo;
    
    $query = "UPDATE products 
              SET stock = stock - :quantity 
              WHERE id = :id AND stock >= :quantity";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':id' => $productId,
        ':quantity' => $quantity
    ]);
    
    if($stmt->rowCount() === 0) {
        throw new Exception('Sản phẩm không đủ số lượng trong kho');
    }
}

// Hàm xử lý thanh toán
function processPayment($method, $orderId, $amount) {
    switch($method) {
        case 'momo':
            return processMomoPayment($orderId, $amount);
        case 'vietqr':
            return processVietQRPayment($orderId, $amount);
        default:
            throw new Exception('Phương thức thanh toán không hợp lệ');
    }
}

// Hàm xử lý thanh toán MoMo
function processMomoPayment($orderId, $amount) {
    // TODO: Tích hợp API MoMo
    return [
        'success' => true,
        'message' => 'Thanh toán MoMo thành công',
        'transaction_id' => uniqid('MOMO_')
    ];
}

// Hàm xử lý thanh toán VietQR
function processVietQRPayment($orderId, $amount) {
    // TODO: Tích hợp API VietQR
    return [
        'success' => true,
        'message' => 'Thanh toán VietQR thành công',
        'transaction_id' => uniqid('VIETQR_')
    ];
}

// Hàm gửi email xác nhận đơn hàng
function sendOrderConfirmationEmail($orderId) {
    global $pdo;
    
    try {
        // Lấy thông tin đơn hàng
        $query = "SELECT o.*, oi.*, p.name as product_name 
                 FROM orders o 
                 JOIN order_items oi ON o.id = oi.order_id 
                 JOIN products p ON oi.product_id = p.id 
                 WHERE o.id = :id";
                 
        $stmt = $pdo->prepare($query);
        $stmt->execute([':id' => $orderId]);
        $orderItems = $stmt->fetchAll();
        
        if(empty($orderItems)) {
            throw new Exception('Không tìm thấy đơn hàng');
        }
        
        // Tạo nội dung email
        $emailContent = createEmailTemplate($orderItems);
        
        // Cấu hình PHPMailer
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'your-email@gmail.com'; // Thay bằng email của bạn
        $mail->Password = 'your-password'; // Thay bằng mật khẩu email của bạn
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('noreply@nakkistore.com', 'Nakki Store');
        $mail->addAddress($orderItems[0]['email']); // Email khách hàng
        
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Xác nhận đơn hàng #' . $orderId . ' - Nakki Store';
        $mail->Body = $emailContent;
        
        $mail->send();
        return true;
        
    } catch(Exception $e) {
        error_log('Lỗi gửi email: ' . $e->getMessage());
        return false;
    }
}

// Hàm tạo template email
function createEmailTemplate($orderItems) {
    $total = 0;
    $items = '';
    
    foreach($orderItems as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        $total += $subtotal;
        
        $items .= "
            <tr>
                <td>{$item['product_name']}</td>
                <td>{$item['quantity']}</td>
                <td>" . number_format($item['price']) . "đ</td>
                <td>" . number_format($subtotal) . "đ</td>
            </tr>
        ";
    }
    
    return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 8px; border: 1px solid #ddd; }
                th { background-color: #f5f5f5; }
            </style>
        </head>
        <body>
            <h2>Xác nhận đơn hàng #{$orderItems[0]['id']}</h2>
            <p>Cảm ơn bạn đã đặt hàng tại Nakki Store!</p>
            
            <h3>Chi tiết đơn hàng:</h3>
            <table>
                <tr>
                    <th>Sản phẩm</th>
                    <th>Số lượng</th>
                    <th>Đơn giá</th>
                    <th>Thành tiền</th>
                </tr>
                {$items}
                <tr>
                    <td colspan='3'><strong>Tổng cộng:</strong></td>
                    <td><strong>" . number_format($total) . "đ</strong></td>
                </tr>
            </table>
            
            <h3>Thông tin giao hàng:</h3>
            <p>
                Địa chỉ: {$orderItems[0]['shipping_address']}<br>
                Số điện thoại: {$orderItems[0]['shipping_phone']}
            </p>
            
            <p>Chúng tôi sẽ sớm liên hệ với bạn để xác nhận đơn hàng.</p>
            
            <p>Trân trọng,<br>Nakki Store</p>
        </body>
        </html>
    ";
}

// Hàm lấy thông tin đơn hàng
function getOrder($id) {
    global $pdo;
    
    try {
        $query = "SELECT o.*, oi.*, p.name as product_name, p.image_url 
                 FROM orders o 
                 JOIN order_items oi ON o.id = oi.order_id 
                 JOIN products p ON oi.product_id = p.id 
                 WHERE o.id = :id";
                 
        $stmt = $pdo->prepare($query);
        $stmt->execute([':id' => $id]);
        $items = $stmt->fetchAll();
        
        if(empty($items)) {
            http_response_code(404);
            echo json_encode(['error' => 'Không tìm thấy đơn hàng']);
            return;
        }
        
        // Định dạng lại dữ liệu
        $order = [
            'id' => $items[0]['id'],
            'total_amount' => $items[0]['total_amount'],
            'status' => $items[0]['status'],
            'payment_method' => $items[0]['payment_method'],
            'payment_status' => $items[0]['payment_status'],
            'shipping_address' => $items[0]['shipping_address'],
            'shipping_phone' => $items[0]['shipping_phone'],
            'created_at' => $items[0]['created_at'],
            'items' => array_map(function($item) {
                return [
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'image_url' => $item['image_url']
                ];
            }, $items)
        ];
        
        echo json_encode([
            'status' => 'success',
            'data' => $order
        ]);
        
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
    }
}
