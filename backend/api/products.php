<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

// Xử lý các request methods
$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        // Kiểm tra nếu có ID sản phẩm cụ thể
        if(isset($_GET['id'])) {
            getProduct($_GET['id']);
        } 
        // Kiểm tra nếu có category_id
        else if(isset($_GET['category_id'])) {
            getProductsByCategory($_GET['category_id']);
        }
        // Lấy tất cả sản phẩm với phân trang và tìm kiếm
        else {
            getAllProducts();
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// Hàm lấy tất cả sản phẩm với phân trang và tìm kiếm
function getAllProducts() {
    global $pdo;
    
    try {
        // Xử lý phân trang
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
        $offset = ($page - 1) * $limit;
        
        // Xử lý tìm kiếm
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        $where = '';
        $params = [];
        
        if($search) {
            $where = "WHERE name LIKE :search OR description LIKE :search";
            $params[':search'] = "%$search%";
        }
        
        // Đếm tổng số sản phẩm
        $countQuery = "SELECT COUNT(*) as total FROM products $where";
        $stmt = $pdo->prepare($countQuery);
        if($search) {
            $stmt->bindParam(':search', $params[':search']);
        }
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        // Query lấy sản phẩm
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 $where 
                 ORDER BY p.created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        if($search) {
            $stmt->bindParam(':search', $params[':search']);
        }
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        // Trả về kết quả
        echo json_encode([
            'status' => 'success',
            'data' => [
                'products' => $products,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ]
        ]);
        
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
    }
}

// Hàm lấy chi tiết một sản phẩm
function getProduct($id) {
    global $pdo;
    
    try {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.id = :id";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $product = $stmt->fetch();
        
        if($product) {
            echo json_encode([
                'status' => 'success',
                'data' => $product
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Không tìm thấy sản phẩm']);
        }
        
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
    }
}

// Hàm lấy sản phẩm theo danh mục
function getProductsByCategory($categoryId) {
    global $pdo;
    
    try {
        // Xử lý phân trang
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
        $offset = ($page - 1) * $limit;
        
        // Đếm tổng số sản phẩm trong danh mục
        $countQuery = "SELECT COUNT(*) as total FROM products WHERE category_id = :category_id";
        $stmt = $pdo->prepare($countQuery);
        $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        // Query lấy sản phẩm
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.category_id = :category_id 
                 ORDER BY p.created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $products = $stmt->fetchAll();
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'products' => $products,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ]
        ]);
        
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
    }
}
