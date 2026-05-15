/**
 * Data Helper - Loads dynamic data from backend APIs
 * Replaces hardcoded data in frontend
 */

class DataHelper {

    /**
     * Get the app root URL (handles subfolder deployments)
     */
    static getAppRoot() {
        // Get the current page path
        const path = window.location.pathname;
        
        // Try to detect if we're in /group31petron_system_official4/ or similar subfolder
        // Look for /public/ in the path to find the app root
        const publicIndex = path.indexOf('/public/');
        if (publicIndex !== -1) {
            return path.substring(0, publicIndex);
        }
        
        // Fallback: assume app is at root
        return '';
    }

    /**
     * Load data from an API endpoint
     */
    static async loadData(endpoint, action = 'list') {
        try {
            // Remove leading slash if present to avoid double slashes
            const cleanEndpoint = endpoint.startsWith('/') ? endpoint.substring(1) : endpoint;
            
            // Build correct path - if we're in /public/, go up one level
            let basePath = '';
            if (window.location.pathname.includes('/public/')) {
                basePath = '../';
            }
            
            const fullUrl = `${basePath}${cleanEndpoint}?action=${action}`;
            const response = await fetch(fullUrl);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'Failed to load data');
            }
            return result.data;
        } catch (error) {
            console.error('DataHelper.loadData error:', error);
            throw error;
        }
    }

    /**
     * Populate a select dropdown with data
     */
    static populateSelect(selectId, data, valueField = 'id', labelField = 'name', placeholder = 'Select...') {
        const select = document.getElementById(selectId);
        if (!select) {
            console.error(`Select element with id "${selectId}" not found`);
            return;
        }

        select.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach(item => {
            select.innerHTML += `<option value="${item[valueField]}">${item[labelField]}</option>`;
        });
    }

    /**
     * Load and populate fuel types
     */
     static async populateFuelTypes(selectId, placeholder = 'Select fuel type') {
         try {
             const fuelTypes = await this.loadData('backend/api/fuel_types.php', 'list');
             this.populateSelect(selectId, fuelTypes, 'id', 'name', placeholder);
             return fuelTypes;
         } catch (error) {
             console.error('Failed to load fuel types, using fallback:', error);
             const fallbackFuelTypes = [
                 { id: 1, name: 'Diesel' },
                 { id: 2, name: 'Gasoline' },
                 { id: 3, name: 'Kerosene' }
             ];
             this.populateSelect(selectId, fallbackFuelTypes, 'id', 'name', placeholder);
             return fallbackFuelTypes;
         }
     }

    /**
     * Load and populate stations
     */
     static async populateStations(selectId, placeholder = 'Select station') {
         try {
             const stations = await this.loadData('backend/api/stations.php', 'list');
             this.populateSelect(selectId, stations, 'id', 'name', placeholder);
             return stations;
         } catch (error) {
             console.error('Failed to load stations, using fallback:', error);
             const fallbackStations = [
                 { id: 1, name: 'PETRON CDO - Kauswagan' },
                 { id: 2, name: 'PETRON CDO - Uptown' },
                 { id: 3, name: 'PETRON CDO - Lapasan' }
             ];
             this.populateSelect(selectId, fallbackStations, 'id', 'name', placeholder);
             return fallbackStations;
         }
     }

     /**
      * Load and populate user roles
      */
      static async populateRoles(selectId, placeholder = 'Select role') {
          try {
              const roles = await this.loadData('backend/api/roles.php', 'list');
              
              // Map role names to lowercase keys for form submission
              const mappedRoles = roles.map(role => ({
                  ...role,
                  value: role.name.toLowerCase().replace(/\s+/g, '')  // "Super Admin" -> "superadmin"
              }));
              
              this.populateSelect(selectId, mappedRoles, 'value', 'name', placeholder);
              return mappedRoles;
          } catch (error) {
              console.error('Failed to load roles, using fallback:', error);
              const fallbackRoles = [
                  { name: 'Super Admin', value: 'superadmin' },
                  { name: 'Admin', value: 'admin' },
                  { name: 'Manager', value: 'manager' },
                  { name: 'Staff', value: 'staff' }
              ];
              this.populateSelect(selectId, fallbackRoles, 'value', 'name', placeholder);
              return fallbackRoles;
          }
      }

    /**
     * Load and populate shifts
     */
     static async populateShifts(selectId, placeholder = 'Select shift') {
         try {
             const shifts = await this.loadData('backend/api/shifts.php', 'list');
             this.populateSelect(selectId, shifts, 'id', 'name', placeholder);
             return shifts;
         } catch (error) {
             console.error('Failed to load shifts, using fallback:', error);
             const fallbackShifts = [
                 { id: 1, name: 'Morning' },
                 { id: 2, name: 'Afternoon' },
                 { id: 3, name: 'Evening' }
             ];
             this.populateSelect(selectId, fallbackShifts, 'id', 'name', placeholder);
             return fallbackShifts;
         }
     }

     /**
      * Load and populate payment methods
      */
     static async populatePaymentMethods(selectId, placeholder = 'Select payment method') {
         try {
             const methods = await this.loadData('backend/api/payment_methods.php', 'list');
             this.populateSelect(selectId, methods, 'id', 'name', placeholder);
             return methods;
         } catch (error) {
             console.error('Failed to load payment methods, using fallback:', error);
             const fallbackMethods = [
                 { id: 1, name: 'Cash' },
                 { id: 2, name: 'GCash' }
             ];
             this.populateSelect(selectId, fallbackMethods, 'id', 'name', placeholder);
             return fallbackMethods;
         }
     }

     /**
      * Show error message in a toast/notification
      */
     static showError(message) {
         alert(message);
     }

     /**
      * Show success message
      */
     static showSuccess(message) {
         alert(message);
     }

     /**
      * Load and populate adjustment types
      */
     static async populateAdjustmentTypes(selectId, placeholder = 'Select adjustment type') {
         try {
             const types = await this.loadData('backend/api/adjustment_types.php', 'list');
             this.populateSelect(selectId, types, 'id', 'name', placeholder);
             return types;
         } catch (error) {
             console.error('Failed to load adjustment types, using fallback:', error);
             const fallbackTypes = [
                 { id: 1, name: 'Loss' },
                 { id: 2, name: 'Transfer' },
                 { id: 3, name: 'Consumption' },
                 { id: 4, name: 'Other' }
             ];
             this.populateSelect(selectId, fallbackTypes, 'id', 'name', placeholder);
             return fallbackTypes;
         }
     }

     /**
      * Load and populate service categories
      */
     static async populateServiceCategories(selectId, placeholder = 'Select service category') {
         try {
             const categories = await this.loadData('backend/api/service_categories.php', 'list');
             this.populateSelect(selectId, categories, 'id', 'name', placeholder);
             return categories;
         } catch (error) {
             console.error('Failed to load service categories, using fallback:', error);
             const fallbackCategories = [
                 { id: 1, name: 'Change Oil' },
                 { id: 2, name: 'Brake Service' },
                 { id: 3, name: 'Vulcanizing' },
                 { id: 4, name: 'Car Wash' },
                 { id: 5, name: 'Battery Check' },
                 { id: 6, name: 'Engine Tune-up' },
                 { id: 7, name: 'Air Filter Replacement' },
                 { id: 8, name: 'Wheel Alignment' },
                 { id: 9, name: 'Other Service' }
             ];
             this.populateSelect(selectId, fallbackCategories, 'id', 'name', placeholder);
             return fallbackCategories;
         }
     }
 }
