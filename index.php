<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

class Config {
    const DB_HOST = 'FANATSLARKA\\SQLEXPRESS';
    const DB_NAME = 'reviews_db';
    const DB_USER = '';
    const DB_PASS = '';
    const DB_CHARSET = 'UTF-8';
    const DB_DRIVER = 'sqlsrv';
}

class Database {
    private $pdo;
    
    public function __construct() {
        $dsn = Config::DB_DRIVER . ":Server=" . Config::DB_HOST . ";Database=" . Config::DB_NAME . ";TrustServerCertificate=1";
        
        try {
            $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Проверяем и создаем отсутствующие таблицы
            $this->createMissingTables();
        } catch (PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    private function createMissingTables() {
        try {
            // Проверяем существование таблицы comments
            $this->pdo->exec("
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='comments' AND xtype='U')
                CREATE TABLE comments (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    review_id INT NOT NULL,
                    user_id INT NOT NULL,
                    text NVARCHAR(1000) NOT NULL,
                    created_at DATETIME DEFAULT GETDATE()
                )
            ");
        } catch (Exception $e) {
            error_log("Ошибка при создании таблицы comments: " . $e->getMessage());
        }
        
        try {
            // Проверяем существование таблицы review_votes
            $this->pdo->exec("
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='review_votes' AND xtype='U')
                CREATE TABLE review_votes (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    review_id INT NOT NULL,
                    user_id INT NOT NULL,
                    vote_type NVARCHAR(10) NOT NULL,
                    created_at DATETIME DEFAULT GETDATE()
                )
            ");
        } catch (Exception $e) {
            error_log("Ошибка при создании таблицы review_votes: " . $e->getMessage());
        }
    }
}

class User {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function register($username, $email, $password) {
        // Проверяем, существует ли пользователь
        $checkSql = "SELECT id FROM users WHERE username = :username OR email = :email";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([
            ':username' => $username,
            ':email' => $email
        ]);
        
        if ($checkStmt->fetch()) {
            return false; // Пользователь уже существует
        }
        
        // Хешируем пароль
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Создаем нового пользователя
        $sql = "INSERT INTO users (username, email, password, created_at) 
                VALUES (:username, :email, :password, GETDATE())";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => $hashedPassword
        ]);
    }
    
    public function login($username, $password) {
        $sql = "SELECT * FROM users WHERE username = :username";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            return true;
        }
        
        return false;
    }
    
    public function logout() {
        session_destroy();
        header('Location: ?page=home');
        exit;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function getCurrentUser() {
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'email' => $_SESSION['email']
            ];
        }
        return null;
    }
    
    public function getUserById($id) {
        $sql = "SELECT id, username, email, created_at FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
}

class Work {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function getAll($category = null) {
        $sql = "SELECT * FROM works";
        
        if ($category) {
            $sql .= " WHERE category = :category";
        }
        
        $sql .= " ORDER BY rating DESC, created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        
        if ($category) {
            $stmt->bindParam(':category', $category);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getById($id) {
        $sql = "SELECT * FROM works WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function create($data) {
        $sql = "INSERT INTO works (title, category, image_url, year, description, user_id) 
                VALUES (:title, :category, :image_url, :year, :description, :user_id)";
        
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute([
            ':title' => $data['title'],
            ':category' => $data['category'],
            ':image_url' => $data['image_url'],
            ':year' => $data['year'],
            ':description' => $data['description'],
            ':user_id' => $data['user_id']
        ]);
    }
    
    public function updateRating($workId) {
        $sql = "SELECT AVG(CAST(rating AS DECIMAL(3,1))) as avg_rating 
                FROM reviews 
                WHERE work_id = :work_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':work_id', $workId);
        $stmt->execute();
        $result = $stmt->fetch();
        $avgRating = $result['avg_rating'] ?: 0;
    
        $sql = "UPDATE works SET rating = :rating WHERE id = :work_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':rating' => $avgRating,
            ':work_id' => $workId
        ]);
    }
    
    public function getLastInsertId() {
        $sql = "SELECT SCOPE_IDENTITY() as id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['id'];
    }
}

class Review {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function getByWorkId($workId) {
        $sql = "SELECT r.*, u.username as author_name 
                FROM reviews r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE work_id = :work_id 
                ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':work_id', $workId);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $sql = "INSERT INTO reviews (work_id, user_id, rating, text) 
                VALUES (:work_id, :user_id, :rating, :text)";
        
        $stmt = $this->db->prepare($sql);
        
        $result = $stmt->execute([
            ':work_id' => $data['work_id'],
            ':user_id' => $data['user_id'],
            ':rating' => $data['rating'],
            ':text' => $data['text']
        ]);
        
