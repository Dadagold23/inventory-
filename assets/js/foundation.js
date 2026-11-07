/*
 * Module 1: Foundation JavaScript
 * Inventory, Sales, Expenses, Income, Approval, and Financial Management System
 * PHP 8.2+, MySQL 8+ / MariaDB
 */

/* Global app object */
window.App = {
    version: "1.0.0-foundation",
    baseUrl: "",
    csrfToken: null,
    
    /* Initialize after DOM content loaded */
    init: function() {
        this.setupCSRF();
        this.setupEventListeners();
        this.setupReducedMotion();
    },
    
    setupCSRF: function() {
        // Get CSRF token from meta tag
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            this.csrfToken = meta.getAttribute("content");
        }
    },
    
    setupEventListeners: function() {
        // Form submissions with CSRF protection
        document.querySelectorAll('form.csrf-protected').forEach(form => {
            form.addEventListener('submit', function(e) {
                // CSRF token will be added by PHP handler
                // This is a placeholder for future AJAX form handling
            });
        });
        
        // Link click prevention for destructive actions without confirmation
        document.querySelectorAll('a[data-destructive]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to perform this action?')) {
                    e.preventDefault();
                }
            });
        });
    },
    
    setupReducedMotion: function() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            // Disable animations
            document.documentElement.style.setProperty('--animation-duration', '0.01ms');
        }
    },
    
    /* Show alert message */
    showAlert: function(message, type = 'info', duration = 5000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-system alert-dismissible fade show`;
        alertDiv.setRole('alert');
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        const container = document.querySelector('.container-fluid') || document.body;
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto-dismiss
        if (duration > 0) {
            setTimeout(() => {
                alertDiv.style.display = 'none';
                setTimeout(() => alertDiv.remove(), 150);
            }, duration);
        }
        
        return alertDiv;
    },
    
    /* Loading indicator */
    showLoading: function(targetSelector = null) {
        const spinner = document.createElement('div');
        spinner.className = 'spinner-system';
        spinner.setAttribute('aria-hidden', 'true');
        
        if (targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target) {
                target.style.position = 'relative';
                target.style.display = 'flex';
                target.style.alignItems = 'center';
                target.style.justifyContent = 'center';
                target.appendChild(spinner);
            }
        } else {
            document.body.style.position = 'relative';
            document.body.style.display = 'flex';
            document.body.style.alignItems = 'center';
            document.body.style.justifyContent = 'center';
            document.body.style.minHeight = '100vh';
            document.body.appendChild(spinner);
        }
        
        return spinner;
    },
    
    hideLoading: function(spinnerElement) {
        if (spinnerElement && spinnerElement.parentNode) {
            spinnerElement.parentNode.removeChild(spinnerElement);
        }
        document.body.style.position = '';
        document.body.style.display = '';
        document.body.style.alignItems = '';
        document.body.style.justifyContent = '';
        document.body.style.minHeight = '';
    }
};

// Initialize on DOM content loaded
document.addEventListener('DOMContentLoaded', function() {
    App.init();
    
    // Initialize tooltips
    const tooltipElements = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (tooltipElements.length > 0) {
        const bsTooltips = [];
        tooltipElements.forEach(el => {
            bsTooltips.push new bootstrap.Tooltip(el);
        });
    }
    
    // Initialize popovers
    const popoverElements = document.querySelectorAll('[data-bs-toggle="popover"]');
    if (popoverElements.length > 0) {
        const bsPopovers = [];
        popoverElements.forEach(el => {
            bsPopovers.push new bootstrap.Popover(el);
        });
    }
});