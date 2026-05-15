// Station Management JavaScript Module
class StationManagement {
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

            const response = await fetch('backend/station_operations.php', {
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

    // Station specific functions
    async getStationDetails(stationId) {
        return await this.apiCall('get_station_details', { station_id: stationId });
    }

    async updateStation(stationData) {
        return await this.apiCall('update_station', stationData);
    }

    async updateStationProfile(profileData) {
        return await this.apiCall('update_station_profile', profileData);
    }

    async updateStationStatus(statusData) {
        return await this.apiCall('update_station_status', statusData);
    }

    async createStation(stationData) {
        return await this.apiCall('create_station', stationData);
    }

    async deleteStation(stationId) {
        return await this.apiCall('delete_station', { station_id: stationId });
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

    // Format station code
    formatStationCode(id) {
        return String(id).padStart(4, '0');
    }

    // Format date
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Calculate fuel percentage
    calculateFuelPercentage(fuelLevel, maxCapacity = 10000) {
        if (!fuelLevel) return 0;
        return Math.min((fuelLevel / maxCapacity) * 100, 100);
    }

    // Get fuel level category
    getFuelLevelCategory(percentage) {
        if (percentage > 75) return 'high';
        if (percentage > 25) return 'medium';
        return 'low';
    }

    // Setup status badge with icon
    createStatusBadge(status) {
        const badge = document.createElement('span');
        badge.className = `status-badge status-${status}`;
        
        let icon = '';
        if (status === 'active') {
            icon = '✅';
        } else if (status === 'maintenance') {
            icon = '⚠️';
        } else if (status === 'inactive') {
            icon = '❌';
        }
        
        badge.innerHTML = `<span class="status-icon"></span>${icon} ${status.charAt(0).toUpperCase() + status.slice(1)}`;
        return badge;
    }

    // Create fuel level indicator
    createFuelLevelIndicator(fuelLevel) {
        const container = document.createElement('div');
        container.className = 'fuel-level-indicator';

        const percentage = this.calculateFuelPercentage(fuelLevel);
        
        const bar = document.createElement('div');
        bar.className = 'fuel-bar';
        
        const fill = document.createElement('div');
        fill.className = 'fuel-fill';
        fill.style.width = `${percentage}%`;
        
        bar.appendChild(fill);
        
        const text = document.createElement('span');
        text.className = 'fuel-text';
        text.textContent = `${Math.round(percentage)}%`;
        
        container.appendChild(bar);
        container.appendChild(text);
        
        return container;
    }

    // Confirmation dialog
    confirmAction(message, callback) {
        if (confirm(message)) {
            callback();
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
    window.stationManagement = new StationManagement();
});

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = StationManagement;
}
