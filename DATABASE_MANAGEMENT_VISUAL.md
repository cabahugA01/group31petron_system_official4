# Database Management - Visual Guide

## 🎨 TABBED LAYOUT PREVIEW

```
┌─────────────────────────────────────────────────────────────────────┐
│  📊 DATABASE MANAGEMENT                                              │
│  Complete database control panel for backup, restore, schema...     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐           │
│  │ 50 MB    │  │   45     │  │ 12,345   │  │  Today   │           │
│  │ DB Size  │  │ Tables   │  │ Records  │  │ Backup   │           │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘           │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  [💾 Backup] [⏱ Restore] [🔀 Schema] [🔄 Replication] [🛡 Logs]   │
│  ═══════════                                                        │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │ 💾 DATABASE BACKUP                                           │  │
│  ├─────────────────────────────────────────────────────────────┤  │
│  │ Create backup copies of your database. Configure automatic  │  │
│  │ backup schedules and retention policies.                    │  │
│  │                                                              │  │
│  │  [Backup Now]  [⚙ Configure]  [📋 View Backups]           │  │
│  │                                                              │  │
│  │  ┌─ Configuration (expandable) ─────────────────────────┐  │  │
│  │  │ Frequency: [Manual ▼]  Storage: [Local ▼]           │  │  │
│  │  │ Retention: [30] days                                  │  │  │
│  │  │ [✓ Save Settings]  [✗ Cancel]                        │  │  │
│  │  └───────────────────────────────────────────────────────┘  │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📑 TAB 1: BACKUP (Default Active)

### Visible Elements:
- **Section Title**: Database Backup
- **Description**: Brief explanation
- **Action Buttons**:
  - `Backup Now` (Blue - Primary)
  - `Configure` (Gray - Secondary)
  - `View Backups` (Gray - Secondary)

### Expandable Configuration:
When "Configure" is clicked, shows:
- **Backup Frequency**: Dropdown (Manual/Daily/Weekly/Monthly)
- **Storage Location**: Dropdown (Local/Cloud/Both)
- **Retention Period**: Number input (1-365 days)
- Save/Cancel buttons

---

## 📑 TAB 2: RESTORE

### Visible Elements:
- **Section Title**: Database Restore
- **Warning Box**: Red/orange alert about data overwrite
- **Action Buttons**:
  - `Restore Point` (Orange - Warning)
  - `Restore History` (Gray - Secondary)

### Restore Modal (Opens when "Restore Point" clicked):
- **Select Backup File**: Dropdown with file list
- **Restore Scope**: Full Database or Specific Tables
- **Table Selection**: Checkboxes (if partial restore)
- **Warning**: Final confirmation before restore
- Buttons: Cancel, Restore Now

---

## 📑 TAB 3: SCHEMA & MIGRATIONS

### Visible Elements:
- **Section Title**: Schema Updates & Migrations
- **Description**: Manage database structure
- **Action Buttons**:
  - `Update Schema` (Blue - Primary)
  - `Migration History` (Gray - Secondary)
  - `Optimize Database` (Gray - Secondary)

### Schema Update Modal (Opens when "Update Schema" clicked):
- **Select Table**: Dropdown with all tables
- **Schema Actions** (buttons):
  - Add Column
  - Modify Column
  - Remove Column
  - Add Index
  - Add Foreign Key
- **Dynamic Form**: Changes based on selected action
- Buttons: Cancel, Apply Changes

---

## 📑 TAB 4: REPLICATION

### Visible Elements:
- **Section Title**: Replication Control
- **Description**: Configure station syncing
- **Action Buttons**:
  - `Enable Sync` (Green - Success)
  - `Disable Sync` (Red - Danger)
  - `Configure` (Gray - Secondary)
  - `Sync Status` (Gray - Secondary)

### Expandable Configuration:
When "Configure" is clicked, shows:
- **Station ID Binding**: Dropdown with all active stations
- **Sync Frequency**: Dropdown (Real-time/5min/15min/Hourly/Daily)
- **Conflict Resolution**: Dropdown (Overwrite/Merge/Manual)
- Save/Cancel buttons

---

## 📑 TAB 5: SECURITY LOGS

### Visible Elements:
- **Section Title**: Security Logs Monitoring
- **Description**: Monitor access & activities
- **Action Buttons**:
  - `View Logs` (Blue - Primary)
  - `Export Logs` (Gray - Secondary)
  - `Alert Setup` (Gray - Secondary)

### Security Logs Modal (Opens when "View Logs" clicked):
- **Filter Bar**:
  - From Date
  - To Date
  - User ID
  - Station (dropdown)
  - Filter button
- **Logs Table**:
  - Timestamp
  - User
  - Action
  - IP Address
  - Status (success/failed badge)
- Buttons: Close, Export

---

## 🎨 COLOR SCHEME

### Buttons:
- **Primary (Blue)**: `#00264d` - Main actions (Backup Now, Update Schema, View Logs)
- **Success (Green)**: `#28a745` - Positive actions (Enable Sync, Save Settings)
- **Warning (Orange)**: `#ff9800` - Caution actions (Restore Point)
- **Danger (Red)**: `#dc3545` - Destructive actions (Disable Sync, Delete)
- **Secondary (Gray)**: `#6c757d` - View/Cancel actions

