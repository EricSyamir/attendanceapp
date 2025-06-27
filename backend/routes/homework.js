const express = require('express');
const router = express.Router();
const Homework = require('../models/Homework');
const Student = require('../models/Student');
const auth = require('../middleware/auth');

// Assign Homework (Teacher/Admin only)
router.post('/', auth, async (req, res) => {
    if (req.user.role !== 'admin' && req.user.role !== 'teacher') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    const { title, description, assignedTo, dueDate } = req.body;
    try {
        const newHomework = new Homework({
            title,
            description,
            assignedTo,
            assignedBy: req.user.id,
            dueDate
        });
        const homework = await newHomework.save();
        res.json(homework);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Get Homework (Admin/Teacher/Parent - parents only see their children's)
router.get('/', auth, async (req, res) => {
    try {
        let homeworks;
        if (req.user.role === 'parent') {
            const students = await Student.find({ parent: req.user.id });
            const studentIds = students.map(student => student._id);
            homeworks = await Homework.find({ assignedTo: { $in: studentIds } }).populate('assignedTo', 'name').populate('assignedBy', 'username');
        } else {
            homeworks = await Homework.find().populate('assignedTo', 'name').populate('assignedBy', 'username');
        }
        res.json(homeworks);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Update Homework (Teacher/Admin only)
router.put('/:id', auth, async (req, res) => {
    if (req.user.role !== 'admin' && req.user.role !== 'teacher') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    const { title, description, assignedTo, dueDate, completed } = req.body;
    const homeworkFields = {};
    if (title) homeworkFields.title = title;
    if (description) homeworkFields.description = description;
    if (assignedTo) homeworkFields.assignedTo = assignedTo;
    if (dueDate) homeworkFields.dueDate = dueDate;
    if (typeof completed === 'boolean') homeworkFields.completed = completed;

    try {
        let homework = await Homework.findById(req.params.id);
        if (!homework) return res.status(404).json({ msg: 'Homework not found' });

        homework = await Homework.findByIdAndUpdate(
            req.params.id,
            { $set: homeworkFields },
            { new: true }
        );
        res.json(homework);
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

// Delete Homework (Admin only)
router.delete('/:id', auth, async (req, res) => {
    if (req.user.role !== 'admin') {
        return res.status(403).json({ msg: 'Access denied' });
    }
    try {
        await Homework.findByIdAndRemove(req.params.id);
        res.json({ msg: 'Homework removed' });
    } catch (err) {
        console.error(err.message);
        res.status(500).send('Server Error');
    }
});

module.exports = router;
