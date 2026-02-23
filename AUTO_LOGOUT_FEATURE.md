# VLE System - Auto Logout Feature Documentation

## Overview
The VLE system now automatically logs out users after **5 minutes of inactivity** to enhance security.

## Implementation Details

### Timeout Duration
- **Session Timeout**: 5 minutes (300 seconds)
- **Warning Time**: 1 minute before logout (shows warning at 4 minutes of inactivity)

### How It Works

#### Server-Side (PHP)
1. **Session Configuration** (`includes/config.php`)
   - Sets session timeout to 300 seconds
   - Tracks last activity timestamp (`$_SESSION['vle_last_activity']`)
   - Automatically destroys session after timeout period
   - Redirects to login page with timeout parameter

2. **Authentication Checks** (`includes/auth.php`)
   - `isLoggedIn()` function checks session timeout on every page load
   - `login()` function sets initial activity timestamps
   - Updates last activity time on each authenticated request

#### Client-Side (JavaScript)
1. **Activity Detection** (`assets/js/session-timeout.js`)
   - Monitors user interactions: mouse movements, clicks, keypress, scroll, touch
   - Resets timer on any detected activity
   - Shows warning 1 minute before auto-logout
   - Automatically logs out user after 5 minutes of inactivity

2. **Warning System**
   - Displays a prominent warning banner at 4 minutes of inactivity
   - Allows user to click "Stay Logged In" to remain active
   - Warning disappears when user resumes activity

### User Experience

#### Normal Activity
- Users remain logged in as long as they interact with the system
- Timer resets with any mouse/keyboard/touch activity
- No interruption to active users

#### Approaching Timeout (4 minutes)
- Yellow warning banner appears in top-right corner
- Message: "Your session will expire in 1 minute due to inactivity"
- User can click "Stay Logged In" button or simply move mouse to reset timer

#### Timeout Reached (5 minutes)
- Session automatically destroyed
- User redirected to login page
- Warning message displayed: "Your session has expired due to inactivity. Please login again."

## Files Modified

### Backend Files
1. **`includes/config.php`**
   - Added `SESSION_TIMEOUT` constant (300 seconds)
   - Implemented automatic session timeout checking
   - Updates last activity timestamp on each request

2. **`includes/auth.php`**
   - Enhanced `isLoggedIn()` with timeout validation
   - Added activity timestamps to `login()` function
   - Automatic logout on timeout detection

3. **`logout.php`**
   - Added auto-logout detection
   - Redirects to login with timeout parameter

4. **`login.php`**
   - Added timeout message display
   - Shows warning when `?timeout=1` parameter present

### Frontend Files
1. **`assets/js/session-timeout.js`** (NEW)
   - Client-side activity monitoring
   - Warning system implementation
   - Auto-logout functionality

2. **Dashboard Pages** (all modified)
   - `student/dashboard.php`
   - `lecturer/dashboard.php`
   - `admin/dashboard.php`
   - `finance/dashboard.php`
   - All include session-timeout.js script

## Configuration

### Changing Timeout Duration

To modify the timeout period, update these values:

**Server-side** (`includes/config.php`):
```php
define('SESSION_TIMEOUT', 300); // Change 300 to desired seconds
```

**Client-side** (`assets/js/session-timeout.js`):
```javascript
const SESSION_TIMEOUT = 300000; // Change 300000 to desired milliseconds (5min = 300000ms)
const WARNING_TIME = 60000;     // Warning time before logout (1min = 60000ms)
```

**Important:** Keep both values synchronized!

### Examples
- **10 minutes**: 600 seconds (PHP) / 600000 milliseconds (JS)
- **15 minutes**: 900 seconds (PHP) / 900000 milliseconds (JS)
- **30 minutes**: 1800 seconds (PHP) / 1800000 milliseconds (JS)

## Testing

### Test Scenario 1: Normal Activity
1. Login to the system
2. Navigate between pages
3. Verify you remain logged in while active

### Test Scenario 2: Warning Display
1. Login to the system
2. Stay idle for 4 minutes
3. Warning banner should appear
4. Move mouse - warning should disappear

### Test Scenario 3: Auto Logout
1. Login to the system
2. Stay completely idle for 5 minutes
3. You should be automatically logged out
4. Redirected to login page with timeout message

### Test Scenario 4: Session Extension
1. Login to the system
2. Wait 3-4 minutes idle
3. Move mouse or click somewhere
4. Timer should reset
5. Can stay logged in for another 5 minutes

## Troubleshooting

### Issue: Users logged out too quickly
**Solution**: Increase `SESSION_TIMEOUT` value in both PHP and JS files

### Issue: Warning doesn't appear
**Solution**: 
- Check browser console for JavaScript errors
- Verify `session-timeout.js` is loading correctly
- Ensure Font Awesome is loaded for icon display

### Issue: Users not logged out after timeout
**Solution**:
- Check that `session-timeout.js` is included in all dashboard pages
- Verify `SESSION_TIMEOUT` constant is defined in `config.php`
- Check server session configuration

### Issue: Logged out while actively working
**Possible causes**:
- AJAX requests not extending session
- Page loaded in background tab (some browsers throttle JS)
- Network issues preventing activity updates

**Solution**: The current implementation updates server activity automatically, but ensure network connectivity is stable

## Security Benefits

1. **Prevents Unauthorized Access**: Automatically locks abandoned sessions
2. **Protects Shared Computers**: Ensures users are logged out if they forget
3. **Compliance**: Meets security requirements for educational systems
4. **User-Friendly**: Provides warning before logout, allowing active users to continue

## Browser Compatibility

The auto-logout feature works on all modern browsers:
- ✅ Chrome/Edge (Chromium)
- ✅ Firefox
- ✅ Safari
- ✅ Opera
- ✅ Mobile browsers (iOS/Android)

## Notes

- Session timeout applies to ALL user roles (students, lecturers, admin, finance)
- Timer resets on ANY page interaction (mouse, keyboard, touch, scroll)
- Warning system is non-intrusive and dismisses automatically on activity
- Users can manually logout at any time using the logout button
- Session data is properly cleaned up on timeout

---
**Feature Implemented**: January 12, 2026  
**Last Updated**: January 12, 2026  
**Status**: Active ✅
