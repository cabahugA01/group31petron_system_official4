/**
 * Dynamic Scroll Toggle Button
 * Automatically switches between up/down arrows based on scroll position
 * Integrates with Petron POS system styling
 */

class ScrollToggleButton {
    constructor(options = {}) {
        // Default configuration
        this.config = {
            containerSelector: options.containerSelector || '.table-wrap, .card-body, .main-content',
            buttonId: options.buttonId || 'scrollToggleBtn',
            buttonClass: options.buttonClass || 'scroll-toggle-btn',
            position: options.position || 'bottom-right', // bottom-right, bottom-left, top-right, top-left
            iconSize: options.iconSize || '16px',
            buttonSize: options.buttonSize || '40px',
            showThreshold: options.showThreshold || 100, // Show button after scrolling this many pixels
            debounceDelay: options.debounceDelay || 100,
            ...options
        };

        this.button = null;
        this.currentContainer = null;
        this.isAtBottom = false;
        this.isVisible = false;
        this.debounceTimer = null;

        this.init();
    }

    init() {
        this.createButton();
        this.attachEventListeners();
        this.scanContainers();
    }

    createButton() {
        // Create button element
        this.button = document.createElement('button');
        this.button.id = this.config.buttonId;
        this.button.className = this.config.buttonClass;
        this.button.innerHTML = '<i class="fas fa-arrow-down"></i>';
        this.button.setAttribute('aria-label', 'Scroll to bottom');
        this.button.style.display = 'none';

        // Add styles
        this.addStyles();

        // Add to body
        document.body.appendChild(this.button);

        // Button click handler
        this.button.addEventListener('click', () => this.handleButtonClick());
    }

    addStyles() {
        const styles = `
            #${this.config.buttonId} {
                position: fixed;
                ${this.getPositionStyles()}
                width: ${this.config.buttonSize};
                height: ${this.config.buttonSize};
                background: linear-gradient(135deg, #002F6C 0%, #0040a0 50%, #002F6C 100%);
                border: 2px solid #ffffff;
                border-radius: 50%;
                color: white;
                font-size: ${this.config.iconSize};
                cursor: pointer;
                z-index: 1000;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(0, 47, 112, 0.3), 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                opacity: 0;
                transform: scale(0.8) translateY(10px);
                pointer-events: none;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            }

            #${this.config.buttonId}.visible {
                opacity: 1;
                transform: scale(1) translateY(0);
                pointer-events: auto;
            }

            #${this.config.buttonId}:hover {
                background: linear-gradient(135deg, #0040a0 0%, #002F6C 50%, #001a4d 100%);
                transform: scale(1.1) translateY(-2px);
                box-shadow: 0 8px 20px rgba(0, 47, 112, 0.4), 0 4px 8px rgba(0, 0, 0, 0.15);
                border-color: rgba(255, 255, 255, 0.9);
            }

            #${this.config.buttonId}:active {
                transform: scale(0.95) translateY(0);
                box-shadow: 0 2px 8px rgba(0, 47, 112, 0.3), 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            #${this.config.buttonId} i {
                transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));
            }

            #${this.config.buttonId}.at-bottom i {
                transform: rotate(180deg);
            }

            #${this.config.buttonId}::before {
                content: '';
                position: absolute;
                top: -2px;
                left: -2px;
                right: -2px;
                bottom: -2px;
                background: linear-gradient(45deg, #002F6C, #0040a0, #002F6C);
                border-radius: 50%;
                z-index: -1;
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            #${this.config.buttonId}:hover::before {
                opacity: 0.3;
            }

            /* Mobile responsive adjustments */
            @media (max-width: 768px) {
                #${this.config.buttonId} {
                    width: 35px;
                    height: 35px;
                    font-size: 14px;
                    bottom: 15px;
                    right: 15px;
                }
            }

            /* High contrast mode support */
            @media (prefers-contrast: high) {
                #${this.config.buttonId} {
                    border-width: 3px;
                    background: #002F6C;
                }
            }

            /* Reduced motion support */
            @media (prefers-reduced-motion: reduce) {
                #${this.config.buttonId},
                #${this.config.buttonId} i,
                #${this.config.buttonId}::before {
                    transition: none;
                }
            }
        `;

        // Add styles to head
        const styleSheet = document.createElement('style');
        styleSheet.textContent = styles;
        document.head.appendChild(styleSheet);
    }

    getPositionStyles() {
        const positions = {
            'bottom-right': 'bottom: 20px; right: 20px;',
            'bottom-left': 'bottom: 20px; left: 20px;',
            'top-right': 'top: 100px; right: 20px;',
            'top-left': 'top: 100px; left: 20px;'
        };
        return positions[this.config.position] || positions['bottom-right'];
    }

