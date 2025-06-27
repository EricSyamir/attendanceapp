const express = require('express');
const router = express.Router();
const Student = require('../models/Student');
const auth = require('../middleware/auth');

// Add Student (Admin/Teacher only)
router.post('/', auth, async (req, res) => {
    if (req.user.role !== 'admin' && req.user.role !== 'teacher') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    const { name, studentId, _class, parent } = req.body;
    try {
        const newStudent = new Student({
            name,
            studentId,
            class: _class,
            parent
        });
        const student = await newStudent.save();
        res.json(student);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Get All Students (Admin/Teacher/Parent - parents only see their children)
router.get('/', auth, async (req, res) => {
    try {
        let students;
        if (req.user.role === 'parent') {
            students = await Student.find({ parent: req.user.id });
        } else {
            students = await Student.find();
        }
        res.json(students);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Get Student by ID
router.get('/:id', auth, async (req, res) => {
    try {
        const student = await Student.findById(req.params.id);
        if (!student) return res.status(404).json({ msg: 'Student not found' });
        
        if (req.user.role === 'parent' && student.parent.toString() !== req.user.id) {
            return res.status(403).json({ msg: 'Access denied' });
        }

        res.json(student);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Update Student (Admin/Teacher only)
router.put('/:id', auth, async (req, res) => {
    if (req.user.role !== 'admin' && req.user.role !== 'teacher') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    const { name, studentId, _class, parent, faceDescriptor } = req.body;
    const studentFields = {};
    if (name) studentFields.name = name;
    if (studentId) studentFields.studentId = studentId;
    if (_class) studentFields.class = _class;
    if (parent) studentFields.parent = parent;
    if (faceDescriptor) studentFields.faceDescriptor = faceDescriptor;

    try {
        let student = await Student.findById(req.params.id);
        if (!student) return res.status(404).json({ msg: 'Student not found' });

        student = await Student.findByIdAndUpdate(
            req.params.id,
            { $set: studentFields },
            { new: true }
        );
        res.json(student);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Delete Student (Admin only)
router.delete('/:id', auth, async (req, res) => {
    if (req.user.role !== 'admin') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    try {
        await Student.findByIdAndRemove(req.params.id);
        res.json({ msg: 'Student removed' });
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

module.exports = router;
