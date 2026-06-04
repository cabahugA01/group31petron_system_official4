# Admin User Management Module - Shift-Based Design

**Feature:** Admin User Management with Shift Binding  
**Date:** June 4, 2026  
**Status:** Design Phase

---

## 🎨 UI Design

### Page Layout

```
┌────────────────────────────────────────────────────────────────────────────┐
│  Admin Dashboard > User Management                           [🔍 Search]   │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐       │
│  │ 👤 Manager       │  │ 👥 Staff         │  │ 📊 Activity      │       │
│  │ Accounts         │  │ Accounts         │  │ Logs             │       │
│  │ Total: 1         │  │ Total: 12        │  │ Today            │       │
│  │ Active: 1        │  │ Active: 10       │  │ Logins: 24       │       │
│  │ Inactive: 0      │  │ Inactive: 2      │  │ Changes: 3       │       │
│  └──────────────────┘  └──────────────────┘  └──────────────────┘       │
│                                                                            │
│  [ Manager Accounts ]  [ Staff Accounts ]  [ Activity Logs ]              │
│  ─────────────────────────────────────────────────────────────────────    │
│                                                                            │
│  Manager Accounts Tab Content (default active)                            │
│                                                                            │
│  [+ Add Manager]                                                          │
│                                                                            │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Name             │ Email            │ Phone    │ Status │ Actions   │  │
│  ├────────────────────────────────────────────────────────────────────┤  │
│  │ Juan Dela Cruz   │ juan@email.com   │ 09xx     │ ✓Active│ View  X  │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                                                            │
└────────────────────────────────────────────────────────────────────────────┘
```

---

## 🎯 Component Breakdown

### 1. Summary Cards Component

**HTML Structure:**
```html
<div class="summary-cards-grid">
  <!-- Manager Card -->
  <div class="summary-card manager-card">
    <div class="card-icon">👤</div>
    <div class="card-content">
      <h3>Manager Accounts</h3>
      <div class="card-stats">
        <div class="stat-item">
          <span class="stat-label">Total</span>
          <span class="stat-value" id="manager-total">0</span>
        </div>
        <div class="stat-item">
          <span class="stat-label">Active</span>
          <span class="stat-value active" id="manager-active">0</span>
        </div>
        <div class="stat-item">
          <span class="stat-label">Inactive</span>
          <span class="stat-value inactive" id="manager-inactive">0</span>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Staff Card -->
  <div class="summary-card staff-card">
    <!-- Similar structure -->
  </div>
  
  <!-- Activity Logs Card -->
  <div class="summary-card activity-card">
    <!-- Similar structure -->
  </div>
</div>
```

**CSS Styling:**
```css
.summary-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.summary-card {
  background: white;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  display: flex;
  gap: 15px;
  align-items: center;
}
