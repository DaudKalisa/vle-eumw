/**
 * VLE Theme Switcher
 * Handles theme changes and persistence
 */

(function() {
    'use strict';

    // Get current theme from localStorage or default
    const currentTheme = localStorage.getItem('vle-theme') || 'navy';
    
    // Apply theme immediately on page load
    document.documentElement.setAttribute('data-theme', currentTheme);

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        // Mark current theme as active
        updateActiveTheme(currentTheme);

        // Handle theme option clicks
        document.querySelectorAll('.theme-option').forEach(function(option) {
            option.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const theme = this.getAttribute('data-theme');
                if (theme) {
                    setTheme(theme);
                }
            });
        });

        // Handle theme submenu visibility on hover
        const themeDropdowns = document.querySelectorAll('.dropdown-submenu');
        themeDropdowns.forEach(function(submenu) {
            const toggle = submenu.querySelector('[data-bs-toggle="dropdown"]');
            const menu = submenu.querySelector('.dropdown-menu');
            
            if (toggle && menu) {
                // Show on hover (desktop)
                submenu.addEventListener('mouseenter', function() {
                    menu.classList.add('show');
                });
                
                submenu.addEventListener('mouseleave', function() {
                    menu.classList.remove('show');
                });

                // Also handle click for mobile
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    menu.classList.toggle('show');
                });
            }
        });
    });

    function setTheme(theme) {
        // Apply theme to document
        document.documentElement.setAttribute('data-theme', theme);
        
        // Save to localStorage
        localStorage.setItem('vle-theme', theme);
        
        // Update active state in menu
        updateActiveTheme(theme);
        
        // Save to server (if logged in)
        saveThemeToServer(theme);
        
        // Close dropdown
        const openDropdowns = document.querySelectorAll('.dropdown-menu.show');
        openDropdowns.forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });
    }

    function updateActiveTheme(theme) {
        // Remove active from all
        document.querySelectorAll('.theme-option').forEach(function(option) {
            option.classList.remove('active');
            // Remove any existing checkmark
            const checkmark = option.querySelector('.theme-checkmark');
            if (checkmark) checkmark.remove();
        });
        
        // Add active to current theme
        const activeOption = document.querySelector('.theme-option[data-theme="' + theme + '"]');
        if (activeOption) {
            activeOption.classList.add('active');
            // Add checkmark
            const checkmark = document.createElement('i');
            checkmark.className = 'bi bi-check-lg ms-auto theme-checkmark text-success';
            activeOption.appendChild(checkmark);
        }
    }

    function saveThemeToServer(theme) {
        // Send AJAX request to save theme preference
        const formData = new FormData();
        formData.append('theme', theme);
        
        // Determine the base path
        const basePath = window.location.pathname.includes('/admin/') || 
                         window.location.pathname.includes('/student/') || 
                         window.location.pathname.includes('/lecturer/') || 
                         window.location.pathname.includes('/finance/') 
                         ? '../includes/theme.php' : 'includes/theme.php';
        
        fetch(basePath, {
            method: 'POST',
            body: formData
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (data.success) {
                console.log('Theme saved:', data.theme);
            }
        }).catch(function(error) {
            console.log('Theme save skipped (not logged in or error)');
        });
    }

    // Expose setTheme globally if needed
    window.VLETheme = {
        set: setTheme,
        get: function() { return localStorage.getItem('vle-theme') || 'navy'; }
    };
})();
