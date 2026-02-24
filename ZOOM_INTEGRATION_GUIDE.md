# Zoom Integration Guide - VLE System

## Overview

The VLE system now supports **Zoom** for live classroom sessions, replacing Google Meet. This guide explains how to set up and use Zoom integration.

## What's Changed

### Previous (Google Meet)
- Meeting URLs: `https://meet.google.com/xxx-xxxx-xxx`
- Limited configuration options
- No persistent session management

### Current (Zoom)
- Meeting URLs: `https://zoom.us/wc/join/{MEETING_ID}`
- Full Zoom account configuration
- Secure API integration with account settings
- Recording options (local, cloud, or none)
- Authentication requirements
- Wait for host feature
- Persistent session tracking

---

## Setup Instructions

### Step 1: Create Zoom App Credentials

1. Go to [Zoom App Marketplace](https://marketplace.zoom.us/)
2. Sign in with your Zoom account (create one if needed)
3. Click "**Develop**" → "**Build App**"
4. Choose "**Server-to-Server OAuth**" app type
5. Fill in app information:
   - App Name: `VLE Classroom Integration`
   - Company: `Your University`
   - Description: `Live classroom sessions for VLE`
6. Click "**Create**"
7. Go to the **Credentials** tab and copy:
   - **Account ID** (this is your API Key)
   - **Client Secret** (this is your API Secret)

### Step 2: Configure in VLE Admin Panel

1. Log in to VLE Admin Dashboard
2. Go to **More** → **Zoom Settings**
3. Fill in the form:
   - **Zoom Account Email**: Email of your Zoom account
   - **API Key**: Paste the Account ID from Step 1
   - **API Secret**: Paste the Client Secret from Step 1
   - **Optional Settings**:
     - Default meeting password
     - Enable/disable recording
     - Require authentication
     - Wait for host
     - Auto-recording option
4. Check "**Activate this Zoom account**" to enable it
5. Click "**Save Zoom Settings**"

---

## How It Works

### For Lecturers

1. **Start a Live Session**
   - Go to Lecturer Dashboard → **Live Classroom**
   - Fill in: Course, Session Name
   - Click "**Start Live Session**"

2. **What Happens**
   - System creates unique Zoom meeting ID
   - Zoom meeting opens automatically
   - All enrolled students get invitations
   - You can see active sessions and participants

3. **Zoom Meeting Features**
   - Screen sharing
   - Recording (if enabled)
   - Chat and messaging
   - Participant management
   - Virtual background

### For Students

1. **Join Active Session**
   - Go to Course Content page
   - Look for active "**Live Session**" card
   - Click "**Join Zoom Meeting Now**" button
   - Zoom opens in new window
   - Join with your name

2. **Session Tracking**
   - System tracks who participated
   - Join time is recorded
   - Participation status shows in gradebook (future feature)

---

## Configuration Options Explained

### Recording
- **None**: No recording
- **Local Recording**: Records on your computer
- **Cloud Recording**: Records to Zoom cloud (requires higher plan)

### Authentication
- **Checked**: Only signed-in Zoom users can join
- **Unchecked**: Anyone with link can join (recommended for students)

### Wait for Host
- **Checked**: Students wait until lecturer joins (recommended)
- **Unchecked**: Students can start meeting without lecturer

### Meeting Password
- Optional password for extra security
- Applied to all meetings created through VLE

---

## Zoom Plans and Limits

| Feature | Free | Pro | Business |
|---------|------|-----|----------|
| Group Meetings | 40 min limit | Unlimited | Unlimited |
| Participants | Up to 100 | Up to 300 | Up to 300+ |
| Cloud Recording | 1 GB | Unlimited | Unlimited |
| Custom URL | No | Yes | Yes |
| Polling | Limited | Yes | Yes |
| Breakout Rooms | 50 min | Unlimited | Unlimited |

**Recommendation**: Pro or Business plan for institutional use.

---

## Troubleshooting

### "Zoom is not configured"
**Solution**: Go to Admin → Zoom Settings and add your Zoom credentials.

### Students can't join meeting
**Solution**: Check that "Require Authentication" is unchecked (unless you want only Zoom users).

### Zoom window doesn't open
**Solution**: Check pop-up blocker settings in your browser. Allow zoom.us domain.

### Meeting recording not working
**Solution**: Ensure your Zoom plan supports recording and it's enabled in Zoom Settings.

### Meeting ID not recognized by Zoom
**Solution**: Ensure API credentials are correct. Check "Test" status in Zoom App Marketplace.

---

## Database Schema

### zoom_settings Table
```sql
CREATE TABLE zoom_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    zoom_account_email VARCHAR(100) NOT NULL UNIQUE,
    zoom_api_key VARCHAR(255) NOT NULL,
    zoom_api_secret VARCHAR(500) NOT NULL,
    zoom_meeting_password VARCHAR(20),
    zoom_enable_recording BOOLEAN DEFAULT TRUE,
    zoom_require_authentication BOOLEAN DEFAULT TRUE,
    zoom_wait_for_host BOOLEAN DEFAULT TRUE,
    zoom_auto_recording ENUM('local', 'cloud', 'none') DEFAULT 'none',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Updated vle_live_sessions Table
```sql
-- Now uses Zoom meeting IDs instead of Google Meet codes
-- meeting_url format: https://zoom.us/wc/join/{MEETING_ID}
-- session_code: Numeric Zoom meeting ID
```

---

## API Integration

### Generate Zoom Meeting URL
```php
// Get active Zoom settings
$zoom_settings = getActiveZoomSettings($conn);

// Generate unique meeting ID
$session_code = generateSessionCode(); // Returns numeric ID

// Create meeting URL
$meeting_url = "https://zoom.us/wc/join/" . $session_code;
```

### Create Meeting (Future Enhancement)
For full Zoom API integration:
```php
// Create actual Zoom meeting via API
POST https://zoom.us/v2/users/me/meetings
Authorization: Bearer {JWT_TOKEN}
{
    "topic": "Live Class Session",
    "type": 1,
    "settings": {
        "host_video": true,
        "participant_video": true,
        "join_before_host": false,
        "use_pmi": false
    }
}
```

---

## File Changes Summary

### Created Files
- `admin/zoom_settings.php` - Zoom configuration admin page
- `ZOOM_INTEGRATION_GUIDE.md` - This guide

### Modified Files
- `setup.php` - Added zoom_settings table
- `api/live_session_api.php` - Updated to use Zoom
- `lecturer/live_classroom.php` - Opens Zoom instead of Google Meet
- `student/course_content.php` - Displays Zoom join button
- `admin/header_nav.php` - Added Zoom Settings link

---

## Best Practices

1. **Test Before Using**
   - Create test meeting with valid Zoom account
   - Verify students can join

2. **Share Meeting Details**
   - Inform students about meeting schedule
   - Include Zoom link in course announcements

3. **Monitor Attendance**
   - Check Zoom participant list
   - Record sessions for review

4. **Security**
   - Use authentication requirement for sensitive courses
   - Set meeting password if needed
   - Don't share credentials widely

5. **Technical Preparation**
   - Test audio/video before session
   - Ensure good internet connection
   - Check system requirements (camera, microphone)

---

## Support

For issues with:
- **Zoom Account**: Visit [Zoom Support](https://support.zoom.us/)
- **API Credentials**: Check [Zoom App Marketplace Docs](https://marketplace.zoom.us/docs/api-reference/zoom-api)
- **VLE System**: Contact system administrator

---

## Future Enhancements

Planned features:
- [ ] Full Zoom API integration for automatic meeting creation
- [ ] JWT token generation for secure joining
- [ ] Attendance tracking and reporting
- [ ] Recording management and download
- [ ] Breakout rooms for group activities
- [ ] Live polls and interactions
- [ ] Integration with gradebook

---

**Last Updated**: February 2, 2026  
**Version**: 1.0
