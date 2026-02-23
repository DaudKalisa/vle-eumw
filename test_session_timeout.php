<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLE - Session Timeout Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            padding: 20px;
            background: #f8f9fa;
        }
        .test-card {
            max-width: 800px;
            margin: 0 auto;
        }
        .timer-display {
            font-size: 3rem;
            font-weight: bold;
            color: #007bff;
            font-family: 'Courier New', monospace;
        }
        .status-badge {
            font-size: 1.2rem;
            padding: 10px 20px;
        }
        .test-info {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="test-card card shadow">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0">
                    <i class="fas fa-clock"></i>
                    VLE Session Timeout Test Page
                </h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong><i class="fas fa-info-circle"></i> Test Instructions:</strong>
                    <ol class="mb-0 mt-2">
                        <li>Login to the system first</li>
                        <li>Return to this page</li>
                        <li>Stay idle (don't move mouse or press keys) for 4-5 minutes</li>
                        <li>You should see a warning at 4 minutes</li>
                        <li>At 5 minutes, you'll be automatically logged out</li>
                    </ol>
                </div>

                <div class="text-center my-4">
                    <h4>Time Since Last Activity</h4>
                    <div class="timer-display" id="inactivityTimer">00:00</div>
                    <div class="mt-3">
                        <span class="badge status-badge bg-success" id="statusBadge">
                            <i class="fas fa-check-circle"></i> Active
                        </span>
                    </div>
                </div>

                <div class="test-info">
                    <h5><i class="fas fa-cog"></i> Current Settings</h5>
                    <ul>
                        <li><strong>Session Timeout:</strong> 5 minutes (300 seconds)</li>
                        <li><strong>Warning Time:</strong> 1 minute before timeout (at 4 minutes)</li>
                        <li><strong>Auto-Logout:</strong> Enabled</li>
                    </ul>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5><i class="fas fa-mouse"></i> Test Actions</h5>
                                <button onclick="simulateActivity()" class="btn btn-primary mt-2">
                                    Simulate Activity (Reset Timer)
                                </button>
                                <button onclick="checkSession()" class="btn btn-secondary mt-2">
                                    Check Session Status
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5><i class="fas fa-list"></i> Activity Log</h5>
                                <div id="activityLog" style="max-height: 150px; overflow-y: auto; font-size: 0.85rem;">
                                    <small class="text-muted">Waiting for activity...</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mt-4">
                    <strong><i class="fas fa-exclamation-triangle"></i> Note:</strong>
                    This test page requires you to be logged in to the VLE system. If you're not logged in,
                    <a href="login.php" class="alert-link">click here to login</a>.
                </div>

                <div class="text-center mt-3">
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                    <a href="login.php" class="btn btn-outline-secondary">
                        <i class="fas fa-sign-in-alt"></i> Login Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let inactivityStart = Date.now();
        let activityCount = 0;
        const TIMEOUT_MS = 300000; // 5 minutes
        const WARNING_MS = 240000; // 4 minutes

        // Update timer display
        function updateTimer() {
            const elapsed = Date.now() - inactivityStart;
            const seconds = Math.floor(elapsed / 1000);
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            
            document.getElementById('inactivityTimer').textContent = 
                `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            
            // Update status badge
            const statusBadge = document.getElementById('statusBadge');
            if (elapsed >= TIMEOUT_MS) {
                statusBadge.className = 'badge status-badge bg-danger';
                statusBadge.innerHTML = '<i class="fas fa-times-circle"></i> Timed Out';
            } else if (elapsed >= WARNING_MS) {
                statusBadge.className = 'badge status-badge bg-warning text-dark';
                statusBadge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Warning';
            } else {
                statusBadge.className = 'badge status-badge bg-success';
                statusBadge.innerHTML = '<i class="fas fa-check-circle"></i> Active';
            }
        }

        // Log activity
        function logActivity(message) {
            const logDiv = document.getElementById('activityLog');
            const time = new Date().toLocaleTimeString();
            const entry = document.createElement('div');
            entry.className = 'mb-1';
            entry.innerHTML = `<small><strong>${time}:</strong> ${message}</small>`;
            
            if (logDiv.firstChild.textContent === 'Waiting for activity...') {
                logDiv.innerHTML = '';
            }
            
            logDiv.insertBefore(entry, logDiv.firstChild);
            
            // Keep only last 10 entries
            while (logDiv.children.length > 10) {
                logDiv.removeChild(logDiv.lastChild);
            }
        }

        // Reset timer on activity
        function resetTimer() {
            inactivityStart = Date.now();
            activityCount++;
            logActivity(`Activity detected (#${activityCount}) - Timer reset`);
        }

        // Simulate activity button
        function simulateActivity() {
            resetTimer();
            logActivity('Manual reset via button click');
        }

        // Check session status
        function checkSession() {
            fetch('', {
                method: 'HEAD',
                cache: 'no-cache'
            })
            .then(response => {
                if (response.ok) {
                    logActivity('Session check: Active ✓');
                } else {
                    logActivity('Session check: Failed ✗');
                }
            })
            .catch(error => {
                logActivity('Session check: Error - ' + error.message);
            });
        }

        // Monitor user activity
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        events.forEach(event => {
            document.addEventListener(event, resetTimer, true);
        });

        // Update timer every second
        setInterval(updateTimer, 1000);
        
        // Initial log
        logActivity('Test page loaded - Monitoring started');
    </script>
    
    <!-- Include the actual session timeout script -->
    <script src="assets/js/session-timeout.js"></script>
</body>
</html>
