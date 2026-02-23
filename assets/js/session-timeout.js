/**
 * VLE System - Session Timeout Manager
 * Handles automatic logout after 5 minutes of inactivity
 */

(function() {
    'use strict';
    
    // Session timeout in milliseconds (15 minutes = 900000ms)
    const SESSION_TIMEOUT = 900000; // 15 minutes
    const WARNING_TIME = 60000; // Show warning 1 minute before timeout
    
    let timeoutTimer;
    let warningTimer;
    let lastActivity = Date.now();
    
    // Activities that reset the timer
    const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    
    /**
     * Reset the inactivity timer
     */
    function resetTimer() {
        lastActivity = Date.now();
        
        // Clear existing timers
        clearTimeout(timeoutTimer);
        clearTimeout(warningTimer);
        
        // Hide warning if it's showing
        hideWarning();
        
        // Set warning timer (1 minute before logout)
        warningTimer = setTimeout(showWarning, SESSION_TIMEOUT - WARNING_TIME);
        
        // Set logout timer (5 minutes)
        timeoutTimer = setTimeout(logoutUser, SESSION_TIMEOUT);
        
        // Update server-side session activity
        updateServerActivity();
    }
    
    /**
     * Show warning message before logout
     */
    function showWarning() {
        const warningDiv = document.getElementById('session-warning');
        if (warningDiv) {
            warningDiv.style.display = 'block';
        } else {
            // Create warning element if it doesn't exist
            const warning = document.createElement('div');
            warning.id = 'session-warning';
            warning.className = 'session-timeout-warning';
            warning.innerHTML = `
                <div class="warning-content">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Your session will expire in 1 minute due to inactivity.</p>
                    <p>Move your mouse or press any key to stay logged in.</p>
                    <button onclick="sessionTimeout.resetTimer()" class="btn btn-primary btn-sm">
                        Stay Logged In
                    </button>
                </div>
            `;
            document.body.appendChild(warning);
            
            // Add CSS if not already present
            if (!document.getElementById('session-timeout-styles')) {
                const style = document.createElement('style');
                style.id = 'session-timeout-styles';
                style.textContent = `
                    .session-timeout-warning {
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        background: #fff3cd;
                        border: 2px solid #ffc107;
                        border-radius: 8px;
                        padding: 20px;
                        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                        z-index: 10000;
                        max-width: 400px;
                        animation: slideIn 0.3s ease-out;
                    }
                    .session-timeout-warning .warning-content {
                        text-align: center;
                    }
                    .session-timeout-warning i {
                        font-size: 2em;
                        color: #856404;
                        margin-bottom: 10px;
                    }
                    .session-timeout-warning p {
                        margin: 10px 0;
                        color: #856404;
                    }
                    @keyframes slideIn {
                        from {
                            transform: translateX(400px);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(0);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
    }
    
    /**
     * Hide warning message
     */
    function hideWarning() {
        const warningDiv = document.getElementById('session-warning');
        if (warningDiv) {
            warningDiv.style.display = 'none';
        }
    }
    
    /**
     * Logout user and redirect to login page
     */
    function logoutUser() {
        // Send logout request
        fetch('logout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'auto_logout=1'
        }).finally(() => {
            // Redirect to login with timeout parameter
            const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
            window.location.href = baseUrl.replace(/\/(admin|student|lecturer|finance).*/, '') + '/login.php?timeout=1';
        });
    }
    
    /**
     * Update server-side session activity via AJAX
     */
    function updateServerActivity() {
        // Only update every 30 seconds to reduce server load
        const now = Date.now();
        if (!window.lastServerUpdate || (now - window.lastServerUpdate) > 30000) {
            window.lastServerUpdate = now;
            
            // Send ping to server to keep session alive
            fetch('', {
                method: 'HEAD',
                cache: 'no-cache'
            }).catch(() => {
                // Ignore errors, server will handle timeout
            });
        }
    }
    
    /**
     * Initialize session timeout tracking
     */
    function init() {
        // Only run on authenticated pages (skip login page)
        if (window.location.pathname.includes('login.php')) {
            return;
        }
        
        // Add event listeners for user activity
        events.forEach(event => {
            document.addEventListener(event, resetTimer, true);
        });
        
        // Start the timer
        resetTimer();
        
        // Check every minute if session is still valid
        setInterval(() => {
            const elapsed = Date.now() - lastActivity;
            if (elapsed >= SESSION_TIMEOUT) {
                logoutUser();
            }
        }, 60000); // Check every minute
    }
    
    // Expose public methods
    window.sessionTimeout = {
        resetTimer: resetTimer,
        init: init
    };
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
