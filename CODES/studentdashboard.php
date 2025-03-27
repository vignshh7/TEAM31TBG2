<?php
ob_start();
session_start();

// Disable display errors to prevent them from corrupting JSON
ini_set('display_errors', 1); // Consider turning this off in production to avoid exposing potential vulnerabilities
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: index.php");
    exit();
}

// CSRF token generation
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Database connection
$conn = new mysqli('localhost', 'root', '', 'college_portal');
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    header("Location: error.php?message=database_error");
    exit();
}

$student_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT content, created_at FROM notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 10");
if (!$stmt) {
    error_log("Initial notifications query failed: " . $conn->error);
    $notifications = [];
} else {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
// Fetch username if not set
if (!isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $_SESSION['username'] = $stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown Student';
    $stmt->close();
}
// Fetch attendance records for the logged-in student
$attendance_data = [];
$marks = $conn->query("SELECT subject, score, grade, comments 
                       FROM marks 
                       WHERE student_id = $student_id")->fetch_all(MYSQLI_ASSOC);
// Step 1: Get the student's enrolled courses with course names
$enrolled_courses = [];
$stmt = $conn->prepare("SELECT ce.course_id, c.course_name 
                       FROM course_enrollments ce 
                       JOIN courses c ON ce.course_id = c.id
                       WHERE ce.student_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $enrolled_courses[$row['course_id']] = $row['course_name'];
    }
    $stmt->close();
} else {
    error_log("Failed to fetch enrolled courses: " . $conn->error);
}

// Step 2: Calculate attendance percentage and generate alerts
$threshold = 75; // Define attendance threshold (e.g., 75%)
foreach ($enrolled_courses as $course_id => $course_name) {
    // Count total days recorded for this course
    $stmt = $conn->prepare("SELECT COUNT(*) as total_days 
                           FROM attendance 
                           WHERE student_id = ? AND course_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $student_id, $course_id);
        $stmt->execute();
        $total_days = $stmt->get_result()->fetch_assoc()['total_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to count total days for course_id $course_id: " . $conn->error);
        $total_days = 0;
    }

    // Count days present for this course
    $stmt = $conn->prepare("SELECT COUNT(*) as present_days 
                           FROM attendance 
                           WHERE student_id = ? AND course_id = ? AND status = 'Present'");
    if ($stmt) {
        $stmt->bind_param("ii", $student_id, $course_id);
        $stmt->execute();
        $present_days = $stmt->get_result()->fetch_assoc()['present_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to count present days for course_id $course_id: " . $conn->error);
        $present_days = 0;
    }

    // Calculate attendance percentage
    $attendance_percentage = ($total_days > 0) ? round(($present_days / $total_days) * 100, 2) : 0;

    // Store the data
    $attendance_data[] = [
        'course_name' => $course_name,
        'total_days' => $total_days,
        'present_days' => $present_days,
        'attendance_percentage' => $attendance_percentage
    ];

    // Check if attendance is below threshold and generate alert
    if ($attendance_percentage < $threshold && $total_days > 0) {
        $message = "Warning: Your attendance in $course_name is $attendance_percentage%, below the required $threshold%.";
        
        // Check if alert already exists to avoid duplicates
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND content = ?");
        $stmt->bind_param("is", $student_id, $message);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();

        if (!$exists) {
            $stmt = $conn->prepare("INSERT INTO notifications (student_id, content) VALUES (?, ?)");
            $stmt->bind_param("is", $student_id, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
}
// Fetch initial notifications and lectures
$notifications = $conn->query("SELECT content, created_at FROM notifications ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$lectures = $conn->query("SELECT title, file_path FROM lectures ORDER BY uploaded_at DESC")->fetch_all(MYSQLI_ASSOC);

// Fetch initial personal details for the logged-in student
$personal_details = [];
$stmt = $conn->prepare("SELECT full_name, email, phone, address, date_of_birth, emergency_contact 
                       FROM student_details WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$personal_details = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();
// Fetch attendance records for the logged-in student
$attendance_data = [];

// Step 1: Get the student's enrolled courses with course names
$enrolled_courses = [];
$stmt = $conn->prepare("SELECT ce.course_id, c.course_name 
                       FROM course_enrollments ce 
                       JOIN courses c ON ce.course_id = c.id  -- Changed c.course_id to c.id
                       WHERE ce.student_id = ?  ");
if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $enrolled_courses[$row['course_id']] = $row['course_name'];
    }
    $stmt->close();
} else {
    error_log("Failed to fetch enrolled courses: " . $conn->error);
}

// Step 2: Calculate attendance percentage for each course
foreach ($enrolled_courses as $course_id => $course_name) {
    // Count total days recorded for this course
    $stmt = $conn->prepare("SELECT COUNT(*) as total_days 
                           FROM attendance 
                           WHERE student_id = ? AND course_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $student_id, $course_id);
        $stmt->execute();
        $total_days = $stmt->get_result()->fetch_assoc()['total_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to count total days for course_id $course_id: " . $conn->error);
        $total_days = 0;
    }

    // Count days present for this course
    $stmt = $conn->prepare("SELECT COUNT(*) as present_days 
                           FROM attendance 
                           WHERE student_id = ? AND course_id = ? AND status = 'Present'");
    if ($stmt) {
        $stmt->bind_param("ii", $student_id, $course_id);
        $stmt->execute();
        $present_days = $stmt->get_result()->fetch_assoc()['present_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to count present days for course_id $course_id: " . $conn->error);
        $present_days = 0;
    }

    // Calculate attendance percentage
    $attendance_percentage = ($total_days > 0) ? round(($present_days / $total_days) * 100, 2) : 0;

    // Store the data
    $attendance_data[] = [
        'course_name' => $course_name,
        'total_days' => $total_days,
        'present_days' => $present_days,
        'attendance_percentage' => $attendance_percentage
    ];
}
// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        $conn->close();
        exit();
    }

    switch ($_POST['action']) {
        case 'submit_assignment':
            ob_clean();
            header('Content-Type: application/json');
        
            // CSRF check
            if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                $conn->close();
                exit();
            }
        
            // Check if file is uploaded
            if (!isset($_FILES['assignment_file']) || $_FILES['assignment_file']['error'] === UPLOAD_ERR_NO_FILE) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                $conn->close();
                exit();
            }
        
            // Required fields
            $assignment_title = $_POST['assignment_title'] ?? '';
            $deadline = $_POST['deadline'] ?? '';
            if (empty($assignment_title) || empty($deadline)) {
                echo json_encode(['success' => false, 'message' => 'Missing assignment title or deadline']);
                $conn->close();
                exit();
            }
        
            // Validate deadline
            $deadline_time = new DateTime($deadline);
            $now = new DateTime();
            $status = ($now > $deadline_time) ? 'Late' : 'Pending';
        
            // Handle file upload
            $upload_dir = 'uploads/assignments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_name = uniqid() . '_' . basename($_FILES['assignment_file']['name']);
            $file_path = $upload_dir . $file_name;
        
            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $file_path)) {
                // Insert into database
                $stmt = $conn->prepare("
                    INSERT INTO assignment_submissions (student_id, assignment_title, file_path, deadline, status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("issss", $student_id, $assignment_title, $file_path, $deadline, $status);
                $success = $stmt->execute();
                $stmt->close();
        
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Assignment submitted successfully']);
                } else {
                    unlink($file_path); // Remove file if DB insert fails
                    echo json_encode(['success' => false, 'message' => 'Failed to save submission']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
            }
        
            $conn->close();
            exit();
            break;
            case 'get_assignments':
                $stmt = $conn->prepare("SELECT title, deadline FROM assignments WHERE deadline > NOW()");
                $stmt->execute();
                $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                ob_clean();
                echo json_encode(['success' => true, 'assignments' => $assignments]);
                $stmt->close();
                $conn->close();
                exit();
                break;
        case 'attendance_query':
            $stmt = $conn->prepare("INSERT INTO attendance_queries (student_id, query_text, status) VALUES (?, ?, 'Pending')");
            $stmt->bind_param("is", $student_id, $_POST['query']);
            ob_clean();
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Query submitted']);
            $stmt->close();
            $conn->close();
            exit();
            break;

        case 'submit_quiz':
            $score = count($_POST['answers']) * 10;
            $stmt = $conn->prepare("INSERT INTO quiz_attempts (student_id, quiz_id, score) VALUES (?, ?, ?)");
            $stmt->bind_param("iid", $student_id, $_POST['quiz_id'], $score);
            ob_clean();
            echo json_encode(['success' => $stmt->execute(), 'score' => $score]);
            $stmt->close();
            $conn->close();
            exit();
            break;
            case 'send_message':
                $receiver_id = $_POST['receiver_id'] ?? null;
                $message = $_POST['message'] ?? null;
                if (!$receiver_id || !$message) {
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'Missing receiver_id or message']);
                    $conn->close();
                    exit();
                }
                $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
                if (!$stmt) {
                    error_log("Prepare failed: " . $conn->error);
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'Database error']);
                    $conn->close();
                    exit();
                }
                $stmt->bind_param("iis", $student_id, $receiver_id, $message);
                $success = $stmt->execute();
                if (!$success) {
                    error_log("Execute failed: " . $stmt->error);
                }
                ob_clean();
                echo json_encode(['success' => $success, 'message' => $success ? 'Message sent' : 'Failed to send']);
                $stmt->close();
                $conn->close();
                exit();
                break;
            case 'update_personal_details':
                ob_clean(); // Clear any previous output
                // Validate required fields
                $required_fields = ['full_name', 'email', 'phone', 'address', 'date_of_birth', 'emergency_contact'];
                foreach ($required_fields as $field) {
                    if (!isset($_POST[$field])) {
                        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                        $conn->close();
                        exit();
                    }
                }
            
                // Validate phone and emergency_contact
                if (!is_numeric($_POST['phone']) || !is_numeric($_POST['emergency_contact'])) {
                    echo json_encode(['success' => false, 'message' => 'Phone and Emergency Contact must be numeric']);
                    $conn->close();
                    exit();
                }
            
                // Check if a row exists for the student_id
                $stmt = $conn->prepare("SELECT COUNT(*) FROM student_details WHERE student_id = ?");
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $row_count = $stmt->get_result()->fetch_row()[0];
                $stmt->close();
            
                if ($row_count == 0) {
                    // If no row exists, insert a new one
                    $stmt = $conn->prepare("INSERT INTO student_details (student_id, full_name, email, phone, address, date_of_birth, emergency_contact) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issssss", 
                        $student_id,
                        $_POST['full_name'],
                        $_POST['email'],
                        $_POST['phone'],
                        $_POST['address'],
                        $_POST['date_of_birth'],
                        $_POST['emergency_contact']
                    );
                } else {
                    // If a row exists, update it
                    $stmt = $conn->prepare("UPDATE student_details 
                                           SET full_name = ?, email = ?, phone = ?, address = ?, date_of_birth = ?, emergency_contact = ? 
                                           WHERE student_id = ?");
                    $stmt->bind_param("ssssssi", 
                        $_POST['full_name'],
                        $_POST['email'],
                        $_POST['phone'],
                        $_POST['address'],
                        $_POST['date_of_birth'],
                        $_POST['emergency_contact'],
                        $student_id
                    );
                }
            
                $success = $stmt->execute();
                if ($success === false) {
                    error_log("Execute failed: " . $stmt->error);
                    echo json_encode(['success' => false, 'message' => 'Failed to update details: ' . $stmt->error]);
                } else {
                    echo json_encode(['success' => true, 'message' => 'Details updated successfully']);
                }
                $stmt->close();
                $conn->close();
                exit();
                break;

        default:
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            $conn->close();
            exit();
            break;
    }
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'stream') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        $last_update = $_GET['last_update'] ?? 0;
        while (true) {
            $data = [
                
                'messages' => $conn->query("SELECT m.*, us.username AS sender_name, ur.username AS receiver_name FROM messages m JOIN users us ON m.sender_id = us.user_id JOIN users ur ON m.receiver_id = ur.user_id WHERE (m.receiver_id = $student_id OR m.sender_id = $student_id) AND UNIX_TIMESTAMP(m.timestamp) > $last_update ORDER BY m.timestamp DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'marks' => $conn->query("SELECT subject, score, grade, comments FROM marks WHERE student_id = $student_id AND UNIX_TIMESTAMP(updated_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'attendance' => $conn->query("SELECT date, status FROM attendance WHERE student_id = $student_id AND UNIX_TIMESTAMP(updated_at) > $last_update LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'spotlight' => $conn->query("SELECT content, created_at FROM spotlight WHERE UNIX_TIMESTAMP(created_at) > $last_update ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC),
                'notifications' => $conn->query("SELECT content, created_at FROM notifications WHERE student_id = $student_id AND UNIX_TIMESTAMP(created_at) > $last_update ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'quizzes' => $conn->query("SELECT id, title FROM quizzes WHERE assigned_to = $student_id AND id NOT IN (SELECT quiz_id FROM quiz_attempts WHERE student_id = $student_id) AND UNIX_TIMESTAMP(created_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'lectures' => $conn->query("SELECT title, file_path FROM lectures WHERE UNIX_TIMESTAMP(uploaded_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'timestamp' => time(),
            ];
            error_log("Stream Data: " . json_encode($data));
            if (array_filter($data, fn($v) => !empty($v))) {
                echo "data: " . json_encode($data) . "\n\n";
                ob_flush();
                flush();
            } else {
                echo "data: {\"timestamp\": " . time() . "}\n\n";
                ob_flush();
                flush();
            }
            sleep(1);
        }
        exit();
    }

    header('Content-Type: application/json');
    switch ($_GET['action']) {
        case 'get_messages':
            $stmt = $conn->prepare("SELECT m.*, us.username AS sender_name, ur.username AS receiver_name FROM messages m JOIN users us ON m.sender_id = us.user_id JOIN users ur ON m.receiver_id = ur.user_id WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) ORDER BY m.timestamp");
            $stmt->bind_param("iiii", $student_id, $_GET['other_user_id'], $_GET['other_user_id'], $student_id);
            $stmt->execute();
            ob_clean();
            echo json_encode(['success' => true, 'messages' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
            $stmt->close();
            $conn->close();
            exit();
            break;

        case 'get_spotlight':
            ob_clean();
            echo json_encode(['success' => true, 'spotlight' => $conn->query("SELECT content, created_at FROM spotlight ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC)]);
            $conn->close();
            exit();
            break;

            case 'get_marks':
                $stmt = $conn->prepare("SELECT subject, score, grade, comments FROM marks WHERE student_id = ?");
                if (!$stmt) {
                    error_log("Prepare failed: " . $conn->error);
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
                    $conn->close();
                    exit();
                }
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $marks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                error_log("Student ID: $student_id");
                error_log("Marks Query Result: " . json_encode($marks));
                ob_clean();
                echo json_encode(['success' => true, 'marks' => $marks]);
                $stmt->close();
                $conn->close();
                exit();
                break;
        case 'get_attendance':
            ob_clean();
            echo json_encode(['success' => true, 'attendance' => $conn->query("SELECT date, status FROM attendance WHERE student_id = $student_id LIMIT 10")->fetch_all(MYSQLI_ASSOC)]);
            $conn->close();
            exit();
            break;

        case 'get_quizzes':
            ob_clean();
            echo json_encode(['success' => true, 'quizzes' => $conn->query("SELECT id, title FROM quizzes WHERE assigned_to = $student_id AND id NOT IN (SELECT quiz_id FROM quiz_attempts WHERE student_id = $student_id)")->fetch_all(MYSQLI_ASSOC)]);
            $conn->close();
            exit();
            break;

        case 'get_notifications':
            ob_clean();
            echo json_encode(['success' => true, 'notifications' => $conn->query("SELECT content, created_at FROM notifications ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC)]);
            $conn->close();
            exit();
            break;

        case 'get_lectures':
            ob_clean();
            echo json_encode(['success' => true, 'lectures' => $conn->query("SELECT title, file_path FROM lectures")->fetch_all(MYSQLI_ASSOC)]);
            $conn->close();
            exit();
            break;

        case 'get_personal_details':
            if (isset($_GET['full_name'])) {
                // Search by full_name
                $stmt = $conn->prepare("SELECT full_name, email, phone, address, date_of_birth, emergency_contact 
                                       FROM student_details WHERE full_name = ?");
                $stmt->bind_param("s", $_GET['full_name']);
                $stmt->execute();
                $details = $stmt->get_result()->fetch_assoc() ?? [];
                ob_clean();
                echo json_encode(['success' => true, 'details' => $details]);
                $stmt->close();
            } else {
                // Default to current student_id
                $stmt = $conn->prepare("SELECT full_name, email, phone, address, date_of_birth, emergency_contact 
                                       FROM student_details WHERE student_id = ?");
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $details = $stmt->get_result()->fetch_assoc() ?? [];
                ob_clean();
                echo json_encode(['success' => true, 'details' => $details]);
                $stmt->close();
            }
            $conn->close();
            exit();
            break;

        default:
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            $conn->close();
            exit();
            break;

            case 'get_course_attendance':
                $student_id = $_SESSION['user_id'];
                $attendance_data = [];
                $enrolled_courses = [];
    
                // Step 1: Get the student's enrolled courses with course names
                $stmt = $conn->prepare("SELECT ce.course_id, c.course_name
                                       FROM course_enrollments ce
                                       JOIN courses c ON ce.course_id = c.id
                                       WHERE ce.student_id = ?"); // Corrected join
                if ($stmt) {
                    $stmt->bind_param("i", $student_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $enrolled_courses[] = [
                            'course_id' => $row['course_id'],
                            'course_name' => $row['course_name']
                        ];  // Store course ID and name
                    }
                    $stmt->close();
                } else {
                    error_log("Failed to fetch enrolled courses: " . $conn->error);
                    echo json_encode(['success' => false, 'message' => 'Failed to fetch enrolled courses']);
                    $conn->close();
                    exit;
                }
    
                // Step 2: Calculate attendance percentage for each course
                foreach ($enrolled_courses as $course) {
                    $course_id = $course['course_id'];
                    $course_name = $course['course_name']; // Retrieve course name
    
                    // Count total days recorded for this course
                    $stmt = $conn->prepare("SELECT COUNT(*) AS total_days
                                           FROM attendance
                                           WHERE student_id = ? AND course_id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ii", $student_id, $course_id);
                        $stmt->execute();
                        $total_days = $stmt->get_result()->fetch_assoc()['total_days'] ?? 0;
                        $stmt->close();
                    } else {
                        error_log("Failed to count total days for course_id $course_id: " . $conn->error);
                        $total_days = 0;
                    }
    
                    // Count days present for this course
                    $stmt = $conn->prepare("SELECT COUNT(*) AS present_days
                                           FROM attendance
                                           WHERE student_id = ? AND course_id = ? AND status = 'Present'");
                    if ($stmt) {
                        $stmt->bind_param("ii", $student_id, $course_id);
                        $stmt->execute();
                        $present_days = $stmt->get_result()->fetch_assoc()['present_days'] ?? 0;
                        $stmt->close();
                    } else {
                        error_log("Failed to count present days for course_id $course_id: " . $conn->error);
                        $present_days = 0;
                    }
    
                    // Calculate attendance percentage
                    $attendance_percentage = ($total_days > 0) ? round(($present_days / $total_days) * 100, 2) : 0;
    
                    // Store the data
                    $attendance_data[] = [
                        'course_name' => $course_name,
                        'total_days' => $total_days,
                        'present_days' => $present_days,
                        'attendance_percentage' => $attendance_percentage
                    ];
                }
    
                ob_clean();
                echo json_encode(['success' => true, 'course_attendance' => $attendance_data]);
                $conn->close();
                exit();
                break;
    }
}
// Fetch course materials for the logged-in student
$student_id = $_SESSION['user_id'];

// If materials are course-specific, get the student's enrolled courses
$enrolled_courses = [];
$stmt = $conn->prepare("SELECT course_id FROM course_enrollments WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $enrolled_courses[] = $row['course_id'];
}
$stmt->close();

// If the student is enrolled in courses, fetch materials for those courses
if (!empty($enrolled_courses)) {
    $course_ids = implode(',', array_map('intval', $enrolled_courses));
    $query = "SELECT title, file_path, type, uploaded_at 
              FROM lectures 
              WHERE course_id IN ($course_ids) 
              ORDER BY uploaded_at DESC";
} else {
    // If no course-specific filtering, fetch all materials (or handle as needed)
    $query = "SELECT title, file_path, type, uploaded_at 
              FROM lectures 
              ORDER BY uploaded_at DESC";
}

$materials = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

// Categorize materials by type
$syllabus = [];
$assignments = [];
$study_materials = [];

foreach ($materials as $material) {
    switch ($material['type']) {
        case 'syllabus':
            $syllabus[] = $material;
            break;
        case 'assignment':
            $assignments[] = $material;
            break;
        case 'study_material':
            $study_materials[] = $material;
            break;
    }
}
// Fetch faculty and alumni lists for rendering
$faculty_list = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'faculty'")->fetch_all(MYSQLI_ASSOC);
$alumni_list = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'alumni'")->fetch_all(MYSQLI_ASSOC);
?>

<!-- HTML and JavaScript sections would follow here -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Panel - DRS Institute of Technology</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Arial', sans-serif;
    }

    body {
        background: linear-gradient(160deg, #d6e6f2 0%, #a3bffa 50%, #7f9cf5 100%);
        color: #1a2a44;
        line-height: 1.8;
        overflow-x: hidden;
        position: relative;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"%3E%3Cpath fill="rgba(255,255,255,0.1)" fill-opacity="1" d="M0,160L48,176C96,192,192,224,288,213.3C384,203,480,149,576,138.7C672,128,768,160,864,181.3C960,203,1056,213,1152,197.3C1248,181,1344,139,1392,117.3L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"%3E%3C/path%3E%3C/svg%3E') no-repeat bottom;
        z-index: -1;
    }

    .header {
        position: fixed;
        top: 0;
        left: 70px;
        width: calc(100% - 70px);
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        padding: 15px 30px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        z-index: 2;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid rgba(45, 84, 136, 0.2);
        transition: all 0.3s ease;
    }

    .header.expanded {
        left: 250px;
        width: calc(100% - 250px);
    }

    .header h1 {
        font-size: 24px;
        color: #1a2a44;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 2px;
        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.1);
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .logout-btn {
        padding: 8px 15px;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: #fff;
        border: none;
        border-radius: 20px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.4s ease;
    }

    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
    }

    .sidenav {
        height: 100%;
        width: 70px;
        position: fixed;
        top: 0;
        left: 0;
        background: linear-gradient(180deg, #2c3e50, #1a252f);
        transition: width 0.3s ease;
        overflow-x: hidden;
        padding-top: 20px;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
        z-index: 3;
    }

    .sidenav.expanded {
        width: 250px;
    }

    .sidenav a {
        padding: 15px 8px 15px 20px;
        text-decoration: none;
        font-size: 16px;
        color: #ecf0f1;
        display: flex;
        align-items: center;
        transition: all 0.3s ease;
    }

    .sidenav a:hover {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: #fff;
    }

    .sidenav a.active {
        background: linear-gradient(135deg, #2980b9, #1e90ff);
        color: #fff;
        box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.2);
    }

    .sidenav a i {
        margin-right: 15px;
        min-width: 30px;
    }

    .sidenav .text {
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .sidenav.expanded .text {
        display: inline;
        opacity: 1;
    }

    .main {
        margin-left: 70px;
        padding: 80px 30px 30px;
        min-height: 100vh;
        transition: margin-left 0.3s ease;
        flex-grow: 1;
    }

    .main.expanded {
        margin-left: 250px;
    }

    .section {
        display: none;
        padding: 25px;
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(10px);
        border-radius: 25px;
        margin-bottom: 25px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.3);
        transition: all 0.5s ease;
    }

    .section.active {
        display: block;
        animation: fadeInUp 0.5s ease-out;
    }

    .section:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18);
        background: rgba(255, 255, 255, 0.9);
    }

    h2 {
        font-size: 30px;
        color: #2d5488;
        font-weight: 800;
        text-transform: uppercase;
        text-align: center;
        margin-bottom: 15px;
        position: relative;
    }

    h2::after {
        content: '';
        width: 60px;
        height: 4px;
        background: linear-gradient(to right, #63b3ed, #2d5488);
        position: absolute;
        bottom: -12px;
        left: 50%;
        transform: translateX(-50%);
        border-radius: 2px;
        transition: width 0.3s ease;
    }

    h2:hover::after {
        width: 80px;
    }

    #dashboard { border-left: 4px solid #e74c3c; }
    #spotlight, #content, #exams { border-left: 4px solid #f1c40f; }
    #marks { border-left: 4px solid #9b59b6; }
    #attendance { border-left: 4px solid #e9c46a; }
    #progress, #lectures { border-left: 4px solid #2ecc71; }
    #notifications, #calendar, #contact-faculty { border-left: 4px solid #e67e22; }
    #mentorship { border-left: 4px solid #264653; }
    #contact-alumni { border-left: 4px solid #d35400; }
    #internship-jobs { border-left: 4px solid #16a085; }
    #academic-wallet { border-left: 4px solid #8e44ad; }
    #code-platform { border-left: 4px solid #e67e22; }
    #study-streak { border-left: 4px solid #ff6f61; }
    #digital-id { border-left: 4px solid #1abc9c; }
    #personal-details { border-left: 4px solid #3498db; }

    #personal-details input, #personal-details textarea {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border: 1px solid rgba(45, 84, 136, 0.3);
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.7);
        transition: all 0.3s ease;
    }

    #personal-details input:focus, #personal-details textarea:focus {
        border-color: #2d5488;
        background: rgba(255, 255, 255, 0.9);
        outline: none;
        box-shadow: 0 0 8px rgba(45, 84, 136, 0.2);
    }

    #personal-details label {
        display: block;
        margin-top: 10px;
        font-weight: 700;
        color: #1a2a44;
    }

    .chat-window {
        position: fixed;
        bottom: 80px;
        right: 30px;
        width: 300px;
        height: 400px;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        display: none;
        flex-direction: column;
        z-index: 1001;
        transform: scale(0.8);
        opacity: 0;
        transition: transform 0.3s ease, opacity 0.3s ease;
    }

    .chat-window.active {
        display: flex;
        transform: scale(1);
        opacity: 1;
    }

    .chat-header {
        background: linear-gradient(135deg, #e67e22, #d35400);
        color: #fff;
        padding: 10px;
        border-radius: 20px 20px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .chat-header button {
        background: none;
        border: none;
        color: #fff;
        cursor: pointer;
        font-size: 16px;
        transition: color 0.3s ease;
    }

    .chat-header button:hover {
        color: #ffebcd;
    }

    .chat-body {
        flex-grow: 1;
        padding: 10px;
        overflow-y: auto;
    }

    .chat-footer {
        padding: 10px;
        border-top: 1px solid rgba(45, 84, 136, 0.2);
        display: flex;
        gap: 10px;
    }

    .chat-footer input {
    width: 70%;
    padding: 8px;
    border: 1px solid rgba(45, 84, 136, 0.3);
    border-radius:  Gabriella:
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.7);
    transition: all 0.3s ease;
    pointer-events: auto;
}

    .chat-footer input:focus {
        border-color: #2d5488;
        background: rgba(255, 255, 255, 0.9);
        outline: none;
    }

    .chat-footer button {
        padding: 8px 15px;
        background: linear-gradient(135deg, #e67e22, #d35400);
        color: #fff;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.4s ease;
    }

    .chat-footer button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(230, 126, 34, 0.3);
    }

    .faculty-item, .alumni-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding: 10px;
        background: rgba(255, 255, 255, 0.7);
        border-radius: 15px;
        transition: all 0.3s ease;
    }

    .faculty-item:hover, .alumni-item:hover {
        background: rgba(255, 255, 255, 0.9);
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .message-btn {
        padding: 8px 15px;
        background: linear-gradient(135deg, #e67e22, #d35400);
        color: #fff;
        border: none;
        border-radius: 15px;
        cursor: pointer;
        transition: all 0.4s ease;
    }

    .message-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(230, 126, 34, 0.3);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        background: rgba(255, 255, 255, 0.7);
        border-radius: 15px;
        overflow: hidden;
    }

    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(45, 84, 136, 0.2);
    }

    th {
        background: linear-gradient(135deg, #2d5488, #63b3ed);
        color: #fff;
        text-transform: uppercase;
        font-weight: 700;
    }

    tr:nth-child(even) {
        background: rgba(240, 247, 255, 0.5);
    }

    tr:hover {
        background: rgba(255, 255, 255, 0.9);
    }

    .button {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: #fff;
        padding: 10px 20px;
        border: none;
        border-radius: 15px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.4s ease;
        margin-top: 15px;
    }

    .button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
    }

    .progress-bar {
        width: 100%;
        background: rgba(45, 84, 136, 0.2);
        border-radius: 10px;
        overflow: hidden;
        margin-top: 10px;
    }

    .progress {
        height: 20px;
        background: linear-gradient(135deg, #2ecc71, #27ae60);
        text-align: center;
        color: #fff;
        line-height: 20px;
        transition: width 0.5s ease;
    }

    .CodeMirror {
        height: 300px;
        font-size: 14px;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .code-output {
        padding: 15px;
        background: rgba(240, 247, 255, 0.8);
        border-radius: 15px;
        margin-top: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .id-card {
        width: 300px;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        padding: 20px;
        text-align: center;
        transition: all 0.5s ease;
    }

    .id-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.18);
    }

    .id-header {
        background: linear-gradient(135deg, #1abc9c, #16a085);
        color: #fff;
        padding: 10px;
        border-radius: 15px 15px 0 0;
    }

    .qrcode {
        width: 150px;
        height: 150px;
        margin: 15px auto;
    }

    .id-footer {
        background: rgba(236, 240, 241, 0.8);
        padding: 10px;
        border-radius: 0 0 15px 15px;
    }

    footer {
        background: linear-gradient(160deg, #d6e6f2 0%, #a3bffa 70%, #7f9cf5 100%);
        text-align: center;
        padding: 15px 20px;
        margin-top: 20px;
        border-top: 3px solid rgba(45, 84, 136, 0.3);
        box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.1);
        border-radius: 15px 15px 0 0;
        position: relative;
    }

    footer p {
        font-size: 15px;
        color: #1a2a44;
        margin: 8px 0;
        font-weight: 500;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.05);
    }

    /* Animation Keyframes */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .header {
            left: 0;
            width: 100%;
            padding: 10px 15px;
        }

        .sidenav {
            width: 0;
        }

        .sidenav.expanded {
            width: 200px;
        }

        .main {
            margin-left: 0;
            padding: 60px 15px 15px;
        }

        .main.expanded {
            margin-left: 200px;
        }

        .section {
            padding: 15px;
        }

        .chat-window {
            width: 90%;
            right: 5%;
            bottom: 60px;
        }

        h2 {
            font-size: 24px;
        }
    }<style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --accent: #e67e22;
            --light-bg: #f5f7fa;
            --white: #ffffff;
            --text: #333333;
            --border: #e0e4e8;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--light-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .header {
            position: fixed;
            top: 0;
            left: 70px;
            width: calc(100% - 70px);
            background: var(--white);
            padding: 20px 30px;
            box-shadow: var(--shadow);
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .header.expanded {
            left: 250px;
            width: calc(100% - 250px);
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            color: var(--primary);
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header .admin-name {
            font-size: 16px;
            color: var(--text);
        }

        .header .logout-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--white);
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        .header .logout-btn:hover {
            background: #d35400;
        }

        .sidenav {
            height: 100%;
            width: 70px;
            position: fixed;
            z-index: 11;
            top: 0;
            left: 0;
            background: linear-gradient(180deg, var(--primary), #1a252f);
            transition: var(--transition);
            padding-top: 20px;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.15);
        }

        .sidenav.expanded {
            width: 250px;
        }

        .sidenav a {
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
            color: #ecf0f1;
            display: flex;
            align-items: center;
            transition: var(--transition);
        }

        .sidenav a:hover, .sidenav a.active {
            background: var(--secondary);
            color: var(--white);
        }

        .sidenav a i {
            min-width: 30px;
            margin-right: 15px;
        }

        .sidenav .text {
            display: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .sidenav.expanded .text {
            display: inline;
            opacity: 1;
        }

        .main {
            margin-left: 70px;
            padding: 90px 30px 30px;
            transition: var(--transition);
            min-height: 100vh;
        }

        .main.expanded {
            margin-left: 250px;
        }

        .section {
            display: none;
            padding: 30px;
            background: var(--white);
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--accent);
        }

        .section.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        #home {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: var(--white);
            border-left: none;
        }

        h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--primary);
        }

        h3 {
            font-size: 18px;
            font-weight: 500;
            margin: 20px 0 10px;
            color: var(--text);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
            color: var(--text);
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--secondary);
            outline: none;
        }

        .button {
            padding: 10px 20px;
            background: var(--accent);
            color: var(--white);
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .button:hover {
            background: #d35400;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--white);
            border-radius: 5px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--primary);
            color: var(--white);
            font-weight: 600;
        }

        tr:hover {
            background: #f9fbfd;
        }

        .alert {
            background: #fef2f2;
            color: #dc2626;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #dc2626;
        }

        .id-card {
            width: 320px;
            background: var(--white);
            border-radius: 10px;
            box-shadow: var(--shadow);
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }

        .id-header {
            background: var(--secondary);
            color: var(--white);
            padding: 15px;
            border-radius: 10px 10px 0 0;
            margin: -20px -20px 20px;
        }

        .qrcode {
            width: 150px;
            height: 150px;
            margin: 0 auto;
        }

        .id-footer {
            background: var(--light-bg);
            padding: 10px;
            border-radius: 0 0 10px 10px;
            margin: 20px -20px -20px;
            font-size: 12px;
            color: #666;
        }

        @media (max-width: 768px) {
            .sidenav { width: 0; }
            .main { margin-left: 0; }
            .header { left: 0; width: 100%; }
            .sidenav.expanded { width: 200px; }
            .main.expanded { margin-left: 200px; }
            .header.expanded { left: 200px; width: calc(100% - 200px); }
            .section { padding: 20px; }
            h2 { font-size: 20px; }
            table { font-size: 12px; }
        }
    </style>
    <style>

        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#f0f4f8;color:#2c3e50;}
        .header{position:fixed;top:0;left:70px;width:calc(100% - 70px);background:#fff;padding:15px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1);z-index:2;display:flex;justify-content:space-between;align-items:center;}
        .header.expanded{left:250px;width:calc(100% - 250px);}
        .header h1{font-size:24px;color:#2c3e50;}
        .user-info{display:flex;align-items:center;gap:15px;}
        .logout-btn{padding:8px 15px;background:#e74c3c;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        .logout-btn:hover{background:#c0392b;}
        .sidenav{height:100%;width:70px;position:fixed;top:0;left:0;background:linear-gradient(180deg,#2c3e50,#1a252f);transition:0.3s;overflow-x:hidden;padding-top:20px;box-shadow:2px 0 10px rgba(0,0,0,0.1);}
        .sidenav.expanded{width:250px;}
        .sidenav a{padding:15px 8px 15px 20px;text-decoration:none;font-size:16px;color:#ecf0f1;display:flex;align-items:center;}
        .sidenav a:hover{background:#3498db;}
        .sidenav a.active{background:#2980b9;}
        .sidenav a i{margin-right:15px;min-width:30px;}
        .sidenav .text{display:none;opacity:0;}
        .sidenav.expanded .text{display:inline;opacity:1;}
        .main{margin-left:70px;padding:80px 30px 30px;min-height:100vh;}
        .main.expanded{margin-left:250px;}
        .section{display:none;padding:25px;background:#fff;border-radius:10px;margin-bottom:25px;box-shadow:0 4px 15px rgba(0,0,0,0.05);}
        .section.active{display:block;animation:fadeIn 0.5s;}
        @keyframes fadeIn{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        #dashboard{border-left:4px solid #e74c3c;}
        #spotlight,#content,#exams{border-left:4px solid #f1c40f;}
        #marks{border-left:4px solid #9b59b6;}
        #attendance{border-left:4px solid #e9c46a;}
        #progress,#lectures{border-left:4px solid #2ecc71;}
        #notifications,#calendar,#contact-faculty{border-left:4px solid #e67e22;}
        #mentorship{border-left:4px solid #264653;}
        #contact-alumni{border-left:4px solid #d35400;}
        #internship-jobs{border-left:4px solid #16a085;}
        #academic-wallet{border-left:4px solid #8e44ad;}
        #code-platform{border-left:4px solid #e67e22;}
        #study-streak{border-left:4px solid #ff6f61;}
        #digital-id{border-left:4px solid #1abc9c;}
        #personal-details { border-left: 4px solid #3498db; }
        #personal-details input, #personal-details textarea {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        #personal-details label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        .chat-window{position:fixed;bottom:80px;right:30px;width:300px;height:400px;background:#fff;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,0.2);display:none;flex-direction:column;z-index:1001;transform:scale(0.8);opacity:0;transition:transform 0.3s,opacity 0.3s;}
        .chat-window.active{display:flex;transform:scale(1);opacity:1;}
        .chat-header{background:#e67e22;color:#fff;padding:10px;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;}
        .chat-header button{background:none;border:none;color:#fff;cursor:pointer;}
        .chat-body{flex-grow:1;padding:10px;overflow-y:auto;}
        .chat-footer{padding:10px;border-top:1px solid #ddd;}
        .chat-footer input{width:70%;padding:5px;border:1px solid #ddd;border-radius:5px;}
        .chat-footer button{padding:5px 10px;background:#e67e22;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        .faculty-item,.alumni-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
        .message-btn{padding:5px 10px;background:#e67e22;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th,td{padding:10px;border:1px solid #ddd;text-align:left;}
        th{background:#e67e22;color:#fff;}
        tr:nth-child(even){background:#f9f9f9;}
        .button{background:#e74c3c;color:#fff;padding:10px 20px;border:none;border-radius:5px;cursor:pointer;margin-top:10px;}
        .button:hover{background:#c0392b;}
        .progress-bar{width:100%;background:#ddd;border-radius:5px;overflow:hidden;margin-top:10px;}
        .progress{height:20px;background:#2ecc71;text-align:center;color:#fff;line-height:20px;}
        .CodeMirror{height:300px;font-size:14px;}
        .code-output{padding:15px;background:#f9f9f9;border-radius:5px;margin-top:15px;}
        .id-card{width:300px;background:#fff;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,0.1);padding:20px;text-align:center;}
        .id-header{background:#1abc9c;color:#fff;padding:10px;}
        .qrcode{width:150px;height:150px;margin:0 auto;}
        .id-footer{background:#ecf0f1;padding:10px;}
    </style>
</head>
<body>
    <div class="header" id="myHeader">
        <h1 id="headerTitle">Student Panel - DRS Institute of Technology</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <button class="logout-btn" onclick="logout()">Log Out</button>
        </div>
    </div>

    <div class="sidenav" id="mySidenav">
        <a href="#personal-details" class="nav-link"><i class="fas fa-user"></i><span class="text">Personal Details</span></a>
        <a href="#attendance" class="nav-link"><i class="fas fa-user-check"></i><span class="text">Attendance</span></a>
        <a href="#spotlight" class="nav-link"><i class="fas fa-star"></i><span class="text">Spotlight</span></a>
        <a href="#course-materials" class="nav-link"><i class="fas fa-folder-open"></i><span class="text">Course Materials</span></a>
        <a href="#marks" class="nav-link"><i class="fas fa-edit"></i><span class="text">Marks</span></a>
        <a href="#content" class="nav-link"><i class="fas fa-book"></i><span class="text">Course Content</span></a>
        <a href="#notifications" class="nav-link"><i class="fas fa-bell"></i><span class="text">Notifications</span></a>
        <a href="#lectures" class="nav-link"><i class="fas fa-video"></i><span class="text">Lectures</span></a>
        <a href="#contact-faculty" class="nav-link"><i class="fas fa-envelope"></i><span class="text">Contact Faculty</span></a>
        
    </div>
        
    <div class="main" id="mainContent">

        <div id="personal-details" class="section">
            <h2>Personal Details</h2>
            <form id="personalDetailsForm" onsubmit="updatePersonalDetails(event)">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div>
                        <label>Full Name:</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($personal_details['full_name'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label>Email:</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($personal_details['email'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label>Phone:</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($personal_details['phone'] ?? ''); ?>" pattern="[0-9]{10}" required>
                    </div>
                    <div>
                        <label>Date of Birth:</label>
                        <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($personal_details['date_of_birth'] ?? ''); ?>" required>
                    </div>
                    <div style="grid-column: span 2;">
                        <label>Address:</label>
                        <textarea name="address" rows="3"><?php echo htmlspecialchars($personal_details['address'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label>Emergency Contact:</label>
                        <input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($personal_details['emergency_contact'] ?? ''); ?>" pattern="[0-9]{10}" required>
                    </div>
                </div>
                <button type="submit" class="button" style="margin-top: 15px;">Update Details</button>
            </form>
        </div>

        <div id="notifications" class="section">
    <h2>Notifications</h2>
    <ul id="notifList">
        <?php if (empty($notifications)): ?>
            <li>No notifications available</li>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <li class="<?php echo strpos($n['content'], 'Warning') === 0 ? 'warning' : ''; ?>">
                    <?php echo htmlspecialchars($n['content']); ?> (<?php echo (new DateTime($n['created_at']))->format('Y-m-d H:i:s'); ?>)
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>
    
    <div class="section" id="course-materials">
    <h2 >Course Materials</h2>
    <?php 
    // Combine all materials into one array with a 'type' field
    $all_materials = [];
    if (!empty($syllabus)) {
        foreach ($syllabus as $material) {
            $all_materials[] = array_merge($material, ['type' => 'Syllabus']);
        }
    }
    if (!empty($assignments)) {
        foreach ($assignments as $material) {
            $all_materials[] = array_merge($material, ['type' => 'Assignment']);
        }
    }
    if (!empty($study_materials)) {
        foreach ($study_materials as $material) {
            $all_materials[] = array_merge($material, ['type' => 'Study Material']);
        }
    }
    ?>

    <?php if (!empty($all_materials)): ?>
        <ul>
            <?php foreach ($all_materials as $material): ?>
                <li>
                    <?php echo htmlspecialchars($material['type']); ?>: 
                    <?php echo htmlspecialchars($material['title']); ?> 
                    (Uploaded: <?php echo htmlspecialchars($material['uploaded_at']); ?>)
                    - <a href="<?php echo htmlspecialchars($material['file_path']); ?>" download>Download</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No course materials available.</p>
    <?php endif; ?>
</div>



<div id="attendance" class="section">
    <h2>Attendance</h2>
    <!-- Course-wise Attendance Table -->
    <h3>Course-wise Attendance</h3>
    <?php if (!empty($attendance_data)): ?>
        <table id="courseAttendanceTable">
            <thead>
                <tr>
                    <th>Course Name</th>
                    <th>Total Days</th>
                    <th>Days Present</th>
                    <th>Attendance Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendance_data as $record): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($record['course_name']); ?></td>
                        <td><?php echo $record['total_days']; ?></td>
                        <td><?php echo $record['present_days']; ?></td>
                        <td style="color: <?php echo $record['attendance_percentage'] < 50 ? 'red' : 'green'; ?>;">
                            <?php echo $record['attendance_percentage']; ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No course-wise attendance records available.</p>
    <?php endif; ?>
    </div>
        <div id="dashboard" class="section active">
            <h2>Dashboard</h2>
            <table>
                <tr><th>Metric</th><th>Value</th></tr>
                <tr><td>Classes Today</td><td>2</td></tr>
                <tr><td>Pending Assignments</td><td>3</td></tr>
            </table>
        </div>

        <div id="spotlight" class="section">
            <h2>Spotlight</h2>
            <ul id="spotlightList"></ul>
        </div>
        <div id="marks" class="section">
    <h2>Marks</h2>
    <table id="marksTable">
        <tr><th>Subject</th><th>Score</th>
        <?php foreach ($marks as $m): ?>
            <tr>
                <td><?php echo htmlspecialchars($m['subject']); ?></td>
                <td><?php echo $m['score']; ?>/100</td>
                <td><?php echo htmlspecialchars($m['grade'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($m['comments'] ?? 'No comments'); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p id="noMarksMessage" style="display: <?php echo empty($marks) ? 'block' : 'none'; ?>;">No marks available.</p>
</div>
       
        <div id="submit-assignments" class="section">
    <h2>Submit Assignments</h2>
    <form id="assignmentForm" enctype="multipart/form-data" onsubmit="submitAssignment(event)">
        <div style="margin-bottom: 15px;">
            <label for="assignment_title">Assignment Title:</label>
            <select name="assignment_title" id="assignment_title" required>
                <option value="">Select an assignment</option>
                <?php
                // Fetch available assignments (example)
                $stmt = $conn->prepare("SELECT title, deadline FROM assignments WHERE deadline > NOW()");
                $stmt->execute();
                $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($assignments as $assignment) {
                    echo "<option value='" . htmlspecialchars($assignment['title']) . "' data-deadline='" . htmlspecialchars($assignment['deadline']) . "'>" . htmlspecialchars($assignment['title']) . " (Due: " . htmlspecialchars($assignment['deadline']) . ")</option>";
                }
                ?>
            </select>
        </div>
        <div style="margin-bottom: 15px;">
            <label for="assignment_file">Upload File:</label>
            <input type="file" name="assignment_file" id="assignment_file" accept=".pdf,.doc,.docx" required>
        </div>
        <input type="hidden" name="deadline" id="deadline">
        <button type="submit" class="button">Submit Assignment</button>
    </form>
    <p id="submissionMessage" style="margin-top: 10px; display: none;"></p>
</div>
        <div id="progress" class="section">
            <h2>Progress</h2>
            <table id="progressTable">
                <tr><th>Subject</th><th>Progress</th></tr>
            </table>
        </div>

        <div id="content" class="section">
            <h2>Course Content</h2>
            <ul id="quizList"></ul>
        </div>

     

        <div id="lectures" class="section">
            <h2>Lectures</h2>
            <ul id="lectureList">
                <?php foreach ($lectures as $l): ?>
                    <li><?php echo htmlspecialchars($l['title']); ?> - <button class="button" onclick="window.open('<?php echo htmlspecialchars($l['file_path']); ?>','_blank')">Play</button></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div id="contact-faculty" class="section">
            <h2>Contact Faculty</h2>
            <?php foreach ($faculty_list as $f): ?>
                <div class="faculty-item">
                    <span><?php echo htmlspecialchars($f['username']); ?></span>
                    <button class="message-btn" onclick="openChat(<?php echo $f['user_id']; ?>,'<?php echo htmlspecialchars($f['username']); ?>')">Message</button>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="contact-alumni" class="section">
            <h2>Contact Alumni</h2>
            <?php foreach ($alumni_list as $a): ?>
                <div class="alumni-item">
                    <span><?php echo htmlspecialchars($a['username']); ?></span>
                    <button class="message-btn" style="background:#d35400;" onclick="openChat(<?php echo $a['user_id']; ?>,'<?php echo htmlspecialchars($a['username']); ?>')">Message</button>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="code-platform" class="section">
            <h2>Code Platform</h2>
            <select id="codeLanguage" onchange="updateCodeEditor()">
                <option value="c">C</option>
                <option value="cpp">C++</option>
                <option value="javascript">JavaScript</option>
                <option value="python">Python</option>
            </select>
            <button class="button" onclick="runCode()">Run</button>
            <div class="code-editor">
                <textarea id="codeEditor"></textarea>
            </div>
            <div class="code-output">
                <h3>Output</h3>
                <div id="codeOutput"></div>
            </div>
        </div>

        <div id="digital-id" class="section">
            <h2>Digital ID Card</h2>
            <div class="id-card">
                <div class="id-header">
                    <h3><?php echo htmlspecialchars($_SESSION['username']); ?></h3>
                    <p>ID: DRS<?php echo $student_id; ?></p>
                </div>
                <div id="qrcode" class="qrcode"></div>
                <div class="id-footer">
                    <p>DRS Institute of Technology</p>
                    <p>Valid Until: March 31, 2026</p>
                </div>
            </div>
            <button class="button" onclick="downloadIdCard()">Download</button>
        </div>
    </div>

    <div class="chat-window" id="chatWindow">
        <div class="chat-header"><span id="chatTitle"></span><button onclick="closeChat()">✖</button></div>
        <div class="chat-body" id="chatBody"></div>
        <div class="chat-footer"><input type="text" id="chatInput" placeholder="Type a message..."><button onclick="sendMessage()">Send</button></div>
    </div>

    <div class="chat-bot" id="tarsChatTrigger">
        <i class="fas fa-comment"></i>
    </div>



    <footer><p>© 2025 DRS Institute Of Technology. All rights reserved.</p></footer>

    <script>
    let recipientId = '', codeEditor, eventSource, lastUpdate = 0, lastMessageId = 0;

    document.getElementById('mySidenav').addEventListener('click', e => {
        if (e.target.closest('.nav-link')) toggleNav();
    });

    document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', e => {
        e.preventDefault();
        const id = l.getAttribute('href').slice(1);
        setActiveSection(id);
    }));

    function toggleNav() {
        ['mySidenav', 'mainContent', 'myHeader'].forEach(id => document.getElementById(id).classList.toggle('expanded'));
    }

    function setActiveSection(id) {
        document.querySelectorAll('.nav-link').forEach(n => n.classList.toggle('active', n.getAttribute('href') === `#${id}`));
        document.querySelectorAll('.section').forEach(s => s.classList.toggle('active', s.id === id));
        document.getElementById('headerTitle').textContent = `Student Panel - DRS Institute of Technology - ${document.getElementById(id).querySelector('h2').textContent}`;
    }
    fetch('?action=get_marks')
    .then(r => r.json())
    .then(d => {
        console.log('Fetched Marks Data:', d);
        updateDisplay('marks', d.marks);
    });
    function updateDisplay(target, data) {
    const displays = {
        spotlight: d => `<li>${d.content} (${new Date(d.created_at).toLocaleString()})</li>`,
        marks: d => {
    console.log('Rendering mark:', d);
    return `<tr>
        <td>${d.subject || 'N/A'}</td>
        <td>${d.score || 0}/100</td>
        <td>${d.grade || 'N/A'}</td>
        <td>${d.comments || 'No comments'}</td>
    </tr>`;
},
        attendance: d => `<tr><td>${d.date || 'N/A'}</td><td>${d.status || 'Unknown'}</td></tr>`,
        progress: d => `<tr><td>${d.subject || 'N/A'}</td><td><div class="progress-bar"><div class="progress" style="width:${d.score || 0}%">${d.score || 0}%</div></div></td></tr>`,
        content: d => `<li>${d.title || 'Untitled'} - <button class="button" onclick="startQuiz(${d.id || 0})">Start</button></li>`,
        notifications: d => `<li class="${d.content.startsWith('Warning') ? 'warning' : ''}">${d.content || 'No content'} (${new Date(d.created_at).toLocaleString()})</li>`,
        lectures: d => `<li>${d.title || 'Untitled'} - <button class="button" onclick="window.open('${d.file_path || '#'}','_blank')">Play</button></li>`,
        messages: d => `
            <div class="message ${d.sender_id == <?php echo $student_id ?> ? 'sent' : 'received'}">
                <strong>${d.sender_name || 'Unknown'}:</strong> ${d.message || 'No message'}
                <small>(${new Date(d.timestamp).toLocaleString()})</small>
            </div>`
    };

    const el = document.getElementById(`${target}List`) || document.getElementById(`${target}Table`) || document.getElementById('chatBody');
    if (!el || !displays[target]) {
        console.error(`Failed to update ${target}: Element or display function missing`, { el, displays });
        return;
    }

    const isTable = el.tagName === 'TABLE';
    const header = isTable ? `<tr>${el.querySelector('tr').innerHTML}</tr>` : '';
    const content = data && data.length ? data.map(item => {
        const rendered = displays[target](item);
        console.log(`Rendered ${target} item:`, rendered);
        return rendered;
    }).join('') : '';

    el.innerHTML = isTable ? header + content : content;

    if (target === 'marks') {
        const noMarksMessage = document.getElementById('noMarksMessage');
        if (noMarksMessage) {
            noMarksMessage.style.display = data && data.length ? 'none' : 'block';
        }
    }
    if (target === 'messages') {
        el.scrollTop = el.scrollHeight;
    }
}
function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (message && recipientId) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=send_message&receiver_id=${recipientId}&message=${encodeURIComponent(message)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(d => {
            if (d.success) {
                const chatBody = document.getElementById('chatBody');
                const timestamp = new Date().toLocaleString();
                chatBody.innerHTML += `
                    <div class="message sent">
                        <strong><?php echo htmlspecialchars($_SESSION['username']); ?>:</strong>
                        ${message}
                        <small>(${timestamp})</small>
                    </div>`;
                chatBody.scrollTop = chatBody.scrollHeight;
                input.value = ''; // Clear input
                input.focus();    // Restore focus to input
            } else {
                alert('Failed to send message: ' + (d.message || 'Unknown error'));
            }
        }).catch(error => {
            console.error("Error sending message:", error);
            alert('Error sending message. Please try again.');
        });
    }
}

    function fetchMessages(recipientId) {
        fetch(`?action=get_messages&other_user_id=${recipientId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const chatBody = document.getElementById('chatBody');
                    chatBody.innerHTML = '';
                    data.messages.forEach(message => {
                        const isSent = message.sender_id == <?php echo $student_id ?>;
                        chatBody.innerHTML += `
                            <div class="message ${isSent ? 'sent' : 'received'}">
                                <strong>${message.sender_name}:</strong>
                                ${message.message}
                                <small>(${new Date(message.timestamp).toLocaleString()})</small>
                            </div>`;
                        lastMessageId = Math.max(lastMessageId, message.id || 0);
                    });
                    chatBody.scrollTop = chatBody.scrollHeight;
                } else {
                    console.error("Failed to fetch messages:", data.message);
                }
            })
            .catch(error => console.error("Error fetching messages:", error));
    }

    function setupRealTimeUpdates() {
    if (eventSource) eventSource.close();
    eventSource = new EventSource(`?action=stream&last_update=${lastUpdate}`);
    eventSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Received SSE data:', data);
        lastUpdate = data.timestamp || lastUpdate;

        if (recipientId && data.messages) {
            const chatBody = document.getElementById('chatBody');
            data.messages
                .filter(msg => !msg.id || msg.id > lastMessageId)
                .forEach(msg => {
                    const isSent = msg.sender_id == <?php echo $student_id ?>;
                    chatBody.innerHTML += `
                        <div class="message ${isSent ? 'sent' : 'received'}">
                            <strong>${msg.sender_name}:</strong>
                            ${msg.message}
                            <small>(${new Date(msg.timestamp).toLocaleString()})</small>
                        </div>`;
                    lastMessageId = Math.max(lastMessageId, msg.id || 0);
                });
            chatBody.scrollTop = chatBody.scrollHeight;
        }
    };
    // Error handling...

        eventSource.onerror = () => {
            console.error('SSE connection error, reconnecting...');
            setTimeout(setupRealTimeUpdates, 1000);
        };
    }

    const style = document.createElement('style');
    style.textContent = `
        .message.sent { text-align: right; background: #e0f7fa; padding: 5px; margin: 5px 0; border-radius: 5px; }
        .message.received { text-align: left; background: #f1f1f1; padding: 5px; margin: 5px 0; border-radius: 5px; }
    `;
    document.head.appendChild(style);
    function openChat(id, name) {
    recipientId = id;
    lastMessageId = 0;
    const chatWindow = document.getElementById('chatWindow');
    chatWindow.classList.add('active');
    document.getElementById('chatTitle').textContent = `Chat with ${name}`;
    document.getElementById('chatBody').innerHTML = '';
    const chatInput = document.getElementById('chatInput');
    chatInput.value = '';
    chatInput.disabled = false;
    chatInput.focus();
    fetchMessages(recipientId);
}

    function closeChat() {
        document.getElementById('chatWindow').classList.remove('active');
        recipientId = '';
    }

    function submitQuery() {
        const query = document.getElementById('attendanceQuery').value.trim();
        if (query) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=attendance_query&query=${encodeURIComponent(query)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('attendanceQuery').value = '';
                    alert('Query submitted successfully!');
                } else {
                    alert('Failed to submit query: ' + (d.message || 'Unknown error'));
                }
            }).catch(error => {
                console.error("Error submitting query:", error);
                alert('Error submitting query. Please try again.');
            });
        }
    }

    function startQuiz(id) {
        console.log(`Starting quiz ${id}`);
        alert(`Starting quiz ${id}`);
    }

    function initializeCodeEditor() {
        if (!codeEditor) {
            codeEditor = CodeMirror.fromTextArea(document.getElementById('codeEditor'), {
                lineNumbers: true,
                mode: 'text/x-csrc',
                theme: 'default'
            });
            updateCodeEditor();
        }
    }

    function updateCodeEditor() {
        const lang = document.getElementById('codeLanguage').value;
        codeEditor.setOption('mode', lang === 'python' ? 'python' : lang === 'javascript' ? 'javascript' : 'text/x-csrc');
        codeEditor.setValue(`// Sample ${lang} code\n${lang === 'python' ? 'print("Hello")' : 'console.log("Hello")'}`);
    }

    function runCode() {
        document.getElementById('codeOutput').textContent = 'Mock Output: Hello\n(Note: Full execution requires backend.)';
    }

    function generateDigitalId() {
        new QRCode(document.getElementById('qrcode'), {
            text: `Student: <?php echo htmlspecialchars($_SESSION['username']); ?>, ID: DRS<?php echo $student_id; ?>`,
            width: 150,
            height: 150
        });
    }

    function downloadIdCard() {
        html2canvas(document.querySelector('.id-card')).then(canvas => {
            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png');
            link.download = '<?php echo htmlspecialchars($_SESSION['username']); ?>_ID.png';
            link.click();
        });
    }

    function logout() {
        window.location.href = 'logout.php';
    }

    function updatePersonalDetails(event) {
    event.preventDefault();
    const form = document.getElementById('personalDetailsForm');
    const formData = new FormData(form);
    formData.append('action', 'update_personal_details');
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

    // Log the form data for debugging
    console.log('Form Data:', Object.fromEntries(formData));

    fetch('', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => {
        // Log the status and headers
        console.log('Response Status:', response.status);
        console.log('Response Headers:', response.headers);

        // Get the raw response text
        return response.text().then(text => {
            console.log('Raw Response Text:', text);
            return text;
        });
    })
    .then(text => {
        // Try to parse the text as JSON
        try {
            const data = JSON.parse(text);
            console.log('Parsed JSON:', data);
            if (data.success) {
                alert('Details updated successfully!');
                location.reload();
            } else {
                alert('Failed to update details: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('JSON Parse Error:', error);
            alert('Error parsing response: ' + error.message + '\nRaw Response: ' + text);
        }
    })
    .catch(error => {
        console.error('Fetch Error:', error);
        alert('Error updating details: ' + error.message);
    });
}

   

    // Fetch course-wise attendance data (optional, if you want to update dynamically)
// Fetch course-wise attendance data (optional, if you want to update dynamically)
function fetchCourseAttendance() {
    fetch('?action=get_course_attendance')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.course_attendance) {
                updateCourseAttendanceDisplay(data.course_attendance);
            } else {
                console.error("Failed to fetch course-wise attendance:", data.message);
            }
        })
        .catch(error => console.error("Error fetching course-wise attendance:", error));
}

// Update the course-wise attendance table
function updateCourseAttendanceDisplay(data) {
    const tableBody = document.querySelector('#courseAttendanceTable tbody');
    if (!tableBody) {
        console.error("Course attendance table body not found");
        return;
    }
    const rows = data.map(record => `
        <tr>
            <td>${record.course_name}</td>
            <td>${record.total_days}</td>
            <td>${record.present_days}</td>
            <td style="color: ${record.attendance_percentage < 50 ? 'red' : 'green'};">
                ${record.attendance_percentage}%
            </td>
        </tr>
    `).join('');
    tableBody.innerHTML = rows;
}
// Ensure the existing updateDisplay function handles attendance correctly
// (This is already in your script, just verifying)
function updateDisplay(target, data) {
    const displays = {
        spotlight: d => `<li>${d.content} (${new Date(d.created_at).toLocaleString()})</li>`,
        marks: d => `<tr><td>${d.subject}</td><td>${d.score}/100</td></tr>`,
        attendance: d => `<tr><td>${d.date}</td><td>${d.status}</td></tr>`,
        progress: d => `<tr><td>${d.subject}</td><td><div class="progress-bar"><div class="progress" style="width:${d.score}%">${d.score}%</div></div></td></tr>`,
        content: d => `<li>${d.title} - <button class="button" onclick="startQuiz(${d.id})">Start</button></li>`,
        notifications: d => `<li>${d.content} (${new Date(d.created_at).toLocaleString()})</li>`,
        lectures: d => `<li>${d.title} - <button class="button" onclick="window.open('${d.file_path}','_blank')">Play</button></li>`,
        messages: d => `
            <div class="message ${d.sender_id == <?php echo $student_id ?> ? 'sent' : 'received'}">
                <strong>${d.sender_name}:</strong> ${d.message}
                <small>(${new Date(d.timestamp).toLocaleString()})</small>
            </div>`
    };
    const el = document.getElementById(`${target}List`) || document.getElementById(`${target}Table`) || document.getElementById('chatBody');
    if (!el || !data || !displays[target]) {
        console.error(`Failed to update ${target}: Element, data, or display missing`, { el, data, displays });
        return;
    }
    const isTable = el.tagName === 'TABLE';
    const header = isTable ? `<tr>${el.querySelector('tr').innerHTML}</tr>` : '';
    const content = data.map(displays[target]).join('');
    el.innerHTML = isTable ? header + content : content;
    if (target === 'messages') el.scrollTop = el.scrollHeight;
}
function submitAssignment(event) {
    event.preventDefault();
    const form = document.getElementById('assignmentForm');
    const formData = new FormData(form);
    const selectedOption = document.getElementById('assignment_title').selectedOptions[0];
    const deadline = selectedOption ? selectedOption.getAttribute('data-deadline') : '';
    formData.append('action', 'submit_assignment');
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
    formData.append('deadline', deadline);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const messageEl = document.getElementById('submissionMessage');
        messageEl.style.display = 'block';
        if (data.success) {
            messageEl.textContent = 'Assignment submitted successfully!';
            messageEl.style.color = 'green';
            form.reset();
            setTimeout(() => { messageEl.style.display = 'none'; }, 3000);
        } else {
            messageEl.textContent = 'Failed to submit assignment: ' + (data.message || 'Unknown error');
            messageEl.style.color = 'red';
        }
    })
    .catch(error => {
        console.error('Error submitting assignment:', error);
        document.getElementById('submissionMessage').textContent = 'Error submitting assignment';
        document.getElementById('submissionMessage').style.color = 'red';
        document.getElementById('submissionMessage').style.display = 'block';
    });
}

// Update window.onload to fetch assignments dynamically (optional)
window.onload = () => {
    // Existing onload code...
    const sendBtn = document.getElementById('sendMessageBtn');
    sendBtn.addEventListener('click', (e) => {
        e.preventDefault(); // Prevent any default behavior
        sendMessage();
    });

    const chatInput = document.getElementById('chatInput');
    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });
    fetchCourseAttendance();
    setActiveSection('personal-details');
    setupRealTimeUpdates();
    document.getElementById('code-platform').addEventListener('click', initializeCodeEditor);
    document.getElementById('digital-id').addEventListener('click', generateDigitalId);

    fetch('?action=get_marks').then(r => r.json()).then(d => updateDisplay('marks', d.marks));
    // ... other fetches ...

    // Optional: Fetch assignments dynamically if not using PHP
    fetch('?action=get_assignments')
        .then(r => r.json())
        .then(d => {
            if (d.success && d.assignments) {
                const select = document.getElementById('assignment_title');
                select.innerHTML = '<option value="">Select an assignment</option>';
                d.assignments.forEach(a => {
                    const option = document.createElement('option');
                    option.value = a.title;
                    option.textContent = `${a.title} (Due: ${a.deadline})`;
                    option.setAttribute('data-deadline', a.deadline);
                    select.appendChild(option);
                });
            }
        });
};
// Add to window.onload to fetch initial data
window.onload = () => {
  
    //other code here....
    fetchCourseAttendance();

    setActiveSection('personal-details');
    setupRealTimeUpdates();
    document.getElementById('code-platform').addEventListener('click', initializeCodeEditor);
    document.getElementById('digital-id').addEventListener('click', generateDigitalId);

    fetch('?action=get_marks').then(r => r.json()).then(d => updateDisplay('marks', d.marks));
    fetch('?action=get_attendance').then(r => r.json()).then(d => updateDisplay('attendance', d.attendance));
    fetch('?action=get_spotlight').then(r => r.json()).then(d => updateDisplay('spotlight', d.spotlight));
    fetch('?action=get_notifications').then(r => r.json()).then(d => updateDisplay('notifications', d.notifications));
    fetch('?action=get_quizzes').then(r => r.json()).then(d => updateDisplay('content', d.quizzes));
    fetch('?action=get_lectures').then(r => r.json()).then(d => updateDisplay('lectures', d.lectures));
    // Optionally fetch course-wise attendance dynamically
    // fetchCourseAttendance();

    fetch('?action=get_personal_details')
        .then(r => r.json())
        .then(d => {
            console.log('Initial Personal Details Response:', d);
            if (d.success && d.details) {
                const form = document.getElementById('personalDetailsForm');
                form.full_name.value = d.details.full_name || '';
                form.email.value = d.details.email || '';
                form.phone.value = d.details.phone || '';
                form.address.value = d.details.address || '';
                form.date_of_birth.value = d.details.date_of_birth || '';
                form.emergency_contact.value = d.details.emergency_contact || '';
            }
        })
        .catch(error => console.error('Error fetching initial personal details:', error));
};
(function(){
            var js, fs, d = document, id = "tars-widget-script", b = "https://tars-file-upload.s3.amazonaws.com/bulb/";
            if (!d.getElementById(id)) {
                js = d.createElement("script");
                js.id = id;
                js.type = "text/javascript";
                js.src = b + "js/widget.js";
                fs = d.getElementsByTagName("script")[0];
                fs.parentNode.insertBefore(js, fs);
            }
        })();
        window.tarsSettings = {
            "convid": "LJ0fsH",
            "href": "https://chatbot.hellotars.com/conv/LJ0fsH"
        };
    </script>
</body>
</html>