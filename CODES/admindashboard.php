<?php
ob_start();
session_start();

// Access control: Ensure only admins can access this dashboard
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// CSRF token for security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'college_portal');
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    header("Location: error.php?message=database_error");
    die();
}

$admin_id = $_SESSION['user_id'];

// Fetch admin username if not already in session
if (!isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $_SESSION['username'] = $stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown Admin';
    $stmt->close();
}

// Fetch initial data
$students = $conn->query("SELECT user_id, username, status FROM users WHERE user_type = 'student'")->fetch_all(MYSQLI_ASSOC);
$faculty = $conn->query("SELECT user_id, username FROM users WHERE user_type = 'faculty'")->fetch_all(MYSQLI_ASSOC);
$courses = $conn->query("SELECT id, course_name, id FROM courses")->fetch_all(MYSQLI_ASSOC);
$resources = $conn->query("SELECT title, file_path FROM resources")->fetch_all(MYSQLI_ASSOC);
$attendance_alerts = $conn->query("SELECT u.username, COUNT(CASE WHEN a.status = 'Present' THEN 1 END) / COUNT(*) * 100 as percentage 
                                   FROM users u JOIN attendance a ON u.user_id = a.student_id 
                                   WHERE u.user_type = 'student' GROUP BY u.user_id HAVING percentage < 75")->fetch_all(MYSQLI_ASSOC);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
    }

    switch ($_POST['action']) {
        case 'add_user':
            $username = trim($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $user_type = $_POST['user_type'];
            
            // Check for duplicate username
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username already exists']);
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, password, user_type, status) VALUES (?, ?, ?, 'pending')");
                $stmt->bind_param("sss", $username, $password, $user_type);
                $success = $stmt->execute();
                echo json_encode(['success' => $success, 'message' => $success ? 'User added successfully' : 'Failed to add user']);
            }
            break;

        case 'approve_student':
            $student_id = $_POST['student_id'];
            $status = $_POST['status'];
            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND user_type = 'student'");
            $stmt->bind_param("si", $status, $student_id);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Student status updated']);
            break;

        case 'assign_faculty':
            $course_id = $_POST['course_id'];
            $faculty_id = $_POST['faculty_id'];
            $stmt = $conn->prepare("UPDATE courses SET faculty_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $faculty_id, $course_id);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Faculty assigned']);
            break;

        case 'send_notification':
            $content = $_POST['content'];
            $stmt = $conn->prepare("INSERT INTO notifications (content, created_at) VALUES (?, NOW())");
            $stmt->bind_param("s", $content);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Notification sent']);
            break;

        case 'upload_resource':
            $file = $_FILES['resource'];
            $title = $_POST['title'];
            $upload_dir = 'uploads/resources/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_path = $upload_dir . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                $stmt = $conn->prepare("INSERT INTO resources (title, file_path, uploaded_by) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $title, $file_path, $admin_id);
                $success = $stmt->execute();
                echo json_encode(['success' => $success, 'message' => $success ? 'Resource uploaded' : 'Upload failed']);
            } else {
                echo json_encode(['success' => false, 'message' => 'File upload error']);
            }
            break;

        case 'update_student_details':
            $student_id = $_POST['student_id'];
            $stmt = $conn->prepare("INSERT INTO student_details (student_id, full_name, email, phone, address, date_of_birth, emergency_contact) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE full_name = ?, email = ?, phone = ?, address = ?, date_of_birth = ?, emergency_contact = ?");
            $stmt->bind_param("isssssssisssss", $student_id, $_POST['full_name'], $_POST['email'], $_POST['phone'], $_POST['address'], $_POST['date_of_birth'], $_POST['emergency_contact'], 
                              $_POST['full_name'], $_POST['email'], $_POST['phone'], $_POST['address'], $_POST['date_of_birth'], $_POST['emergency_contact']);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Student details updated']);
            break;

        case 'update_grade':
            $student_id = $_POST['student_id'];
            $subject = $_POST['subject'];
            $score = $_POST['score'];
            $grade = $_POST['grade'];
            $comments = $_POST['comments'];
            $stmt = $conn->prepare("INSERT INTO marks (student_id, subject, score, grade, comments) 
                                    VALUES (?, ?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE score = ?, grade = ?, comments = ?");
            $stmt->bind_param("isidssds", $student_id, $subject, $score, $grade, $comments, $score, $grade, $comments);
            echo json_encode(['success' => $stmt->execute(), 'message' => 'Grade updated']);
            break;
    }
    if (isset($stmt)) $stmt->close();
    $conn->close();
    exit();
}

