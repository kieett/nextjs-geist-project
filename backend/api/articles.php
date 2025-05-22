<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        if(isset($_GET['id'])) {
            getArticle($_GET['id']);
        } else {
            getArticles();
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// Hàm lấy danh sách bài viết
function getArticles() {
    global $pdo;
    
    try {
        // Xử lý phân trang
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        // Đếm tổng số bài viết
        $countQuery = "SELECT COUNT(*) as total FROM articles WHERE published = true";
        $stmt = $pdo->query($countQuery);
        $total = $stmt->fetch()['total'];
        
        // Query lấy bài viết
        $query = "SELECT id, title, slug, SUBSTRING(content, 1, 200) as excerpt, 
                        image_url, created_at 
                 FROM articles 
                 WHERE published = true 
                 ORDER BY created_at DESC 
                 LIMIT :limit OFFSET :offset";
                 
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $articles = $stmt->fetchAll();
        
        // Thêm "..." vào excerpt nếu nội dung dài
        foreach($articles as &$article) {
            if(strlen($article['excerpt']) >= 200) {
                $article['excerpt'] .= '...';
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'articles' => $articles,
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

// Hàm lấy chi tiết bài viết
function getArticle($id) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM articles WHERE id = :id AND published = true";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $article = $stmt->fetch();
        
        if($article) {
            echo json_encode([
                'status' => 'success',
                'data' => $article
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Không tìm thấy bài viết']);
        }
        
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
    }
}
