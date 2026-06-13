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


$user_type = isset($_SESSION['admin_id']) ? 'admin' : 'teacher';
$user_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : $_SESSION['teacher_id'];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['book_file'])) {
    $category_id = intval($_POST['category_id']);
    $title = $conn->real_escape_string($_POST['title']);
    $author = $conn->real_escape_string($_POST['author']);
    $description = $conn->real_escape_string($_POST['description']);
    $isbn = $conn->real_escape_string($_POST['isbn']);
    $publisher = $conn->real_escape_string($_POST['publisher']);
    $publish_year = intval($_POST['publish_year']);
    $status = $conn->real_escape_string($_POST['status']);
    
    // File upload handling
    $target_dir = "../uploads/books/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = time() . '_' . basename($_FILES["book_file"]["name"]);
    $target_file = $target_dir . $file_name;
    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    $file_size = $_FILES["book_file"]["size"];
    
    // Handle cover image upload
    $cover_image = null;
    if (!empty($_FILES["cover_image"]["name"])) {
        $cover_image_name = time() . '_' . basename($_FILES["cover_image"]["name"]);
        $target_cover = $target_dir . 'covers/' . $cover_image_name;
        $cover_type = strtolower(pathinfo($target_cover, PATHINFO_EXTENSION));
        
        // Create covers directory if it doesn't exist
        if (!file_exists($target_dir . 'covers/')) {
            mkdir($target_dir . 'covers/', 0777, true);
        }
        
        // Check if image file is an actual image
        $check = getimagesize($_FILES["cover_image"]["tmp_name"]);
        if ($check !== false) {
            // Allow certain file formats
            if (in_array($cover_type, ['jpg', 'jpeg', 'png', 'gif'])) {
                if (move_uploaded_file($_FILES["cover_image"]["tmp_name"], $target_cover)) {
                    $cover_image = $target_cover;
                }
            }
        }
    }
    
    // Check if file already exists
    if (file_exists($target_file)) {
        $error = "Sorry, file already exists.";
    } 
    // Check file size (max 50MB for books)
    elseif ($file_size > 52428800) {
        $error = "Sorry, your file is too large. Maximum size is 50MB.";
    }
    // Allow certain file formats
    elseif (!in_array($file_type, ['pdf', 'epub', 'doc', 'docx'])) {
        $error = "Sorry, only PDF, EPUB, DOC & DOCX files are allowed.";
    } 
    // Upload file
    elseif (move_uploaded_file($_FILES["book_file"]["tmp_name"], $target_file)) {
        // Determine file type category
        $file_type_category = 'other';
        if (in_array($file_type, ['pdf'])) $file_type_category = 'pdf';
        if (in_array($file_type, ['epub'])) $file_type_category = 'epub';
        if (in_array($file_type, ['doc', 'docx'])) $file_type_category = 'doc';
        
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO books (category_id, title, author, description, isbn, publisher, publish_year, file_path, cover_image, file_type, file_size, uploader_id, uploader_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $formatted_size = formatFileSize($file_size);
        $stmt->bind_param("isssssissssiss", $category_id, $title, $author, $description, $isbn, $publisher, $publish_year, $target_file, $cover_image, $file_type_category, $formatted_size, $user_id, $user_type, $status);
        
        if ($stmt->execute()) {
            $success = "The book '" . htmlspecialchars($title) . "' has been uploaded successfully.";
        } else {
            $error = "Sorry, there was an error saving to database: " . $stmt->error;
            // Remove the uploaded file if database insert failed
            unlink($target_file);
            if ($cover_image) unlink($cover_image);
        }
        $stmt->close();
    } else {
        $error = "Sorry, there was an error uploading your file.";
    }
}

// Function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}

// Get all categories
$categories = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Book - Book System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .upload-form {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .preview-container {
            display: none;
            margin-top: 10px;
        }
        .preview-image {
            max-width: 200px;
            max-height: 300px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <h2>Upload New Book</h2>
                
                <!-- Success/Error Messages -->
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="upload-form">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category_id" class="form-label">Category *</label>
                                    <select class="form-select" id="category_id" name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>">
                                            <?php echo $category['name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="published">Publish Immediately</option>
                                        <option value="draft">Save as Draft</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="title" class="form-label">Book Title *</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="author" class="form-label">Author *</label>
                            <input type="text" class="form-control" id="author" name="author" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="isbn" class="form-label">ISBN</label>
                                    <input type="text" class="form-control" id="isbn" name="isbn">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="publisher" class="form-label">Publisher</label>
                                    <input type="text" class="form-control" id="publisher" name="publisher">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="publish_year" class="form-label">Publish Year</label>
                                    <select class="form-select" id="publish_year" name="publish_year">
                                        <option value="">Select Year</option>
                                        <?php for ($year = date('Y'); $year >= 1900; $year--): ?>
                                        <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="cover_image" class="form-label">Cover Image</label>
                                    <input class="form-control" type="file" id="cover_image" name="cover_image" accept="image/*">
                                    <div class="form-text">JPG, PNG or GIF (optional)</div>
                                    <div class="preview-container" id="coverPreview">
                                        <img src="#" alt="Cover preview" class="preview-image mt-2">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="book_file" class="form-label">Book File *</label>
                            <input class="form-control" type="file" id="book_file" name="book_file" required>
                            <div class="form-text">Accepted formats: PDF, EPUB, DOC, DOCX. Max size: 50MB</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Book</button>
                        <a href="books.php" class="btn btn-secondary">Back to Books</a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cover image preview
        document.getElementById('cover_image').addEventListener('change', function(e) {
            const preview = document.getElementById('coverPreview');
            const previewImage = preview.querySelector('img');
            
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(this.files[0]);
            } else {
                preview.style.display = 'none';
            }
        });
    </script>
</body>
</html>