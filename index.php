<?php
/**
 * Standalone Face Attendance System
 * Single PHP file with all functionality
 */

// Database Configuration
date_default_timezone_set('Asia/Kuala_Lumpur');
define('DB_HOST', 'sql309.infinityfree.com');
define('DB_USER', 'if0_40367615');
define('DB_PASS', '6NcruMR3Ulv5'); // Replace with your actual MySQL password
define('DB_NAME', 'if0_40367615_face');
define('DB_PORT', 3306);

// Database Connection
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Database connection failed. Please check your configuration.");
    }
}

// Timetable Configuration (Hard-coded)
function getTimetable() {
    // Japanese class: 8:00 PM - 10:00 PM (20:00 - 22:00)
    return [
        [
            'subject' => 'Japanese',
            'subject_code' => 'JPN101',
            'start_time' => '20:00:00', // 8:00 PM
            'end_time' => '22:00:00',   // 10:00 PM
            'day' => 'all' // Available all days
        ]
    ];
}

// Get current class information
function getCurrentClass() {
    $timetable = getTimetable();
    $current_time_str = date('H:i:s');
    $current_time = strtotime($current_time_str);
    $current_day = strtolower(date('l')); // Monday, Tuesday, etc.
    
    foreach ($timetable as $class) {
        $start = $class['start_time'];
        $end = $class['end_time'];
        $start_time = strtotime($start);
        $end_time = strtotime($end);
        
        // Check if current time is within class hours (using timestamp comparison)
        if ($current_time >= $start_time && $current_time <= $end_time) {
            return [
                'has_class' => true,
                'subject' => $class['subject'],
                'subject_code' => $class['subject_code'],
                'start_time' => date('h:i A', $start_time),
                'end_time' => date('h:i A', $end_time),
                'start_time_24h' => $start,
                'end_time_24h' => $end,
                'is_late' => checkIfLate($current_time_str, $start),
                'current_time' => $current_time_str,
                'current_time_formatted' => date('h:i A', $current_time)
            ];
        }
    }
    
    return [
        'has_class' => false,
        'current_time' => $current_time_str,
        'current_time_formatted' => date('h:i A', $current_time)
    ];
}

// Check if student is late (15 minutes grace period)
function checkIfLate($current_time, $class_start_time) {
    $current = strtotime($current_time);
    $start = strtotime($class_start_time);
    $grace_period = 15 * 60; // 15 minutes in seconds
    
    return ($current - $start) > $grace_period;
}

