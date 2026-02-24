# Live Video Classroom Feature

## Overview

The Live Video Classroom feature enables lecturers to conduct real-time video classes with students using a Google Meet-like interface. When a lecturer starts a live session, all enrolled students automatically receive invitations to join the classroom.

## Features

### For Lecturers

- **Start Live Sessions**: Launch a live video class for any of their courses
- **Course Selection**: Choose which course the session belongs to
- **Session Management**: View active sessions with real-time participant tracking
- **Participant Monitoring**: See who has accepted the invite and who has joined
- **Session Control**: End sessions when class is complete
- **Auto-Invite**: Automatically sends invites to all enrolled students

### For Students

- **Live Invitations**: Receive real-time notifications for live classroom sessions
- **Easy Join**: One-click access to join live classes
- **Session History**: View past sessions they attended
- **Status Tracking**: See their participation status (invited, viewed, accepted, joined)
- **Quick Access**: "Live Classes" button on student dashboard for quick access

## How It Works

### Lecturer Side

1. Navigate to **Live Classroom** from the lecturer dashboard
2. Click **"Start New Live Session"**
3. Select the course and enter a session topic
4. Click **"Start Live Session"**
   - Jitsi Meet opens in a new window
   - All enrolled students receive invites automatically
   - Lecturer joins the meeting
5. Students can see active participants
6. End the session when class is complete

### Student Side

1. Navigate to **Live Classes** from the student dashboard
2. View all active live invitations
3. Click **"Join Meeting"** to enter the classroom
   - Browser redirects to Jitsi Meet
   - Student joins the video conference
4. Participate in the live class
5. Leave when the session ends or manually exit

## Database Tables

### vle_live_sessions
Stores information about each live session
- `session_id`: Unique identifier
- `course_id`: Associated course
- `lecturer_id`: Conducting lecturer
- `session_name`: Topic/title of the session
- `session_code`: Unique code for meeting
- `status`: pending, active, or completed
- `meeting_url`: Jitsi Meet URL
- `started_at`, `ended_at`: Timestamps

### vle_session_participants
Tracks who participated in each session
- `participant_id`: Unique identifier
- `session_id`: Link to session
- `student_id`: Student user
- `joined_at`, `left_at`: Participation timestamps
- `status`: invited, joined, or left

### vle_session_invites
Manages invitations sent to students
- `invite_id`: Unique identifier
- `session_id`: Link to session
- `student_id`: Invitee
- `sent_at`, `viewed_at`, `accepted_at`: Action timestamps
- `status`: sent, viewed, accepted, or declined

## API Endpoints

All endpoints are in `/api/live_session_api.php`:

### Start Session
```
POST /api/live_session_api.php
action=start_session
course_id=1
session_name=Lecture 5
```
Returns: `{success, message, session_id, session_code, meeting_url}`

### End Session
```
POST /api/live_session_api.php
action=end_session
session_id=1
```

### Accept Invite (Student)
```
POST /api/live_session_api.php
action=accept_invite
session_id=1
```
Returns: `{success, message, meeting_url}`

### Get Active Sessions
```
GET /api/live_session_api.php?action=get_sessions&course_id=1
```

### Get Student Invites
```
GET /api/live_session_api.php?action=get_invites
```

### Get Session Participants
```
GET /api/live_session_api.php?action=get_participants&session_id=1
```

## Technology Stack

- **Video Conferencing**: [Jitsi Meet](https://meet.jitsi.org/) - Free, open-source
- **Backend**: PHP with MySQLi
- **Frontend**: Bootstrap 5.1.3 + JavaScript Fetch API
- **Database**: MySQL/MariaDB

## Setup Instructions

1. **Run Database Setup**
   - Visit `http://localhost/vle-eumw/setup.php`
   - The new tables will be created automatically

2. **Access Live Classroom**
   - **Lecturers**: Click "Live Classroom" button on dashboard
   - **Students**: Click "Live Classes" button on dashboard

3. **Start a Session** (Lecturer)
   - Select course and session topic
   - Click "Start Live Session"
   - Jitsi Meet opens automatically

4. **Join a Session** (Student)
   - View live invitations on your dashboard
   - Click "Join Meeting"
   - Join the video conference

## Security Considerations

- **Authentication**: All endpoints require login
- **Authorization**: Lecturers can only start sessions for their own courses
- **Student Verification**: Students can only be invited to courses they're enrolled in
- **UNIQUE Constraints**: Prevents duplicate invites and participants

## Customization Options

### Change Video Conferencing Provider

Currently uses Jitsi Meet (free). To use a different provider:

1. Edit `/api/live_session_api.php` line ~46:
```php
$meeting_url = "YOUR_CUSTOM_MEETING_URL/" . $session_code;
```

Options:
- **Google Meet**: `https://meet.google.com/` (requires integration)
- **Zoom**: `https://zoom.us/` (requires API key)
- **BigBlueButton**: Self-hosted option

### Customize Jitsi Configuration

Add custom parameters to meeting URL:
```php
$meeting_url = "https://meet.jitsi.org/" . $session_code . "?userInfo.displayName=" . urlencode($user['full_name']);
```

## Troubleshooting

### Issue: Pop-up blocked when joining
**Solution**: Browser blocks new windows. Allow pop-ups for this site or use Firefox.

### Issue: "Unauthorized" error when starting session
**Solution**: Verify you're logged in as a lecturer and the course belongs to you.

### Issue: Students not receiving invites
**Solution**: 
1. Verify students are enrolled in the course
2. Check `vle_enrollments` table for the student
3. Verify course_id is correct

### Issue: Meeting URL not opening
**Solution**: Check internet connection and Jitsi Meet service status at https://meet.jitsi.org/

## File Structure

```
lecturer/live_classroom.php          - Lecturer interface
student/live_invites.php             - Student interface
api/live_session_api.php             - Backend API
setup_live_sessions.php              - Database setup (optional)
setup.php                            - Updated with live session tables
```

## Future Enhancements

- Recording sessions
- Screen sharing analytics
- Attendance reports
- Session replays/video storage
- Scheduled recurring sessions
- Chat during live sessions
- Breakout rooms for group activities
- Integration with course calendar

## Support

For issues or feature requests, contact the system administrator.
