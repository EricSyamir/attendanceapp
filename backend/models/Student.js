const mongoose = require('mongoose');

const studentSchema = new mongoose.Schema({
    name: {
        type: String,
        required: true
    },
    studentId: {
        type: String,
        required: true,
        unique: true
    },
    class: {
        type: String,
        required: true
    },
    parent: {
        type: mongoose.Schema.Types.ObjectId,
        ref: 'User'
    },
    faceDescriptor: {
        type: [Number], // Store facial recognition descriptor as an array of numbers
        required: false // Not required initially, will be added during enrollment
    }
});

module.exports = mongoose.model('Student', studentSchema);
