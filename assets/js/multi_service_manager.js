
// Multi-Service Selection System
class MultiServiceManager {
    constructor() {
        this.selectedServices = new Map();
        this.serviceData = new Map();
        this.init();
    }
    
    init() {
        this.setupEventListeners();
        this.setupCostCalculation();
    }
    
    setupEventListeners() {
        // Listen for all service type checkboxes
        const serviceCheckboxes = document.querySelectorAll('input[name="service_types[]"]');
        
        serviceCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const serviceName = e.target.value.trim();
                const isChecked = e.target.checked;
                
                if (isChecked) {
                    this.loadServiceParts(serviceName);
                } else {
                    this.removeService(serviceName);
                }
                
                this.updateDisplay();
                this.calculateTotals();
            });
        });
    }
    
    async loadServiceParts(serviceName) {
        try {
            // Show loading state
            this.showLoadingState(serviceName);
            
            // Get service key from service name
            const serviceKey = this.getServiceKeyFromName(serviceName);
            
            // Load service data including fees and parts
            const response = await fetch(`../backend/api/service_parts.php?service_key=${encodeURIComponent(serviceKey)}`);
            const result = await response.json();
            
            if (result.success && result.data) {
                // Get service fee information
                const feeResponse = await fetch(`../backend/api/multi_service_operations.php?action=get_service_fees`);
                const feeResult = await feeResponse.json();
                
                let serviceFee = 0;
                if (feeResult.success) {
                    const feeInfo = feeResult.data.find(f => f.service_key === serviceKey);
                    if (feeInfo) {
                        serviceFee = parseFloat(feeInfo.total_fee);
                    }
                }
                
                this.selectedServices.set(serviceName, {
                    service_key: serviceKey,
                    service_name: serviceName,
                    service_fee: serviceFee,
                    parts: result.data.map(part => ({
                        ...part,
                        selected: false,
                        quantity: part.default_quantity || 1,
                        remarks: '',
                        unit_price: part.default_unit_price || 0
                    }))
                });
                
                console.log(`<i class="fas fa-check-circle"></i> Loaded ${serviceName}: ${result.data.length} parts`);
                
            } else {
                console.error(`<i class="fas fa-times-circle"></i> Failed to load parts for ${serviceName}:`, result.error);
                this.showErrorState(serviceName);
            }
            
        } catch (error) {
            console.error(`<i class="fas fa-times-circle"></i> Error loading ${serviceName}:`, error);
            this.showErrorState(serviceName);
        }
    }
    
    removeService(serviceName) {
        this.selectedServices.delete(serviceName);
        console.log(`🗑️ Removed service: ${serviceName}`);
    }
    
    updateDisplay() {
        const container = document.getElementById('required-parts-container');
        const autoText = document.getElementById('auto-populate-text');
        
        if (this.selectedServices.size === 0) {
            container.innerHTML = '<p style="color: #666;">Select service types above to auto-populate parts</p>';
            if (autoText) autoText.textContent = '';
            return;
        }
        
        container.innerHTML = '';
        
        // Display each service with its parts
        let totalParts = 0;
        this.selectedServices.forEach((serviceData, serviceName) => {
            totalParts += serviceData.parts.length;
            this.displayServiceSection(serviceName, serviceData);
        });
        
        if (autoText) {
            const serviceNames = Array.from(this.selectedServices.keys());
            autoText.textContent = `${this.selectedServices.size} services selected (${totalParts} total parts)`;
        }
    }
    
    displayServiceSection(serviceName, serviceData) {
        const container = document.getElementById('required-parts-container');
        
        // Create service section
        const serviceSection = document.createElement('div');
        serviceSection.className = 'service-section';
        serviceSection.dataset.service = serviceName;
        
        // Service header
        const serviceHeader = document.createElement('div');
        serviceHeader.className = 'service-header';
        serviceHeader.innerHTML = `
            <h4><i class="fas fa-wrench"></i> ${serviceName}</h4>
            <div class="service-info">
                <span class="service-fee">Service Fee: ₱${serviceData.service_fee.toFixed(2)}</span>
                <span class="parts-count">${serviceData.parts.length} parts</span>
            </div>
        `;
        
        serviceSection.appendChild(serviceHeader);
        
        // Parts container
        const partsContainer = document.createElement('div');
        partsContainer.className = 'parts-container';
        
        serviceData.parts.forEach((part, index) => {
            const partDiv = this.createPartElement(serviceName, part, index);
            partsContainer.appendChild(partDiv);
        });
        
        serviceSection.appendChild(partsContainer);
        container.appendChild(serviceSection);
    }
    
    createPartElement(serviceName, part, index) {
        const partDiv = document.createElement('div');
        partDiv.className = 'part-item multi-service-part';
        partDiv.dataset.service = serviceName;
        partDiv.dataset.part = part.part_name;
        
        partDiv.innerHTML = `
            <div class="part-checkbox">
                <input type="checkbox" 
                       name="service_parts_${serviceName}[]" 
                       value="${part.part_name}" 
                       data-service="${serviceName}" 
                       data-part="${part.part_name}"
                       data-unit-price="${part.unit_price}"
                       onchange="multiServiceManager.togglePartSelection('${serviceName}', '${part.part_name}', this.checked)">
            </div>
            <div class="part-info">
                <label class="part-name">
                    <strong>${part.part_name}</strong>
                    <br><small class="part-category">${part.category || 'General'} - ₱${part.unit_price.toFixed(2)}/unit</small>
                </label>
            </div>
            <div class="part-quantity">
                <input type="number" 
                       name="part_qty_${serviceName}_${index}" 
                       value="${part.quantity}" 
                       min="1" 
                       max="999" 
                       class="qty-input"
                       data-service="${serviceName}" 
                       data-part="${part.part_name}"
                       onchange="multiServiceManager.updatePartQuantity('${serviceName}', '${part.part_name}', this.value)">
            </div>
            <div class="part-remarks">
                <input type="text" 
                       name="part_remarks_${serviceName}_${index}" 
                       placeholder="Remarks" 
                       value="${part.remarks}"
                       class="remarks-input"
                       data-service="${serviceName}" 
                       data-part="${part.part_name}"
                       onchange="multiServiceManager.updatePartRemarks('${serviceName}', '${part.part_name}', this.value)">
            </div>
            <div class="part-cost">
                <span class="part-total">₱${(part.quantity * part.unit_price).toFixed(2)}</span>
            </div>
            <div class="part-actions">
                <button type="button" 
                        class="delete-part-btn" 
                        onclick="multiServiceManager.removePart('${serviceName}', '${part.part_name}')"
                        title="Remove part">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        
        return partDiv;
    }
    
    togglePartSelection(serviceName, partName, isSelected) {
        const service = this.selectedServices.get(serviceName);
        if (service) {
            const part = service.parts.find(p => p.part_name === partName);
            if (part) {
                part.selected = isSelected;
                this.calculateTotals();
            }
        }
    }
    
    updatePartQuantity(serviceName, partName, quantity) {
        const service = this.selectedServices.get(serviceName);
        if (service) {
            const part = service.parts.find(p => p.part_name === partName);
            if (part) {
                part.quantity = parseInt(quantity) || 1;
                this.updatePartCost(serviceName, partName);
                this.calculateTotals();
            }
        }
    }
    
    updatePartRemarks(serviceName, partName, remarks) {
        const service = this.selectedServices.get(serviceName);
        if (service) {
            const part = service.parts.find(p => p.part_name === partName);
            if (part) {
                part.remarks = remarks;
            }
        }
    }
    
    updatePartCost(serviceName, partName) {
        const service = this.selectedServices.get(serviceName);
        if (service) {
            const part = service.parts.find(p => p.part_name === partName);
            if (part) {
                const totalCost = part.quantity * part.unit_price;
                const partElement = document.querySelector(`[data-service="${serviceName}"][data-part="${partName}"] .part-total`);
                if (partElement) {
                    partElement.textContent = `₱${totalCost.toFixed(2)}`;
                }
            }
        }
    }
    
    removePart(serviceName, partName) {
        const service = this.selectedServices.get(serviceName);
        if (service) {
            const partIndex = service.parts.findIndex(p => p.part_name === partName);
            if (partIndex !== -1) {
                service.parts.splice(partIndex, 1);
                
                // Remove from DOM
                const partElement = document.querySelector(`[data-service="${serviceName}"][data-part="${partName}"]`);
                if (partElement) {
                    partElement.remove();
                }
                
                this.updateDisplay();
                this.calculateTotals();
            }
        }
    }
    
    async calculateTotals() {
        try {
            const servicesData = [];
            
            this.selectedServices.forEach((serviceData, serviceName) => {
                const selectedParts = serviceData.parts
                    .filter(part => part.selected)
                    .map(part => ({
                        part_name: part.part_name,
                        quantity: part.quantity,
                        unit_price: part.unit_price,
                        remarks: part.remarks
                    }));
                
                servicesData.push({
                    service_key: serviceData.service_key,
                    service_name: serviceName,
                    selected_parts: selectedParts
                });
            });
            
            const response = await fetch('../backend/api/multi_service_operations.php?action=calculate_totals', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    services: servicesData
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.displayCostSummary(result);
            } else {
                console.error('<i class="fas fa-times-circle"></i> Cost calculation failed:', result.error);
            }
            
        } catch (error) {
            console.error('<i class="fas fa-times-circle"></i> Error calculating totals:', error);
        }
    }
    
    displayCostSummary(result) {
        // Update or create cost summary section
        let summaryContainer = document.getElementById('cost-summary-container');
        
        if (!summaryContainer) {
            summaryContainer = document.createElement('div');
            summaryContainer.id = 'cost-summary-container';
            summaryContainer.className = 'cost-summary';
            
            // Insert after required parts container
            const partsContainer = document.getElementById('required-parts-container');
            if (partsContainer && partsContainer.parentNode) {
                partsContainer.parentNode.insertBefore(summaryContainer, partsContainer.nextSibling);
            }
        }
        
        summaryContainer.innerHTML = `
            <div class="cost-summary-header">
                <h3><i class="fas fa-calculator"></i> Cost Summary</h3>
            </div>
            <div class="cost-breakdown">
                ${result.service_breakdown.map(service => `
                    <div class="service-cost-item">
                        <div class="service-name">${service.service_name}</div>
                        <div class="service-details">
                            <span class="service-fee">Service Fee: ₱${service.service_fee.toFixed(2)}</span>
                            <span class="parts-cost">Parts Cost: ₱${service.parts_cost.toFixed(2)}</span>
                        </div>
                        <div class="service-total">₱${service.service_total.toFixed(2)}</div>
                    </div>
                `).join('')}
            </div>
            <div class="grand-total">
                <div class="total-label">GRAND TOTAL</div>
                <div class="total-amount">₱${result.grand_total.toFixed(2)}</div>
            </div>
            <div class="summary-stats">
                <span>Total Services: ${result.summary.total_services}</span>
                <span>Total Service Fees: ₱${result.summary.total_service_fees.toFixed(2)}</span>
                <span>Total Parts Cost: ₱${result.summary.total_parts_cost.toFixed(2)}</span>
            </div>
        `;
    }
    
    showLoadingState(serviceName) {
        console.log(`<i class="fas fa-clock"></i> Loading ${serviceName}...`);
    }
    
    showErrorState(serviceName) {
        console.error(`<i class="fas fa-times-circle"></i> Error loading ${serviceName}`);
    }
    
    getServiceKeyFromName(serviceName) {
        const serviceKeyMap = {
            'Oil Change': 'oil_change',
            'Tire Repair': 'tire_repair',
            'Calibration': 'calibration',
            'General Maintenance': 'general_maintenance',
            'Engine Repair': 'engine_repair',
            'Brake Service': 'brake_service',
            'Electrical': 'electrical',
            'Air Conditioning': 'air_conditioning',
            'Transmission Service': 'transmission_service',
            'Suspension Repair': 'suspension_repair',
            'Wheel Alignment': 'wheel_alignment',
            'Battery Replacement': 'battery_replacement',
            'Diagnostic Check': 'diagnostic_check',
            'Detailing / Cleaning': 'detailing_cleaning',
            'Other': 'other'
        };
        
        return serviceKeyMap[serviceName] || serviceName.toLowerCase().replace(/\s+/g, '_');
    }
    
    setupCostCalculation() {
        // Auto-calculate totals when parts are selected/modified
        document.addEventListener('change', (e) => {
            if (e.target.matches('input[type="checkbox"][data-service]') ||
                e.target.matches('.qty-input[data-service]')) {
                setTimeout(() => this.calculateTotals(), 100);
            }
        });
    }
}

// Initialize multi-service manager
const multiServiceManager = new MultiServiceManager();