        if ($result) {
            $workModel = new Work();
            $workModel->updateRating($data['work_id']);
        }
        
        return $result;
    }
    
    public function getCountByWorkId($workId) {
        $sql = "SELECT COUNT(*) as count FROM reviews WHERE work_id = :work_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':work_id', $workId);
        $stmt->execute();
        return $stmt->fetch()['count'];
    }
    
    public function getAllWorks() {
        $sql = "SELECT id, title FROM works ORDER BY title";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getById($id) {
        $sql = "SELECT r.*, u.username as author_name 
                FROM reviews r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }
}

class Comment {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function getByReviewId($reviewId) {
        try {
            $sql = "SELECT c.*, u.username as author_name 
                    FROM comments c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    WHERE review_id = :review_id 
                    ORDER BY created_at ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':review_id', $reviewId);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            // Если таблицы не существует, возвращаем пустой массив
            return [];
        }
    }
    
    public function create($data) {
        try {
            $sql = "INSERT INTO comments (review_id, user_id, text) 
                    VALUES (:review_id, :user_id, :text)";
            
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([
                ':review_id' => $data['review_id'],
                ':user_id' => $data['user_id'],
                ':text' => $data['text']
            ]);
        } catch (Exception $e) {
            error_log("Ошибка при создании комментария: " . $e->getMessage());
            return false;
        }
    }
    
    public function getCountByReviewId($reviewId) {
        try {
            $sql = "SELECT COUNT(*) as count FROM comments WHERE review_id = :review_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':review_id', $reviewId);
            $stmt->execute();
            return $stmt->fetch()['count'];
        } catch (Exception $e) {
            return 0;
        }
    }
}

class ReviewVote {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function vote($reviewId, $userId, $voteType) {
        try {
            // Проверяем, не голосовал ли уже пользователь
            $checkSql = "SELECT id FROM review_votes WHERE review_id = :review_id AND user_id = :user_id";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute([
                ':review_id' => $reviewId,
                ':user_id' => $userId
            ]);
            
            if ($checkStmt->fetch()) {
                // Обновляем существующий голос
                $sql = "UPDATE review_votes SET vote_type = :vote_type WHERE review_id = :review_id AND user_id = :user_id";
            } else {
                // Создаем новый голос
                $sql = "INSERT INTO review_votes (review_id, user_id, vote_type) VALUES (:review_id, :user_id, :vote_type)";
            }
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':review_id' => $reviewId,
                ':user_id' => $userId,
                ':vote_type' => $voteType
            ]);
        } catch (Exception $e) {
            error_log("Ошибка при голосовании: " . $e->getMessage());
            return false;
        }
    }
    
    public function getVotesCount($reviewId) {
        try {
            $sql = "SELECT 
                    SUM(CASE WHEN vote_type = 'up' THEN 1 ELSE 0 END) as upvotes,
                    SUM(CASE WHEN vote_type = 'down' THEN 1 ELSE 0 END) as downvotes
                    FROM review_votes 
                    WHERE review_id = :review_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':review_id', $reviewId);
            $stmt->execute();
            $result = $stmt->fetch();
            return [
                'upvotes' => $result['upvotes'] ?? 0,
                'downvotes' => $result['downvotes'] ?? 0
            ];
        } catch (Exception $e) {
            // Если таблицы не существует, возвращаем нулевые значения
            return ['upvotes' => 0, 'downvotes' => 0];
        }
    }
    
    public function getUserVote($reviewId, $userId) {
        try {
            $sql = "SELECT vote_type FROM review_votes WHERE review_id = :review_id AND user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':review_id' => $reviewId,
                ':user_id' => $userId
            ]);
            $result = $stmt->fetch();
            return $result ? $result['vote_type'] : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

function getCategoryName($category) {
    $categories = [
        'game' => 'Игра',
        'movie' => 'Фильм', 
        'book' => 'Книга'
    ];
    return $categories[$category] ?? 'Неизвестно';
}

function getStarRating($rating) {
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    return str_repeat('★', $fullStars) . ($halfStar ? '½' : '') . str_repeat('☆', $emptyStars);
}

session_start();

$page = $_GET['page'] ?? 'home';
$category = $_GET['category'] ?? 'all';
$workId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? '';

$validCategories = ['game', 'movie', 'book'];
$currentCategory = in_array($category, $validCategories) ? $category : null;

$userModel = new User();
$workModel = new Work();
$reviewModel = new Review();
$commentModel = new Comment();
$voteModel = new ReviewVote();

