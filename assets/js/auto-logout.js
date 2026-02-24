/**
 * Auto-logout system for VLE
 * Logs out user after 3 hours of inactivity
 */

(function() {
    'use strict';
    
    // Configuration: 3 hours in milliseconds
    const TIMEOUT_DURATION = 3 * 60 * 60 * 1000; // 3 hours
    const WARNING_BEFORE = 5 * 60 * 1000; // Show warning 5 minutes before logout
    const CHECK_INTERVAL = 60 * 1000; // Check every minute
    
    let lastActivity = Date.now();
    let warningShown = false;
    let logoutTimer = null;
    let warningTimer = null;
    
    // Get base URL (handle subdirectory installations)
    function getBaseUrl() {
        const path = window.location.pathname;
        // Find the VLE root directory
        const vleIndex = path.indexOf('/vle');
        if (vleIndex !== -1) {
            return path.substring(0, path.indexOf('/', vleIndex + 1) + 1) || '/';
        }
        // Check for common folder patterns
        const patterns = ['/admin/', '/student/', '/lecturer/', '/finance/'];
        for (const pattern of patterns) {
            const idx = path.indexOf(pattern);
            if (idx !== -1) {
                return path.substring(0, idx + 1);
            }
        }
        // Default: go up one level from current
        return '../';
    }
    
    // Update last activity timestamp
    function updateActivity() {
        lastActivity = Date.now();
        warningShown = false;
        hideWarning();
        
        // Store in localStorage for cross-tab sync
        localStorage.setItem('vle_last_activity', lastActivity.toString());
    }
    
    // Check for inactivity
    function checkInactivity() {
        const now = Date.now();
        const elapsed = now - lastActivity;
        
        // Check localStorage for activity from other tabs
        const storedActivity = localStorage.getItem('vle_last_activity');
        if (storedActivity) {
            const storedTime = parseInt(storedActivity, 10);
            if (storedTime > lastActivity) {
                lastActivity = storedTime;
                warningShown = false;
                hideWarning();
                return;
            }
        }
        
        // If past timeout, logout
        if (elapsed >= TIMEOUT_DURATION) {
            performLogout();
            return;
        }
        
        // If approaching timeout, show warning
        if (elapsed >= (TIMEOUT_DURATION - WARNING_BEFORE) && !warningShown) {
            showWarning();
            warningShown = true;
        }
    }
    
    // Show warning modal/notification
    function showWarning() {
        // Remove existing warning if any
        hideWarning();
        
        const remainingMinutes = Math.ceil((TIMEOUT_DURATION - (Date.now() - lastActivity)) / 60000);
        
        const warningDiv = document.createElement('div');
        warningDiv.id = 'inactivity-warning';
        warningDiv.innerHTML = `
            <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 10px; max-width: 400px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                    <div style="font-size: 60px; margin-bottom: 15px;">⏰</div>
                    <h4 style="margin-bottom: 15px; color: #dc3545;">Session Expiring Soon</h4>
                    <p style="margin-bottom: 20px; color: #666;">You will be logged out in approximately <strong id="countdown-minutes">${remainingMinutes}</strong> minute(s) due to inactivity.</p>
                    <button onclick="window.vleExtendSession()" style="background: #0d6efd; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; margin-right: 10px;">
                        <i class="bi bi-arrow-clockwise"></i> Stay Logged In
                    </button>
                    <button onclick="window.vleLogoutNow()" style="background: #6c757d; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px;">
                        Logout Now
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(warningDiv);
        
        // Start countdown
        startCountdown();
    }
    
    // Countdown timer
    function startCountdown() {
        const countdownEl = document.getElementById('countdown-minutes');
        if (!countdownEl) return;
        
        const countdownInterval = setInterval(() => {
            const remaining = TIMEOUT_DURATION - (Date.now() - lastActivity);
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                performLogout();
                return;
            }
            const minutes = Math.ceil(remaining / 60000);
            if (countdownEl) {
                countdownEl.textContent = minutes;
            }
        }, 30000); // Update every 30 seconds
    }
    
    // Hide warning
    function hideWarning() {
        const warning = document.getElementById('inactivity-warning');
        if (warning) {
            warning.remove();
        }
    }
    
    // Extend session
    window.vleExtendSession = function() {
        updateActivity();
        
        // Ping server to extend session
        fetch(getBaseUrl() + 'api/extend_session.php', {
            method: 'POST',
            credentials: 'same-origin'
        }).catch(() => {
            // Silently fail - session will still be extended on next page load
        });
    };
    
    // Logout now
    window.vleLogoutNow = function() {
        performLogout();
    };
    
    // Perform logout
    function performLogout() {
        // Clear localStorage
        localStorage.removeItem('vle_last_activity');
        localStorage.removeItem('vle_theme');
        
        // Redirect to index page with logout message
        const baseUrl = getBaseUrl();
        window.location.href = baseUrl + 'index.php?session_expired=1';
    }
    
    // Activity event listeners
    const activityEvents = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
    
    // Debounce function to avoid too many updates
    let activityDebounce = null;
    function handleActivity() {
        if (activityDebounce) return;
        activityDebounce = setTimeout(() => {
            updateActivity();
            activityDebounce = null;
        }, 1000); // Debounce to 1 second
    }
    
    // Initialize
    function init() {
        // Set initial activity time
        const storedActivity = localStorage.getItem('vle_last_activity');
        if (storedActivity) {
            lastActivity = parseInt(storedActivity, 10);
        } else {
            updateActivity();
        }
        
        // Add event listeners for user activity
        activityEvents.forEach(event => {
            document.addEventListener(event, handleActivity, { passive: true });
        });
        
        // Check inactivity periodically
        setInterval(checkInactivity, CHECK_INTERVAL);
        
        // Also check on visibility change (tab becomes visible)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                checkInactivity();
            }
        });
        
        // Sync across tabs using storage event
        window.addEventListener('storage', (e) => {
            if (e.key === 'vle_last_activity' && e.newValue) {
                const newTime = parseInt(e.newValue, 10);
                if (newTime > lastActivity) {
                    lastActivity = newTime;
                    warningShown = false;
                    hideWarning();
                }
            }
        });
        
        console.log('VLE Auto-logout initialized (3 hour timeout)');
    }
    
    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
