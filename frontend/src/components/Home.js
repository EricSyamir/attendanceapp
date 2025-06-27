import React from 'react';
import { Container, Typography, Box, Button } from '@mui/material';
import { Link } from 'react-router-dom';

function Home() {
  return (
    <Container maxWidth="md">
      <Box sx={{ mt: 8, display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
        <Typography component="h1" variant="h3" gutterBottom>
          Smart School Attendance & Homework Tracker
        </Typography>
        <Typography variant="h6" align="center" color="text.secondary" paragraph>
          Streamline school operations with facial recognition attendance, easy homework management, and comprehensive student progress tracking.
        </Typography>
        <Box sx={{ mt: 4 }}>
          <Button variant="contained" component={Link} to="/login" sx={{ mr: 2 }}>
            Login
          </Button>
          <Button variant="outlined" component={Link} to="/register">
            Register
          </Button>
        </Box>
      </Box>
    </Container>
  );
}

export default Home;