$works = [];
$currentWork = null;
$reviews = [];
$allWorks = [];
$error = null;
$success = null;

// Обработка действий аутентификации
if ($action === 'logout') {
    $userModel->logout();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['auth_action'])) {
        switch ($_POST['auth_action']) {
            case 'register':
                $username = $_POST['username'] ?? '';
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($username) || empty($email) || empty($password)) {
                    $error = "Все поля обязательны для заполнения";
                } elseif ($password !== $confirm_password) {
                    $error = "Пароли не совпадают";
                } elseif (strlen($password) < 6) {
                    $error = "Пароль должен содержать минимум 6 символов";
                } else {
                    if ($userModel->register($username, $email, $password)) {
                        $success = "Регистрация успешна! Теперь вы можете войти.";
                    } else {
                        $error = "Пользователь с таким именем или email уже существует";
                    }
                }
                break;
                
            case 'login':
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                
                if ($userModel->login($username, $password)) {
                    $success = "Вход выполнен успешно!";
                } else {
                    $error = "Неверное имя пользователя или пароль";
                }
                break;
        }
    } elseif (isset($_POST['action'])) {
        // Проверяем авторизацию для защищенных действий
        if (!$userModel->isLoggedIn()) {
            $error = "Для выполнения этого действия необходимо войти в систему";
        } else {
            switch ($_POST['action']) {
                case 'add_work':
                    $data = [
                        'title' => $_POST['title'] ?? '',
                        'category' => $_POST['category'] ?? '',
                        'image_url' => $_POST['image_url'] ?? '',
                        'year' => $_POST['year'] ?? '',
                        'description' => $_POST['description'] ?? '',
                        'user_id' => $_SESSION['user_id']
                    ];
                    
                    if ($workModel->create($data)) {
                        header('Location: ?success=work_added');
                        exit;
                    } else {
                        $error = "Ошибка при добавлении произведения";
                    }
                    break;
                    
                case 'add_review':
                    $data = [
                        'work_id' => $_POST['work_id'] ?? '',
                        'user_id' => $_SESSION['user_id'],
                        'rating' => $_POST['rating'] ?? '',
                        'text' => $_POST['text'] ?? ''
                    ];
                    
                    if ($reviewModel->create($data)) {
                        header('Location: ?success=review_added');
                        exit;
                    } else {
                        $error = "Ошибка при добавлении отзыва";
                    }
                    break;
                    
                case 'add_comment':
                    $data = [
                        'review_id' => $_POST['review_id'] ?? '',
                        'user_id' => $_SESSION['user_id'],
                        'text' => $_POST['text'] ?? ''
                    ];
                    
                    if ($commentModel->create($data)) {
                        $success = "Комментарий успешно добавлен!";
                    } else {
                        $error = "Ошибка при добавлении комментария";
                    }
                    break;
                    
                case 'vote_review':
                    $reviewId = $_POST['review_id'] ?? '';
                    $voteType = $_POST['vote_type'] ?? '';
                    
                    if ($voteModel->vote($reviewId, $_SESSION['user_id'], $voteType)) {
                        $success = "Ваш голос учтен!";
                    } else {
                        $error = "Ошибка при голосовании";
                    }
                    break;
            }
        }
    }
}

