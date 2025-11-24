-- Face Attendance Database Schema
-- Create database
CREATE DATABASE IF NOT EXISTS face_attendance_db;
USE face_attendance_db;

-- Students table
CREATE TABLE IF NOT EXISTS students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    student_code VARCHAR(20) UNIQUE NOT NULL,
    class VARCHAR(20) NOT NULL,
    face_encoding TEXT,
    face_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Attendance table
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time DATETIME NOT NULL,
    check_out_time DATETIME NULL,
    status ENUM('present', 'late', 'absent') DEFAULT 'present',
    face_confidence DECIMAL(5,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE KEY unique_daily_attendance (student_id, attendance_date)
);

