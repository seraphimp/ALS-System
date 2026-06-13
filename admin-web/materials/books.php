<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
// Check if user is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

// Get category filter
$category_id = isset($_GET['category']) ? intval($_GET['category']) : 0;
$category_name = "All Books";

// Build query based on category filter
if ($category_id > 0) {
    // Get category name
    $stmt = $conn->prepare("SELECT name FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $category_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($category_result) {
        $category_name = $category_result['name'];
    }
    
    // Get books for this category
    $books_query = $conn->prepare("
        SELECT b.*, c.name as category_name, c.color_code 
        FROM books b 
        JOIN categories c ON b.category_id = c.category_id 
        WHERE b.category_id = ? 
        ORDER BY b.title
    ");
    $books_query->bind_param("i", $category_id);
} else {
    // Get all books
    $books_query = $conn->prepare("
        SELECT b.*, c.name as category_name, c.color_code 
        FROM books b 
        JOIN categories c ON b.category_id = c.category_id 
        ORDER BY b.title
    ");
}

$books_query->execute();
$books = $books_query->get_result()->fetch_all(MYSQLI_ASSOC);
$books_query->close();

// Get all categories for the filter dropdown
$categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $category_name; ?> - Book System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .book-card {
            border-left: 5px solid;
            transition: transform 0.3s;
            height: 100%;
        }
        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .book-cover {
            height: 200px;
            object-fit: cover;
            width: 100%;
            border-radius: 5px;
        }
        .file-icon {
            font-size: 3rem;
            color: #6c757d;
        }
        .category-badge {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><?php echo $category_name; ?></h2>
                    <div>
                        <a href="upload.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Book
                        </a>
                        <a href="category.php" class="btn btn-outline-secondary">
                            <i class="fas fa-folder"></i> Manage Categories
                        </a>
                    </div>
                </div>

                <!-- Category Filter -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row align-items-center">
                            <div class="col-md-4">
                                <label for="category" class="form-label">Filter by Category:</label>
                                <select class="form-select" id="category" name="category" onchange="this.form.submit()">
                                    <option value="0" <?php echo $category_id == 0 ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_id == $cat['category_id'] ? 'selected' : ''; ?>>
                                        <?php echo $cat['name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="form-text">
                                    Showing <?php echo count($books); ?> book<?php echo count($books) != 1 ? 's' : ''; ?>
                                    <?php if ($category_id > 0): ?>
                                    in <?php echo $category_name; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (count($books) > 0): ?>
                <div class="row">
                    <?php foreach ($books as $book): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card book-card" style="border-left-color: <?php echo $book['color_code']; ?>">
                            <div class="card-body">
                                <?php if ($book['cover_image']): ?>
                                <img src="<?php echo $book['cover_image']; ?>" alt="Book cover" class="book-cover mb-3">
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <?php 
                                    $icon_class = "fa-file";
                                    
                                    switch($book['file_type']) {
                                        case 'pdf':
                                            $icon_class = "fa-file-pdf";
                                            break;
                                        case 'epub':
                                            $icon_class = "fa-book";
                                            break;
                                        case 'doc':
                                        case 'docx':
                                            $icon_class = "fa-file-word";
                                            break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?> file-icon"></i>
                                </div>
                                <?php endif; ?>
                                
                                <h5 class="card-title"><?php echo $book['title']; ?></h5>
                                <h6 class="card-subtitle mb-2 text-muted">by <?php echo $book['author']; ?></h6>
                                
                                <p class="card-text small"><?php echo strlen($book['description']) > 100 ? substr($book['description'], 0, 100) . '...' : $book['description']; ?></p>
                                
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge rounded-pill category-badge" style="background-color: <?php echo $book['color_code']; ?>; color: white;">
                                        <?php echo $book['category_name']; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $book['status'] == 'published' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($book['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted"><?php echo $book['file_size']; ?> • <?php echo strtoupper($book['file_type']); ?></small>
                                    <a href="<?php echo $book['file_path']; ?>" class="btn btn-sm btn-outline-primary" download>
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                                
                                <?php if ($book['publisher'] || $book['publish_year']): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <?php if ($book['publisher']) echo $book['publisher']; ?>
                                        <?php if ($book['publish_year']) echo ' (' . $book['publish_year'] . ')'; ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-2">
                                    <small class="text-muted">Uploaded: <?php echo date('M j, Y', strtotime($book['uploaded_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <h4>No books found</h4>
                    <p>
                        <?php if ($category_id > 0): ?>
                        No books available in this category yet.
                        <?php else: ?>
                        No books available yet.
                        <?php endif; ?>
                    </p>
                    <a href="upload.php" class="btn btn-primary">Upload Your First Book</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>