    attachEventListeners() {
        // Listen for scroll events on all containers
        document.addEventListener('scroll', (e) => this.handleScroll(e), true);
        
        // Listen for DOM changes to detect new containers
        const observer = new MutationObserver(() => {
            this.scanContainers();
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Window resize handler
        window.addEventListener('resize', () => {
            this.debounce(() => {
                this.scanContainers();
            });
        });
    }

    scanContainers() {
        const containers = document.querySelectorAll(this.config.containerSelector);
        
        containers.forEach(container => {
            if (!container.hasAttribute('data-scroll-listener')) {
                container.addEventListener('scroll', () => this.handleContainerScroll(container));
                container.setAttribute('data-scroll-listener', 'true');
            }
        });
    }

    handleScroll(e) {
        this.debounce(() => {
            const target = e.target;
            
            // Check if target is a scrollable container
            if (target.matches && target.matches(this.config.containerSelector)) {
                this.updateButtonState(target);
            }
        });
    }

    handleContainerScroll(container) {
        this.debounce(() => {
            this.updateButtonState(container);
        });
    }

    updateButtonState(container) {
        this.currentContainer = container;
        
        const scrollTop = container.scrollTop || container.pageYOffset || 0;
        const scrollHeight = container.scrollHeight || container.document?.scrollElement?.scrollHeight || 0;
        const clientHeight = container.clientHeight || container.innerHeight || 0;
        
        // Check if at bottom (within 10px threshold)
        this.isAtBottom = (scrollTop + clientHeight) >= (scrollHeight - 10);
        
        // Check if should show button
        const shouldShow = scrollTop > this.config.showThreshold || scrollHeight > clientHeight;
        
        this.updateButton(shouldShow);
    }

    updateButton(show) {
        if (show && !this.isVisible) {
            this.showButton();
        } else if (!show && this.isVisible) {
            this.hideButton();
        }

        // Update icon based on position
        this.updateIcon();
    }

    showButton() {
        this.button.classList.add('visible');
        this.isVisible = true;
    }

    hideButton() {
        this.button.classList.remove('visible');
        this.isVisible = false;
    }

    updateIcon() {
        const icon = this.button.querySelector('i');
        
        if (this.isAtBottom) {
            icon.className = 'fas fa-arrow-up';
            this.button.classList.add('at-bottom');
            this.button.setAttribute('aria-label', 'Scroll to top');
        } else {
            icon.className = 'fas fa-arrow-down';
            this.button.classList.remove('at-bottom');
            this.button.setAttribute('aria-label', 'Scroll to bottom');
        }
    }

    handleButtonClick() {
        if (!this.currentContainer) {
            // Find the most likely container
            this.currentContainer = this.findScrollableContainer();
        }

        if (this.currentContainer) {
            if (this.isAtBottom) {
                // Scroll to top
                this.currentContainer.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            } else {
                // Scroll to bottom
                this.currentContainer.scrollTo({
                    top: this.currentContainer.scrollHeight,
                    behavior: 'smooth'
                });
            }
        }
    }

    findScrollableContainer() {
        // Try to find the main content container
        const selectors = [
            '.main-content',
            '.card-body',
            '.table-wrap',
            '.main',
            'body'
        ];

        for (const selector of selectors) {
            const container = document.querySelector(selector);
            if (container && container.scrollHeight > container.clientHeight) {
                return container;
            }
        }

        return document.documentElement;
    }

    debounce(func) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(func, this.config.debounceDelay);
    }

    // Public methods
    destroy() {
        if (this.button) {
            this.button.remove();
        }
        
        // Remove event listeners
        document.removeEventListener('scroll', this.handleScroll, true);
        window.removeEventListener('resize', this.debounce);
        
        // Clear debounce timer
        clearTimeout(this.debounceTimer);
    }

    updateConfig(newConfig) {
        this.config = { ...this.config, ...newConfig };
        
        // Update button styles if needed
        if (this.button) {
            this.button.style.cssText = '';
            this.addStyles();
        }
    }
}

// Auto-initialize for common use cases
document.addEventListener('DOMContentLoaded', () => {
    console.log('🔄 Initializing scroll toggle button...');
    
    // Default initialization for table wraps and card bodies
    window.scrollToggleButton = new ScrollToggleButton({
        containerSelector: '.table-wrap, .card-body, .main-content, .main, body',
        position: 'bottom-right',
        showThreshold: 50
    });
    
    console.log('✅ Scroll toggle button initialized');
    
    // Force button to be visible for testing
    setTimeout(() => {
        const button = document.getElementById('scrollToggleBtn');
        if (button) {
            console.log('🎯 Button found, making it visible for testing');
            button.classList.add('visible');
            button.style.display = 'flex';
            button.style.opacity = '1';
            console.log('Button element:', button);
        } else {
            console.error('❌ Button not found after initialization');
        }
    }, 1000);
});

// Export for manual initialization
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ScrollToggleButton;
}
