const express = require('express');
const router = express.Router();
const Attendance = require('../models/Attendance');
const Student = require('../models/Student');
const auth = require('../middleware/auth');

// Mark Attendance (Teacher/Admin only)
router.post('/', auth, async (req, res) => {
    if (req.user.role !== 'admin' && req.user.role !== 'teacher') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    const { studentId, status } = req.body;
    try {
        const student = await Student.findOne({ studentId });
        if (!student) {
            return res.status(404).json({ msg: 'Student not found' });
        }

        const newAttendance = new Attendance({
            student: student._id,
            status
        });
        const attendance = await newAttendance.save();
        res.json(attendance);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Get Attendance Records (Admin/Teacher/Parent - parents only see their children's)
router.get('/', auth, async (req, res) => {
    try {
        let attendanceRecords;
        if (req.user.role === 'parent') {
            const students = await Student.find({ parent: req.user.id });
            const studentIds = students.map(student => student._id);
            attendanceRecords = await Attendance.find({ student: { $in: studentIds } }).populate('student');
        } else {
            attendanceRecords = await Attendance.find().populate('student');
        }
        res.json(attendanceRecords);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Get Attendance by Student ID
router.get('/student/:studentId', auth, async (req, res) => {
    try {
        const student = await Student.findOne({ studentId: req.params.studentId });
        if (!student) {
            return res.status(404).json({ msg: 'Student not found' });
        }

        if (req.user.role === 'parent' && student.parent.toString() !== req.user.id) {
            return res.status(403).json({ msg: 'Access denied' });
        }

        const attendanceRecords = await Attendance.find({ student: student._id }).populate('student');
        res.json(attendanceRecords);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

module.exports = router;
