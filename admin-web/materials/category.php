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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = $conn->real_escape_string($_POST['name']);
        $description = $conn->real_escape_string($_POST['description']);
        $color_code = $conn->real_escape_string($_POST['color_code']);
        $status = $conn->real_escape_string($_POST['status']);
        
        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO categories (name, description, color_code, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $description, $color_code, $status);
            
            if ($stmt->execute()) {
                $success = "Category '$name' added successfully!";
            } else {
                $error = "Error adding category: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Category name is required.";
        }
    }
    
    if (isset($_POST['edit_category'])) {
        $category_id = intval($_POST['category_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $description = $conn->real_escape_string($_POST['description']);
        $color_code = $conn->real_escape_string($_POST['color_code']);
        $status = $conn->real_escape_string($_POST['status']);
        
        if (!empty($name)) {
            $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ?, color_code = ?, status = ? WHERE category_id = ?");
            $stmt->bind_param("ssssi", $name, $description, $color_code, $status, $category_id);
            
            if ($stmt->execute()) {
                $success = "Category updated successfully!";
            } else {
                $error = "Error updating category: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Category name is required.";
        }
    }
    
    if (isset($_POST['delete_category'])) {
        $category_id = intval($_POST['category_id']);
        
        // Check if category has books
        $check_stmt = $conn->prepare("SELECT COUNT(*) as book_count FROM books WHERE category_id = ?");
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($result['book_count'] > 0) {
            $error = "Cannot delete category. It contains " . $result['book_count'] . " book(s).";
        } else {
            $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
            $stmt->bind_param("i", $category_id);
            
            if ($stmt->execute()) {
                $success = "Category deleted successfully!";
            } else {
                $error = "Error deleting category: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Get all categories
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get category for editing if requested
$edit_category = null;
if (isset($_GET['edit'])) {
    $category_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $edit_category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - Book System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .category-card {
            border-left: 5px solid;
            transition: transform 0.3s;
            cursor: pointer;
        }
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 10px;
        }
        .category-link {
            text-decoration: none;
            color: inherit;
        }
        .book-count-badge {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <h2>Manage Book Categories</h2>
                
                <!-- Success/Error Messages -->
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- Add/Edit Category Form -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><?php echo $edit_category ? 'Edit Category' : 'Add New Category'; ?></h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php if ($edit_category): ?>
                                    <input type="hidden" name="category_id" value="<?php echo $edit_category['category_id']; ?>">
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Category Name *</label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo $edit_category ? $edit_category['name'] : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo $edit_category ? $edit_category['description'] : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="color_code" class="form-label">Color Code</label>
                                        <div class="input-group">
                                            <span class="input-group-text">#</span>
                                            <input type="text" class="form-control" id="color_code" name="color_code" 
                                                   value="<?php echo $edit_category ? $edit_category['color_code'] : '#0d6efd'; ?>" 
                                                   pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" placeholder="Hex color code">
                                        </div>
                                        <div class="form-text">Enter a hex color code (e.g., #0d6efd)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo ($edit_category && $edit_category['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($edit_category && $edit_category['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <?php if ($edit_category): ?>
                                        <button type="submit" name="edit_category" class="btn btn-primary">Update Category</button>
                                        <a href="category.php" class="btn btn-secondary">Cancel</a>
                                        <?php else: ?>
                                        <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Categories List -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5>Existing Categories</h5>
                                <p class="text-muted mb-0">Click on a category to view its books</p>
                            </div>
                            <div class="card-body">
                                <?php if (count($categories) > 0): ?>
                                <div class="row">
                                    <?php foreach ($categories as $category): 
                                    // Get book count for this category
                                    $count_stmt = $conn->prepare("SELECT COUNT(*) as book_count FROM books WHERE category_id = ?");
                                    $count_stmt->bind_param("i", $category['category_id']);
                                    $count_stmt->execute();
                                    $count_result = $count_stmt->get_result()->fetch_assoc();
                                    $book_count = $count_result['book_count'];
                                    $count_stmt->close();
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <a href="books.php?category=<?php echo $category['category_id']; ?>" class="category-link">
                                            <div class="card category-card" style="border-left-color: <?php echo $category['color_code']; ?>">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <h6 class="card-title mb-0">
                                                            <span class="color-preview" style="background-color: <?php echo $category['color_code']; ?>"></span>
                                                            <?php echo $category['name']; ?>
                                                        </h6>
                                                        <span class="badge bg-primary book-count-badge">
                                                            <?php echo $book_count; ?> book<?php echo $book_count != 1 ? 's' : ''; ?>
                                                        </span>
                                                    </div>
                                                    <p class="card-text text-muted small mt-2"><?php echo $category['description']; ?></p>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="badge bg-<?php echo $category['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($category['status']); ?>
                                                        </span>
                                                        <div>
                                                            <a href="category.php?edit=<?php echo $category['category_id']; ?>" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation()">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <form method="POST" style="display: inline-block;" onclick="event.stopPropagation()">
                                                                <input type="hidden" name="category_id" value="<?php echo $category['category_id']; ?>">
                                                                <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" 
                                                                        onclick="return confirm('Are you sure you want to delete this category?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> No categories found. Add your first category using the form.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Prevent card click when clicking on edit/delete buttons
        document.addEventListener('click', function(e) {
            if (e.target.closest('.btn') || e.target.closest('form')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    </script>
</body>
</html>