### Stats Cards:
- **Blue Icon**: Database Size
- **Green Icon**: Total Tables
- **Orange Icon**: Total Records
- **Purple Icon**: Last Backup

### Tab Bar:
- **Inactive Tabs**: Gray text (#666)
- **Active Tab**: Blue text + blue bottom border
- **Hover**: Light blue background

---

## 💡 USER EXPERIENCE

### Navigation Flow:
1. Page loads → **Backup tab active** (most common action)
2. Click tab → Content **smoothly fades in** (0.3s)
3. Click action button → Modal/config **slides up**
4. Complete action → **Toast notification** at top center
5. Auto-reload on success (backup, restore, optimize)

### Visual Feedback:
- **Hover effects**: Buttons lift slightly + shadow
- **Loading states**: "Processing..." toast message
- **Success states**: Green toast + checkmark
- **Error states**: Red toast + error message
- **Active states**: Blue highlights on active elements

### Responsive Design:
- **Stats cards**: Auto-grid (4 columns → 2 columns → 1 column)
- **Tab bar**: Horizontal scroll on mobile
- **Modals**: Max 95% viewport width
- **Buttons**: Wrap to multiple rows if needed

---

## 📱 MOBILE VIEW

```
┌─────────────────────────┐
│  DATABASE MANAGEMENT    │
├─────────────────────────┤
│  ┌───────┐  ┌───────┐  │
│  │ 50 MB │  │  45   │  │
│  └───────┘  └───────┘  │
│  ┌───────┐  ┌───────┐  │
│  │12,345 │  │ Today │  │
│  └───────┘  └───────┘  │
├─────────────────────────┤
│ [Backup][Restore][...]→ │
│ ═══════                 │
├─────────────────────────┤
│  DATABASE BACKUP        │
│                         │
│  [Backup Now]           │
│  [Configure]            │
│  [View Backups]         │
└─────────────────────────┘
```

---

## ✅ ACCESSIBILITY

- **Keyboard Navigation**: Tab through buttons
- **ARIA Labels**: Screen reader friendly
- **Focus Indicators**: Blue outline on focused elements
- **Color Contrast**: WCAG AA compliant
- **Icon + Text**: Icons paired with text labels

---

## 🎯 QUICK ACTIONS

### Most Common Tasks:

**Create Backup:**
1. Click "Backup Now" button
2. Confirm dialog
3. Wait for toast notification
4. Page reloads with updated stats

**Restore Database:**
1. Click "Restore" tab
2. Click "Restore Point"
3. Select backup file from dropdown
4. Choose Full or Partial restore
5. Double confirmation
6. Wait for restore completion

**Optimize Database:**
1. Click "Schema & Migrations" tab
2. Click "Optimize Database"
3. Confirm action
4. Wait for optimization

**View Security Logs:**
1. Click "Security Logs" tab
2. Click "View Logs"
3. Use filters (date, user, station)
4. Export if needed

---

**DESIGN STATUS: COMPLETE ✅**

Tabbed interface fully implemented with smooth navigation, clean design, and intuitive user experience!
