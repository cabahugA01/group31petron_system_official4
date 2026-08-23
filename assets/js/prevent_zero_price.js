
// Prevent zero price display in job order form
function preventZeroPriceDisplay() {
    console.log("<i class="fas fa-wrench"></i> Preventing zero price display...");
    
    // Override price display functions
    const originalDisplayParts = window.displayPartsEmergency || window.displayServicePartsDirect;
    
    if (window.displayPartsEmergency) {
        window.displayPartsEmergency = function(serviceName, parts) {
            // Ensure all parts have valid prices
            parts = parts.map(part => ({
                ...part,
                unit_price: part.unit_price > 0 ? part.unit_price : getDefaultPriceForPart(part.part_name),
                default_unit_price: part.default_unit_price > 0 ? part.default_unit_price : getDefaultPriceForPart(part.part_name)
            }));
            
            return originalDisplayParts.call(this, serviceName, parts);
        };
    }
    
    if (window.displayServicePartsDirect) {
        window.displayServicePartsDirect = function(parts, serviceName) {
            // Ensure all parts have valid prices
            parts = parts.map(part => ({
                ...part,
                unit_price: part.unit_price > 0 ? part.unit_price : getDefaultPriceForPart(part.part_name),
                default_unit_price: part.default_unit_price > 0 ? part.default_unit_price : getDefaultPriceForPart(part.part_name)
            }));
            
            return originalDisplayParts.call(this, parts, serviceName);
        };
    }
    
    console.log("<i class="fas fa-check-circle"></i> Zero price prevention enabled");
}

function getDefaultPriceForPart(partName) {
    // Default prices for common parts
    if (partName.includes("Engine Oil")) return 150.00;
    if (partName.includes("Oil Filter")) return 175.00;
    if (partName.includes("Tire Valve")) return 50.00;
    if (partName.includes("MP Grease")) return 89.00;
    if (partName.includes("WD-40")) return 150.00;
    if (partName.includes("Coolant")) return 115.00;
    if (partName.includes("Brake Fluid")) return 135.00;
    if (partName.includes("Gasket")) return 95.00;
    if (partName.includes("Battery")) return 3000.00;
    if (partName.includes("Scanner")) return 2500.00;
    
    return 100.00; // Default fallback
}

// Run the fix
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", preventZeroPriceDisplay);
} else {
    preventZeroPriceDisplay();
}
