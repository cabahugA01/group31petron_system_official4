/**
 * Simple Scroll Toggle Button - Guaranteed to Work
 * Direct implementation without complex class structure
 */

(function() {
    'use strict';
    
    console.log('🚀 Loading simple scroll toggle button...');
    
    // Create button
    const button = document.createElement('button');
    button.id = 'scrollToggleBtn';
    button.innerHTML = '<i class="fas fa-arrow-down"></i>';
    button.setAttribute('aria-label', 'Scroll to bottom');
    button.title = 'Scroll to bottom';
    
    // Add styles directly
    const styles = `
        #scrollToggleBtn {
            position: fixed !important;
            bottom: 20px !important;
            right: 20px !important;
            width: 45px !important;
            height: 45px !important;
            background: linear-gradient(135deg, #002F6C 0%, #0040a0 50%, #002F6C 100%) !important;
            border: 2px solid #ffffff !important;
            border-radius: 50% !important;
            color: white !important;
            font-size: 16px !important;
            cursor: pointer !important;
            z-index: 9999 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-shadow: 0 4px 12px rgba(0, 47, 112, 0.4), 0 2px 4px rgba(0, 0, 0, 0.1) !important;
            transition: all 0.3s ease !important;
            opacity: 0 !important;
            transform: scale(0.8) !important;
            pointer-events: none !important;
        }
        
        #scrollToggleBtn.visible {
            opacity: 1 !important;
            transform: scale(1) !important;
            pointer-events: auto !important;
        }
        
        #scrollToggleBtn:hover {
            background: linear-gradient(135deg, #0040a0 0%, #002F6C 50%, #001a4d 100%) !important;
            transform: scale(1.1) !important;
            box-shadow: 0 6px 16px rgba(0, 47, 112, 0.5), 0 4px 8px rgba(0, 0, 0, 0.15) !important;
        }
        
        #scrollToggleBtn:active {
            transform: scale(0.95) !important;
        }
        
        #scrollToggleBtn i {
            transition: transform 0.3s ease !important;
        }
        
        #scrollToggleBtn.at-bottom i {
            transform: rotate(180deg) !important;
        }
        
        @media (max-width: 768px) {
            #scrollToggleBtn {
                width: 40px !important;
                height: 40px !important;
                font-size: 14px !important;
                bottom: 15px !important;
                right: 15px !important;
            }
        }
    `;
    
    // Add styles to page
    const styleSheet = document.createElement('style');
    styleSheet.textContent = styles;
    document.head.appendChild(styleSheet);
    
    // Add button to page
    document.body.appendChild(button);
    
    console.log('✅ Button created and added to page');
    
    let isAtBottom = false;
    let isVisible = false;
    
    function updateButton() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight;
        const clientHeight = window.innerHeight;
        
        // Check if at bottom (within 50px threshold)
        isAtBottom = (scrollTop + clientHeight) >= (scrollHeight - 50);
        
        // Check if should show button (after scrolling 100px)
        const shouldShow = scrollTop > 100 || scrollHeight > clientHeight;
        
        if (shouldShow && !isVisible) {
            button.classList.add('visible');
            isVisible = true;
            console.log('👁️ Button shown');
        } else if (!shouldShow && isVisible) {
            button.classList.remove('visible');
            isVisible = false;
            console.log('🙈 Button hidden');
        }
        
        // Update icon based on position
        const icon = button.querySelector('i');
        if (isAtBottom) {
            icon.className = 'fas fa-arrow-up';
            button.classList.add('at-bottom');
            button.setAttribute('aria-label', 'Scroll to top');
            button.title = 'Scroll to top';
        } else {
            icon.className = 'fas fa-arrow-down';
            button.classList.remove('at-bottom');
            button.setAttribute('aria-label', 'Scroll to bottom');
            button.title = 'Scroll to bottom';
        }
    }
    
    // Button click handler
    button.addEventListener('click', function() {
        if (isAtBottom) {
            // Scroll to top
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
            console.log('⬆️ Scrolling to top');
        } else {
            // Scroll to bottom
            window.scrollTo({
                top: document.documentElement.scrollHeight,
                behavior: 'smooth'
            });
            console.log('⬇️ Scrolling to bottom');
        }
    });
    
    // Scroll event listener
    window.addEventListener('scroll', function() {
        updateButton();
    });
    
    // Initial state
    setTimeout(function() {
        updateButton();
        console.log('✅ Simple scroll toggle button initialized');
        
        // Force show for testing
        button.classList.add('visible');
        button.style.display = 'flex';
        console.log('🎯 Button forced visible for testing');
    }, 500);
    
})();
