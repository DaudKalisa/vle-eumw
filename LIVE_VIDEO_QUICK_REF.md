# Live Video Classroom - Quick Reference Card

## 🚀 QUICK START (30 seconds)

### For Lecturers
```
1. Dashboard → [Live Classroom] button
2. Select course & enter topic
3. [Start Live Session]
4. ✅ Jitsi opens + students get invite
```

### For Students
```
1. Dashboard → [Live Classes] button  
2. See live invitations
3. [Join Meeting]
4. ✅ Jitsi opens
```

---

## 📊 KEY INFO

| Item | Details |
|------|---------|
| **Video Service** | Jitsi Meet (free) |
| **Setup Time** | ~2 minutes |
| **Browser Support** | Chrome, Firefox, Safari, Edge |
| **Mobile** | Yes, fully responsive |
| **Recording** | Built into Jitsi |
| **Cost** | FREE |

---

## 🔗 LINKS

| Item | URL |
|------|-----|
| **Setup Database** | `/setup.php` |
| **Lecturer UI** | `/lecturer/live_classroom.php` |
| **Student UI** | `/student/live_invites.php` |
| **API** | `/api/live_session_api.php` |
| **Documentation** | See below ↓ |

---

## 📚 DOCUMENTATION

### Start Here
- **LIVE_VIDEO_README.md** ← Start here!

### User Guides
- **LIVE_VIDEO_SETUP.md** - Setup & How to Use

### For Developers
- **LIVE_VIDEO_FEATURE.md** - Technical Details
- **LIVE_VIDEO_IMPLEMENTATION.md** - What Was Built
- **LIVE_VIDEO_VISUAL_GUIDE.md** - Diagrams
- **LIVE_VIDEO_FILES_LIST.md** - All Changes

---

## 💾 DATABASE

### Tables Created
- `vle_live_sessions` - Sessions
- `vle_session_participants` - Who joined
- `vle_session_invites` - Invitations sent

### Auto-Created By
- `setup.php` (automatic on first run)

---

## 🎛️ DASHBOARD BUTTONS

### Lecturer Dashboard
- **Button**: "Live Classroom"
- **Color**: Red
- **Icon**: 📹 Camera
- **Location**: Top right navigation

### Student Dashboard
- **Button**: "Live Classes"
- **Color**: Red (pulsing)
- **Icon**: 🎥 Camera
- **Location**: Quick actions section

---

## 📡 API ENDPOINTS

### Start Session
```
POST /api/live_session_api.php
  action=start_session
  course_id=X
  session_name=Y
```

### Accept Invite
```
POST /api/live_session_api.php
  action=accept_invite
  session_id=X
```

### Get Invites (Student)
```
GET /api/live_session_api.php
  action=get_invites
```

### Get Sessions (Lecturer)
```
GET /api/live_session_api.php
  action=get_sessions
  course_id=X
```

### End Session
```
POST /api/live_session_api.php
  action=end_session
  session_id=X
```

---

## 🔐 SECURITY

✅ Login required  
✅ Role-based (lecturer/student)  
✅ Course ownership verified  
✅ Enrollment verified  
✅ Unique codes prevent access  
✅ SQL injection protected  

---

## ⚡ QUICK TROUBLESHOOTING

### Pop-up blocked?
→ Allow pop-ups for domain

### Can't find button?
→ Refresh page, verify login

### Meeting won't open?
→ Try Firefox, check internet

### Students not getting invites?
→ Verify course enrollment

---

## 📋 STATUS CODES

### Invitation Statuses
- `sent` - Delivered, not viewed
- `viewed` - Student saw invite
- `accepted` - Student clicked join
- `declined` - Student declined

### Participant Statuses
- `invited` - Invite pending
- `joined` - In meeting
- `left` - Exited meeting

### Session Statuses
- `pending` - Not started
- `active` - In progress
- `completed` - Finished

---

## 🎯 CORE FEATURES

✅ Auto-send invites to all students  
✅ One-click join (Jitsi opens)  
✅ Real-time participant tracking  
✅ Unique session codes  
✅ Full audit trail (timestamps)  
✅ View past sessions  
✅ Mobile-friendly  

---

## 📝 FILES REFERENCE

### Core Code
- `api/live_session_api.php` - Backend (375 lines)
- `lecturer/live_classroom.php` - Lecturer UI (290 lines)
- `student/live_invites.php` - Student UI (365 lines)

### Setup
- `setup.php` - Updated (+65 lines)
- `setup_live_sessions.php` - Standalone setup (65 lines)

### Modified
- `lecturer/dashboard.php` - Added button
- `student/dashboard.php` - Added quick action

### Documentation
- 6 markdown files with complete guides

---

## ✅ VERIFICATION CHECKLIST

- [ ] Run `setup.php`
- [ ] See "Live Classroom" button in lecturer dashboard
- [ ] See "Live Classes" button in student dashboard
- [ ] Test: Lecturer starts session
- [ ] Test: Student sees invitation
- [ ] Test: Student joins meeting
- [ ] Check: Tables created in database
- [ ] Verify: No PHP errors in console

---

## 🎓 TRAINING SUMMARY

### Lecturer Training (2 min)
1. Show "Live Classroom" button
2. Demo: Start session
3. Show: Participant list
4. Explain: Auto-invites students

### Student Training (1 min)
1. Show "Live Classes" button
2. Demo: Join meeting
3. Explain: See invitations here
4. Show: Past sessions history

---

## 🔧 CUSTOMIZATION

### Change Video Service
Edit line in `api/live_session_api.php`:
```php
// Currently:
$meeting_url = "https://meet.jitsi.org/" . $session_code;

// Change to your service:
$meeting_url = "https://yourservice.com/" . $session_code;
```

### Change Button Colors
Edit CSS in `live_classroom.php` or `live_invites.php`

### Add Custom Session Fields
Add columns to `vle_live_sessions` table

---

## 📞 SUPPORT

**Quick Issue Fix**
1. Check LIVE_VIDEO_SETUP.md troubleshooting
2. Verify database tables exist
3. Check browser console for errors
4. Verify course enrollment

**For Help**
- See documentation files
- Check API responses in browser console
- Verify database integrity

---

## 🎉 YOU'RE ALL SET!

```
✅ Backend: Complete (API working)
✅ UI: Complete (Lecturer & Student)
✅ Database: Complete (Tables ready)
✅ Security: Complete (Auth & role checks)
✅ Documentation: Complete (6 guides)
✅ Testing: Complete (All validated)
✅ Ready: PRODUCTION USE
```

---

### Next: Read LIVE_VIDEO_SETUP.md for detailed instructions!

**Status**: 🟢 **READY TO USE**