// Handle GET requests for real-time updates
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {
        case 'get_attendance_report':
            $report = $conn->query("SELECT u.username, COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present, COUNT(*) as total 
                                    FROM users u JOIN attendance a ON u.user_id = a.student_id 
                                    WHERE u.user_type = 'student' GROUP BY u.user_id")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'report' => $report]);
            break;

        case 'get_student_attendance':
            $student_id = $_GET['student_id'];
            $stmt = $conn->prepare("SELECT c.course_name, COUNT(*) as total, SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present 
                                    FROM attendance a JOIN courses c ON a.course_id = c.id 
                                    WHERE a.student_id = ? GROUP BY a.course_id");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'attendance' => $attendance]);
            $stmt->close();
            break;

        case 'get_grades':
            $grades = $conn->query("SELECT u.username, m.subject, m.score, m.grade, m.comments 
                                    FROM users u JOIN marks m ON u.user_id = m.student_id 
                                    WHERE u.user_type = 'student'")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'grades' => $grades]);
            break;

        case 'get_assignment_submissions':
            $submissions = $conn->query("SELECT a.assignment_title, a.file_path, a.deadline, a.status, u.username 
                                         FROM assignment_submissions a JOIN users u ON a.student_id = u.user_id")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'submissions' => $submissions]);
            break;

        case 'get_messages':
            $messages = $conn->query("SELECT m.*, us.username AS sender, ur.username AS receiver 
                                      FROM messages m JOIN users us ON m.sender_id = us.user_id 
                                      JOIN users ur ON m.receiver_id = ur.user_id 
                                      ORDER BY m.timestamp DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'messages' => $messages]);
            break;

        case 'get_personal_details':
            $student_id = $_GET['student_id'];
            $stmt = $conn->prepare("SELECT full_name, email, phone, address, date_of_birth, emergency_contact 
                                    FROM student_details WHERE student_id = ?");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc() ?? [];
            echo json_encode(['success' => true, 'details' => $details]);
            $stmt->close();
            break;

        case 'get_study_streak':
            $streaks = $conn->query("SELECT u.username, COUNT(DISTINCT DATE(login_date)) as streak 
                                     FROM users u LEFT JOIN login_logs l ON u.user_id = l.student_id 
                                     WHERE u.user_type = 'student' GROUP BY u.user_id")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'streaks' => $streaks]);
            break;

        case 'get_users':
            $users = $conn->query("SELECT user_id, username, user_type, status FROM users WHERE user_type IN ('student', 'faculty')")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            break;
    }
    $conn->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - DRS Institute of Technology</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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
        <h1 id="headerTitle">DRS Institute of Technology - Admin Dashboard</h1>
        <div class="user-info">
            <span class="admin-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </div>

    <div class="sidenav" id="mySidenav">
        <a href="#home" class="nav-link active"><i class="fas fa-home"></i><span class="text">Home</span></a>
        <a href="#user-management" class="nav-link"><i class="fas fa-users"></i><span class="text">User Management</span></a>
        <a href="#course-management" class="nav-link"><i class="fas fa-book"></i><span class="text">Course Management</span></a>
        <a href="#enrollment" class="nav-link"><i class="fas fa-user-plus"></i><span class="text">Enrollment Approval</span></a>
        <a href="#attendance" class="nav-link"><i class="fas fa-user-check"></i><span class="text">Attendance Monitoring</span></a>
        <a href="#grades" class="nav-link"><i class="fas fa-edit"></i><span class="text">Exams & Grades</span></a>
        <a href="#notifications" class="nav-link"><i class="fas fa-bell"></i><span class="text">Notifications</span></a>
        <a href="#resources" class="nav-link"><i class="fas fa-folder"></i><span class="text">Resources</span></a>
        <a href="#ai-alerts" class="nav-link"><i class="fas fa-exclamation-triangle"></i><span class="text">AI Attendance Alerts</span></a>
        <a href="#messages" class="nav-link"><i class="fas fa-envelope"></i><span class="text">Messages Overview</span></a>
        <a href="#code-submissions" class="nav-link"><i class="fas fa-code"></i><span class="text">Code Submissions</span></a>
        <a href="#digital-id" class="nav-link"><i class="fas fa-id-card"></i><span class="text">Digital ID</span></a>
        <a href="#study-streak" class="nav-link"><i class="fas fa-fire"></i><span class="text">Study Streak</span></a>
    </div>

    <div class="main" id="mainContent">
        <div id="home" class="section active">
            <h2>Welcome, Admin!</h2>
            <p>Manage the DRS Institute of Technology system efficiently with this modern dashboard.</p>
        </div>

        <div id="user-management" class="section">
            <h2>User Management</h2>
            <div class="form-group">
                <h3>Add New User</h3>
                <form id="addUserForm">
                    <label>Username:</label><input type="text" name="username" required>
                    <label>Password:</label><input type="password" name="password" required>
                    <label>User Type:</label>
                    <select name="user_type">
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                    </select>
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button type="submit" class="button">Add User</button>
                </form>
            </div>
            <h3>Edit Student Details</h3>
            <form id="studentDetailsForm">
                <select name="student_id" onchange="fetchStudentDetails(this.value)">
                    <option value="">Select Student</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-group">
                    <label>Full Name:</label><input type="text" name="full_name">
                    <label>Email:</label><input type="email" name="email">
                    <label>Phone:</label><input type="tel" name="phone">
                    <label>Address:</label><textarea name="address"></textarea>
                    <label>Date of Birth:</label><input type="date" name="date_of_birth">
                    <label>Emergency Contact:</label><input type="tel" name="emergency_contact">
                </div>
                <input type="hidden" name="action" value="update_student_details">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="button">Update</button>
            </form>
            <h3>Existing Users</h3>
            <table id="usersTable">
                <tr><th>Username</th><th>Type</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['username']); ?></td>
                        <td>Student</td>
                        <td><?php echo htmlspecialchars($s['status']); ?></td>
                        <td><button class="button" onclick="alert('Edit/Delete feature coming soon!')">Edit/Delete</button></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($faculty as $f): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($f['username']); ?></td>
                        <td>Faculty</td>
                        <td><?php echo htmlspecialchars($f['status']); ?></td>
                        <td><button class="button" onclick="alert('Edit/Delete feature coming soon!')">Edit/Delete</button></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div id="course-management" class="section">
            <h2>Course Management</h2>
            <table>
                <tr><th>Course Name</th><th>Assigned Faculty</th><th>Actions</th></tr>
                <?php foreach ($courses as $c): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['course_name']); ?></td>
                        <td>
                            <form class="assignFacultyForm">
                                <select name="faculty_id">
                                    <option value="">Select Faculty</option>
                                    <?php foreach ($faculty as $f): ?>
                                        <option value="<?php echo $f['user_id']; ?>" <?php echo $c['id'] == $f['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($f['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                                <input type="hidden" name="action" value="assign_faculty">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="button">Assign</button>
                            </form>
                        </td>
                        <td><button class="button" onclick="alert('Edit/Delete course feature coming soon!')">Edit/Delete</button></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div id="enrollment" class="section">
            <h2>Student Enrollment Approval</h2>
            <table>
                <tr><th>Student Name</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['username']); ?></td>
                        <td><?php echo htmlspecialchars($s['status']); ?></td>
                        <td>
                            <form class="approveStudentForm">
                                <select name="status">
                                    <option value="approved" <?php echo $s['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $s['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="pending" <?php echo $s['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                                <input type="hidden" name="student_id" value="<?php echo $s['user_id']; ?>">
                                <input type="hidden" name="action" value="approve_student">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" class="button">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div id="attendance" class="section">
            <h2>Attendance Monitoring</h2>
            <select id="studentFilter" onchange="fetchStudentAttendance()">
                <option value="">Select Student</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                <?php endforeach; ?>
            </select>
            <table id="studentAttendanceTable">
                <tr><th>Course Name</th><th>Total Days</th><th>Present</th><th>Percentage</th></tr>
            </table>
            <h3>Overall Report</h3>
            <table id="attendanceTable">
                <tr><th>Student Name</th><th>Present</th><th>Total Classes</th><th>Percentage</th></tr>
            </table>
        </div>

        <div id="grades" class="section">
            <h2>Exams & Grades</h2>
            <h3>Update Grade</h3>
            <form id="gradeForm">
                <select name="student_id" onchange="fetchStudentGrades(this.value)">
                    <option value="">Select Student</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-group">
                    <label>Subject:</label><input type="text" name="subject" required>
                    <label>Score (0-100):</label><input type="number" name="score" min="0" max="100" required>
                    <label>Grade:</label><input type="text" name="grade">
                    <label>Comments:</label><textarea name="comments"></textarea>
                </div>
                <input type="hidden" name="action" value="update_grade">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="button">Update Grade</button>
            </form>
            <table id="gradesTable">
                <tr><th>Student Name</th><th>Subject</th><th>Score</th><th>Grade</th><th>Comments</th></tr>
            </table>
        </div>

        <div id="notifications" class="section">
            <h2>Notifications & Announcements</h2>
            <form id="notificationForm">
                <div class="form-group">
                    <label>Announcement:</label>
                    <textarea name="content" rows="3" required></textarea>
                </div>
                <input type="hidden" name="action" value="send_notification">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="button">Send</button>
            </form>
        </div>

        <div id="resources" class="section">
            <h2>Resources & Cloud Storage</h2>
            <form id="resourceForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Title:</label><input type="text" name="title" required>
                    <label>File:</label><input type="file" name="resource" required>
                </div>
                <input type="hidden" name="action" value="upload_resource">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="button">Upload</button>
            </form>
            <h3>Existing Resources</h3>
            <ul>
                <?php foreach ($resources as $r): ?>
                    <li><?php echo htmlspecialchars($r['title']); ?> - <a href="<?php echo htmlspecialchars($r['file_path']); ?>" target="_blank">Download</a></li>
                <?php endforeach; ?>
            </ul>
            <h3>Assignment Submissions</h3>
            <table id="submissionsTable">
                <tr><th>Student</th><th>Title</th><th>Deadline</th><th>Status</th><th>File</th></tr>
            </table>
        </div>

        <div id="ai-alerts" class="section">
            <h2>AI Attendance Alerts</h2>
            <?php if (empty($attendance_alerts)): ?>
                <p>No students with low attendance.</p>
            <?php else: ?>
                <div class="alert">
                    <?php foreach ($attendance_alerts as $alert): ?>
                        <p><strong><?php echo htmlspecialchars($alert['username']); ?>:</strong> Attendance is <?php echo number_format($alert['percentage'], 2); ?>%</p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="messages" class="section">
            <h2>Messages Overview</h2>
            <table id="messagesTable">
                <tr><th>Sender</th><th>Receiver</th><th>Message</th><th>Timestamp</th></tr>
            </table>
        </div>

        <div id="code-submissions" class="section">
            <h2>Code Submissions</h2>
            <p>Feature to review student code submissions coming soon! (Requires backend execution service)</p>
        </div>

        <div id="digital-id" class="section">
            <h2>Digital ID</h2>
            <select id="idStudentFilter" onchange="generateDigitalId(this.value)">
                <option value="">Select Student</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s['user_id']; ?>"><?php echo htmlspecialchars($s['username']); ?></option>
                <?php endforeach; ?>
            </select>
            <div id="idCardContainer"></div>
        </div>

        <div id="study-streak" class="section">
            <h2>Study Streak</h2>
            <table id="streakTable">
                <tr><th>Student Name</th><th>Streak (Days)</th></tr>
            </table>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Navigation toggle
        document.getElementById('mySidenav').addEventListener('click', function() {
            this.classList.toggle('expanded');
            document.getElementById('mainContent').classList.toggle('expanded');
            document.getElementById('myHeader').classList.toggle('expanded');
        });

        const navLinks = document.querySelectorAll('.nav-link');
        const sections = document.querySelectorAll('.section');
        const headerTitle = document.getElementById('headerTitle');

        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const sectionId = this.getAttribute('href').substring(1);
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                sections.forEach(section => {
                    section.classList.remove('active');
                    if (section.id === sectionId) {
                        section.classList.add('active');
                        headerTitle.textContent = `DRS Institute of Technology - ${section.querySelector('h2').textContent}`;
                    }
                });
            });
        });

        // Form submissions
        document.getElementById('addUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(this));
            fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            }).then(r => r.json()).then(d => {
                alert(d.message);
                if (d.success) {
                    this.reset();
                    updateUsersTable();
                }
            });
        });

        document.querySelectorAll('.approveStudentForm').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                fetchForm(this);
            });
        });

        document.querySelectorAll('.assignFacultyForm').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                fetchForm(this);
            });
        });

        document.getElementById('notificationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            fetchForm(this, true);
        });

        document.getElementById('resourceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            fetchForm(this, false, true);
        });

        document.getElementById('studentDetailsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            fetchForm(this);
        });

        document.getElementById('gradeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            fetchForm(this);
        });

        function fetchForm(form, reset = false, isFile = false) {
            const formData = isFile ? new FormData(form) : new URLSearchParams(new FormData(form));
            fetch('', {
                method: 'POST',
                body: formData,
                headers: isFile ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }
            }).then(r => r.json()).then(d => {
                alert(d.message);
                if (d.success) {
                    if (reset) form.reset();
                    else location.reload();
                }
            });
        }

        // Fetch and update data
        function updateUsersTable() {
            fetch('?action=get_users')
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const table = document.getElementById('usersTable');
                        table.innerHTML = '<tr><th>Username</th><th>Type</th><th>Status</th><th>Actions</th></tr>';
                        d.users.forEach(user => {
                            table.innerHTML += `
                                <tr>
                                    <td>${user.username}</td>
                                    <td>${user.user_type}</td>
                                    <td>${user.status}</td>
                                    <td><button class="button" onclick="alert('Edit/Delete feature coming soon!')">Edit/Delete</button></td>
                                </tr>`;
                        });
                    }
                });
        }

        function updateAttendanceReport() {
            fetch('?action=get_attendance_report')
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const table = document.getElementById('attendanceTable');
                        table.innerHTML = '<tr><th>Student Name</th><th>Present</th><th>Total Classes</th><th>Percentage</th></tr>';
                        d.report.forEach(row => {
                            const percentage = (row.present / row.total * 100).toFixed(2);
                            table.innerHTML += `<tr><td>${row.username}</td><td>${row.present}</td><td>${row.total}</td><td>${percentage}%</td></tr>`;
                        });
                    }
                });
        }

        function fetchStudentAttendance() {
            const studentId = document.getElementById('studentFilter').value;
            if (studentId) {
                fetch(`?action=get_student_attendance&student_id=${studentId}`)
                    .then(r => r.json())
                    .then(d => {
                        const table = document.getElementById('studentAttendanceTable');
                        table.innerHTML = '<tr><th>Course Name</th><th>Total Days</th><th>Present</th><th>Percentage</th></tr>';
                        d.attendance.forEach(row => {
                            const percentage = (row.present / row.total * 100).toFixed(2);
                            table.innerHTML += `<tr><td>${row.course_name}</td><td>${row.total}</td><td>${row.present}</td><td>${percentage}%</td></tr>`;
                        });
                    });
            }
        }

        function updateGradesReport() {
            fetch('?action=get_grades')
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const table = document.getElementById('gradesTable');
                        table.innerHTML = '<tr><th>Student Name</th><th>Subject</th><th>Score</th><th>Grade</th><th>Comments</th></tr>';
                        d.grades.forEach(row => {
                            table.innerHTML += `<tr><td>${row.username}</td><td>${row.subject}</td><td>${row.score}/100</td><td>${row.grade || 'N/A'}</td><td>${row.comments || 'N/A'}</td></tr>`;
                        });
                    }
                });
        }

        function fetchStudentGrades(studentId) {
            if (studentId) {
                fetch(`?action=get_grades`)
                    .then(r => r.json())
                    .then(d => {
                        const form = document.getElementById('gradeForm');
                        const grade = d.grades.find(g => g.username === document.querySelector(`#gradeForm select option[value="${studentId}"]`).text);
                        if (grade) {
                            form.subject.value = grade.subject || '';
                            form.score.value = grade.score || '';
                            form.grade.value = grade.grade || '';
                            form.comments.value = grade.comments || '';
                        } else {
                            form.subject.value = '';
                            form.score.value = '';
                            form.grade.value = '';
                            form.comments.value = '';
                        }
                    });
            }
        }

        function fetchStudentDetails(studentId) {
            if (studentId) {
                fetch(`?action=get_personal_details&student_id=${studentId}`)
                    .then(r => r.json())
                    .then(d => {
                        const form = document.getElementById('studentDetailsForm');
                        form.full_name.value = d.details.full_name || '';
                        form.email.value = d.details.email || '';
                        form.phone.value = d.details.phone || '';
                        form.address.value = d.details.address || '';
                        form.date_of_birth.value = d.details.date_of_birth || '';
                        form.emergency_contact.value = d.details.emergency_contact || '';
                    });
            }
        }

        fetch('?action=get_assignment_submissions')
            .then(r => r.json())
            .then(d => {
                const table = document.getElementById('submissionsTable');
                d.submissions.forEach(s => {
                    table.innerHTML += `<tr><td>${s.username}</td><td>${s.assignment_title}</td><td>${s.deadline}</td><td>${s.status}</td><td><a href="${s.file_path}" download>Download</a></td></tr>`;
                });
            });

        fetch('?action=get_messages')
            .then(r => r.json())
            .then(d => {
                const table = document.getElementById('messagesTable');
                d.messages.forEach(m => {
                    table.innerHTML += `<tr><td>${m.sender}</td><td>${m.receiver}</td><td>${m.message}</td><td>${m.timestamp}</td></tr>`;
                });
            });

        fetch('?action=get_study_streak')
            .then(r => r.json())
            .then(d => {
                const table = document.getElementById('streakTable');
                d.streaks.forEach(s => {
                    table.innerHTML += `<tr><td>${s.username}</td><td>${s.streak || 0}</td></tr>`;
                });
            });

        function generateDigitalId(studentId) {
            if (studentId) {
                const username = document.querySelector(`#idStudentFilter option[value="${studentId}"]`).text;
                const container = document.getElementById('idCardContainer');
                container.innerHTML = `
                    <div class="id-card">
                        <div class="id-header">
                            <h3>${username}</h3>
                            <p>ID: DRS${studentId}</p>
                        </div>
                        <div id="qrcode${studentId}" class="qrcode"></div>
                        <div class="id-footer">
                            <p>DRS Institute of Technology</p>
                            <p>Valid Until: March 31, 2026</p>
                        </div>
                    </div>`;
                new QRCode(document.getElementById(`qrcode${studentId}`), {
                    text: `Student: ${username}, ID: DRS${studentId}`,
                    width: 150,
                    height: 150
                });
            }
        }

        // Voice Assistant
        function setupVoiceAssistant() {
            const recognition = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
            recognition.onresult = (event) => {
                const command = event.results[0][0].transcript.toLowerCase();
                const link = [...navLinks].find(l => l.querySelector('.text').textContent.toLowerCase().includes(command));
                if (link) link.click();
                else alert('Command not recognized.');
            };
            recognition.onerror = () => alert('Voice recognition failed.');
            document.addEventListener('keydown', (e) => {
                if (e.key === 'v') recognition.start();
            });
        }

        // Logout
        function logout() {
            window.location.href = 'logout.php';
        }

        // Initialize
        window.onload = function() {
            updateAttendanceReport();
            updateGradesReport();
            updateUsersTable();
            setupVoiceAssistant();
        };
    </script>
</body>
</html>