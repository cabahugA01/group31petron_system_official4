/**
 * Manager Customer Management - Client-side interactions
 * Handles search, filtering, pagination, payment modal, and AJAX submissions
 */

(function() {
    'use strict';

    // ========== UTILITY FUNCTIONS ==========

    /**
     * Debounce function to limit rate of execution
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Show flash message
     */
    function showFlash(message, type = 'success') {
        const flashDiv = document.createElement('div');
        flashDiv.className = type === 'success' ? 'flash-ok' : 'flash-err';
        flashDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle"></i> ${message}`;
        
        const container = document.querySelector('.page-head');
        if (container) {
            container.insertAdjacentElement('afterend', flashDiv);
            setTimeout(() => flashDiv.remove(), 5000);
        }
    }

    // ========== SEARCH & FILTER ==========

    /**
     * Initialize search functionality
     */
    function initSearch() {
        const searchInput = document.getElementById('customerSearch');
        if (!searchInput) return;

        const debouncedSearch = debounce(() => {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = document.querySelectorAll('.data-table tbody tr[data-search]');

            rows.forEach(row => {
                const searchData = row.getAttribute('data-search');
                if (searchData.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            updateEmptyState();
        }, 300);

        searchInput.addEventListener('input', debouncedSearch);
    }

    /**
     * Update empty state visibility
     */
    function updateEmptyState() {
        const rows = document.querySelectorAll('.data-table tbody tr[data-search]');
        const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
        
        let emptyState = document.querySelector('.empty-state-filter');
        
        if (visibleRows.length === 0 && rows.length > 0) {
            if (!emptyState) {
                emptyState = document.createElement('tr');
                emptyState.className = 'empty-state-filter';
                emptyState.innerHTML = '<td colspan="100%" class="empty-state"><i class="fas fa-search"></i><p>No customers match your search.</p></td>';
                document.querySelector('.data-table tbody').appendChild(emptyState);
            }
            emptyState.style.display = '';
        } else if (emptyState) {
            emptyState.style.display = 'none';
        }
    }

    // ========== PAYMENT MODAL ==========

    let currentCustomerId = null;
    let currentCustomerName = '';
    let currentOutstanding = 0;

    /**
     * Open payment modal
     */
    window.openPaymentModal = function(customerId, customerName, outstanding) {
        currentCustomerId = customerId;
        currentCustomerName = customerName;
        currentOutstanding = parseFloat(outstanding);

        document.getElementById('paymentCustomerName').textContent = customerName;
        document.getElementById('paymentCustomerId').value = customerId;
        document.getElementById('paymentAmount').value = '';
        document.getElementById('paymentReference').value = '';
        document.getElementById('paymentModal').classList.add('open');
    };

    /**
     * Close payment modal
     */
    window.closePaymentModal = function() {
        document.getElementById('paymentModal').classList.remove('open');
        currentCustomerId = null;
        currentCustomerName = '';
        currentOutstanding = 0;
    };

    /**
     * Submit payment via AJAX
     */
    window.submitPayment = async function() {
        const amount = parseFloat(document.getElementById('paymentAmount').value);
        const reference = document.getElementById('paymentReference').value.trim();

        // Validation
        if (!amount || amount <= 0) {
            alert('Payment amount must be greater than 0.');
            return;
        }

        if (reference.length < 3) {
            alert('Reference must be at least 3 characters.');
            return;
        }

        // Check for overpayment
        if (amount > currentOutstanding) {
            const excess = amount - currentOutstanding;
            const confirmed = confirm(
                `Warning: Payment amount (₱${amount.toFixed(2)}) exceeds outstanding balance (₱${currentOutstanding.toFixed(2)}).\n\n` +
                `Overpayment: ₱${excess.toFixed(2)}\n\n` +
                `Do you want to proceed?`
            );
            if (!confirmed) return;
        }

        // Disable submit button
        const submitBtn = document.querySelector('#paymentModal .btn-validate');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const response = await fetch('manager_customer_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    action: 'validate_payment',
                    customer_id: currentCustomerId,
                    amount: amount,
                    reference: reference
                })
            });

            const result = await response.json();

            if (result.success) {
                // Update the row in-place
                updateCustomerRow(currentCustomerId, result.new_balance, result.new_utilization);
                
                // Show success message
                showFlash(`Payment of ₱${amount.toFixed(2)} recorded successfully. New balance: ₱${result.new_balance.toFixed(2)}`, 'success');
                
                // Close modal
                closePaymentModal();
            } else {
                alert(result.error || 'Payment failed. Please try again.');
            }
        } catch (error) {
            console.error('Payment error:', error);
            alert('An error occurred while processing the payment. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Submit Payment';
        }
    };

    /**
     * Update customer row after payment
     */
    function updateCustomerRow(customerId, newBalance, newUtilization) {
        const row = document.querySelector(`tr[data-customer-id="${customerId}"]`);
        if (!row) return;

        // Update outstanding balance cell
        const balanceCell = row.querySelector('.outstanding-balance');
        if (balanceCell) {
            balanceCell.textContent = `₱${newBalance.toFixed(2)}`;
        }

        // Update available credit cell
        const creditLimit = parseFloat(row.getAttribute('data-credit-limit') || 0);
        const availableCredit = creditLimit - newBalance;
        const availableCell = row.querySelector('.available-credit');
        if (availableCell) {
            availableCell.textContent = `₱${availableCredit.toFixed(2)}`;
        }

        // Update utilization cell and badge
        const utilizationCell = row.querySelector('.utilization');
        if (utilizationCell) {
            utilizationCell.textContent = `${newUtilization.toFixed(1)}%`;
        }

        // Update row class based on new utilization
        row.classList.remove('row-over-limit', 'row-near-limit');
        if (newUtilization >= 100) {
            row.classList.add('row-over-limit');
        } else if (newUtilization >= 80) {
            row.classList.add('row-near-limit');
        }
    }

    // ========== INITIALIZATION ==========

    document.addEventListener('DOMContentLoaded', function() {
        initSearch();

        // Close modal on overlay click
        const modalOverlay = document.getElementById('paymentModal');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(e) {
                if (e.target === modalOverlay) {
                    closePaymentModal();
                }
            });
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentModal();
            }
        });
    });

})();
