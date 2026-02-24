# Live Video Classroom - Quick Setup Guide

## 1. Initialize Database Tables

### Option A: Auto-Setup (Recommended)
Visit: `http://localhost/vle-eumw/setup.php`
- The new live session tables will be created automatically

### Option B: Manual Setup
Run `setup_live_sessions.php`: `http://localhost/vle-eumw/setup_live_sessions.php`

**Tables Created:**
- `vle_live_sessions` - Session information
- `vle_session_participants` - Participant tracking
- `vle_session_invites` - Invitation management

---

## 2. For Lecturers

### Start a Live Class
1. Login as a lecturer
2. Go to **Lecturer Dashboard**
3. Click **"Live Classroom"** button (red button with camera icon)
4. Click **"Start New Live Session"**
5. **Select Course** from dropdown
6. **Enter Session Topic** (e.g., "Lecture 5: Advanced Topics")
7. Click **"Start Live Session"**
   - Jitsi Meet opens in a new window
   - All enrolled students get notified automatically
8. Your video conference is ready!
9. Click **"End"** when class is complete

### Monitor Participants
- View **"Active Live Sessions"** section
- Shows number of students invited and currently online
- Click **"Participants"** button to see detailed list with join times

---

## 3. For Students

### Join a Live Class
1. Login as a student
2. Go to **Student Dashboard**
3. Click **"Live Classes"** button (red button with camera icon) in quick actions
4. View **"Active Live Sessions"** section
   - Shows live invitations from lecturers
   - Red "LIVE" badge indicates active sessions
5. Click **"Join Meeting"** button
   - Jitsi Meet opens automatically
   - You're now in the video conference!
6. You can also see:
   - Number of students currently in the meeting
   - When the session started
   - Lecturer name and course name

### View History
- **"Past Sessions"** section shows previous sessions you attended
- Includes attendance timestamps and status

---

## 4. How Invitations Work

### Automatic Process
1. Lecturer starts a session for Course A
2. System automatically:
   - Queries all students enrolled in Course A
   - Creates invite records for each student
   - Marks invites as "sent"
3. Students see notification on their Live Classes page

### Invitation Statuses
- **"sent"**: Invite delivered, not viewed yet
- **"viewed"**: Student saw the invitation
- **"accepted"**: Student clicked "Join Meeting"
- **"joined"**: Student is now in the video conference

---

## 5. Technical Details

### Meeting URL Format
Uses free **Jitsi Meet** service:
```
https://meet.jitsi.org/{unique-session-code}
```

### Browser Support
- ✅ Chrome/Chromium
- ✅ Firefox
- ✅ Safari
- ✅ Edge
- Requires: Camera/Microphone permissions

### Pop-up Handling
- Browser may ask to allow pop-ups
- Firefox recommended (less restrictive)
- Allow pop-ups for your VLE domain

---

## 6. Features Overview

### Live Indicators
- 🔴 **LIVE** badge: Session is currently active
- 📊 Participant count: Shows online attendees
- ⏱️ Start time: When the session began

### Quick Actions
- **Join Meeting**: Enter video conference
- **Details**: View session information
- **Participants**: See who's in the meeting (Lecturer only)
- **End Session**: Terminate the session (Lecturer only)

---

## 7. Troubleshooting

### Can't Find Live Classroom Button?
- **Lecturers**: Check you're logged in with lecturer role
- **Students**: Check you're logged in with student role
- Refresh the page if needed

### Pop-up Blocked?
- Check browser pop-up settings
- Add your VLE to pop-up whitelist
- Try Firefox (more permissive)

### Meeting Won't Open?
- Check internet connection
- Verify Jitsi service: https://meet.jitsi.org/
- Try different browser
- Allow camera/microphone permissions

### Students Not Getting Invites?
1. Verify student is enrolled in the course
2. Check student is logged in to dashboard
3. Have student refresh page to see new invites
4. Check database: `vle_enrollments` table

### Poor Video/Audio Quality?
- Check internet connection (upload/download speed)
- Close other bandwidth-heavy applications
- Reduce video quality in Jitsi settings
- Use wired connection if possible

---

## 8. Student Dashboard Updates

New quick action added to student dashboard:
- **Icon**: Camera with video play button 🎥
- **Label**: "Live Classes"
- **Color**: Red/animated
- **Location**: Quick actions row (between Announcements and others)

---

## 9. Lecturer Dashboard Updates

New button added to lecturer dashboard header:
- **Icon**: Camera video icon 📹
- **Label**: "Live Classroom"
- **Color**: Red (danger class)
- **Location**: Top right navigation area
- **Position**: Between dashboard title and Finance/Attendance buttons

---

## 10. File Locations

```
📁 VLE System
├── 📄 setup.php (UPDATED - includes live tables)
├── 📄 setup_live_sessions.php (NEW - standalone setup)
├── 📄 LIVE_VIDEO_FEATURE.md (NEW - detailed documentation)
├── 📁 api/
│   └── 📄 live_session_api.php (NEW - API backend)
├── 📁 lecturer/
│   ├── 📄 dashboard.php (UPDATED - added Live Classroom link)
│   └── 📄 live_classroom.php (NEW - lecturer interface)
└── 📁 student/
    ├── 📄 dashboard.php (UPDATED - added Live Classes link)
    └── 📄 live_invites.php (NEW - student interface)
```

---

## 11. Session Workflow Diagram

```
LECTURER SIDE              →  SYSTEM/DATABASE     →   STUDENT SIDE
─────────────────────────────────────────────────────────────────

1. Click "Live Classroom"
2. Fill session form
3. Click "Start Live"    →   Create vle_live_sessions
                         →   Auto-create invites for all
                         →   Update vle_session_invites
                         →   Open Jitsi Meet

                                                    ← Student sees "Live Classes"
                                                    ← Invitation appears
                                                    4. Click "Join Meeting"
                                              5. Accept invite  → Update status to "accepted"
                                              6. Jitsi opens   → Join video conference
                                                    
7. Monitor participants ←   Query vle_session_participants
                        ←   Show real-time count
                        
8. Click "End Session"  →   Update status to "completed"
                        →   Record end_at timestamp

                                                    ← Session moves to "Past Sessions"
                                                    ← Shows attendance record
```

---

## 12. Key Security Features

✅ **Authentication Required**
- All pages require login
- API endpoints check user roles

✅ **Authorization Checks**
- Lecturers can only start sessions for their own courses
- Students can only see invites for their enrolled courses
- Prevent unauthorized session creation/modification

✅ **Data Integrity**
- UNIQUE constraints prevent duplicate invites
- Foreign keys maintain referential integrity
- Timestamps track all actions

---

## 13. Next Steps

1. **Run Setup**: Visit `setup.php`
2. **Test as Lecturer**: Start a practice session
3. **Test as Student**: Receive and join invite
4. **Verify Recording**: Check participant logs
5. **Train Users**: Share this guide with staff/students

---

**For detailed technical documentation, see:** `LIVE_VIDEO_FEATURE.md`
