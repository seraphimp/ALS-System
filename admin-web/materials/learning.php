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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['material_file'])) {
    $strand_id = intval($_POST['strand_id']);
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $status = $conn->real_escape_string($_POST['status']);
    
    // File upload handling
    $target_dir = "../uploads/learning_materials/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = time() . '_' . basename($_FILES["material_file"]["name"]);
    $target_file = $target_dir . $file_name;
    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    $file_size = $_FILES["material_file"]["size"];
    
    // Check if file already exists
    if (file_exists($target_file)) {
        $error = "Sorry, file already exists.";
    } 
    // Check file size (max 20MB)
    elseif ($file_size > 20971520) {
        $error = "Sorry, your file is too large. Maximum size is 20MB.";
    }
    // Allow certain file formats
    elseif (!in_array($file_type, ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'mp4', 'avi', 'mov'])) {
        $error = "Sorry, only PDF, DOC, DOCX, PPT, PPTX, JPG, PNG, MP4, AVI & MOV files are allowed.";
    } 
    // Upload file
    elseif (move_uploaded_file($_FILES["material_file"]["tmp_name"], $target_file)) {
        // Determine file type category
        $file_type_category = 'other';
        if (in_array($file_type, ['pdf'])) $file_type_category = 'pdf';
        if (in_array($file_type, ['doc', 'docx'])) $file_type_category = 'doc';
        if (in_array($file_type, ['ppt', 'pptx'])) $file_type_category = 'ppt';
        if (in_array($file_type, ['jpg', 'jpeg', 'png'])) $file_type_category = 'image';
        if (in_array($file_type, ['mp4', 'avi', 'mov'])) $file_type_category = 'video';
        
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO learning_materials (strand_id, title, description, file_path, file_type, file_size, uploader_id, uploader_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $formatted_size = formatFileSize($file_size);
        $stmt->bind_param("isssssiss", $strand_id, $title, $description, $target_file, $file_type_category, $formatted_size, $user_id, $user_type, $status);
        
        if ($stmt->execute()) {
            $success = "The file " . htmlspecialchars(basename($_FILES["material_file"]["name"])) . " has been uploaded.";
        } else {
            $error = "Sorry, there was an error saving to database.";
            // Remove the uploaded file if database insert failed
            unlink($target_file);
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

// Get all learning strands
$strands = $conn->query("SELECT * FROM learning_strands WHERE status = 'active' ORDER BY strand_number")->fetch_all(MYSQLI_ASSOC);

// Get materials for each strand
$materials_by_strand = [];
foreach ($strands as $strand) {
    $strand_id = $strand['strand_id'];
    $materials_query = $conn->prepare("SELECT lm.*, ls.title as strand_title, ls.strand_number 
                                      FROM learning_materials lm 
                                      JOIN learning_strands ls ON lm.strand_id = ls.strand_id 
                                      WHERE lm.strand_id = ? AND lm.status = 'published'
                                      ORDER BY lm.uploaded_at DESC");
    $materials_query->bind_param("i", $strand_id);
    $materials_query->execute();
    $materials = $materials_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $materials_by_strand[$strand_id] = $materials;
    $materials_query->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Materials - ALS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .strand-card {
            border-left: 5px solid;
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .strand-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .material-card {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        .file-icon {
            font-size: 2rem;
            margin-right: 15px;
        }
        .accordion-button:not(.collapsed) {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        .upload-form {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <h2>ALS Learning Strand Materials</h2>
                <p class="text-muted">Access and manage learning materials for all 7 Learning Strands</p>

                <!-- Success/Error Messages -->
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Upload Form (for admin/teacher) -->
                <?php if ($user_type === 'admin' || $user_type === 'teacher'): ?>
                <div class="upload-form">
                    <h4><i class="fas fa-upload"></i> Upload New Material</h4>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="strand_id" class="form-label">Learning Strand</label>
                                    <select class="form-select" id="strand_id" name="strand_id" required>
                                        <option value="">Select Learning Strand</option>
                                        <?php foreach ($strands as $strand): ?>
                                        <option value="<?php echo $strand['strand_id']; ?>">
                                            <?php echo $strand['strand_number'] . ' - ' . $strand['title']; ?>
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
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="material_file" class="form-label">File</label>
                            <input class="form-control" type="file" id="material_file" name="material_file" required>
                            <div class="form-text">Accepted formats: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG, MP4, AVI, MOV. Max size: 20MB</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Material</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Learning Strands Accordion -->
                <div class="accordion" id="strandsAccordion">
                    <?php foreach ($strands as $strand): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading<?php echo $strand['strand_id']; ?>">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" 
                                    data-bs-target="#collapse<?php echo $strand['strand_id']; ?>" 
                                    aria-expanded="true" aria-controls="collapse<?php echo $strand['strand_id']; ?>"
                                    style="border-left: 5px solid <?php echo $strand['color_code']; ?>">
                                <strong><?php echo $strand['strand_number']; ?>: </strong>&nbsp;<?php echo $strand['title']; ?>
                                <span class="badge bg-secondary ms-2"><?php echo count($materials_by_strand[$strand['strand_id']]); ?> materials</span>
                            </button>
                        </h2>
                        <div id="collapse<?php echo $strand['strand_id']; ?>" class="accordion-collapse collapse show" 
                             aria-labelledby="heading<?php echo $strand['strand_id']; ?>" data-bs-parent="#strandsAccordion">
                            <div class="accordion-body">
                                <p class="text-muted"><?php echo $strand['description']; ?></p>
                                
                                <?php if (count($materials_by_strand[$strand['strand_id']]) > 0): ?>
                                    <div class="row">
                                        <?php foreach ($materials_by_strand[$strand['strand_id']] as $material): ?>
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <div class="material-card">
                                                <div class="d-flex">
                                                    <div class="flex-shrink-0">
                                                        <?php 
                                                        $icon_class = "fa-file";
                                                        $icon_color = "#6c757d";
                                                        
                                                        switch($material['file_type']) {
                                                            case 'pdf':
                                                                $icon_class = "fa-file-pdf";
                                                                $icon_color = "#dc3545";
                                                                break;
                                                            case 'doc':
                                                            case 'docx':
                                                                $icon_class = "fa-file-word";
                                                                $icon_color = "#0d6efd";
                                                                break;
                                                            case 'ppt':
                                                            case 'pptx':
                                                                $icon_class = "fa-file-powerpoint";
                                                                $icon_color = "#fd7e14";
                                                                break;
                                                            case 'image':
                                                                $icon_class = "fa-file-image";
                                                                $icon_color = "#20c997";
                                                                break;
                                                            case 'video':
                                                                $icon_class = "fa-file-video";
                                                                $icon_color = "#6f42c1";
                                                                break;
                                                        }
                                                        ?>
                                                        <i class="fas <?php echo $icon_class; ?> file-icon" style="color: <?php echo $icon_color; ?>"></i>
                                                    </div>
                                                    <div class="flex-grow-1 ms-3">
                                                        <h6><?php echo $material['title']; ?></h6>
                                                        <p class="text-muted small mb-1"><?php echo $material['description']; ?></p>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="badge bg-light text-dark"><?php echo $material['file_size']; ?></span>
                                                            <a href="<?php echo $material['file_path']; ?>" class="btn btn-sm btn-outline-primary" download>
                                                                <i class="fas fa-download"></i> Download
                                                            </a>
                                                        </div>
                                                        <small class="text-muted">Uploaded: <?php echo date('M j, Y', strtotime($material['uploaded_at'])); ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No materials available for this learning strand yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-open the first accordion item
        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.accordion-button')) {
                document.querySelector('.accordion-button').click();
            }
        });
    </script>
</body>
</html>