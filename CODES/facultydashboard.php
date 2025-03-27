<?php
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'faculty') {
    header("Location: facultylogin.php");
    exit();
}
$faculty_id = (int)$_SESSION['user_id'];
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// Fetch initial notifications (all notifications created by faculty or globally visible)

$conn = new mysqli('localhost', 'root', '', 'college_portal');
$notifications = $conn->query("SELECT content, created_at FROM notifications ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$attendance = $conn->query("SELECT a.student_id, u.username, c.course_name, a.date, a.status 
                            FROM attendance a 
                            JOIN users u ON a.student_id = u.user_id 
                            JOIN courses c ON a.course_id = c.id 
                            WHERE a.marked_by = $faculty_id 
                            ORDER BY a.date DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$courses = $conn->query("SELECT id, course_name FROM courses")->fetch_all(MYSQLI_ASSOC);
// Fetch initial lectures (uploaded by faculty or globally visible)
$lectures = $conn->query("SELECT title, file_path FROM lectures ORDER BY uploaded_at DESC")->fetch_all(MYSQLI_ASSOC);
$marks = $conn->query("SELECT m.student_id, u.username, m.subject, m.score, m.grade, m.result_status, m.comments 
                       FROM marks m 
                       JOIN users u ON m.student_id = u.user_id")->fetch_all(MYSQLI_ASSOC);
$faculty_id = $_SESSION['user_id'] ?? 1; // Replace with actual session logic

// Fetch courses taught by this faculty (example)
$stmt = $conn->prepare("SELECT id, course_name FROM courses WHERE id = ?");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Attendance calculation for each course
$attendance_data = [];
foreach ($courses as $course) {
    $course_id = $course['id'];
    $course_name = $course['course_name'];

    // Initialize variables to avoid undefined errors
    $total_days = 0;
    $present_days = 0;

    // Fetch total days (line 30 area)
    $stmt = $conn->prepare("SELECT COUNT(*) as total_days FROM attendance WHERE course_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $total_days = $stmt->get_result()->fetch_assoc()['total_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to fetch total days for course $course_id: " . $conn->error);
    }

    // Fetch present days (line 31 area)
    $stmt = $conn->prepare("SELECT COUNT(*) as present_days FROM attendance WHERE course_id = ? AND status = 'Present'");
    if ($stmt) {
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $present_days = $stmt->get_result()->fetch_assoc()['present_days'] ?? 0;
        $stmt->close();
    } else {
        error_log("Failed to fetch present days for course $course_id: " . $conn->error);
    }

    // Calculate attendance percentage (line 32 area)
    $attendance_percentage = ($total_days > 0) ? round(($present_days / $total_days) * 100, 2) : 0;

    $attendance_data[] = [
        'course_name' => $course_name,
        'total_days' => $total_days,
        'present_days' => $present_days,
        'attendance_percentage' => $attendance_percentage
    ];
}

// Aggregate marks
if (!empty($marks)) {
    $total_score = array_sum(array_column($marks, 'score'));
    $average_marks = round($total_score / count($marks), 2);
    foreach ($marks as $m) {
        $progress_data[$m['subject']] = ['score' => $m['score']];
    }
}

// Aggregate attendance
if (!empty($attendance_data)) {
    $total_attendance = array_sum(array_column($attendance_data, 'attendance_percentage'));
    $average_attendance = round($total_attendance / count($attendance_data), 2);
    foreach ($attendance_data as $a) {
        $progress_data[$a['course_name']]['attendance'] = $a['attendance_percentage'];
    }
}

// Combine into a unified progress metric (e.g., weighted average)
foreach ($progress_data as $subject => &$data) {
    $data['progress'] = round(($data['score'] ?? 0) * 0.6 + ($data['attendance'] ?? 0) * 0.4, 2);
}
unset($data);
$spotlight = $conn->query("SELECT content, created_at FROM spotlight ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Connection failed");
}

$faculty_id = $_SESSION['user_id'];

if (!isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $_SESSION['username'] = $stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown Faculty';
    $stmt->close();
}
// Helper function to log to PHP error log
function logData($message) {
    error_log(date("Y-m-d H:i:s") . " - " . $message);
}


// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));

    $action = $_POST['action'];
    $success = false;
    $message = '';

    switch ($action) {
        case 'get_progress':
            $progress_data = [];
            $average_marks = 0;
            $average_attendance = 0;
        
            // Fetch marks
            $stmt = $conn->prepare("SELECT subject, score FROM marks WHERE student_id = ?");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $marks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        
            if (!empty($marks)) {
                $total_score = array_sum(array_column($marks, 'score'));
                $average_marks = round($total_score / count($marks), 2);
                foreach ($marks as $m) {
                    $progress_data[$m['subject']] = ['score' => $m['score']];
                }
            }
        
            // Fetch attendance
            $stmt = $conn->prepare("SELECT c.course_name, COUNT(*) as total_days, SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days
                                   FROM attendance a
                                   JOIN courses c ON a.course_id = c.id
                                   WHERE a.student_id = ?
                                   GROUP BY a.course_id, c.course_name");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $attendance_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        
            if (!empty($attendance_result)) {
                $total_attendance = 0;
                foreach ($attendance_result as $a) {
                    $attendance_percentage = $a['total_days'] > 0 ? round(($a['present_days'] / $a['total_days']) * 100, 2) : 0;
                    $progress_data[$a['course_name']]['attendance'] = $attendance_percentage;
                    $total_attendance += $attendance_percentage;
                }
                $average_attendance = round($total_attendance / count($attendance_result), 2);
            }
        
            // Calculate overall progress
            foreach ($progress_data as $subject => &$data) {
                $data['progress'] = round(($data['score'] ?? 0) * 0.6 + ($data['attendance'] ?? 0) * 0.4, 2);
            }
            unset($data);
        
            ob_clean();
            echo json_encode([
                'success' => true,
                'progress' => array_map(function ($subject, $data) {
                    return ['subject' => $subject, 'score' => $data['score'] ?? null, 'attendance' => $data['attendance'] ?? null, 'progress' => $data['progress']];
                }, array_keys($progress_data), $progress_data),
                'average_marks' => $average_marks,
                'average_attendance' => $average_attendance
            ]);
            $conn->close();
            exit();
            break;
        case 'upload_course_content':
            header('Content-Type: application/json');
            if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                exit;
            }
        
            // Required fields
            if (!isset($_POST['title'], $_POST['type']) || !isset($_FILES['content_file'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
        
            $title = $_POST['title'];
            $type = $_POST['type']; // 'syllabus', 'study_material', or 'assignment'
            $course_id = $_POST['course_id'] ?? null; // Optional
        
            // Validate type
            if (!in_array($type, ['syllabus', 'study_material', 'assignment'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid content type']);
                exit;
            }
        
            // File upload handling
            $upload_dir = 'uploads/course_content/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_name = uniqid() . '_' . basename($_FILES['content_file']['name']);
            $file_path = $upload_dir . $file_name;
        
            if (move_uploaded_file($_FILES['content_file']['tmp_name'], $file_path)) {
                $stmt = $conn->prepare("INSERT INTO lectures (title, file_path, type, uploaded_by, course_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssii", $title, $file_path, $type, $faculty_id, $course_id);
                $success = $stmt->execute();
                $stmt->close();
        
                if ($success) {
                    $message = "Course content ($type) uploaded successfully: $title";
                    logData($message);
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    unlink($file_path); // Remove file if DB insert fails
                    echo json_encode(['success' => false, 'message' => 'Failed to save content']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
            }
            $conn->close();
            exit;
            break;
            case 'update_marks':
                header('Content-Type: application/json');
                if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                    exit;
                }
                if (!isset($_POST['student_id'], $_POST['subject'], $_POST['score'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields: student_id, subject, or score']);
                    exit;
                }
            
                $student_id = (int)$_POST['student_id'];
                $subject = trim($_POST['subject']);
                $score = (int)$_POST['score'];
                $comments = trim($_POST['comments'] ?? ''); // Optional comments field
            
                // Validate inputs
                if ($student_id <= 0 || empty($subject) || $score < 0 || $score > 100) {
                    echo json_encode(['success' => false, 'message' => 'Invalid input: Student ID must be positive, subject cannot be empty, score must be between 0 and 100']);
                    exit;
                }
            
                // Calculate grade and result status
                $grade = '';
                $result_status = '';
                if ($score >= 90) {
                    $grade = 'A';
                    $result_status = 'Pass';
                } elseif ($score >= 80) {
                    $grade = 'B';
                    $result_status = 'Pass';
                } elseif ($score >= 70) {
                    $grade = 'C';
                    $result_status = 'Pass';
                } elseif ($score >= 60) {
                    $grade = 'D';
                    $result_status = 'Pass';
                } elseif ($score >= 50) {
                    $grade = 'E';
                    $result_status = 'Pass';
                } else {
                    $grade = 'F';
                    $result_status = 'Fail';
                }
            
                // Prepare and execute SQL
                $stmt = $conn->prepare("
                    INSERT INTO marks (student_id, subject, score, grade, result_status, comments, updated_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                    score = VALUES(score), 
                    grade = VALUES(grade), 
                    result_status = VALUES(result_status), 
                    comments = VALUES(comments), 
                    updated_by = VALUES(updated_by), 
                    updated_at = CURRENT_TIMESTAMP
                ");
                
                if (!$stmt) {
                    $error = $conn->error;
                    error_log("Prepare failed: $error");
                    echo json_encode(['success' => false, 'message' => "Database prepare error: $error"]);
                    exit;
                }
            
                $stmt->bind_param("isisssi", $student_id, $subject, $score, $grade, $result_status, $comments, $faculty_id);
                
                if ($stmt->execute()) {
                    $success = true;
                    $message = "Marks updated successfully for student ID: $student_id in $subject (Score: $score, Grade: $grade, Status: $result_status, Comments: $comments)";
                } else {
                    $error = $stmt->error;
                    error_log("Execute failed: $error");
                    $success = false;
                    $message = "Failed to update marks: $error";
                }
            
                $stmt->close();
                
                logData("Action: update_marks, Success: " . ($success ? 'true' : 'false') . ", Message: $message, Data: " . json_encode($_POST));
                echo json_encode(['success' => $success, 'message' => $message]);
                exit;
                break;
            case 'mark_attendance':
                header('Content-Type: application/json');
                if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
                    exit;
                }
                if (!isset($_POST['student_id'], $_POST['course_id'], $_POST['date'], $_POST['status'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing attendance data']);
                    exit;
                }
            
                $student_id = (int)$_POST['student_id'];
                $course_id = (int)$_POST['course_id'];
                $date = $_POST['date'];
                $status = $_POST['status'];
            
                if (!in_array($status, ['Present', 'Absent'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']);
                    exit;
                }
            
                $stmt = $conn->prepare("INSERT INTO attendance (student_id, course_id, date, status, marked_by) 
                                        VALUES (?, ?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE status = ?, marked_by = ?, marked_at = CURRENT_TIMESTAMP");
                if (!$stmt) {
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                    exit;
                }
                $stmt->bind_param("iississ", $student_id, $course_id, $date, $status, $faculty_id, $status, $faculty_id);
            
                if ($stmt->execute()) {
                    $success = true;
                    $message = "Attendance marked successfully for student ID: $student_id in course ID: $course_id on $date";
                } else {
                    $success = false;
                    $message = "Failed to mark attendance: " . $stmt->error;
                    error_log("Attendance SQL Error: " . $stmt->error);
                }
                $stmt->close();
            
                logData("Action: mark_attendance, Success: " . ($success ? 'true' : 'false') . ", Message: $message, Data: " . json_encode($_POST));
                ob_end_clean();
                echo json_encode(['success' => $success, 'message' => $message]);
                exit;
                break;
        case 'add_spotlight':
            $content = $_POST['content'];
            $stmt = $conn->prepare("INSERT INTO spotlight (content, created_by) VALUES (?, ?)");
            $stmt->bind_param("si", $content, $faculty_id);
            $success = $stmt->execute();
            $message = $success ? 'Spotlight added successfully.' : 'Failed to add spotlight.';
            break;
        

        case 'create_quiz':
             if (!isset($_POST['title'], $_POST['assigned_to'])) {
                 echo json_encode(['success' => false, 'message' => 'Missing quiz data.']);
                 exit;
             }
            $title = $_POST['title'];
            $assigned_to = $_POST['assigned_to'];

            $stmt = $conn->prepare("INSERT INTO quizzes (title, created_by, assigned_to) VALUES (?, ?, ?)");
            $stmt->bind_param("sii", $title, $faculty_id, $assigned_to);
                if ($stmt->execute()) {
                    $success = true;
                    $message = "Quiz created successfully with title: " . $title;
                } else {
                    $success = false;
                    $message = "Failed to create quiz: " . $stmt->error;
                }
            break;
        case 'send_notification':
            $content = $_POST['content'];
            $stmt = $conn->prepare("INSERT INTO notifications (content, created_by) VALUES (?, ?)");
            $stmt->bind_param("si", $content, $faculty_id);
            $success = $stmt->execute();
             $message = $success ? 'Notification send successfully.' : 'Failed to send notification.';
            break;
        case 'upload_lecture':
            $title = $_POST['title'];
            $file_path = $_POST['file_path'];
            $stmt = $conn->prepare("INSERT INTO lectures (title, file_path, uploaded_by) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $title, $file_path, $faculty_id);
             if ($stmt->execute()) {
                    $success = true;
                    $message = "Lecture uploaded successfully with title: " . $title;
                } else {
                    $success = false;
                    $message = "Failed to upload lecture: " . $stmt->error;
                }
            break;
       // In the POST request handler, update 'send_message' case:
case 'send_message':
    $receiver_id = (int)$_POST['receiver_id'];
    $message_text = trim($_POST['message']);
    if ($receiver_id && $message_text) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $faculty_id, $receiver_id, $message_text);
        $success = $stmt->execute();
        $message_id = $stmt->insert_id;
        $stmt->close();
        
        $message = $success ? "Message sent successfully" : "Failed to send message: " . $conn->error;
        if ($success) {
            // Return the new message for immediate display
            $response = [
                'success' => true,
                'message' => $message,
                'new_message' => [
                    'id' => $message_id,
                    'sender_id' => $faculty_id,
                    'receiver_id' => $receiver_id,
                    'message' => $message_text,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'sender_name' => $_SESSION['username']
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => $message];
        }
    } else {
        $response = ['success' => false, 'message' => 'Invalid message data'];
    }
    logData("Action: send_message, Success: " . ($success ? 'true' : 'false') . ", Message: $message");
    echo json_encode($response);
    exit();
    break;

// In the GET request handler, update 'get_messages':
if ($_GET['action'] === 'get_messages') {
    $other_user_id = (int)$_GET['other_user_id'];
    $faculty_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT m.id, m.sender_id, m.receiver_id, m.message, m.timestamp, 
               us.username AS sender_name, ur.username AS receiver_name
        FROM messages m
        JOIN users us ON m.sender_id = us.user_id
        JOIN users ur ON m.receiver_id = ur.user_id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
        ORDER BY m.timestamp ASC
    ");
    $stmt->bind_param("iiii", $faculty_id, $other_user_id, $other_user_id, $faculty_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'messages' => $messages]);
    $conn->close();
    exit();
}
        default:
            $success = false;
            $message = 'Invalid action';
            break;
    }

      logData("Action: $action, Success: " . ($success ? 'true' : 'false') . ", Message: $message, Data: " . json_encode($_POST));

    if (isset($stmt)) $stmt->close();
    $conn->close();
    echo json_encode(['success' => $success, 'action' => $action, 'post' => $_POST, 'message' => $message]);
    exit();
}

// Handle GET requests (SSE Stream)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'get_students') {
        echo json_encode(['success' => true, 'students' => $conn->query("SELECT user_id, username FROM users WHERE user_type = 'student'")->fetch_all(MYSQLI_ASSOC)]);
        $conn->close();
        exit();
    }

    if ($_GET['action'] === 'get_messages') {
        $other_user_id = (int)$_GET['other_user_id'];
        $faculty_id = (int)$_SESSION['user_id'];

        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message, m.timestamp, 
                   us.username AS sender_name, ur.username AS receiver_name
            FROM messages m
            JOIN users us ON m.sender_id = us.user_id
            JOIN users ur ON m.receiver_id = ur.user_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.timestamp ASC
        ");
        if (!$stmt) {
            error_log("Prepare failed for get_messages: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error']);
            $conn->close();
            exit();
        }
        $stmt->bind_param("iiii", $faculty_id, $other_user_id, $other_user_id, $faculty_id);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_messages: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Query execution failed']);
            $stmt->close();
            $conn->close();
            exit();
        }
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        error_log("Messages fetched for faculty $faculty_id and user $other_user_id: " . json_encode($messages));
        echo json_encode(['success' => true, 'messages' => $messages]);
        $conn->close();
        exit();
    }

    if ($_GET['action'] === 'stream') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        $last_update = $_GET['last_update'] ?? 0;

        while (true) {
            $data = [
                'messages' => $conn->query("SELECT m.*, us.username AS sender_name, ur.username AS receiver_name FROM messages m JOIN users us ON m.sender_id = us.user_id JOIN users ur ON m.receiver_id = ur.user_id WHERE (m.receiver_id = $faculty_id OR m.sender_id = $faculty_id) AND UNIX_TIMESTAMP(m.timestamp) > $last_update ORDER BY m.timestamp DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'timestamp' => time(),
            ];

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
    if ($_GET['action'] === 'stream') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        $last_update = $_GET['last_update'] ?? 0;
    
        while (true) {
            $data = [
                'lectures' => $conn->query("SELECT title, file_path, type FROM lectures WHERE uploaded_by = $faculty_id AND UNIX_TIMESTAMP(uploaded_at) > $last_update ORDER BY uploaded_at DESC")->fetch_all(MYSQLI_ASSOC),
                'messages' => $conn->query("SELECT m.*, us.username AS sender_name, ur.username AS receiver_name FROM messages m JOIN users us ON m.sender_id = us.user_id JOIN users ur ON m.receiver_id = ur.user_id WHERE (m.receiver_id = $faculty_id OR m.sender_id = $faculty_id) AND UNIX_TIMESTAMP(m.timestamp) > $last_update ORDER BY m.timestamp DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'marks' => $conn->query("SELECT m.student_id, u.username, m.subject, m.score, m.grade, m.result_status 
                         FROM marks m 
                         JOIN users u ON m.student_id = u.user_id 
                         WHERE UNIX_TIMESTAMP(m.updated_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'attendance' => $conn->query("SELECT a.student_id, u.username, c.course_name, a.date, a.status 
                              FROM attendance a 
                              JOIN users u ON a.student_id = u.user_id 
                              JOIN courses c ON a.course_id = c.course_id 
                              WHERE a.marked_by = $faculty_id AND UNIX_TIMESTAMP(a.updated_at) > $last_update 
                              ORDER BY a.updated_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'spotlight' => $conn->query("SELECT content, created_at FROM spotlight WHERE UNIX_TIMESTAMP(created_at) > $last_update ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC), // Show all spotlights
                'notifications' => $conn->query("SELECT content, created_at FROM notifications WHERE UNIX_TIMESTAMP(created_at) > $last_update ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC), // Show all notifications
                'quizzes' => $conn->query("SELECT title, assigned_to FROM quizzes WHERE UNIX_TIMESTAMP(created_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'lectures' => $conn->query("SELECT title, file_path FROM lectures WHERE UNIX_TIMESTAMP(uploaded_at) > $last_update")->fetch_all(MYSQLI_ASSOC),
                'timestamp' => time(),
            ];
    
            // Log data for debugging
            error_log("Faculty Stream Data: " . json_encode($data));
    
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
    
}}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'stream') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        $last_update = $_GET['last_update'] ?? 0;
        
        while (true) {
            $data = [
                'messages' => $conn->query("SELECT m.*, us.username AS sender_name, ur.username AS receiver_name FROM messages m JOIN users us ON m.sender_id = us.user_id JOIN users ur ON m.receiver_id = ur.user_id WHERE (m.receiver_id = $faculty_id OR m.sender_id = $faculty_id) AND UNIX_TIMESTAMP(m.timestamp) > $last_update ORDER BY m.timestamp DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC),
                'timestamp' => time(),
            ];
            
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
    }}
    // Add other GE
$student_list = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'student'")->fetch_all(MYSQLI_ASSOC);
$alumni_list = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'alumni'")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Faculty Panel - DRS Institute of Technology</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
       
    /* Existing styles from faculty panel assumed here */
    .header {
        position: fixed;
        top: 0;
        left: 70px;
        width: calc(100% - 70px);
        background: #fff;
        padding: 15px 30px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        z-index: 2;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: left 0.3s ease, width 0.3s ease;
    }
    .header.expanded {
        left: 250px;
        width: calc(100% - 250px);
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
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
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
    }
    .sidenav a:hover {
        background: #3498db;
    }
    .sidenav a.active {
        background: #2980b9;
    }
    .sidenav a i {
        margin-right: 15px;
        min-width: 30px;
    }
    .sidenav .text {
        display: none;
        opacity: 0;
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
    }
    .main.expanded {
        margin-left: 250px;
    }
    #marksTable th, #marksTable td { padding: 10px; text-align: center; }
#marksTable th:nth-child(4), #marksTable th:nth-child(5) { background: #f39c12; }
#marksTable td:nth-child(4) { color: #2980b9; font-weight: bold; }
#marksTable td:nth-child(5) { color: #e74c3c; font-weight: bold; }
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
        #spotlight,#content-creator,#exam-generator{border-left:4px solid #f1c40f;}
        #manage-marks,#attendance,#progress,#lecture-sharing{border-left:4px solid #2ecc71;}
        #contact-students,#notifications{border-left:4px solid #e67e22;}
        #contact-alumni{border-left:4px solid #d35400;}
        .chat-window{position:fixed;bottom:80px;right:30px;width:300px;height:400px;background:#fff;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,0.2);display:none;flex-direction:column;z-index:1001;transform:scale(0.8);opacity:0;transition:transform 0.3s,opacity 0.3s;}
        .chat-window.active{display:flex;transform:scale(1);opacity:1;}
        .chat-header{background:#e67e22;color:#fff;padding:10px;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;}
        .chat-header button{background:none;border:none;color:#fff;cursor:pointer;}
        .chat-body{flex-grow:1;padding:10px;overflow-y:auto;}
        .chat-footer{padding:10px;border-top:1px solid #ddd;}
        .chat-footer input{width:70%;padding:5px;border:1px solid #ddd;border-radius:5px;}
        .chat-footer button{padding:5px 10px;background:#e67e22;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        .student-item,.alumni-item{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
        .message-btn{padding:5px 10px;background:#e67e22;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th,td{padding:10px;border:1px solid #ddd;text-align:left;}
        th{background:#e67e22;color:#fff;}
        tr:nth-child(even){background:#f9f9f9;}
        .button{background:#e74c3c;color:#fff;padding:10px 20px;border:none;border-radius:5px;cursor:pointer;margin-top:10px;}
        .button:hover{background:#c0392b;}
        .progress-bar{width:100%;background:#ddd;border-radius:5px;overflow:hidden;margin-top:10px;}
        .progress{height:20px;background:#2ecc71;text-align:center;color:#fff;line-height:20px;}
        input,select,textarea{padding:5px;margin:5px 0;border:1px solid #ddd;border-radius:5px;}
    </style>
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
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.7);
        transition: all 0.3s ease;
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
    }
</style><style>
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
</head>
<body>
    <div class="header" id="myHeader">
        <h1 id="headerTitle">Faculty Panel - DRS Institute of Technology</h1>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <button class="logout-btn" onclick="logout()">Log Out</button>
        </div>
    </div>

    <div class="sidenav" id="mySidenav">
        <a href="#dashboard" class="nav-link active"><i class="fas fa-tachometer-alt"></i><span class="text">Dashboard</span></a>
        <a href="#spotlight" class="nav-link"><i class="fas fa-star"></i><span class="text">Spotlight</span></a>
        <a href="#manage-marks" class="nav-link"><i class="fas fa-edit"></i><span class="text">Manage Marks</span></a>
        <a href="#manage-course-content" class="nav-link"><i class="fas fa-folder-open"></i><span class="text">Manage Course Content</span></a>
        <a href="#attendance" class="nav-link"><i class="fas fa-user-check"></i><span class="text">Attendance</span></a>
        <a href="#progress" class="nav-link"><i class="fas fa-chart-line"></i><span class="text">Student Progress</span></a>
        <a href="#content-creator" class="nav-link"><i class="fas fa-book"></i><span class="text">Content Creator</span></a>
        <a href="#notifications" class="nav-link"><i class="fas fa-bell"></i><span class="text">Notifications</span></a>
        <a href="#lecture-sharing" class="nav-link"><i class="fas fa-video"></i><span class="text">Lecture Sharing</span></a>
        <a href="#contact-students" class="nav-link"><i class="fas fa-envelope"></i><span class="text">Contact Students</span></a>
        <a href="#contact-alumni" class="nav-link"><i class="fas fa-users"></i><span class="text">Contact Alumni</span></a>
    </div>

    <div class="main" id="mainContent">
    <div id="dashboard" class="section active">
        <h2>Dashboard</h2>
        <table>
            <tr><th>Metric</th><th>Value</th></tr>
            <tr><td>Total Students</td><td><?php echo count($student_list); ?></td></tr>
            <tr><td>Classes Today</td><td>3</td></tr>
        </table>
    </div>
    <div id="manage-course-content" class="section">
    <h2>Manage Course Content</h2>
    <form id="courseContentForm" enctype="multipart/form-data" onsubmit="uploadCourseContent(event)">
        <input type="text" id="contentTitle" name="title" placeholder="Content Title" required>
        <select id="contentType" name="type" required>
            <option value="">Select Type</option>
            <option value="syllabus">Syllabus</option>
            <option value="study_material">Study Material</option>
            <option value="assignment">Assignment</option>
        </select>
        <input type="file" id="contentFile" name="content_file" accept=".pdf,.doc,.docx" required>
        <select id="courseId" name="course_id">
            <option value="">Select Course (Optional)</option>
            <?php
            $courses = $conn->query("SELECT id, course_name FROM courses")->fetch_all(MYSQLI_ASSOC);
            foreach ($courses as $course) {
                echo "<option value='{$course['id']}'>" . htmlspecialchars($course['course_name']) . "</option>";
            }
            ?>
        </select>
        <button class="button">Upload</button>
    </form>
    <h3>Uploaded Content</h3>
    <ul id="courseContentList">
        <?php foreach ($lectures as $l): ?>
            <li><?php echo htmlspecialchars($l['type'] ?? 'Unknown'); ?>: <?php echo htmlspecialchars($l['title']); ?> (<a href="<?php echo htmlspecialchars($l['file_path']); ?>" target="_blank">View</a>)</li>
        <?php endforeach; ?>
    </ul>
</div>
    <div id="spotlight" class="section">
        <h2>Spotlight</h2>
        <form onsubmit="addSpotlight(event)">
            <input type="text" id="spotlightText" placeholder="Enter spotlight text" required>
            <button class="button">Add</button>
        </form>
        <ul id="spotlightList">
            <?php foreach ($spotlight as $s): ?>
                <li><?php echo htmlspecialchars($s['content']); ?> (<?php echo (new DateTime($s['created_at']))->format('Y-m-d H:i:s'); ?>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div id="manage-marks" class="section">
    <h2>Examination & Grading</h2>
    <form id="marksForm" onsubmit="updateMarks(event)">
        <select id="markStudent" required>
            <option value="" disabled selected>Select Student</option>
            <?php foreach ($student_list as $s): ?>
                <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="markSubject" placeholder="Subject" required>
        <input type="number" id="markScore" placeholder="Score (0-100)" min="0" max="100" required>
        <textarea id="markComments" placeholder="Faculty Comments (optional)" rows="3"></textarea>
        <button class="button">Update Marks</button>
    </form>
    <h3>Student Results</h3>
    <table id="marksTable">
        <tr>
            <th>Student</th>
            <th>Subject</th>
            <th>Score</th>
            <th>Grade</th>
            <th>Result Status</th>
            <th>Comments</th>
        </tr>
        <?php foreach ($marks as $m): ?>
            <tr>
                <td><?php echo htmlspecialchars($m['username']); ?></td>
                <td><?php echo htmlspecialchars($m['subject']); ?></td>
                <td><?php echo $m['score']; ?>/100</td>
                <td><?php echo htmlspecialchars($m['grade'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($m['result_status'] ?? 'Pending'); ?></td>
                <td><?php echo htmlspecialchars($m['comments'] ?? 'No comments'); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
    <div id="attendance" class="section">
    <h2>Mark Attendance</h2>
    <form onsubmit="markAttendance(event)">
        <select id="attStudent" required>
            <option value="">Select Student</option>
            <?php foreach ($student_list as $s): ?>
                <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="attCourse" required>
            <option value="">Select Course</option>
            <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['course_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" id="attDate" required>
        <select id="attStatus" required>
            <option value="Present">Present</option>
            <option value="Absent">Absent</option>
        </select>
        <button class="button">Mark</button>
    </form>
    <h3>Attendance Records</h3>
    <table id="attTable">
        <tr><th>Student</th><th>Course</th><th>Date</th><th>Status</th></tr>
        <?php foreach ($attendance as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['username']); ?></td>
                <td><?php echo htmlspecialchars($a['course_name']); ?></td>
                <td><?php echo $a['date']; ?></td>
                <td><?php echo $a['status']; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
    
<div id="progress" class="section">
    <h2>Progress</h2>
    <table id="progressTable">
        <tr><th>Subject/Course</th><th>Marks (%)</th><th>Attendance (%)</th><th>Overall Progress (%)</th></tr>
        <?php if (empty($progress_data)): ?>
            <tr><td colspan="4">No progress data available</td></tr>
        <?php else: ?>
            <?php foreach ($progress_data as $subject => $data): ?>
                <tr>
                    <td><?php echo htmlspecialchars($subject); ?></td>
                    <td><?php echo $data['score'] ?? 'N/A'; ?></td>
                    <td><?php echo $data['attendance'] ?? 'N/A'; ?></td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress" style="width:<?php echo $data['progress'] ?? 0; ?>%;">
                                <?php echo $data['progress'] ?? 0; ?>%
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
    <p>Average Marks: <?php echo $average_marks; ?>% | Average Attendance: <?php echo $average_attendance; ?>%</p>
</div>
    <div id="content-creator" class="section">
        <h2>Content Creator</h2>
        <form onsubmit="createContent(event)">
            <input type="text" id="contentTitle" placeholder="Quiz Title" required>
            <select id="contentStudent" required>
                <?php foreach ($student_list as $s): ?>
                    <option value="<?php echo htmlspecialchars($s['user_id']); ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button">Create</button>
        </form>
        <ul id="contentList"></ul>
    </div>
    
    <div id="notifications" class="section">
        <h2>Notifications</h2>
        <form onsubmit="sendNotification(event)">
            <input type="text" id="notifText" placeholder="Notification Text" required>
            <button class="button">Send</button>
        </form>
    
    </div>
    <div id="progress" class="section">
    <h2>Progress</h2>
    <table id="progressTable">
        <tr><th>Subject</th><th>Progress</th></tr>
    </table>
</div>
    <div id="lecture-sharing" class="section">
        <h2>Lecture Sharing</h2>
        <form onsubmit="uploadLecture(event)">
            <input type="text" id="lectureTitle" placeholder="Lecture Title" required>
            <input type="text" id="lecturePath" placeholder="File Path" required>
            <button class="button">Upload</button>
        </form>
        <ul id="lectureList">
            <?php foreach ($lectures as $l): ?>
                <li><?php echo htmlspecialchars($l['title']); ?> (<a href="<?php echo htmlspecialchars($l['file_path']); ?>" target="_blank"><?php echo htmlspecialchars($l['file_path']); ?></a>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div id="contact-students" class="section">
        <h2>Contact Students</h2>
        <?php foreach ($student_list as $s): ?>
            <div class="student-item">
                <span><?php echo htmlspecialchars($s['username']); ?></span>
                <button class="message-btn" onclick="openChat(<?php echo $s['user_id']; ?>,'<?php echo htmlspecialchars($s['username']); ?>')">Message</button>
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
</div>

    <div class="chat-window" id="chatWindow">
        <div class="chat-header"><span id="chatTitle"></span><button onclick="closeChat()">✖</button></div>
        <div class="chat-body" id="chatBody"></div>
        <div class="chat-footer"><input type="text" id="chatInput" placeholder="Type a message..."><button onclick="sendMessage()">Send</button></div>
    </div>

    <footer><p>© 2025 DRS Institute Of Technology. All rights reserved.</p></footer>
    <script>
    let recipientId = '', eventSource, lastUpdate = 0, lastMessageId = 0;

    // Event listener for sidenav clicks
    document.getElementById('mySidenav').addEventListener('click', e => {
        if (e.target.closest('.nav-link')) toggleNav(); // Toggle sidebar on nav link click
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
        document.getElementById('headerTitle').textContent = `Faculty Panel - DRS Institute of Technology - ${document.getElementById(id).querySelector('h2').textContent}`;
    }

    function updateDisplay(target, data) {
        const displays = {
            progress: d => `<tr><td>${d.subject || 'N/A'}</td><td><div class="progress-bar"><div class="progress" style="width:${d.score || 0}%">${d.score || 0}%</div></div></td></tr>`,
            'manage-marks': d => `<tr>
            <td>${d.username}</td>
            <td>${d.subject}</td>
            <td>${d.score}/100</td>
            <td>${d.grade || 'N/A'}</td>
            <td>${d.result_status || 'Pending'}</td>
            <td>${d.comments || 'No comments'}</td>
        </tr>`,
        progress: d => `<tr>
            <td>${d.username}</td>
            <td>${d.subject}</td>
            <td><div class="progress-bar"><div class="progress" style="width:${d.score}%">${d.score}%</div></div></td>
        </tr>`,
            attendance: d => `<tr><td>${d.username}</td><td>${d.course_name}</td><td>${d.date}</td><td>${d.status}</td></tr>`,
            'manage-course-content': d => `<li>${d.type}: ${d.title} (<a href="${d.file_path}" target="_blank">View</a>)</li>`,
            spotlight: d => `<li>${d.content} (${new Date(d.created_at).toLocaleString()})</li>`,
            'manage-marks': d => `<tr><td>${d.username}</td><td>${d.subject}</td><td>${d.score}/100</td></tr>`,

            progress: d => `<tr><td>${d.username}</td><td>${d.subject}</td><td><div class="progress-bar"><div class="progress" style="width:${d.score}%">${d.score}%</div></div></td></tr>`,
            'content-creator': d => `<li>${d.title} (Assigned to ID: ${d.assigned_to})</li>`,
            notifications: d => `<li>${d.content} (${new Date(d.created_at).toLocaleString()})</li>`,
            'lecture-sharing': d => `<li>${d.title} (<a href="${d.file_path}" target="_blank">${d.file_path.split('/').pop()}</a>)</li>`,
            messages: d => `
                <div class="message ${d.sender_id == <?php echo $faculty_id ?> ? 'sent' : 'received'}">
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
    function setupRealTimeUpdates() {
    if (eventSource) eventSource.close();
    eventSource = new EventSource(`?action=stream&last_update=${lastUpdate}`);
    eventSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Received SSE data:', data);
        lastUpdate = data.timestamp || lastUpdate;

        // ... existing updates ...
        if (data.lectures) updateDisplay('manage-course-content', data.lectures);
        // ... other updates ...
    };
    eventSource.onerror = () => {
        console.error('SSE connection error, reconnecting...');
        setTimeout(setupRealTimeUpdates, 1000);
    };
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
                    input.value = '';
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
    console.log('Fetching messages for recipient:', recipientId);
    fetch(`?action=get_messages&other_user_id=${recipientId}`)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Messages response:', data);
            const chatBody = document.getElementById('chatBody');
            if (data.success && Array.isArray(data.messages)) {
                chatBody.innerHTML = '';
                if (data.messages.length === 0) {
                    chatBody.innerHTML = '<p>No previous messages found.</p>';
                } else {
                    data.messages.forEach(message => {
                        const isSent = message.sender_id == <?php echo $faculty_id ?>;
                        chatBody.innerHTML += `
                            <div class="message ${isSent ? 'sent' : 'received'}">
                                <strong>${message.sender_name}:</strong>
                                ${message.message}
                                <small>(${new Date(message.timestamp).toLocaleString()})</small>
                            </div>`;
                        lastMessageId = Math.max(lastMessageId, message.id || 0);
                    });
                    chatBody.scrollTop = chatBody.scrollHeight;
                }
            } else {
                console.error('Failed to fetch messages:', data.message || 'Invalid response');
                chatBody.innerHTML = '<p>Failed to load messages: ' + (data.message || 'Unknown error') + '</p>';
            }
        })
        .catch(error => {
            console.error('Error fetching messages:', error);
            document.getElementById('chatBody').innerHTML = '<p>Failed to load chat history. Please try again.</p>';
            alert('Error loading chat history: ' + error.message);
        });
}
    function setupRealTimeUpdates() {
        if (eventSource) eventSource.close();
        eventSource = new EventSource(`?action=stream&last_update=${lastUpdate}`);
        eventSource.onmessage = function(event) {
            const data = JSON.parse(event.data);
            console.log('Received SSE data:', data);
            lastUpdate = data.timestamp || lastUpdate;

            if (data.spotlight) updateDisplay('spotlight', data.spotlight);
            if (data.marks) {
                updateDisplay('manage-marks', data.marks);
                updateDisplay('progress', data.marks);
            }
            if (data.attendance) updateDisplay('attendance', data.attendance);
            if (data.quizzes) updateDisplay('content-creator', data.quizzes);
            if (data.notifications) updateDisplay('notifications', data.notifications);
            if (data.lectures) updateDisplay('lecture-sharing', data.lectures);
            if (recipientId && data.messages) {
                const chatBody = document.getElementById('chatBody');
                data.messages
                    .filter(msg => !msg.id || msg.id > lastMessageId)
                    .forEach(msg => {
                        const isSent = msg.sender_id == <?php echo $faculty_id ?>;
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
        eventSource.onerror = () => {
            console.error('SSE connection error, reconnecting...');
            setTimeout(setupRealTimeUpdates, 1000);
        };
    }

    // Add CSS for sent/received messages and sidenav transitions
    const style = document.createElement('style');
    style.textContent = `
        .message.sent { text-align: right; background: #e0f7fa; padding: 5px; margin: 5px 0; border-radius: 5px; }
        .message.received { text-align: left; background: #f1f1f1; padding: 5px; margin: 5px 0; border-radius: 5px; }
        .sidenav { transition: width 0.3s ease; }
        .main { transition: margin-left 0.3s ease; }
        .header { transition: left 0.3s ease, width 0.3s ease; }
    `;
    document.head.appendChild(style);

    function openChat(id, name) {
        recipientId = id;
        lastMessageId = 0;
        document.getElementById('chatWindow').classList.add('active');
        document.getElementById('chatTitle').textContent = `Chat with ${name}`;
        document.getElementById('chatBody').innerHTML = '';
        fetchMessages(recipientId);
    }

    function closeChat() {
        document.getElementById('chatWindow').classList.remove('active');
        recipientId = '';
    }

    function updateMarks(event) {
    event.preventDefault();
    const studentId = document.getElementById('markStudent').value;
    const subject = document.getElementById('markSubject').value.trim();
    const score = document.getElementById('markScore').value;
    const comments = document.getElementById('markComments').value.trim();

    if (!studentId || !subject || !score) {
        alert('Please fill all required fields.');
        return;
    }

    if (isNaN(score) || score < 0 || score > 100) {
        alert('Score must be a number between 0 and 100.');
        return;
    }

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_marks&student_id=${studentId}&subject=${encodeURIComponent(subject)}&score=${score}&comments=${encodeURIComponent(comments)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
    })
    .then(r => {
        if (!r.ok) throw new Error(`HTTP error! Status: ${r.status}`);
        return r.json();
    })
    .then(d => {
        if (d.success) {
            document.getElementById('marksForm').reset();
            alert(d.message);
            fetch('?action=get_marks')
                .then(r => r.json())
                .then(data => updateDisplay('manage-marks', data.marks));
        } else {
            alert('Failed to update marks: ' + d.message);
        }
    })
    .catch(error => {
        console.error("Error updating marks:", error);
        alert('Error updating marks: ' + error.message);
    });
}
    function markAttendance(event) {
    event.preventDefault();
    const studentId = document.getElementById('attStudent').value;
    const courseId = document.getElementById('attCourse').value;
    const date = document.getElementById('attDate').value;
    const status = document.getElementById('attStatus').value;

    if (studentId && courseId && date && status) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_attendance&student_id=${studentId}&course_id=${courseId}&date=${date}&status=${status}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        })
        .then(r => {
            console.log('Response Status:', r.status); // Log status
            if (!r.ok) {
                return r.text().then(text => {
                    throw new Error(`HTTP error! Status: ${r.status}, Response: ${text}`);
                });
            }
            return r.json();
        })
        .then(d => {
            console.log('Response Data:', d); // Log response data
            if (d.success) {
                document.querySelector('#attendance form').reset();
                alert(d.message);
            } else {
                alert('Failed to mark attendance: ' + (d.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error("Error marking attendance:", error);
            alert('Error marking attendance. Please try again.');
        });
    } else {
        alert('Please fill all fields.');
    }
}
    function createContent(event) {
        event.preventDefault();
        const title = document.getElementById('contentTitle').value;
        const studentId = document.getElementById('contentStudent').value;
        if (title && studentId) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=create_quiz&title=${encodeURIComponent(title)}&assigned_to=${studentId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('contentForm').reset();
                    alert('Quiz created successfully!');
                } else {
                    alert('Failed to create quiz: ' + (d.message || 'Unknown error'));
                }
            }).catch(error => {
                console.error("Error creating quiz:", error);
                alert('Error creating quiz. Please try again.');
            });
        }
    }

    function sendNotification(event) {
        event.preventDefault();
        const text = document.getElementById('notifText').value;
        if (text) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=send_notification&content=${encodeURIComponent(text)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('notifText').value = '';
                    alert('Notification sent successfully!');
                } else {
                    alert('Failed to send notification: ' + (d.message || 'Unknown error'));
                }
            }).catch(error => {
                console.error("Error sending notification:", error);
                alert('Error sending notification. Please try again.');
            });
        }
    }

    function uploadLecture(event) {
        event.preventDefault();
        const title = document.getElementById('lectureTitle').value;
        const filePath = document.getElementById('lecturePath').value;
        if (title && filePath) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=upload_lecture&title=${encodeURIComponent(title)}&file_path=${encodeURIComponent(filePath)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('lectureForm').reset();
                    alert('Lecture uploaded successfully!');
                } else {
                    alert('Failed to upload lecture: ' + (d.message || 'Unknown error'));
                }
            }).catch(error => {
                console.error("Error uploading lecture:", error);
                alert('Error uploading lecture. Please try again.');
            });
        }
    }

    function addSpotlight(event) {
        event.preventDefault();
        const text = document.getElementById('spotlightText').value;
        if (text) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add_spotlight&content=${encodeURIComponent(text)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('spotlightText').value = '';
                    alert('Spotlight added successfully!');
                } else {
                    alert('Failed to add spotlight: ' + (d.message || 'Unknown error'));
                }
            }).catch(error => {
                console.error("Error adding spotlight:", error);
                alert('Error adding spotlight. Please try again.');
            });
        }
    }
    function uploadCourseContent(event) {
    event.preventDefault();
    const form = document.getElementById('courseContentForm');
    const formData = new FormData(form);
    formData.append('action', 'upload_course_content');
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            form.reset();
            alert(d.message);
            // Optionally fetch updated list immediately
            fetchCourseContentList();
        } else {
            alert('Failed to upload content: ' + (d.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error("Error uploading course content:", error);
        alert('Error uploading content. Please try again.');
    });
}

// Optional: Fetch updated list
function fetchCourseContentList() {
    fetch('?action=get_lectures')
        .then(r => r.json())
        .then(d => updateDisplay('manage-course-content', d.lectures));
}

    function logout() {
        window.location.href = 'logout.php';
    }

    window.onload = () => {
        setActiveSection('dashboard');
        setupRealTimeUpdates();
        setActiveSection('dashboard');
        setupRealTimeUpdates();
        // Initial fetch to populate data
        fetch('?action=get_spotlight').then(r => r.json()).then(d => updateDisplay('spotlight', d.spotlight));
        fetch('?action=get_marks')
        .then(r => r.json())
        .then(d => {
            updateDisplay('manage-marks', d.marks);
            updateDisplay('progress', d.marks);
        });
        fetch('?action=get_lectures').then(r => r.json()).then(d => updateDisplay('manage-course-content', d.lectures));
        fetch('?action=get_attendance').then(r => r.json()).then(d => updateDisplay('attendance', d.attendance));
        fetch('?action=get_quizzes').then(r => r.json()).then(d => updateDisplay('content-creator', d.quizzes));
        fetch('?action=get_notifications').then(r => r.json()).then(d => updateDisplay('notifications', d.notifications));
        fetch('?action=get_lectures').then(r => r.json()).then(d => updateDisplay('lecture-sharing', d.lectures));
        fetch('?action=get_progress')
        .then(r => r.json())
        .then(d => {
            console.log('Fetched Progress:', d);
            if (d.success) {
                updateDisplay('progress', d.progress);
                document.querySelector('#progress p').textContent = `Average Marks: ${d.average_marks}% | Average Attendance: ${d.average_attendance}%`;
            }
        })
        .catch(e => console.error('Progress fetch error:', e));};   
</script>