// Обработка GET параметров для страниц
switch ($page) {
    case 'home':
    case 'works':
        $works = $workModel->getAll($currentCategory);
        foreach ($works as &$work) {
            $work['reviews_count'] = $reviewModel->getCountByWorkId($work['id']);
        }
        unset($work);
        break;
        
    case 'work_detail':
        if ($workId) {
            $currentWork = $workModel->getById($workId);
            if ($currentWork) {
                $reviews = $reviewModel->getByWorkId($workId);
                // Добавляем информацию о голосах и комментариях для каждого отзыва
                foreach ($reviews as &$review) {
                    $review['votes'] = $voteModel->getVotesCount($review['id']);
                    $review['comments'] = $commentModel->getByReviewId($review['id']);
                    $review['comments_count'] = $commentModel->getCountByReviewId($review['id']);
                    if ($userModel->isLoggedIn()) {
                        $review['user_vote'] = $voteModel->getUserVote($review['id'], $_SESSION['user_id']);
                    }
                }
                unset($review);
            }
        }
        break;
        
    case 'add_review':
        $allWorks = $reviewModel->getAllWorks();
        break;
        
    case 'profile':
        // Страница профиля
        break;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        $title = 'Отзывы.ру';
        switch ($page) {
            case 'work_detail':
                $title = htmlspecialchars($currentWork['title'] ?? 'Произведение') . ' - ' . $title;
                break;
            case 'add_work':
                $title = 'Добавить произведение - ' . $title;
                break;
            case 'add_review':
                $title = 'Добавить отзыв - ' . $title;
                break;
            case 'profile':
                $title = 'Профиль - ' . $title;
                break;
        }
        echo $title;
        ?>
    </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Общие стили */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Хедер */
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 2rem;
            font-weight: bold;
            color: white;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        nav a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        nav a:hover, nav a.active {
            background-color: rgba(255,255,255,0.2);
        }

        .auth-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .login-btn, .register-btn {
            padding: 8px 16px;
            border: 1px solid #007bff;
            border-radius: 5px;
            text-decoration: none;
            color: #007bff;
            transition: all 0.3s;
            background: white;
        }

        .login-btn:hover, .register-btn:hover {
            background-color: #007bff;
            color: white;
        }

        .user-menu {
            position: relative;
            display: inline-block;
        }

        .user-welcome {
            color: white;
            font-weight: bold;
            cursor: pointer;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
        }

        .user-dropdown {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 160px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 5px;
            z-index: 1000;
            right: 0;
            top: 100%;
        }

        .user-dropdown a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid #eee;
        }

        .user-dropdown a:hover {
            background-color: #f8f9fa;
        }

        .user-menu:hover .user-dropdown {
            display: block;
        }

        /* Герой секция */
        .hero {
            text-align: center;
            padding: 4rem 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin-bottom: 2rem;
            border-radius: 10px;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #333;
        }

        .hero p {
            font-size: 1.2rem;
            color: #666;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Кнопки добавления */
        .add-buttons {
            text-align: center;
            margin: 2rem 0;
        }

        .add-btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 0 10px;
            transition: background-color 0.3s;
        }

        .add-btn:hover {
            background-color: #0056b3;
        }

        .add-btn.secondary {
            background-color: #6c757d;
        }

        .add-btn.secondary:hover {
            background-color: #545b62;
        }

        /* Категории */
        .categories {
            text-align: center;
            margin: 2rem 0;
        }

        .category-btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 0 5px;
            background-color: white;
            color: #333;
            text-decoration: none;
            border: 2px solid #007bff;
            border-radius: 25px;
            transition: all 0.3s;
        }

        .category-btn:hover, .category-btn.active {
            background-color: #007bff;
            color: white;
        }

        /* Сетка произведений */
        .works-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }

        .work-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
            color: inherit;
        }

        .work-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }

        .work-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .work-content {
            padding: 1.5rem;
        }

        .work-title {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .work-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #666;
        }

        .work-rating {
            color: #ffc107;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .work-reviews-count {
            color: #666;
            font-size: 0.9rem;
        }

        /* Детальная страница произведения */
        .work-detail-page {
            max-width: 1000px;
            margin: 0 auto;
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 2rem;
        }

        .back-button:hover {
            background-color: #545b62;
        }

        .work-detail-header {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .work-detail-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 10px;
        }

        .work-detail-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #333;
        }

        .work-detail-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            color: #666;
        }

        .work-detail-rating {
            font-size: 1.5rem;
            color: #ffc107;
            margin-bottom: 1rem;
        }

        .work-detail-description {
            font-size: 1.1rem;
            line-height: 1.8;
            color: #555;
        }

        /* Секция отзывов */
        .reviews-list {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 2rem;
            margin-bottom: 1.5rem;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
        }

        .review-item {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #f8f9fa;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .review-author {
            font-weight: bold;
            color: #007bff;
        }

        .review-rating {
            color: #ffc107;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .review-text {
            line-height: 1.6;
            color: #555;
        }

        /* Система голосования */
        .vote-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 1rem 0;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .vote-btn {
            background: none;
            border: 1px solid #ddd;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
        }

        .vote-btn:hover {
            background-color: #f8f9fa;
        }

        .vote-btn.upvote.active {
            background-color: #28a745;
            color: white;
            border-color: #28a745;
        }

        .vote-btn.downvote.active {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .vote-count {
            font-weight: bold;
            color: #333;
            font-size: 1.1rem;
            min-width: 30px;
            text-align: center;
        }

        .toggle-comments {
            background: none;
            border: none;
            color: #007bff;
            cursor: pointer;
            padding: 5px 10px;
            font-size: 0.9rem;
            margin-left: auto;
        }

        .toggle-comments:hover {
            text-decoration: underline;
        }

        /* Комментарии */
        .comments-section {
            margin-top: 1.5rem;
            border-top: 1px solid #eee;
            padding-top: 1.5rem;
        }

        .comment-form {
            margin-bottom: 1.5rem;
        }

        .comment-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
            font-size: 1rem;
        }

        .comment-list {
            margin-top: 1rem;
        }

        .comment-item {
            padding: 1rem;
            border: 1px solid #eee;
            border-radius: 5px;
            margin-bottom: 1rem;
            background-color: white;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            color: #666;
        }

        .comment-author {
            font-weight: bold;
            color: #007bff;
        }

        .comment-text {
            line-height: 1.4;
            color: #333;
        }

        /* Формы */
        .form-container {
            max-width: 600px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .form-title {
            font-size: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            color: #333;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
        }

        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #ddd;
        }

        .auth-tab {
            padding: 10px 20px;
            cursor: pointer;
            border: none;
            background: none;
            flex: 1;
            text-align: center;
            font-size: 1rem;
            transition: color 0.3s;
        }

        .auth-tab.active {
            border-bottom: 2px solid #007bff;
            color: #007bff;
        }

        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .auth-form input {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }

        .auth-form input:focus {
            outline: none;
            border-color: #007bff;
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            margin-top: 1rem;
        }

        .auth-btn {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            cursor: pointer;
            flex: 1;
            font-size: 1rem;
            transition: background-color 0.3s;
        }

        .auth-btn:hover {
            background-color: #0056b3;
        }

        /* Сообщения */
        .success, .error {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 5px;
            text-align: center;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Состояния "нет данных" */
        .no-works, .no-reviews {
            text-align: center;
            padding: 3rem;
            color: #666;
            font-size: 1.1rem;
            background: white;
            border-radius: 10px;
            border: 2px dashed #ddd;
        }

        /* Профиль */
        .profile-page {
            max-width: 600px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .profile-info p {
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #007bff;
        }

        /* Футер */
        footer {
            background: #333;
            color: white;
            padding: 3rem 0 1rem;
            margin-top: 4rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            margin-bottom: 1rem;
            color: #007bff;
        }

        .footer-section a {
            display: block;
            color: #ccc;
            text-decoration: none;
            margin-bottom: 0.5rem;
            transition: color 0.3s;
        }

        .footer-section a:hover {
            color: #007bff;
        }

        .footer-section p {
            color: #ccc;
            line-height: 1.6;
        }

        .copyright {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid #444;
            color: #999;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            nav ul {
                flex-wrap: wrap;
                justify-content: center;
                gap: 1rem;
            }
            
            .work-detail-header {
                grid-template-columns: 1fr;
            }
            
            .works-grid {
                grid-template-columns: 1fr;
            }
            
            .hero h1 {
                font-size: 2rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .vote-buttons {
                flex-wrap: wrap;
            }
            
            .review-header {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0 10px;
            }
            
            .hero {
                padding: 2rem 0;
            }
            
            .add-buttons {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            
            .add-btn {
                margin: 0;
            }
            
            .categories {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .category-btn {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">Record.ру</div>
                <nav>
                    <ul>
                        <li><a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">Главная</a></li>
                        <li><a href="?page=works&category=game" class="<?= $category === 'game' ? 'active' : '' ?>">Игры</a></li>
                        <li><a href="?page=works&category=movie" class="<?= $category === 'movie' ? 'active' : '' ?>">Фильмы</a></li>
                        <li><a href="?page=works&category=book" class="<?= $category === 'book' ? 'active' : '' ?>">Книги</a></li>
                    </ul>
                </nav>
                <div class="auth-buttons">
                    <?php if ($userModel->isLoggedIn()): ?>
                        <div class="user-menu">
                            <span class="user-welcome">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                            </span>
                            <div class="user-dropdown">
                                <a href="?page=profile">Профиль</a>
                                <a href="?action=logout">Выйти</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="#" class="login-btn" onclick="showAuthModal('login')">Войти</a>
                        <a href="#" class="register-btn" onclick="showAuthModal('register')">Регистрация</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if (isset($_GET['success'])): ?>
            <div class="success">
                <?php
                switch ($_GET['success']) {
                    case 'work_added':
                        echo 'Произведение успешно добавлено!';
                        break;
                    case 'review_added':
                        echo 'Отзыв успешно добавлен!';
                        break;
                }
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($page === 'home' || $page === 'works'): ?>
            <section class="hero">
                <h1>Делитесь своими впечатлениями</h1>
                <p>Находите отзывы на игры, фильмы и книги или оставляйте свои собственные. Помогите другим сделать правильный выбор!</p>
            </section>

            <div class="add-buttons">
                <?php if ($userModel->isLoggedIn()): ?>
                    <a href="?page=add_work" class="add-btn">
                        <i class="fas fa-plus"></i> Добавить произведение
                    </a>
                    <a href="?page=add_review" class="add-btn secondary">
                        <i class="fas fa-comment"></i> Добавить отзыв
                    </a>
                <?php else: ?>
                    <p>Чтобы добавлять произведения и отзывы, <a href="#" onclick="showAuthModal('login')" style="color: #007bff;">войдите</a> или <a href="#" onclick="showAuthModal('register')" style="color: #007bff;">зарегистрируйтесь</a>.</p>
                <?php endif; ?>
            </div>

            <div class="categories">
                <a href="?page=home" class="category-btn <?= $category === 'all' ? 'active' : '' ?>">Все</a>
                <a href="?page=works&category=game" class="category-btn <?= $category === 'game' ? 'active' : '' ?>">Игры</a>
                <a href="?page=works&category=movie" class="category-btn <?= $category === 'movie' ? 'active' : '' ?>">Фильмы</a>
                <a href="?page=works&category=book" class="category-btn <?= $category === 'book' ? 'active' : '' ?>">Книги</a>
            </div>

            <section class="works-section">
                <h2 class="section-title">
                    <?php
                    if ($currentCategory) {
                        echo getCategoryName($currentCategory);
                    } else {
                        echo 'Все произведения';
                    }
                    ?>
                </h2>
                <div class="works-grid">
                    <?php if (empty($works)): ?>
                        <div class="no-works">
                            Произведения не найдены. Будьте первым, кто добавит произведение!
                        </div>
                    <?php else: ?>
                        <?php foreach ($works as $work): ?>
                            <a href="?page=work_detail&id=<?= $work['id'] ?>" class="work-card">
                                <img src="<?= htmlspecialchars($work['image_url']) ?>" alt="<?= htmlspecialchars($work['title']) ?>" class="work-image">
                                <div class="work-content">
                                    <h3 class="work-title"><?= htmlspecialchars($work['title']) ?></h3>
                                    <div class="work-meta">
                                        <span class="work-category"><?= getCategoryName($work['category']) ?></span>
                                        <span><?= $work['year'] ?></span>
                                    </div>
                                    <div class="work-rating"><?= getStarRating($work['rating']) ?></div>
                                    <div class="work-reviews-count"><?= $work['reviews_count'] ?> отзывов</div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

        <?php elseif ($page === 'work_detail' && $currentWork): ?>
            <div class="work-detail-page">
                <a href="?page=home" class="back-button">← Назад к списку</a>
                <div class="work-detail-header">
                    <img src="<?= htmlspecialchars($currentWork['image_url']) ?>" alt="<?= htmlspecialchars($currentWork['title']) ?>" class="work-detail-image">
                    <div class="work-detail-info">
                        <h1 class="work-detail-title"><?= htmlspecialchars($currentWork['title']) ?></h1>
                        <div class="work-detail-meta">
                            <span class="work-detail-category"><?= getCategoryName($currentWork['category']) ?></span>
                            <span class="work-detail-year"><?= $currentWork['year'] ?></span>
                        </div>
                        <div class="work-detail-rating"><?= getStarRating($currentWork['rating']) ?></div>
                        <p class="work-detail-description"><?= htmlspecialchars($currentWork['description']) ?></p>
                    </div>
                </div>
                <div class="reviews-list">
                    <h2 class="section-title">Отзывы (<?= count($reviews) ?>)</h2>
                    <?php if ($userModel->isLoggedIn()): ?>
                        <a href="?page=add_review&work_id=<?= $currentWork['id'] ?>" class="add-btn" style="margin-bottom: 20px;">
                            <i class="fas fa-comment"></i> Добавить отзыв
                        </a>
                    <?php else: ?>
                        <p>Чтобы оставить отзыв, <a href="#" onclick="showAuthModal('login')" style="color: #007bff;">войдите</a> или <a href="#" onclick="showAuthModal('register')" style="color: #007bff;">зарегистрируйтесь</a>.</p>
                    <?php endif; ?>
                    
                    <?php if (empty($reviews)): ?>
                        <div class="no-reviews">Пока нет отзывов. Будьте первым, кто оставит отзыв!</div>
                    <?php else: ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <span class="review-author"><?= htmlspecialchars($review['author_name'] ?? 'Аноним') ?></span>
                                    <span class="review-date"><?= date('d.m.Y', strtotime($review['created_at'])) ?></span>
                                </div>
                                <div class="review-rating"><?= getStarRating($review['rating']) ?></div>
                                <p class="review-text"><?= htmlspecialchars($review['text']) ?></p>
                                
                                <!-- Система голосования -->
                                <div class="vote-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="vote_review">
                                        <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                        <input type="hidden" name="vote_type" value="up">
                                        <button type="submit" class="vote-btn upvote <?= ($review['user_vote'] ?? '') === 'up' ? 'active' : '' ?>">
                                            <i class="fas fa-thumbs-up"></i>
                                        </button>
                                    </form>
                                    
                                    <span class="vote-count">
                                        <?= ($review['votes']['upvotes'] ?? 0) - ($review['votes']['downvotes'] ?? 0) ?>
                                    </span>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="vote_review">
                                        <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                        <input type="hidden" name="vote_type" value="down">
                                        <button type="submit" class="vote-btn downvote <?= ($review['user_vote'] ?? '') === 'down' ? 'active' : '' ?>">
                                            <i class="fas fa-thumbs-down"></i>
                                        </button>
                                    </form>
                                    
                                    <span style="margin-left: auto;">
                                        <button class="toggle-comments" onclick="toggleComments(<?= $review['id'] ?>)">
                                            Комментарии (<?= $review['comments_count'] ?? 0 ?>)
                                        </button>
                                    </span>
                                </div>
                                
                                <!-- Секция комментариев -->
                                <div class="comments-section" id="comments-<?= $review['id'] ?>" style="display: none;">
                                    <?php if ($userModel->isLoggedIn()): ?>
                                        <form method="POST" class="comment-form">
                                            <input type="hidden" name="action" value="add_comment">
                                            <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                            <textarea name="text" placeholder="Добавить комментарий..." required></textarea>
                                            <button type="submit" class="auth-btn" style="padding: 8px 16px;">Отправить</button>
                                        </form>
                                    <?php else: ?>
                                        <p>Чтобы оставить комментарий, <a href="#" onclick="showAuthModal('login')" style="color: #007bff;">войдите</a> в систему.</p>
                                    <?php endif; ?>
                                    
                                    <div class="comment-list">
                                        <?php if (!empty($review['comments'])): ?>
                                            <?php foreach ($review['comments'] as $comment): ?>
                                                <div class="comment-item">
                                                    <div class="comment-header">
                                                        <span class="comment-author"><?= htmlspecialchars($comment['author_name'] ?? 'Аноним') ?></span>
                                                        <span class="comment-date"><?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?></span>
                                                    </div>
                                                    <div class="comment-text"><?= htmlspecialchars($comment['text']) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>Пока нет комментариев.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($page === 'add_work'): ?>
            <?php if (!$userModel->isLoggedIn()): ?>
                <div class="error">Для добавления произведения необходимо <a href="#" onclick="showAuthModal('login')" style="color: #007bff;">войти</a> в систему.</div>
            <?php else: ?>
                <div class="form-container">
                    <h2 class="form-title">Добавить новое произведение</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_work">
                        <div class="form-group">
                            <label for="title">Название произведения</label>
                            <input type="text" class="form-control" id="title" name="title" placeholder="Введите название" required>
                        </div>
                        <div class="form-group">
                            <label for="category">Категория</label>
                            <select class="form-control" id="category" name="category" required>
                                <option value="">Выберите категорию</option>
                                <option value="game">Игра</option>
                                <option value="movie">Фильм</option>
                                <option value="book">Книга</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year">Год выпуска</label>
                            <input type="number" class="form-control" id="year" name="year" min="1900" max="2030" placeholder="Введите год выпуска" required>
                        </div>
                        <div class="form-group">
                            <label for="image_url">Ссылка на изображение</label>
                            <input type="url" class="form-control" id="image_url" name="image_url" placeholder="Введите URL изображения" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Описание</label>
                            <textarea class="form-control" id="description" name="description" placeholder="Введите описание произведения" required></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Добавить произведение</button>
                            <a href="?page=home" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php elseif ($page === 'add_review'): ?>
            <?php if (!$userModel->isLoggedIn()): ?>
                <div class="error">Для добавления отзыва необходимо <a href="#" onclick="showAuthModal('login')" style="color: #007bff;">войти</a> в систему.</div>
            <?php else: ?>
                <div class="form-container">
                    <h2 class="form-title">Добавить отзыв</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_review">
                        <div class="form-group">
                            <label for="work_id">Произведение</label>
                            <select class="form-control" id="work_id" name="work_id" required>
                                <option value="">Выберите произведение</option>
                                <?php foreach ($allWorks as $work): ?>
                                    <option value="<?= $work['id'] ?>" <?= isset($_GET['work_id']) && $_GET['work_id'] == $work['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($work['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="rating">Оценка</label>
                            <select class="form-control" id="rating" name="rating" required>
                                <option value="">Выберите оценку</option>
                                <option value="5">★★★★★ Отлично</option>
                                <option value="4">★★★★☆ Хорошо</option>
                                <option value="3">★★★☆☆ Удовлетворительно</option>
                                <option value="2">★★☆☆☆ Плохо</option>
                                <option value="1">★☆☆☆☆ Очень плохо</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="text">Текст отзыва</label>
                            <textarea class="form-control" id="text" name="text" placeholder="Напишите ваш отзыв..." required></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Опубликовать отзыв</button>
                            <a href="?page=home" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php elseif ($page === 'profile' && $userModel->isLoggedIn()): ?>
            <div class="profile-page">
                <h2>Профиль пользователя</h2>
                <div class="profile-info">
                    <p><strong>Имя пользователя:</strong> <?= htmlspecialchars($_SESSION['username']) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($_SESSION['email']) ?></p>
                    <p><strong>Дата регистрации:</strong> 
                        <?php 
                        $user = $userModel->getUserById($_SESSION['user_id']);
                        echo $user ? date('d.m.Y', strtotime($user['created_at'])) : 'Неизвестно';
                        ?>
                    </p>
                </div>
            </div>

        <?php else: ?>
            <div class="hero">
                <h1>Страница не найдена</h1>
                <p>Запрашиваемая страница не существует.</p>
                <a href="?page=home" class="btn btn-primary" style="margin-top: 20px;">Вернуться на главную</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно авторизации -->
    <div id="authModal" class="modal"> 
        <div class="modal-content">
            <div class="auth-tabs">
                <button class="auth-tab active" onclick="switchAuthTab('login')">Вход</button>
                <button class="auth-tab" onclick="switchAuthTab('register')">Регистрация</button>
            </div>

            <form method="POST" class="auth-form" id="loginForm">
                <input type="hidden" name="auth_action" value="login">
                <input type="text" placeholder="Имя пользователя" name="username" required>
                <input type="password" placeholder="Пароль" name="password" required>
                <div class="form-buttons">
                    <button type="submit" class="auth-btn">Войти</button>
                </div>
            </form>

            <form method="POST" class="auth-form" id="registerForm" style="display: none;">
                <input type="hidden" name="auth_action" value="register">
                <input type="text" placeholder="Имя пользователя" name="username" required>
                <input type="email" placeholder="Email" name="email" required>
                <input type="password" placeholder="Пароль" name="password" required>
                <input type="password" placeholder="Подтвердите пароль" name="confirm_password" required>
                <div class="form-buttons">
                    <button type="submit" class="auth-btn">Зарегистрироваться</button>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>О нас</h3>
                    <p>Мы - платформа для обмена отзывами о играх, фильмах и книгах. Наша цель - помочь людям находить качественный контент.</p>
                </div>
                <div class="footer-section">
                    <h3>Категории</h3>
                    <a href="?page=works&category=game">Игры</a>
                    <a href="?page=works&category=movie">Фильмы</a>
                    <a href="?page=works&category=book">Книги</a>
                </div>
                <div class="footer-section">
                    <h3>Контакты</h3>
                    <a href="mailto:info@reviews.ru">info@reviews.ru</a>
                    <a href="tel:+79991234567">+7 (999) 123-45-67</a>
                </div>
            </div>
            <div class="copyright">
                &copy; 2023 Record.ру. Все права защищены.
            </div>
        </div>
    </footer>

    <script>
        function showAuthModal(tab) {
            document.getElementById('authModal').style.display = 'block';
            switchAuthTab(tab);
        }

        function switchAuthTab(tab) {
            // Обновляем активные табы
            document.querySelectorAll('.auth-tab').forEach(button => {
                button.classList.remove('active');
            });
            event.target.classList.add('active');

            // Показываем соответствующую форму
            document.getElementById('loginForm').style.display = tab === 'login' ? 'block' : 'none';
            document.getElementById('registerForm').style.display = tab === 'register' ? 'block' : 'none';
        }

        function toggleComments(reviewId) {
            const commentsSection = document.getElementById('comments-' + reviewId);
            if (commentsSection.style.display === 'none') {
                commentsSection.style.display = 'block';
            } else {
                commentsSection.style.display = 'none';
            }
        }

        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('authModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }

        // Закрытие модального окна при успешной отправке формы
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('.auth-form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    setTimeout(() => {
                        document.getElementById('authModal').style.display = 'none';
                    }, 1000);
                });
            });
        });
    </script>
</body>
</html>