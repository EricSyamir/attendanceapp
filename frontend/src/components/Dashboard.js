import React, { useEffect, useState } from 'react';
import { Container, Typography, Box, Button } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';

function Dashboard() {
    const [user, setUser] = useState(null);
    const navigate = useNavigate();

    useEffect(() => {
        const fetchUser = async () => {
            try {
                const token = localStorage.getItem('token');
                if (!token) {
                    navigate('/login');
                    return;
                }
                // In a real app, you'd have an endpoint to get user details from the token
                // For now, we'll just assume the user is logged in if a token exists
                // and display a generic dashboard.
                // You would decode the JWT token here to get user role and display specific content.
                setUser({ username: 'Logged In User', role: 'unknown' }); // Placeholder
            } catch (err) {
                console.error('Error fetching user data:', err);
                localStorage.removeItem('token');
                navigate('/login');
            }
        };
        fetchUser();
    }, [navigate]);

    const handleLogout = () => {
        localStorage.removeItem('token');
        navigate('/login');
    };

    if (!user) {
        return <Typography>Loading dashboard...</Typography>;
    }

    return (
        <Container maxWidth="lg">
            <Box sx={{ mt: 8, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <Typography component="h1" variant="h4" gutterBottom>
                    Welcome to your Dashboard, {user.username}!
                </Typography>
                <Typography variant="h6" color="text.secondary" paragraph>
                    Your role: {user.role}
                </Typography>
                <Button variant="contained" color="secondary" onClick={handleLogout}>
                    Logout
                </Button>
                {/* TODO: Add conditional rendering based on user.role for different dashboards */}
            </Box>
        </Container>
    );
}

export default Dashboard;
