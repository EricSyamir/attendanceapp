import React, { useRef, useEffect, useState } from 'react';
import * as faceapi from 'face-api.js';
import { Container, Typography, Box, Button, CircularProgress } from '@mui/material';
import axios from 'axios';

const MODEL_URL = '/models'; // Path to face-api.js models

function FacialRecognitionAttendance() {
    const videoRef = useRef();
    const canvasRef = useRef();
    const [modelsLoaded, setModelsLoaded] = useState(false);
    const [attendanceStatus, setAttendanceStatus] = useState('');
    const [students, setStudents] = useState([]);

    useEffect(() => {
        const loadModels = async () => {
            await faceapi.nets.tinyFaceDetector.load(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.load(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.load(MODEL_URL);
            await faceapi.nets.faceExpressionNet.load(MODEL_URL);
            setModelsLoaded(true);
        };
        loadModels();
    }, []);

    useEffect(() => {
        if (modelsLoaded) {
            startVideo();
        }
    }, [modelsLoaded]);

    const startVideo = () => {
        navigator.mediaDevices.getUserMedia({ video: {} })
            .then(stream => {
                videoRef.current.srcObject = stream;
            })
            .catch(err => console.error(err));
    };

    const handleVideoPlay = () => {
        const canvas = canvasRef.current;
        const video = videoRef.current;
        const displaySize = { width: video.width, height: video.height };
        faceapi.matchDimensions(canvas, displaySize);

        setInterval(async () => {
            const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceExpressions().withFaceDescriptors();
            const resizedDetections = faceapi.resizeResults(detections, displaySize);
            canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
            faceapi.draw.drawDetections(canvas, resizedDetections);
            faceapi.draw.drawFaceLandmarks(canvas, resizedDetections);
            faceapi.draw.drawFaceExpressions(canvas, resizedDetections);

            if (resizedDetections.length > 0) {
                // For simplicity, let's assume the first detected face is the one we care about
                const faceDescriptor = resizedDetections[0].descriptor;
                verifyAttendance(faceDescriptor);
            }
        }, 100);
    };

    const verifyAttendance = async (faceDescriptor) => {
        try {
            const token = localStorage.getItem('token');
            const res = await axios.post('http://localhost:5000/api/attendance/verify', 
                { faceDescriptor },
                { headers: { 'x-auth-token': token } }
            );
            setAttendanceStatus(res.data.message);
        } catch (err) {
            console.error('Error verifying attendance:', err);
            setAttendanceStatus('Attendance verification failed.');
        }
    };

    const handleEnrollStudent = async (studentId, faceDescriptor) => {
        try {
            const token = localStorage.getItem('token');
            await axios.put(`http://localhost:5000/api/students/${studentId}`, 
                { faceDescriptor: Array.from(faceDescriptor) }, // Convert Float32Array to regular Array
                { headers: { 'x-auth-token': token } }
            );
            alert('Student enrolled successfully!');
        } catch (err) {
            console.error('Error enrolling student:', err);
            alert('Student enrollment failed.');
        }
    };

    // Fetch students to display for enrollment (Teacher/Admin only)
    useEffect(() => {
        const fetchStudents = async () => {
            try {
                const token = localStorage.getItem('token');
                const res = await axios.get('http://localhost:5000/api/students', { headers: { 'x-auth-token': token } });
                setStudents(res.data);
            } catch (err) {
                console.error('Error fetching students:', err);
            }
        };
        if (modelsLoaded) {
            fetchStudents();
        }
    }, [modelsLoaded]);

    return (
        <Container maxWidth="md">
            <Box sx={{ mt: 8, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <Typography component="h1" variant="h4" gutterBottom>
                    Facial Recognition Attendance
                </Typography>
                {!modelsLoaded ? (
                    <Box sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                        <CircularProgress />
                        <Typography variant="body1" sx={{ mt: 2 }}>Loading facial recognition models...</Typography>
                    </Box>
                ) : (
                    <>
                        <video ref={videoRef} onPlay={handleVideoPlay} autoPlay muted width="640" height="480" style={{ backgroundColor: '#000' }} />
                        <canvas ref={canvasRef} style={{ position: 'absolute' }} />
                        <Typography variant="h6" sx={{ mt: 2 }}>{attendanceStatus}</Typography>

                        <Box sx={{ mt: 4, width: '100%' }}>
                            <Typography variant="h5" gutterBottom>Enroll Students</Typography>
                            {students.length > 0 ? (
                                students.map(student => (
                                    <Box key={student._id} sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                                        <Typography>{student.name} ({student.studentId})</Typography>
                                        <Button 
                                            variant="outlined" 
                                            onClick={() => {
                                                const detections = faceapi.detectAllFaces(videoRef.current, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceDescriptors();
                                                detections.then(res => {
                                                    if (res.length > 0) {
                                                        handleEnrollStudent(student._id, res[0].descriptor);
                                                    } else {
                                                        alert('No face detected for enrollment. Please ensure your face is visible.');
                                                    }
                                                });
                                            }}
                                        >
                                            Enroll Face
                                        </Button>
                                    </Box>
                                ))
                            ) : (
                                <Typography>No students found or loaded.</Typography>
                            )}
                        </Box>
                    </>
                )}
            </Box>
        </Container>
    );
}

export default FacialRecognitionAttendance;
