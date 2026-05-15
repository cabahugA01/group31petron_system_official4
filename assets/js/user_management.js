// User Management JavaScript Module
class UserManagement {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.setupModals();
        this.setupFilters();
    }

    setupEventListeners() {
        // Close modals when clicking outside
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });

        // Handle escape key to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
        });
    }

    setupModals() {
        // Generic modal close function
        window.closeModal = (modalId) => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        };

        // Close all modals
        window.closeAllModals = () => {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
        };
    }

    setupFilters() {
        // Generic filter function for tables
        window.filterTable = (tableId, filters) => {
            const table = document.getElementById(tableId);
            if (!table) return;

            const tbody = table.querySelector('tbody');
            const rows = tbody.querySelectorAll('tr');

            rows.forEach(row => {
                let showRow = true;

                filters.forEach(filter => {
                    const filterValue = filter.value.toLowerCase();
                    const rowValue = row.dataset[filter.dataset] || '';

                    if (filterValue && !rowValue.toLowerCase().includes(filterValue)) {
                        showRow = false;
                    }
                });

                row.style.display = showRow ? '' : 'none';
            });
        };
    }

    // Toast notification system
    showToast(message, type = 'success') {
        // Remove existing toast if any
        const existingToast = document.getElementById('toast');
        if (existingToast) {
            existingToast.remove();
        }

        // Create new toast
        const toast = document.createElement('div');
        toast.id = 'toast';
        toast.className = 'toast';
        toast.textContent = message;
        
        // Set background color based on type
        if (type === 'success') {
            toast.style.background = '#28A745';
        } else if (type === 'error') {
            toast.style.background = '#DC3545';
        } else if (type === 'warning') {
            toast.style.background = '#FFC107';
            toast.style.color = '#212529';
        }

        // Add to page
        document.body.appendChild(toast);

        // Show toast
        setTimeout(() => {
            toast.style.display = 'block';
        }, 100);

        // Auto hide after 3 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }

    // API helper functions
    async apiCall(action, data = {}) {
        try {
            const formData = new FormData();
            formData.append('action', action);
            
            // Add CSRF token if available
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            // Add all data to form
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });

            const response = await fetch('backend/user_operations.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Operation failed');
            }

            return result;
        } catch (error) {
            this.showToast(error.message, 'error');
            throw error;
        }
    }

    // User specific functions
    async getUserDetails(userId) {
        return await this.apiCall('get_user_details', { user_id: userId });
    }

    async createStationAdmin(adminData) {
        return await this.apiCall('create_station_admin', adminData);
    }

    async createDefaultAccounts(stationId) {
        return await this.apiCall('create_default_accounts', { station_id: stationId });
    }

    async resetPassword(userId) {
        return await this.apiCall('reset_password', { user_id: userId });
    }

    async updateUserStatus(statusData) {
        return await this.apiCall('update_user_status', statusData);
    }

    async getAllUsers(filters = {}) {
        const params = new URLSearchParams();
        Object.keys(filters).forEach(key => {
            if (filters[key]) {
                params.append(key, filters[key]);
            }
        });
        
        const response = await fetch(`backend/user_operations.php?action=get_all_users&${params}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to fetch users');
        }
        
        return result;
    }

    async updateUser(userData) {
        return await this.apiCall('update_user', userData);
    }

    async deleteUser(userId) {
        return await this.apiCall('delete_user', { user_id: userId });
    }

    // Form validation helpers
    validateForm(formId) {
        const form = document.getElementById(formId);
        if (!form) return false;

        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('error');
                isValid = false;
            } else {
                field.classList.remove('error');
            }
        });

        return isValid;
    }

    // Email validation
    validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Phone number validation
    validatePhone(phone) {
        const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
        return phoneRegex.test(phone.replace(/[\s\-\(\)]/g, ''));
    }

    // Username validation
    validateUsername(username) {
        return username.length >= 3 && username.length <= 50 && /^[a-zA-Z0-9_]+$/.test(username);
    }

    // Format date for display
    formatDate(dateString) {
        if (!dateString) return 'Never';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Create status badge
    createStatusBadge(status) {
        const badge = document.createElement('span');
        badge.className = `status-badge status-${status}`;
        
        let icon = '';
        if (status === 'active') {
            icon = '✅';
        } else if (status === 'inactive') {
            icon = '❌';
        }
        
        badge.innerHTML = `${icon} ${status.charAt(0).toUpperCase() + status.slice(1)}`;
        return badge;
    }

    // Create action buttons for user rows
    createActionButtons(user, options = {}) {
        const container = document.createElement('div');
        container.className = 'action-buttons';

        // View button
        if (options.showView !== false) {
            const viewBtn = document.createElement('button');
            viewBtn.className = 'action-btn view';
            viewBtn.innerHTML = '<i class="fas fa-eye"></i>';
            viewBtn.title = 'View';
            viewBtn.onclick = () => this.viewUser(user.id, user.name, user.username, user.role, user.station_name);
            container.appendChild(viewBtn);
        }

        // Reset password button
        if (options.showReset !== false) {
            const resetBtn = document.createElement('button');
            resetBtn.className = 'action-btn reset';
            resetBtn.innerHTML = '<i class="fas fa-key"></i>';
            resetBtn.title = 'Reset Password';
            resetBtn.onclick = () => this.showResetModal(user.id, user.name, user.username);
            container.appendChild(resetBtn);
        }

        // Status toggle button
        if (options.showStatusToggle !== false) {
            const statusBtn = document.createElement('button');
            statusBtn.className = `action-btn ${user.status === 'active' ? 'deactivate' : 'activate'}`;
            statusBtn.innerHTML = `<i class="fas fa-${user.status === 'active' ? 'ban' : 'check'}"></i>`;
            statusBtn.title = user.status === 'active' ? 'Deactivate' : 'Activate';
            statusBtn.onclick = () => this.showStatusModal(
                user.id, 
                user.name, 
                user.status, 
                user.status === 'active' ? 'inactive' : 'active'
            );
            container.appendChild(statusBtn);
        }

        return container;
    }

    // Modal functions
    viewUser(userId, userName, username, role, station) {
        document.getElementById('viewUserName').textContent = userName;
        document.getElementById('viewUsername').textContent = username;
        document.getElementById('viewRole').textContent = role.charAt(0).toUpperCase() + role.slice(1);
        document.getElementById('viewStation').textContent = station || 'Head Office';
        
        document.getElementById('viewModal').style.display = 'block';
    }

    showResetModal(userId, userName, username) {
        document.getElementById('resetModalTitle').textContent = `Reset password for ${userName}?`;
        document.getElementById('resetUserName').textContent = userName;
        document.getElementById('resetUsername').textContent = username;
        
        document.getElementById('resetModal').style.display = 'block';
    }

    showStatusModal(userId, userName, currentStatus, newStatus) {
        document.getElementById('statusUserName').textContent = userName;
        document.getElementById('currentStatus').textContent = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
        document.getElementById('newStatus').textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
        
        // Clear previous reason
        document.getElementById('reason').value = '';
        
        // Store user ID for confirmation
        this.selectedUserId = userId;
        
        document.getElementById('statusModal').style.display = 'block';
    }

    // Confirmation functions
    async confirmReset() {
        if (!this.selectedUserId) return;
        
        try {
            const result = await this.resetPassword(this.selectedUserId);
            this.closeAllModals();
            this.showToast(result.message, 'success');
            
            // Refresh page after a short delay
            setTimeout(() => location.reload(), 1000);
        } catch (error) {
            console.error('Reset password error:', error);
        }
    }

    async confirmStatusChange() {
        const reason = document.getElementById('reason').value.trim();
        
        if (!reason) {
            this.showToast('Please provide a reason for the status change', 'error');
            return;
        }
        
        if (!this.selectedUserId) return;
        
        try {
            const newStatus = document.getElementById('newStatus').textContent.toLowerCase();
            const result = await this.updateUserStatus({
                user_id: this.selectedUserId,
                new_status: newStatus,
                reason: reason
            });
            
            this.closeAllModals();
            this.showToast(result.message, 'success');
            
            // Refresh page after a short delay
            setTimeout(() => location.reload(), 1000);
        } catch (error) {
            console.error('Status change error:', error);
        }
    }

    // Station selection for auto-create defaults
    selectStation(stationId, stationName) {
        // Remove previous selection
        document.querySelectorAll('.station-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Add selection to clicked card
        event.currentTarget.classList.add('selected');
        
        // Store selection
        this.selectedStationId = stationId;
        this.selectedStationName = stationName;
        
        // Update UI
        document.getElementById('selectedStationName').textContent = stationName;
        document.getElementById('infoPanel').classList.add('show');
        document.getElementById('createBtn').disabled = false;
    }

    // Confirm create default accounts
    async confirmCreateDefaults() {
        if (!this.selectedStationId) return;
        
        try {
            const result = await this.createDefaultAccounts(this.selectedStationId);
            this.closeAllModals();
            this.showToast(result.message, 'success');
            
            // Refresh page after a short delay
            setTimeout(() => location.reload(), 1000);
        } catch (error) {
            console.error('Create defaults error:', error);
        }
    }

    // Loading state
    setLoading(element, loading = true) {
        if (loading) {
            element.disabled = true;
            element.dataset.originalText = element.textContent;
            element.textContent = 'Loading...';
        } else {
            element.disabled = false;
            element.textContent = element.dataset.originalText || element.textContent;
        }
    }

    // Refresh page after action
    refreshPage(delay = 1000) {
        setTimeout(() => {
            window.location.reload();
        }, delay);
    }
}

// Initialize the module when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.userManagement = new UserManagement();
});

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UserManagement;
}
