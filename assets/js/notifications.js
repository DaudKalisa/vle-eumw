/**
 * VLE Notification System - Frontend JavaScript
 * Handles notification bell, dropdown, polling, click-to-email, and navigation
 */
(function() {
    'use strict';

    const POLL_INTERVAL = 30000; // 30 seconds
    const API_BASE = '../api/notifications.php';
    let currentNotifications = [];

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        fetchNotifications();
        setInterval(fetchNotifications, POLL_INTERVAL);
        setupDropdownEvents();
    });

    /**
     * Fetch notifications from API
     */
    function fetchNotifications() {
        fetch(API_BASE + '?action=fetch&limit=15')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentNotifications = data.notifications;
                    updateBadge(data.unread_count);
                    renderDropdown(data.notifications, data.unread_count);
                }
            })
            .catch(err => console.error('Notification fetch error:', err));
    }

    /**
     * Update the notification badge count
     */
    function updateBadge(count) {
        document.querySelectorAll('.vle-notif-badge').forEach(badge => {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        });
    }

    /**
     * Render the notification dropdown content
     */
    function renderDropdown(notifications, unreadCount) {
        const container = document.getElementById('vle-notif-list');
        if (!container) return;

        if (notifications.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-bell-slash" style="font-size:2rem;"></i>
                    <p class="mb-0 mt-2">No notifications yet</p>
                </div>`;
            return;
        }

        let html = '';
        
        // Header with mark-all-read
        if (unreadCount > 0) {
            html += `<div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                <small class="text-muted">${unreadCount} unread</small>
                <a href="#" class="small text-primary" onclick="VLENotif.markAllRead(event)">Mark all read</a>
            </div>`;
        }

        notifications.forEach(n => {
            const unreadClass = n.is_read == 0 ? 'bg-light border-start border-primary border-3' : '';
            const emailBtnClass = n.is_emailed == 1 ? 'text-success' : 'text-muted';
            const emailIcon = n.is_emailed == 1 ? 'bi-envelope-check-fill' : 'bi-envelope';
            const emailTitle = n.is_emailed == 1 ? 'Already emailed' : 'Send to my email';
            
            html += `
            <div class="notif-item d-flex align-items-start px-3 py-2 border-bottom ${unreadClass}" 
                 data-id="${n.notification_id}" style="cursor:pointer;">
                <div class="me-2 mt-1">
                    <span class="badge bg-${n.badge_color} rounded-circle p-2">
                        <i class="${n.icon}"></i>
                    </span>
                </div>
                <div class="flex-grow-1" onclick="VLENotif.clickNotification(${n.notification_id}, event)">
                    <div class="fw-semibold small">${escapeHtml(n.title)}</div>
                    <div class="text-muted small text-truncate" style="max-width:250px;">${escapeHtml(n.message)}</div>
                    <div class="text-muted" style="font-size:0.7rem;">${n.time_ago}</div>
                </div>
                <div class="ms-2 d-flex flex-column align-items-center">
                    <button class="btn btn-sm p-0 ${emailBtnClass}" 
                            onclick="VLENotif.emailNotification(${n.notification_id}, event)" 
                            title="${emailTitle}" style="font-size:1rem;">
                        <i class="${emailIcon}"></i>
                    </button>
                </div>
            </div>`;
        });

        // Footer
        html += `<div class="text-center py-2">
            <a href="#" class="small text-primary" onclick="VLENotif.viewAll(event)">View All Notifications</a>
        </div>`;

        container.innerHTML = html;
    }

    /**
     * Click a notification: mark read, email to self, navigate to link
     */
    function clickNotification(id, event) {
        event.preventDefault();
        event.stopPropagation();
        
        // Get email preference from checkbox if present
        const emailCheckbox = document.getElementById('vle-notif-email-toggle');
        const alsoEmail = emailCheckbox ? (emailCheckbox.checked ? '1' : '0') : '0';
        
        const formData = new FormData();
        formData.append('action', 'click');
        formData.append('id', id);
        formData.append('email', alsoEmail);

        fetch(API_BASE, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.email_sent) {
                        showToast('Notification sent to your email!', 'success');
                    }
                    if (data.link) {
                        // Navigate - handle relative/absolute URLs
                        let url = data.link;
                        if (url.indexOf('http') !== 0 && url.indexOf('/') !== 0) {
                            url = '../' + url;
                        } else if (url.indexOf('/') === 0) {
                            url = '..' + url;
                        }
                        window.location.href = url;
                    } else {
                        // Just refresh notifications
                        fetchNotifications();
                    }
                }
            })
            .catch(err => {
                console.error('Click notification error:', err);
                fetchNotifications();
            });
    }

    /**
     * Email a single notification to self
     */
    function emailNotification(id, event) {
        event.preventDefault();
        event.stopPropagation();
        
        const btn = event.currentTarget;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'email');
        formData.append('id', id);

        fetch(API_BASE, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.innerHTML = '<i class="bi bi-envelope-check-fill"></i>';
                    btn.classList.remove('text-muted');
                    btn.classList.add('text-success');
                    btn.title = 'Emailed!';
                    showToast('Notification sent to your email!', 'success');
                } else {
                    btn.innerHTML = '<i class="bi bi-envelope-x"></i>';
                    btn.classList.add('text-danger');
                    showToast(data.message || 'Failed to send email', 'danger');
                }
                btn.disabled = false;
            })
            .catch(err => {
                btn.innerHTML = '<i class="bi bi-envelope"></i>';
                btn.disabled = false;
                showToast('Network error sending email', 'danger');
            });
    }

    /**
     * Mark all notifications as read
     */
    function markAllRead(event) {
        event.preventDefault();
        event.stopPropagation();
        
        const formData = new FormData();
        formData.append('action', 'mark_all_read');

        fetch(API_BASE, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchNotifications();
                    showToast('All notifications marked as read', 'info');
                }
            });
    }

    /**
     * View all - scrolls to or opens notification page
     */
    function viewAll(event) {
        event.preventDefault();
        // Could navigate to a dedicated page in the future
        const emailCheckbox = document.getElementById('vle-notif-email-toggle');
        if (emailCheckbox) {
            emailCheckbox.checked = false;
        }
        fetchNotifications();
    }

    /**
     * Setup dropdown click-outside close behavior
     */
    function setupDropdownEvents() {
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('vle-notif-dropdown');
            const bell = document.getElementById('vle-notif-bell');
            if (dropdown && bell && !dropdown.contains(e.target) && !bell.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        const bell = document.getElementById('vle-notif-bell');
        if (bell) {
            bell.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const dropdown = document.getElementById('vle-notif-dropdown');
                if (dropdown) {
                    dropdown.classList.toggle('show');
                    if (dropdown.classList.contains('show')) {
                        fetchNotifications();
                    }
                }
            });
        }
    }

    /**
     * Show a toast notification
     */
    function showToast(message, type) {
        // Create toast container if not exists
        let container = document.getElementById('vle-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'vle-toast-container';
            container.style.cssText = 'position:fixed;top:80px;right:20px;z-index:99999;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `alert alert-${type} alert-dismissible fade show shadow-sm`;
        toast.style.cssText = 'min-width:280px;font-size:0.9rem;animation:slideInRight 0.3s ease;';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // Expose global API
    window.VLENotif = {
        clickNotification: clickNotification,
        emailNotification: emailNotification,
        markAllRead: markAllRead,
        viewAll: viewAll,
        refresh: fetchNotifications
    };

})();
