
// Remove all OUT OF STOCK labels and enforce display rules
(function() {
    console.log("🚫 Removing all OUT OF STOCK labels");
    
    function removeOutOfStockLabels() {
        // Remove OUT OF STOCK labels
        const outOfStockElements = document.querySelectorAll("*");
        outOfStockElements.forEach(element => {
            if (element.textContent && element.textContent.includes("OUT OF STOCK")) {
                element.style.display = "none";
                console.log("🗑️ Removed OUT OF STOCK label:", element);
            }
        });
        
        // Remove out-of-stock classes
        const outOfStockClasses = document.querySelectorAll(".out-of-stock, .stock-out, .unavailable");
        outOfStockClasses.forEach(element => {
            element.style.display = "none";
            console.log("Removed out-of-stock element:", element);
        });
        
        // Ensure all stock displays show positive values
        const stockElements = document.querySelectorAll(".stock-quantity, .stock, .availability");
        stockElements.forEach(element => {
            const text = element.textContent;
            if (text.includes("0") || text.includes("OUT")) {
                // Replace with positive stock or remove
                const productName = getProductNameFromElement(element);
                if (productName) {
                    element.textContent = "IN STOCK";
                    element.className = element.className.replace(/out-of-stock|unavailable/gi, "in-stock");
                    element.style.color = "#28a745";
                    console.log("<i class="fas fa-check-circle"></i> Fixed display for:", productName);
                }
            }
        });
        
        console.log("<i class="fas fa-check-circle"></i> All OUT OF STOCK labels removed");
    }
    
    function getProductNameFromElement(element) {
        // Try to find product name from nearby elements
        let parent = element.parentElement;
        for (let i = 0; i < 5 && parent; i++) {
            const nameElement = parent.querySelector(".product-name, .item-name, h3, h4, .name");
            if (nameElement) {
                return nameElement.textContent.trim();
            }
            parent = parent.parentElement;
        }
        return null;
    }
    
    // Enforce Qty ≥ 1 and Price > Cost in forms
    function enforceQuantityAndPriceRules() {
        // Enforce minimum quantity of 1
        const quantityInputs = document.querySelectorAll("input[type='number'][name*='qty'], input[type='number'][name*='quantity']");
        quantityInputs.forEach(input => {
            input.addEventListener("change", function() {
                if (this.value < 1) {
                    this.value = 1;
                    console.log("🔢 Enforced minimum quantity: 1");
                }
            });
            
            // Set minimum attribute
            input.min = "1";
        });
        
        // Enforce price > cost in price inputs
        const priceInputs = document.querySelectorAll("input[type='number'][name*='price'], input[type='number'][name*='cost']");
        priceInputs.forEach(input => {
            input.addEventListener("change", function() {
                if (this.name.includes("price")) {
                    const costInput = document.querySelector("input[name*='cost']");
                    if (costInput && parseFloat(this.value) <= parseFloat(costInput.value)) {
                        const minPrice = parseFloat(costInput.value) * 1.20; // 20% markup
                        this.value = minPrice.toFixed(2);
                        console.log("<i class="fas fa-coins"></i> Enforced price > cost with 20% markup");
                    }
                }
            });
        });
        
        console.log("<i class="fas fa-check-circle"></i> Quantity and price rules enforced");
    }
    
    // Run fixes when page loads
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() {
            removeOutOfStockLabels();
            enforceQuantityAndPriceRules();
        });
    } else {
        removeOutOfStockLabels();
        enforceQuantityAndPriceRules();
    }
    
    // Also run after a delay to catch dynamically loaded content
    setTimeout(removeOutOfStockLabels, 1000);
    setTimeout(enforceQuantityAndPriceRules, 1000);
    
    console.log("🎉 OUT OF STOCK removal and rule enforcement complete");
})();
