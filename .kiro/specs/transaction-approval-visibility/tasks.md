# Transaction Approval Visibility - Tasks

## Task List

### ✅ Phase 1: Requirements & Design
- [x] Document requirements
- [x] Create design document
- [ ] Review with stakeholders

### 🔄 Phase 2: Database Updates
- [ ] **Task 1**: Add database indexes for performance
  - Add index on `job_orders.validation_status`
  - Add index on `merchandise_transactions.validation_status`
  - Add composite index on `(station_id, validation_status)` for both tables

### 🔄 Phase 3: Backend Query Updates

- [ ] **Task 2**: Update Job Order Tracker query logic
  - File: `public/staff_transactions_hub.php`
  - Update SQL query to include `validation_status` filtering
  - Include approved/validated job orders
  - Add validation_status to SELECT columns
  - Update ORDER BY to prioritize by validation status

- [ ] **Task 3**: Update Merchandise History query logic
  - File: `public/staff_transactions_hub.php`
  - Update SQL query to include `validation_status` filtering
  - Include approved/validated merchandise transactions
  - Add validation_status to SELECT columns
  - Update ORDER BY clause

- [ ] **Task 4**: Update Staff Dashboard job order widget
  - File: `public/staff_dashboard.php`
  - Update job order query to include validation_status
  - Display validation status in dashboard widget
  - Add validation status badge

### 🔄 Phase 4: UI Component Updates

- [ ] **Task 5**: Create validation status badge function
  - Add `render_validation_badge()` function
  - Define badge colors and icons for each status
  - Return styled HTML badge component

- [ ] **Task 6**: Update Job Order Tracker table UI
  - Add "Validation Status" column
  - Display validation badge in each row
  - Ensure workflow status and validation status are both visible
  - Update table headers

- [ ] **Task 7**: Update Merchandise History panel UI
  - Add validation status badge display
  - Update row template to include validation indicator
  - Ensure proper styling and alignment

- [ ] **Task 8**: Update Staff Dashboard widget UI
  - Display validation status badges in dashboard job order cards
  - Update card layout to accommodate validation status
  - Ensure badges are visible and styled correctly

### 🔄 Phase 5: AJAX Endpoints (Optional)

- [ ] **Task 9**: Create AJAX refresh endpoint for job orders
  - Add `?refresh_job_orders=1` endpoint
  - Return filtered job orders with validation status
  - Include proper JSON response format

- [ ] **Task 10**: Create AJAX refresh endpoint for merchandise history
  - Add `?refresh_merchandise_history=1` endpoint
  - Return filtered transactions with validation status
  - Include proper JSON response format

### 🔄 Phase 6: Testing

- [ ] **Task 11**: Test Job Order approval flow
  - Staff creates job order → Pending Validation
  - Manager approves → Approved status
  - **Verify appears in staff's Job Order Tracker**
  - **Verify validation badge displays correctly**
  - Staff updates workflow status
  - Verify validation status persists

- [ ] **Task 12**: Test Merchandise Transaction approval flow
  - Staff creates merchandise transaction → Pending
  - Manager approves → Approved status
  - **Verify appears in staff's Merchandise History**
  - **Verify validation badge displays correctly**

- [ ] **Task 13**: Test Staff Dashboard widget
  - Create multiple job orders with different statuses
  - Approve some via manager
  - **Verify dashboard shows approved job orders**
  - **Verify badges display correctly**

- [ ] **Task 14**: Test edge cases
  - Job order with no validation status set (NULL)
  - Transaction approved then adjusted
  - Transaction rejected (should NOT appear in staff views)
  - Multiple transactions approved in quick succession

- [ ] **Task 15**: Performance testing
  - Test query performance with large datasets
  - Verify indexes are being used (EXPLAIN query)
  - Ensure page load time < 1 second

### 🔄 Phase 7: Documentation & Deployment

- [ ] **Task 16**: Update user documentation
  - Document validation status badges
  - Explain approval workflow to staff
  - Add screenshots of updated UI

- [ ] **Task 17**: Create migration script (if needed)
  - Script to add database indexes
  - Script to update any missing validation_status values

- [ ] **Task 18**: Deploy to production
  - Run migration scripts
  - Deploy code changes
  - Monitor for errors

- [ ] **Task 19**: Post-deployment verification
  - Verify all approved transactions are visible to staff
  - Check validation badges display correctly
  - Monitor performance metrics
  - Collect user feedback

---

## Task Dependencies

```
Task 1 (DB Indexes) → Task 2, 3, 4 (Query Updates)
Task 5 (Badge Function) → Task 6, 7, 8 (UI Updates)
Task 2, 3, 4 (Query Updates) → Task 11, 12, 13 (Testing)
Task 6, 7, 8 (UI Updates) → Task 11, 12, 13 (Testing)
Task 11-15 (Testing) → Task 18 (Deployment)
```

## Priority Levels

### 🔴 Critical (Must have for basic functionality)
- Task 1: Database indexes
- Task 2: Job Order Tracker query
- Task 3: Merchandise History query
- Task 5: Validation badge function
- Task 6: Job Order Tracker UI
- Task 7: Merchandise History UI

### 🟡 Important (Enhances user experience)
- Task 4: Dashboard widget query
- Task 8: Dashboard widget UI
- Task 11-13: Core testing

### 🟢 Nice to have (Future improvements)
- Task 9-10: AJAX endpoints
- Task 14-15: Advanced testing
- Task 16: Documentation

## Estimated Timeline

- **Phase 1**: ✅ Complete
- **Phase 2**: 30 minutes (database indexes)
- **Phase 3**: 2 hours (query updates)
- **Phase 4**: 3 hours (UI updates)
- **Phase 5**: 1 hour (AJAX endpoints, optional)
- **Phase 6**: 2 hours (testing)
- **Phase 7**: 1 hour (documentation & deployment)

**Total Estimated Time**: 9-10 hours

## Notes

- All tasks maintain backward compatibility
- No breaking changes to existing approval workflow
- Audit trail is preserved
- Can be deployed incrementally (phase by phase)