// Handle API Requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        exit(0);
    }
    
    $action = $_GET['action'];
    
    try {
        $conn = getDBConnection();
        
        switch ($action) {
            case 'get_faces':
                // Get all registered faces
                $sql = "SELECT student_id, name, student_code, class, face_encoding 
                        FROM students 
                        WHERE face_encoding IS NOT NULL AND face_encoding != ''";
                $result = $conn->query($sql);
                $faces = [];
                while ($row = $result->fetch_assoc()) {
                    $faces[] = [
                        'student_id' => $row['student_id'],
                        'name' => $row['name'],
                        'student_code' => $row['student_code'],
                        'class' => $row['class'],
                        'course' => $row['class'],
                        'encoding' => $row['face_encoding']
                    ];
                }
                echo json_encode(['success' => true, 'faces' => $faces, 'count' => count($faces)]);
                break;
                
            case 'get_current_class':
                // Get current class information
                $current_class = getCurrentClass();
                echo json_encode(['success' => true, 'class' => $current_class]);
                break;
                
            case 'mark_attendance':
                // Mark attendance
                $input = json_decode(file_get_contents('php://input'), true);
                $student_id = intval($input['student_id'] ?? 0);
                $confidence = floatval($input['confidence'] ?? 0);
                
                if ($student_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
                    exit;
                }
                
                // Get student details
                $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$student) {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                    exit;
                }
                
                $today = date('Y-m-d');
                $now = date('Y-m-d H:i:s');
                $current_time = date('H:i:s');
                
                // Get current class information
                $current_class = getCurrentClass();
                
                // Check if attendance already marked today
                $check_stmt = $conn->prepare("SELECT * FROM attendance WHERE student_id = ? AND attendance_date = ?");
                $check_stmt->bind_param("is", $student_id, $today);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($existing) {
                    // Update checkout time
                    $update_stmt = $conn->prepare("UPDATE attendance SET check_out_time = ?, face_confidence = ? WHERE attendance_id = ?");
                    $update_stmt->bind_param("sdi", $now, $confidence, $existing['attendance_id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Check-out time updated',
                        'action' => 'checkout',
                        'attendance_id' => $existing['attendance_id'],
                        'student_name' => $student['name'],
                        'check_in_time' => date('h:i A', strtotime($existing['check_in_time'])),
                        'check_out_time' => date('h:i A'),
                        'class_info' => $current_class
                    ]);
                } else {
                    // Mark new attendance with timetable-based late detection
                    $status = 'present';
                    $is_late = false;
                    $class_subject = 'N/A';
                    $class_code = 'N/A';
                    
                    if ($current_class['has_class']) {
                        $class_subject = $current_class['subject'];
                        $class_code = $current_class['subject_code'];
                        $is_late = checkIfLate($current_time, $current_class['start_time_24h']);
                        
                        if ($is_late) {
                            $status = 'late';
                        }
                    } else {
                        // No class scheduled, but still mark attendance
                        // Check if it's after 8:15 PM (15 min grace period for 8 PM class)
                        if ($current_time >= '20:15:00') {
                            $status = 'late';
                        }
                    }
                    
                    $insert_stmt = $conn->prepare("INSERT INTO attendance (student_id, attendance_date, check_in_time, status, face_confidence) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->bind_param("isssd", $student_id, $today, $now, $status, $confidence);
                    $insert_stmt->execute();
                    $attendance_id = $conn->insert_id;
                    $insert_stmt->close();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Attendance marked successfully',
                        'action' => 'checkin',
                        'attendance_id' => $attendance_id,
                        'student_name' => $student['name'],
                        'student_code' => $student['student_code'],
                        'class' => $student['class'],
                        'course' => $student['class'],
                        'status' => $status,
                        'check_in_time' => date('h:i A'),
                        'is_late' => $is_late,
                        'class_info' => $current_class
                    ]);
                }
                break;
                
            case 'get_recent':
                // Get recent attendance records
                $sql = "SELECT a.*, s.name, s.student_code, s.class 
                        FROM attendance a 
                        JOIN students s ON a.student_id = s.student_id 
                        ORDER BY a.check_in_time DESC 
                        LIMIT 20";
                $result = $conn->query($sql);
                $records = [];
                while ($row = $result->fetch_assoc()) {
                    $records[] = [
                        'time' => date('h:i A', strtotime($row['check_in_time'])),
                        'name' => $row['name'],
                        'class' => $row['class'],
                        'course' => $row['class'],
                        'status' => $row['status'],
                        'date' => date('M d, Y', strtotime($row['attendance_date']))
                    ];
                }
                echo json_encode(['success' => true, 'attendance' => $records]);
                break;
                
            case 'get_admin_stats':
                // Get admin statistics
                $today = date('Y-m-d');
                
                // Total attendance today
                $sql_today = "SELECT COUNT(*) as total FROM attendance WHERE attendance_date = ?";
                $stmt = $conn->prepare($sql_today);
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $today_result = $stmt->get_result()->fetch_assoc();
                $today_total = $today_result['total'] ?? 0;
                $stmt->close();
                
                // On time today
                $sql_ontime = "SELECT COUNT(*) as total FROM attendance WHERE attendance_date = ? AND status = 'present'";
                $stmt = $conn->prepare($sql_ontime);
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $ontime_result = $stmt->get_result()->fetch_assoc();
                $ontime_total = $ontime_result['total'] ?? 0;
                $stmt->close();
                
                // Late today
                $sql_late = "SELECT COUNT(*) as total FROM attendance WHERE attendance_date = ? AND status = 'late'";
                $stmt = $conn->prepare($sql_late);
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $late_result = $stmt->get_result()->fetch_assoc();
                $late_total = $late_result['total'] ?? 0;
                $stmt->close();
                
                // Total registered students
                $sql_students = "SELECT COUNT(*) as total FROM students WHERE face_encoding IS NOT NULL AND face_encoding != ''";
                $result = $conn->query($sql_students);
                $students_result = $result->fetch_assoc();
                $students_total = $students_result['total'] ?? 0;
                
                // Get detailed attendance for today
                $sql_details = "SELECT a.*, s.name, s.student_code, s.class 
                                FROM attendance a 
                                JOIN students s ON a.student_id = s.student_id 
                                WHERE a.attendance_date = ?
                                ORDER BY a.check_in_time DESC";
                $stmt = $conn->prepare($sql_details);
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $details_result = $stmt->get_result();
                $details = [];
                while ($row = $details_result->fetch_assoc()) {
                    $details[] = [
                        'time' => date('h:i A', strtotime($row['check_in_time'])),
                        'name' => $row['name'],
                        'student_code' => $row['student_code'],
                        'class' => $row['class'],
                        'status' => $row['status'],
                        'check_in' => date('h:i A', strtotime($row['check_in_time'])),
                        'check_out' => $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : 'N/A'
                    ];
                }
                $stmt->close();
                
                // Calculate detection accuracy from feedback
                $sql_feedback = "SELECT 
                    COUNT(*) as total_feedback,
                    SUM(CASE WHEN detection_feedback = 'positive' THEN 1 ELSE 0 END) as positive_feedback,
                    SUM(CASE WHEN detection_feedback = 'negative' THEN 1 ELSE 0 END) as negative_feedback
                    FROM attendance 
                    WHERE attendance_date = ? AND detection_feedback IS NOT NULL";
                $stmt = $conn->prepare($sql_feedback);
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $feedback_result = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $total_feedback = intval($feedback_result['total_feedback'] ?? 0);
                $positive_feedback = intval($feedback_result['positive_feedback'] ?? 0);
                $negative_feedback = intval($feedback_result['negative_feedback'] ?? 0);
                $accuracy = $total_feedback > 0 ? round(($positive_feedback / $total_feedback) * 100, 1) : 0;
                
                echo json_encode([
                    'success' => true,
                    'stats' => [
                        'today_total' => intval($today_total),
                        'ontime_total' => intval($ontime_total),
                        'late_total' => intval($late_total),
                        'students_total' => intval($students_total),
                        'detection_accuracy' => $accuracy,
                        'total_feedback' => $total_feedback,
                        'positive_feedback' => $positive_feedback,
                        'negative_feedback' => $negative_feedback
                    ],
                    'attendance_details' => $details
                ]);
                break;
                
            case 'save_feedback':
                // Save user feedback for detection accuracy
                $input = json_decode(file_get_contents('php://input'), true);
                $attendance_id = intval($input['attendance_id'] ?? 0);
                $feedback = $input['feedback'] ?? ''; // 'positive' or 'negative'
                
                if ($attendance_id <= 0 || !in_array($feedback, ['positive', 'negative'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid feedback data']);
                    exit;
                }
                
                // Check if attendance record exists
                $check_stmt = $conn->prepare("SELECT attendance_id FROM attendance WHERE attendance_id = ?");
                $check_stmt->bind_param("i", $attendance_id);
                $check_stmt->execute();
                $exists = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if (!$exists) {
                    echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
                    exit;
                }
                
                // Try to add feedback column if it doesn't exist
                try {
                    // Check if column exists
                    $check_col = $conn->query("SHOW COLUMNS FROM attendance LIKE 'detection_feedback'");
                    if ($check_col->num_rows == 0) {
                        // Column doesn't exist, add it
                        $conn->query("ALTER TABLE attendance ADD COLUMN detection_feedback ENUM('positive', 'negative') NULL");
                    }
                } catch (Exception $e) {
                    // Column might already exist or error occurred, continue
                    error_log("Feedback column check error: " . $e->getMessage());
                }
                
                $update_stmt = $conn->prepare("UPDATE attendance SET detection_feedback = ? WHERE attendance_id = ?");
                $update_stmt->bind_param("si", $feedback, $attendance_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Feedback saved successfully',
                    'feedback' => $feedback
                ]);
                break;
                
            case 'register_face':
                // Register a new face
                $input = json_decode(file_get_contents('php://input'), true);
                $name = trim($input['name'] ?? '');
                $student_code = trim($input['student_code'] ?? '');
                $class = trim($input['class'] ?? '');
                $face_encoding = $input['face_encoding'] ?? '';
                
                if (empty($name) || empty($student_code) || empty($class) || empty($face_encoding)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit;
                }
                
                // Check if student code already exists
                $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE student_code = ?");
                $check_stmt->bind_param("s", $student_code);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($existing) {
                    // Update existing student
                    $update_stmt = $conn->prepare("UPDATE students SET name = ?, class = ?, face_encoding = ? WHERE student_code = ?");
                    $update_stmt->bind_param("ssss", $name, $class, $face_encoding, $student_code);
                    $update_stmt->execute();
                    $update_stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Face updated successfully', 'student_id' => $existing['student_id']]);
                } else {
                    // Insert new student
                    $insert_stmt = $conn->prepare("INSERT INTO students (name, student_code, class, face_encoding) VALUES (?, ?, ?, ?)");
                    $insert_stmt->bind_param("ssss", $name, $student_code, $class, $face_encoding);
                    $insert_stmt->execute();
                    $student_id = $conn->insert_id;
                    $insert_stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Face registered successfully', 'student_id' => $student_id]);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UTP Face Attendance System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #003366 0%, #004d99 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #003366 0%, #004d99 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #FFD700 0%, #FFA500 100%);
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .tabs {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .tab {
            flex: 1;
            padding: 15px 30px;
            background: transparent;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            color: #666;
            transition: all 0.3s;
        }
        
        .tab.active {
            color: #003366;
            border-bottom: 3px solid #FFD700;
            background: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .camera-section {
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .register-section {
            padding: 30px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #003366;
        }
        
        .video-container {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        #video {
            width: 640px;
            height: 480px;
            display: block;
            background: #000;
        }
        
        #canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 640px;
            height: 480px;
        }
        
        .controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #003366;
            color: white;
        }
        
        .btn-primary:hover {
            background: #004d99;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 51, 102, 0.4);
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(72, 187, 120, 0.4);
        }
        
        .status {
            padding: 15px 25px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 500;
            text-align: center;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .status.info {
            background: #e6f3ff;
            color: #0066cc;
        }
        
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        
        .status.warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .status.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .student-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            display: none;
        }
        
        .student-info.show {
            display: block;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .student-info h3 {
            color: #003366;
            margin-bottom: 10px;
        }
        
        .student-info p {
            margin: 5px 0;
            font-size: 16px;
        }
        
        .attendance-log {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .attendance-log h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background: #003366;
            color: white;
            font-weight: 600;
        }
        
        tr:hover {
            background: #f1f1f1;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .class-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .class-info-card.no-class {
            background: linear-gradient(135deg, #868e96 0%, #495057 100%);
        }
        
        .class-info-card h3 {
            margin: 0 0 15px 0;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .class-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .class-detail-item {
            background: rgba(255,255,255,0.2);
            padding: 12px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }
        
        .class-detail-item strong {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .class-detail-item span {
            font-size: 1.1em;
            font-weight: 600;
        }
        
        .late-indicator {
            display: inline-block;
            padding: 8px 16px;
            background: #ff4757;
            color: white;
            border-radius: 20px;
            font-weight: 600;
            margin-top: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .timetable-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        
        .timetable-section h3 {
            color: #003366;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .timetable-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #003366;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .timetable-item.active {
            border-left-color: #48bb78;
            background: #f0fff4;
        }
        
        .timetable-time {
            font-weight: 600;
            color: #003366;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card .stat-value {
            font-size: 2em;
            font-weight: 700;
            color: #003366;
            margin: 10px 0;
        }
        
        .stat-card .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .feedback-buttons {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            justify-content: center;
            align-items: center;
        }
        
        .feedback-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        
        .feedback-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .feedback-btn.positive {
            background: #48bb78;
            color: white;
        }
        
        .feedback-btn.positive:hover {
            background: #38a169;
        }
        
        .feedback-btn.negative {
            background: #f56565;
            color: white;
        }
        
        .feedback-btn.negative:hover {
            background: #e53e3e;
        }
        
        .feedback-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .feedback-message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            display: none;
        }
        
        .feedback-message.success {
            background: #d4edda;
            color: #155724;
            display: block;
        }
        
        @media (max-width: 768px) {
            #video, #canvas {
                width: 100%;
                max-width: 640px;
                height: auto;
            }
            
            .header h1 {
                font-size: 1.8em;
            }
        }
    </style>
    <script src="face-api.min.js"></script>
</head>
<body>
        <div class="container">
        <div class="header">
            <h1><i class="fas fa-graduation-cap"></i> Universiti Teknologi Petronas</h1>
            <h2 style="font-size: 1.5em; margin-top: 10px; font-weight: 400;">Face Attendance System</h2>
            <p style="margin-top: 10px; opacity: 0.9;">Automated attendance marking using facial recognition</p>
        </div>
        
        <div class="tabs">
            <button class="tab active" onclick="switchTab('attendance')">Mark Attendance</button>
            <button class="tab" onclick="switchTab('register')">Register Face</button>
            <button class="tab" onclick="switchTab('admin')">Admin View</button>
        </div>
        
        <div id="attendance-tab" class="tab-content active">
        <div class="camera-section">
            <!-- Current Class Information Card -->
            <div id="class-info-card" class="class-info-card">
                <h3><i class="fas fa-calendar-alt"></i> Current Class</h3>
                <div id="class-info-content">
                    <p>Loading class information...</p>
                </div>
            </div>
            
            <!-- Timetable Section -->
            <div class="timetable-section">
                <h3><i class="fas fa-clock"></i> Class Schedule</h3>
                <div id="timetable-list">
                    <div class="timetable-item">
                        <div>
                            <strong>Japanese (JPN101)</strong>
                            <div style="color: #666; font-size: 0.9em; margin-top: 5px;">Monday - Sunday</div>
                        </div>
                        <div class="timetable-time">8:00 PM - 10:00 PM</div>
                    </div>
                </div>
            </div>
            
            <div class="video-container">
                <video id="video" autoplay muted></video>
                <canvas id="canvas"></canvas>
            </div>
            
            <div id="status" class="status info">Initializing camera...</div>
            
            <div class="controls">
                <button class="btn btn-primary" onclick="startDetection()">
                    <i class="fas fa-play"></i> Start Detection
                </button>
                <button class="btn btn-success" onclick="captureAndIdentify()">
                    <i class="fas fa-camera"></i> Manual Capture
                </button>
            </div>
            
            <div id="student-info" class="student-info">
                <h3>Student Detected:</h3>
                <p id="student-name"></p>
                <p id="student-course"></p>
                <p id="attendance-status"></p>
                <p id="class-attendance-info" style="margin-top: 10px; font-weight: 600;"></p>
                
                <div id="feedback-section" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e0e0e0; display: none;">
                    <p style="text-align: center; margin-bottom: 10px; font-weight: 600; color: #333;">Was the detection correct?</p>
                    <div class="feedback-buttons">
                        <button class="feedback-btn positive" onclick="submitFeedback('positive')" id="thumbs-up-btn">
                            <i class="fas fa-thumbs-up"></i> Correct
                        </button>
                        <button class="feedback-btn negative" onclick="submitFeedback('negative')" id="thumbs-down-btn">
                            <i class="fas fa-thumbs-down"></i> Incorrect
                        </button>
                    </div>
                    <div id="feedback-message" class="feedback-message"></div>
                </div>
            </div>
            
        </div>
        </div>
        
        <div id="register-tab" class="tab-content">
        <div class="register-section">
            <h2 style="text-align: center; margin-bottom: 30px; color: #003366;">Register New Face</h2>
            
            <div class="video-container">
                <video id="register-video" autoplay muted></video>
                <canvas id="register-canvas"></canvas>
            </div>
            
            <div id="register-status" class="status info" style="margin-top: 20px;">Position your face in the camera</div>
            
            <form id="register-form" style="margin-top: 20px;">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="reg-name" required>
                </div>
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" id="reg-code" required>
                </div>
                <div class="form-group">
                    <label>Course</label>
                    <input type="text" id="reg-class" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-user-plus"></i> Register Face
                </button>
            </form>
        </div>
        </div>
        
        <div id="admin-tab" class="tab-content">
        <div class="camera-section">
            <h2 style="text-align: center; margin-bottom: 30px; color: #003366;">
                <i class="fas fa-chart-line"></i> Admin Dashboard
            </h2>
            
            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Today's Attendance</div>
                    <div class="stat-value" id="admin-today-count">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">On Time</div>
                    <div class="stat-value" id="admin-ontime-count">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Late Arrivals</div>
                    <div class="stat-value" id="admin-late-count">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value" id="admin-students-count">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Detection Accuracy</div>
                    <div class="stat-value" id="admin-accuracy-count">0%</div>
                    <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                        <span id="admin-positive-feedback">0</span> correct / <span id="admin-total-feedback">0</span> feedback
                    </div>
                </div>
            </div>
            
            <!-- Current Class Information -->
            <div id="admin-class-info-card" class="class-info-card" style="margin-bottom: 25px;">
                <h3><i class="fas fa-calendar-alt"></i> Current Class Status</h3>
                <div id="admin-class-info-content">
                    <p>Loading class information...</p>
                </div>
            </div>
            
            <!-- Today's Attendance Details -->
            <div class="attendance-log">
                <h3><i class="fas fa-list"></i> Today's Attendance Details</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>Course</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="admin-attendance-body">
                        <tr>
                            <td colspan="7" style="text-align: center;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Recent Attendance -->
            <div class="attendance-log">
                <h3><i class="fas fa-history"></i> Recent Attendance</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="recent-attendance-body">
                        <tr>
                            <td colspan="5" style="text-align: center;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
        </div>
    </div>
    
    <script>
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        let ctx = canvas.getContext('2d');
        let isDetecting = false;
        let modelsLoaded = false;
        let registeredFaces = [];
        let currentClassInfo = null;
        
        // Fetch and update current class information
        async function updateClassInfo() {
            try {
                const response = await fetch('?action=get_current_class');
                const data = await response.json();
                
                if (data.success && data.class) {
                    currentClassInfo = data.class;
                    displayClassInfo(data.class);
                    updateTimetableStatus(data.class);
                }
            } catch (error) {
                console.error('Error fetching class info:', error);
            }
        }
        
        // Display class information
        function displayClassInfo(classInfo) {
            currentClassInfo = classInfo; // Store for admin tab
            const card = document.getElementById('class-info-card');
            const content = document.getElementById('class-info-content');
            
            if (classInfo.has_class) {
                card.classList.remove('no-class');
                content.innerHTML = `
                    <div class="class-details">
                        <div class="class-detail-item">
                            <strong>Subject</strong>
                            <span>${classInfo.subject}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Subject Code</strong>
                            <span>${classInfo.subject_code}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Time</strong>
                            <span>${classInfo.start_time} - ${classInfo.end_time}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Current Time</strong>
                            <span>${classInfo.current_time_formatted || 'N/A'}</span>
                        </div>
                    </div>
                    ${classInfo.is_late ? '<div class="late-indicator"><i class="fas fa-exclamation-triangle"></i> Late Arrival Period Active</div>' : ''}
                `;
            } else {
                card.classList.add('no-class');
                content.innerHTML = `
                    <div class="class-details">
                        <div class="class-detail-item" style="grid-column: 1 / -1;">
                            <strong>No Class Scheduled</strong>
                            <span>No active class at this time</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Current Time</strong>
                            <span>${classInfo.current_time_formatted || 'N/A'}</span>
                        </div>
                    </div>
                    <div style="margin-top: 15px; opacity: 0.9;">
                        <strong>Next Class:</strong> Japanese (JPN101) - 8:00 PM - 10:00 PM
                    </div>
                `;
            }
        }
        
        // Update timetable active status
        function updateTimetableStatus(classInfo) {
            const timetableItems = document.querySelectorAll('.timetable-item');
            timetableItems.forEach(item => {
                if (classInfo.has_class && item.textContent.includes('Japanese')) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
        }
        
        // Update admin statistics
        async function updateAdminStatistics() {
            try {
                const response = await fetch('?action=get_admin_stats');
                const data = await response.json();
                
                if (data.success && data.stats) {
                    document.getElementById('admin-today-count').textContent = data.stats.today_total;
                    document.getElementById('admin-ontime-count').textContent = data.stats.ontime_total;
                    document.getElementById('admin-late-count').textContent = data.stats.late_total;
                    document.getElementById('admin-students-count').textContent = data.stats.students_total;
                    
                    // Update accuracy statistics
                    document.getElementById('admin-accuracy-count').textContent = (data.stats.detection_accuracy || 0) + '%';
                    document.getElementById('admin-positive-feedback').textContent = data.stats.positive_feedback || 0;
                    document.getElementById('admin-total-feedback').textContent = data.stats.total_feedback || 0;
                    
                    // Update admin attendance table
                    const tbody = document.getElementById('admin-attendance-body');
                    if (data.attendance_details && data.attendance_details.length > 0) {
                        tbody.innerHTML = '';
                        data.attendance_details.forEach(record => {
                            const row = tbody.insertRow();
                            const badgeClass = record.status === 'late' ? 'badge-warning' : 'badge-success';
                            row.innerHTML = `
                                <td>${record.time}</td>
                                <td>${record.name}</td>
                                <td>${record.student_code}</td>
                                <td>${record.class}</td>
                                <td>${record.check_in}</td>
                                <td>${record.check_out}</td>
                                <td><span class="badge ${badgeClass}">${record.status}</span></td>
                            `;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No attendance records for today</td></tr>';
                    }
                }
            } catch (error) {
                console.error('Error updating admin statistics:', error);
            }
        }
        
        // Display admin class info
        function displayAdminClassInfo(classInfo) {
            const card = document.getElementById('admin-class-info-card');
            const content = document.getElementById('admin-class-info-content');
            
            if (classInfo.has_class) {
                card.classList.remove('no-class');
                content.innerHTML = `
                    <div class="class-details">
                        <div class="class-detail-item">
                            <strong>Subject</strong>
                            <span>${classInfo.subject}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Subject Code</strong>
                            <span>${classInfo.subject_code}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Time</strong>
                            <span>${classInfo.start_time} - ${classInfo.end_time}</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Current Time</strong>
                            <span>${classInfo.current_time_formatted || 'N/A'}</span>
                        </div>
                    </div>
                    ${classInfo.is_late ? '<div class="late-indicator"><i class="fas fa-exclamation-triangle"></i> Late Arrival Period Active</div>' : ''}
                `;
            } else {
                card.classList.add('no-class');
                content.innerHTML = `
                    <div class="class-details">
                        <div class="class-detail-item" style="grid-column: 1 / -1;">
                            <strong>No Class Scheduled</strong>
                            <span>No active class at this time</span>
                        </div>
                        <div class="class-detail-item">
                            <strong>Current Time</strong>
                            <span>${classInfo.current_time_formatted || 'N/A'}</span>
                        </div>
                    </div>
                    <div style="margin-top: 15px; opacity: 0.9;">
                        <strong>Next Class:</strong> Japanese (JPN101) - 8:00 PM - 10:00 PM
                    </div>
                `;
            }
        }
        
        // Load face-api.js models
        async function loadModels() {
            try {
                updateStatus('Loading face detection models... (0/3)', 'info');
                
                const MODEL_URL = './models';
                
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                updateStatus('Loading face detection models... (1/3)', 'info');
                
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                updateStatus('Loading face detection models... (2/3)', 'info');
                
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
                updateStatus('Loading face detection models... (3/3)', 'info');
                
                modelsLoaded = true;
                updateStatus('✅ Models loaded! Camera ready.', 'success');
                await loadRegisteredFaces();
            } catch (error) {
                updateStatus('❌ Error loading models: ' + error.message, 'error');
                console.error('Model loading error:', error);
            }
        }
        
        // Initialize camera
        async function initCamera() {
            try {
                updateStatus('Requesting camera access...', 'info');
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { width: 640, height: 480 } 
                });
                video.srcObject = stream;
                
                video.addEventListener('loadeddata', () => {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    updateStatus('📹 Camera ready. Click "Start Detection" to begin.', 'success');
                });
            } catch (error) {
                updateStatus('❌ Error accessing camera. Please check permissions.', 'error');
                alert('Failed to access camera: ' + error.message);
            }
        }
        
        // Load registered faces from database
        async function loadRegisteredFaces() {
            try {
                const response = await fetch('?action=get_faces');
                const data = await response.json();
                
                if (data.success && data.faces) {
                    registeredFaces = data.faces.map(face => ({
                        ...face,
                        descriptor: face.encoding ? new Float32Array(JSON.parse(face.encoding)) : null
                    }));
                    updateStatus(`Loaded ${registeredFaces.length} registered faces`, 'info');
                }
            } catch (error) {
                console.error('Error loading registered faces:', error);
                updateStatus('Warning: Could not load registered faces', 'warning');
            }
        }
        
        // Start face detection
        async function startDetection() {
            if (!modelsLoaded) {
                updateStatus('Please wait for models to load...', 'warning');
                return;
            }
            
            if (isDetecting) {
                isDetecting = false;
                updateStatus('Detection stopped', 'info');
                return;
            }
            
            isDetecting = true;
            updateStatus('Detection started - Position your face in the camera', 'success');
            detectFaces();
        }
        
        // Detect faces continuously
        async function detectFaces() {
            if (!isDetecting) return;
            
            try {
                const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                if (detections.length > 0) {
                    const resizedDetections = faceapi.resizeResults(detections, {
                        width: canvas.width,
                        height: canvas.height
                    });
                    
                    faceapi.draw.drawDetections(canvas, resizedDetections);
                    
                    for (const detection of detections) {
                        await identifyFace(detection.descriptor);
                    }
                }
            } catch (error) {
                console.error('Detection error:', error);
            }
            
            if (isDetecting) {
                setTimeout(() => detectFaces(), 100);
            }
        }
        
        // Identify face against registered faces
        async function identifyFace(descriptor) {
            if (registeredFaces.length === 0) {
                updateStatus('No registered faces in database', 'warning');
                return;
            }
            
            let minDistance = 1;
            let matchedStudent = null;
            
            for (const registered of registeredFaces) {
                if (registered.descriptor) {
                    const distance = faceapi.euclideanDistance(descriptor, registered.descriptor);
                    if (distance < 0.6 && distance < minDistance) {
                        minDistance = distance;
                        matchedStudent = registered;
                    }
                }
            }
            
            if (matchedStudent) {
                updateStatus(`Recognized: ${matchedStudent.name}`, 'success');
                displayStudentInfo(matchedStudent);
                await markAttendance(matchedStudent.student_id, minDistance);
                
                isDetecting = false;
                
                setTimeout(() => {
                    clearStudentInfo();
                    isDetecting = true;
                    detectFaces();
                }, 3000);
            }
        }
        
        // Manual capture and identify
        async function captureAndIdentify() {
            if (!modelsLoaded) {
                updateStatus('Please wait for models to load...', 'warning');
                return;
            }
            
            const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptors();
            
            if (detections.length > 0) {
                await identifyFace(detections[0].descriptor);
            } else {
                updateStatus('No face detected. Please try again.', 'error');
            }
        }
        
        // Mark attendance
        async function markAttendance(studentId, confidence) {
            try {
                const response = await fetch('?action=mark_attendance', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        student_id: studentId,
                        confidence: 1 - confidence // Convert distance to confidence
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    updateAttendanceLog();
                    updateAdminStatistics(); // Update admin stats when attendance is marked
                    
                    let statusText = 'Status: ✅ ' + data.message;
                    let classInfoText = '';
                    
                    if (data.class_info && data.class_info.has_class) {
                        statusText += ` | Class: ${data.class_info.subject}`;
                        if (data.is_late) {
                            statusText = 'Status: ⚠️ LATE - ' + data.message;
                            classInfoText = `⚠️ Arrived late for ${data.class_info.subject} (${data.class_info.start_time})`;
                        } else {
                            classInfoText = `✅ On time for ${data.class_info.subject} (${data.class_info.start_time})`;
                        }
                    }
                    
                    document.getElementById('attendance-status').textContent = statusText;
                    document.getElementById('class-attendance-info').textContent = classInfoText;
                    
                    // Show feedback buttons if attendance_id is available
                    if (data.attendance_id) {
                        showFeedbackButtons(data.attendance_id);
                    }
                    
                    // Update class info if it changed
                    if (data.class_info) {
                        displayClassInfo(data.class_info);
                    }
                } else {
                    document.getElementById('attendance-status').textContent = 
                        'Status: ❌ ' + data.message;
                }
            } catch (error) {
                console.error('Error marking attendance:', error);
            }
        }
        
        // Update attendance log
        async function updateAttendanceLog() {
            try {
                const response = await fetch('?action=get_recent');
                const data = await response.json();
                
                if (data.success && data.attendance) {
                    const tbody = document.getElementById('recent-attendance-body');
                    tbody.innerHTML = '';
                    
                    if (data.attendance.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No records yet</td></tr>';
                    } else {
                        data.attendance.forEach(record => {
                            const row = tbody.insertRow();
                            const badgeClass = record.status === 'late' ? 'badge-warning' : 'badge-success';
                            row.innerHTML = `
                                <td>${record.date || 'N/A'}</td>
                                <td>${record.time}</td>
                                <td>${record.name}</td>
                                <td>${record.course || record.class}</td>
                                <td><span class="badge ${badgeClass}">${record.status}</span></td>
                            `;
                        });
                    }
                }
            } catch (error) {
                console.error('Error updating attendance log:', error);
            }
        }
        
        // Display student information
        function displayStudentInfo(student) {
            const infoDiv = document.getElementById('student-info');
            document.getElementById('student-name').textContent = 'Name: ' + student.name;
            document.getElementById('student-course').textContent = 'Course: ' + (student.course || student.class);
            document.getElementById('class-attendance-info').textContent = '';
            infoDiv.classList.add('show');
        }
        
        // Show feedback buttons
        function showFeedbackButtons(attendanceId) {
            const feedbackSection = document.getElementById('feedback-section');
            const thumbsUpBtn = document.getElementById('thumbs-up-btn');
            const thumbsDownBtn = document.getElementById('thumbs-down-btn');
            const feedbackMessage = document.getElementById('feedback-message');
            
            // Store attendance_id in buttons for feedback submission
            thumbsUpBtn.setAttribute('data-attendance-id', attendanceId);
            thumbsDownBtn.setAttribute('data-attendance-id', attendanceId);
            
            // Reset buttons and message
            thumbsUpBtn.disabled = false;
            thumbsDownBtn.disabled = false;
            feedbackMessage.classList.remove('success');
            feedbackMessage.textContent = '';
            feedbackMessage.style.display = 'none';
            
            // Show feedback section
            feedbackSection.style.display = 'block';
        }
        
        // Submit feedback
        async function submitFeedback(feedbackType) {
            const thumbsUpBtn = document.getElementById('thumbs-up-btn');
            const thumbsDownBtn = document.getElementById('thumbs-down-btn');
            const feedbackMessage = document.getElementById('feedback-message');
            const attendanceId = thumbsUpBtn.getAttribute('data-attendance-id');
            
            if (!attendanceId) {
                feedbackMessage.textContent = 'Error: Attendance ID not found';
                feedbackMessage.style.display = 'block';
                return;
            }
            
            // Disable buttons
            thumbsUpBtn.disabled = true;
            thumbsDownBtn.disabled = true;
            
            try {
                const response = await fetch('?action=save_feedback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        attendance_id: parseInt(attendanceId),
                        feedback: feedbackType
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    feedbackMessage.textContent = feedbackType === 'positive' 
                        ? '✅ Thank you! Detection marked as correct.' 
                        : '⚠️ Thank you! Detection marked as incorrect.';
                    feedbackMessage.classList.add('success');
                    feedbackMessage.style.display = 'block';
                    
                    // Update admin statistics
                    updateAdminStatistics();
                    
                    // Hide feedback section after 3 seconds
                    setTimeout(() => {
                        document.getElementById('feedback-section').style.display = 'none';
                    }, 3000);
                } else {
                    feedbackMessage.textContent = 'Error: ' + (data.message || 'Failed to save feedback');
                    feedbackMessage.style.display = 'block';
                    thumbsUpBtn.disabled = false;
                    thumbsDownBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error submitting feedback:', error);
                feedbackMessage.textContent = 'Error: Failed to submit feedback';
                feedbackMessage.style.display = 'block';
                thumbsUpBtn.disabled = false;
                thumbsDownBtn.disabled = false;
            }
        }
        
        // Clear student information
        function clearStudentInfo() {
            const infoDiv = document.getElementById('student-info');
            infoDiv.classList.remove('show');
            document.getElementById('class-attendance-info').textContent = '';
            document.getElementById('feedback-section').style.display = 'none';
        }
        
        // Update status message
        function updateStatus(message, type = 'info') {
            const statusDiv = document.getElementById('status');
            statusDiv.textContent = message;
            statusDiv.className = 'status ' + type;
        }
        
        // Initialize on page load
        window.addEventListener('load', async () => {
            await loadModels();
            await initCamera();
            await updateClassInfo();
            await updateAttendanceLog();
            await updateAdminStatistics();
            
            // Update class info every minute
            setInterval(async () => {
                await updateClassInfo();
                // If admin tab is active, update admin class info too
                if (document.getElementById('admin-tab').classList.contains('active')) {
                    if (currentClassInfo) {
                        displayAdminClassInfo(currentClassInfo);
                    }
                }
            }, 60000);
            
            // Update admin statistics every 30 seconds (only if admin tab is visible)
            setInterval(() => {
                if (document.getElementById('admin-tab').classList.contains('active')) {
                    updateAdminStatistics();
                }
            }, 30000);
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (video && video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
        });
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate the correct tab button
            const tabButtons = {
                'attendance': 'Mark Attendance',
                'register': 'Register Face',
                'admin': 'Admin View'
            };
            
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.textContent.includes(tabButtons[tabName])) {
                    tab.classList.add('active');
                }
            });
            
            // Initialize registration camera if needed
            if (tabName === 'register' && !registerVideoInitialized) {
                initRegisterCamera();
            }
            
            // Load admin data if admin tab is selected
            if (tabName === 'admin') {
                updateAdminStatistics();
                updateClassInfo().then(() => {
                    if (currentClassInfo) {
                        displayAdminClassInfo(currentClassInfo);
                    }
                });
            }
        }
        
        // Registration functionality
        let registerVideo = null;
        let registerCanvas = null;
        let registerCtx = null;
        let registerVideoInitialized = false;
        
        async function initRegisterCamera() {
            if (registerVideoInitialized) return;
            
            registerVideo = document.getElementById('register-video');
            registerCanvas = document.getElementById('register-canvas');
            registerCtx = registerCanvas.getContext('2d');
            
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { width: 640, height: 480 } 
                });
                registerVideo.srcObject = stream;
                
                registerVideo.addEventListener('loadeddata', () => {
                    registerCanvas.width = registerVideo.videoWidth;
                    registerCanvas.height = registerVideo.videoHeight;
                    updateRegisterStatus('Camera ready. Fill in details and click Register.', 'success');
                    startRegisterPreview();
                });
                
                registerVideoInitialized = true;
            } catch (error) {
                updateRegisterStatus('Error accessing camera: ' + error.message, 'error');
            }
        }
        
        function startRegisterPreview() {
            if (!registerVideo || !registerCanvas) return;
            
            registerCtx.drawImage(registerVideo, 0, 0, registerCanvas.width, registerCanvas.height);
            
            // Detect face
            faceapi.detectAllFaces(registerVideo, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptors()
                .then(detections => {
                    registerCtx.clearRect(0, 0, registerCanvas.width, registerCanvas.height);
                    registerCtx.drawImage(registerVideo, 0, 0, registerCanvas.width, registerCanvas.height);
                    
                    if (detections.length > 0) {
                        const resizedDetections = faceapi.resizeResults(detections, {
                            width: registerCanvas.width,
                            height: registerCanvas.height
                        });
                        faceapi.draw.drawDetections(registerCanvas, resizedDetections);
                        updateRegisterStatus('Face detected! Fill in details and click Register.', 'success');
                    } else {
                        updateRegisterStatus('Position your face in the camera', 'info');
                    }
                });
            
            requestAnimationFrame(startRegisterPreview);
        }
        
        function updateRegisterStatus(message, type) {
            const statusDiv = document.getElementById('register-status');
            if (statusDiv) {
                statusDiv.textContent = message;
                statusDiv.className = 'status ' + type;
            }
        }
        
        // Handle registration form submission
        document.getElementById('register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!modelsLoaded) {
                updateRegisterStatus('Please wait for models to load...', 'warning');
                return;
            }
            
            const name = document.getElementById('reg-name').value.trim();
            const studentCode = document.getElementById('reg-code').value.trim();
            const className = document.getElementById('reg-class').value.trim();
            
            if (!name || !studentCode || !className) {
                updateRegisterStatus('Please fill in all fields', 'error');
                return;
            }
            
            try {
                updateRegisterStatus('Capturing face...', 'info');
                
                const detections = await faceapi.detectAllFaces(registerVideo, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                
                if (detections.length === 0) {
                    updateRegisterStatus('No face detected. Please position your face in the camera.', 'error');
                    return;
                }
                
                const descriptor = detections[0].descriptor;
                const encoding = JSON.stringify(Array.from(descriptor));
                
                updateRegisterStatus('Registering face...', 'info');
                
                const response = await fetch('?action=register_face', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: name,
                        student_code: studentCode,
                        class: className,
                        face_encoding: encoding
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateRegisterStatus('✅ Face registered successfully!', 'success');
                    document.getElementById('register-form').reset();
                    
                    // Reload registered faces for attendance
                    await loadRegisteredFaces();
                    
                    setTimeout(() => {
                        switchTab('attendance');
                        document.querySelector('.tab').click();
                    }, 2000);
                } else {
                    updateRegisterStatus('❌ Error: ' + data.message, 'error');
                }
            } catch (error) {
                updateRegisterStatus('❌ Error: ' + error.message, 'error');
                console.error('Registration error:', error);
            }
        });
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>

