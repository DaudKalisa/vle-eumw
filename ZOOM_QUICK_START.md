# Zoom Integration - Quick Setup

## ⚡ Quick Start (5 minutes)

### Step 1: Get Zoom Credentials (2 min)
1. Visit https://marketplace.zoom.us/
2. Sign in / Create Zoom account
3. Click "Build App" → "Server-to-Server OAuth"
4. Copy: **Account ID** and **Client Secret**

### Step 2: Configure in VLE Admin (2 min)
1. Login to VLE Admin
2. Go to: **More** → **Zoom Settings**
3. Paste credentials:
   - Email: your-email@zoom.us
   - API Key: [Account ID]
   - API Secret: [Client Secret]
4. Check "Activate this account"
5. Save

### Step 3: Test (1 min)
1. Go to Lecturer Dashboard
2. Click "Live Classroom"
3. Create a test session
4. Zoom should open automatically ✓

---

## 📋 File Changes

| File | What Changed |
|------|--------------|
| `setup.php` | Added `zoom_settings` table |
| `api/live_session_api.php` | Zoom URLs instead of Google Meet |
| `admin/zoom_settings.php` | **NEW** - Configuration page |
| `lecturer/live_classroom.php` | Opens Zoom instead of Google Meet |
| `student/course_content.php` | Zoom join button instead of iframe |
| `admin/header_nav.php` | Added Zoom Settings link |

---

## 🔑 Configuration Options

| Setting | Purpose |
|---------|---------|
| Zoom Email | Email of your Zoom account |
| API Key | Account ID from Zoom Marketplace |
| API Secret | Client Secret from Zoom Marketplace |
| Meeting Password | Optional security (optional) |
| Enable Recording | Let students record sessions |
| Require Authentication | Only Zoom users can join |
| Wait for Host | Students wait for lecturer |
| Auto Recording | Local/Cloud/None |

---

## 🎯 How It Works

### Lecturer Flow
```
Dashboard → Live Classroom
        ↓
    Create Session
        ↓
    System generates Zoom meeting ID
        ↓
    Zoom opens automatically
        ↓
    Students get invitations
```

### Student Flow
```
Course Content
        ↓
    See Active Sessions
        ↓
    Click "Join Zoom Meeting"
        ↓
    Zoom opens
        ↓
    Participate in class
```

---

## 🆘 Troubleshooting

| Issue | Solution |
|-------|----------|
| "Zoom not configured" | Go to Admin → Zoom Settings |
| Can't join from browser | Check pop-up blocker |
| Video/audio not working | Check Zoom app, not browser |
| Meeting not recognized | Verify API credentials in Zoom Marketplace |
| Recording not available | Check Zoom plan level (Pro+ required) |

---

## 📊 Zoom Plan Requirements

- **Free Tier**: Works, but 40 min limit on group sessions
- **Pro**: Recommended - Unlimited recording
- **Business**: For large deployments

---

## 🔒 Security Notes

✅ API credentials stored in database  
✅ Only admins can view/edit credentials  
✅ Optional authentication requirement  
✅ Optional meeting passwords  
✅ Meeting IDs are unique and unpredictable  

---

## 📞 Support Links

- Zoom Account: https://zoom.us/
- Zoom Marketplace: https://marketplace.zoom.us/
- API Docs: https://marketplace.zoom.us/docs/
- Support: https://support.zoom.us/

---

**Version**: 1.0  
**Updated**: Feb 2, 2026
