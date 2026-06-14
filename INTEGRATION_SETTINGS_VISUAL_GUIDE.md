# 🎨 Integration Settings – Visual & Functional Guide

## 📍 Navigation Path
```
Main Menu (Sidebar)
└── Integration Settings [fas fa-plug]
    ├── POS Import Config
    ├── API Connections ⭐ NEW
    ├── Git Workflow ⭐ NEW
    └── External System Sync ⭐ NEW
```

---

## 🔹 Section 1: API Connections

### **Page Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  🔌 API CONNECTIONS                    [+ Add API Config]│
├─────────────────────────────────────────────────────────┤
│  ℹ️ Configure API connections for Fleet Card            │
│     authentication and ERP system integration.           │
├─────────────────────────────────────────────────────────┤
│  💳 FLEET CARD API                                      │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Config Name │ Endpoint URL │ Auth Type │ Status │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Fleet API   │ https://...  │ API_KEY   │ ✅ OK  │   │
│  │             │              │           │ [Test] │   │
│  │             │              │           │ [Edit] │   │
│  │             │              │           │ [Del]  │   │
│  └─────────────────────────────────────────────────┘   │
│                                                          │
│  🗄️ ERP SYSTEM CONNECTIONS        [+ Add ERP Connection]│
│  ┌─────────────────────────────────────────────────┐   │
│  │ Connection  │ Endpoint URL │ Status    │ Actions│   │
│  ├─────────────────────────────────────────────────┤   │
│  │ SAP ERP     │ https://...  │ 🟢 Connected      │   │
│  │             │              │           │ [Test] │   │
│  │             │              │           │ [Edit] │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### **Fields & Functions:**

#### Fleet Card API:
| Field | Type | Purpose |
|-------|------|---------|
| **API Key** | Text (Secure) | Authenticate fleet card transactions |
| **Endpoint URL** | Text | Connect to Fleet Card API |
| **Auth Type** | Dropdown | none / api_key / bearer / basic |
| **Test Connection** | Button | Verify API link status |
| **Last Test** | Display | Show when last tested |
| **Status** | Badge | ✅ OK / ❌ FAIL / ⚪ UNTESTED |

#### ERP Connections:
| Field | Type | Purpose |
|-------|------|---------|
| **Connection Name** | Text | Identify ERP connection |
| **Endpoint URL** | Text | ERP system endpoint |
| **Auth Keys** | Secure Field | Store tokens/keys |
| **Connection Status** | Badge | 🟢 Connected / ⚪ Disconnected / 🔴 Error |
| **Test Connection** | Button | Verify connection |

---

## 🔹 Section 2: Git Workflow

### **Page Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  🌿 GIT WORKFLOW CONFIGURATION         [+ Add Repository]│
├─────────────────────────────────────────────────────────┤
│  ℹ️ Configure Git repositories, manage branches,         │
│     track commits, and handle deployment pipelines.      │
├─────────────────────────────────────────────────────────┤
│  📚 REPOSITORIES                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Repo Name   │ URL         │ Branch │ Last Push │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Petron-Sys  │ github.com  │ [main] │ 2h ago    │   │
│  │             │             │        │ [↑ Push]  │   │
│  │             │             │        │ [↓ Pull]  │   │
│  │             │             │        │ [Edit]    │   │
│  └─────────────────────────────────────────────────┘   │
│                                                          │
│  📜 RECENT COMMITS                                       │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Repo  │ Branch │ Hash   │ Author  │ Message    │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Petron│ main   │ a3f5d8 │ Dev1    │ Fix bug    │   │
│  │ Petron│ dev    │ b4e2c7 │ Dev2    │ Add feature│   │
│  └─────────────────────────────────────────────────┘   │
│                                                          │
│  🚀 DEPLOYMENT PIPELINE           [🚀 Trigger Deployment]│
│  ┌─────────────────────────────────────────────────┐   │
│  │ Repo  │ Hash   │ Type   │ Status │ Deployed By │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Petron│ a3f5d8 │ Manual │ ✅ OK  │ Developer1  │   │
│  │ Petron│ b4e2c7 │ Auto   │ ❌ FAIL│ System      │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### **Fields & Functions:**

#### Repository Management:
| Field | Type | Purpose |
|-------|------|---------|
| **Repository URL** | Text | Link project repo |
| **Branch Selector** | Dropdown | main / dev / feature / custom |
| **Current Branch** | Badge | Display active branch |
| **Push Button** | Button | Sync code to remote repo |
| **Pull Button** | Button | Fetch code from remote repo |
| **Last Push/Pull** | Display | Timestamp of last operation |

#### Commit Log:
| Field | Display | Purpose |
|-------|---------|---------|
| **Commit Hash** | 8-char short | Unique commit identifier |
| **Author** | Text | Who made the commit |
| **Message** | Text (truncated) | Commit description |
| **Branch** | Badge | Branch name |
| **Timestamp** | DateTime | When committed |

#### Deployment Pipeline:
| Field | Type | Purpose |
|-------|------|---------|
| **Trigger Deployment** | Button | Execute deployment |
| **Deployment Type** | Display | Manual / Auto |
| **Status** | Badge | ✅ Success / ❌ Failed / ⏳ Pending |
| **Deployed By** | Display | User who triggered |
| **Notes** | Text | Deployment notes |

---

## 🔹 Section 3: External System Sync

### **Page Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  🔄 EXTERNAL SYSTEM SYNC                [+ Add Sync Job]│
├─────────────────────────────────────────────────────────┤
│  ℹ️ Configure synchronization jobs for external data    │
│     feeds and manage conflict resolution strategies.     │
├─────────────────────────────────────────────────────────┤
│  📋 SYNC JOBS                                            │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Job Name │ Frequency│ Feed URL │ Conflict│Status│   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Daily Inv│ Daily    │ https:// │ Merge   │ ✅   │   │
│  │          │          │          │         │[Sync]│   │
│  │          │          │          │         │[Edit]│   │
│  └─────────────────────────────────────────────────┘   │
│                                                          │
│  📊 SYNC LOGS                                            │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Job Name │ Status │ Records │ Error   │ Time    │   │
│  ├─────────────────────────────────────────────────┤   │
│  │ Daily Inv│ ✅ OK  │ 1,234   │ -       │ 10:30AM │   │
│  │ Weekly PO│ ❌ FAIL│ 0       │ Timeout │ 11:00AM │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### **Fields & Functions:**

#### Sync Job Configuration:
| Field | Type | Purpose |
|-------|------|---------|
| **Sync Job Name** | Text | Identify sync task |
| **Sync Frequency** | Dropdown | realtime / hourly / daily / weekly / manual |
| **External Feed URL** | Text | Connect external data source |
| **Conflict Resolution** | Radio | overwrite / merge / skip |
| **Sync Now** | Button | Trigger immediate sync |
| **Last Synced** | Display | Last successful sync time |
| **Status** | Badge | ✅ Success / ❌ Failed / ⏳ Pending |

#### Sync Logs:
| Field | Display | Purpose |
|-------|---------|---------|
| **Job Name** | Text | Which sync job |
| **Status** | Badge | Success/Failed/Pending |
| **Records Synced** | Number | Count of records |
| **Error Message** | Text (truncated) | Error details if failed |
| **Synced At** | DateTime | When sync executed |

---

## 🎨 UI Components Legend

### Status Badges:
- **✅ OK / Success** → Green badge (`int-badge-ok`)
- **❌ FAIL / Failed** → Red badge (`int-badge-fail`)
- **⚪ UNTESTED / Pending** → Gray badge (`int-badge-untested`)
- **🟢 Connected** → Green badge (`int-badge-ok`)
- **⚪ Disconnected** → Gray badge (`int-badge-untested`)
- **🔴 Error** → Red badge (`int-badge-fail`)

### Action Buttons:
- **🟦 Primary** → Blue button (`int-btn-primary`)
- **🟩 Success/Test** → Green button (`int-btn-success`)
- **🟨 Info** → Yellow/blue button (`int-btn-info`)
- **⬜ Outline** → White outlined button (`int-btn-outline`)
- **🟥 Danger/Delete** → Red button (`int-btn-danger`)

### Icons:
- **🔌** → API Connections (`fas fa-plug`)
- **🌿** → Git Workflow (`fas fa-code-branch`)
- **🔄** → External Sync (`fas fa-sync`)
- **💳** → Fleet Card (`fas fa-credit-card`)
- **🗄️** → ERP System (`fas fa-database`)
- **📚** → Repositories (`fas fa-code-branch`)
- **📜** → Commits (`fas fa-history`)
- **🚀** → Deployment (`fas fa-rocket`)
- **📋** → Sync Jobs (`fas fa-tasks`)
- **📊** → Sync Logs (`fas fa-list`)

---

## 🔄 Functional Workflows

### 1️⃣ Adding Fleet Card API:
```
1. Navigate: Integration Settings → API Connections
2. Click: [+ Add API Config]
3. Fill Form:
   - Config Name: "Fleet Card API"
   - Endpoint URL: "https://api.fleetcard.com/v1"
   - Auth Type: Select "API_KEY"
   - API Key: Enter secure key
4. Click: [Save Config]
5. System: Stores in `api_config` table
6. Click: [Test Connection]
7. System: Verifies connectivity, updates status
8. View: Status badge shows ✅ OK or ❌ FAIL
```

### 2️⃣ Adding Git Repository:
```
1. Navigate: Integration Settings → Git Workflow
2. Click: [+ Add Repository]
3. Fill Form:
   - Repo Name: "Petron System"
   - Repo URL: "https://github.com/org/repo.git"
   - Current Branch: "main"
   - Merge Rules: Select rules
4. Click: [Save]
5. System: Stores in `git_repos` table
6. View: Repository appears in list
7. Actions: [↑ Push] [↓ Pull] [Edit] [Delete]
```

### 3️⃣ Creating Sync Job:
```
1. Navigate: Integration Settings → External System Sync
2. Click: [+ Add Sync Job]
3. Fill Form:
   - Job Name: "Daily Inventory Sync"
   - Frequency: Select "Daily"
   - Feed URL: "https://external.com/api/inventory"
   - Conflict Resolution: Select "Merge"
4. Click: [Save]
5. System: Stores in `sync_jobs` table
6. Click: [Sync Now] to test immediately
7. View: Sync log entry appears with status
```

---

## 🔐 Access Control Visual

```
┌─────────────────────────────────────────────────────┐
│  ROLE PERMISSIONS                                   │
├─────────────────────────────────────────────────────┤
│  SuperAdmin:  ✅ READ  ✅ WRITE  ✅ DELETE  ✅ TEST │
│  Developer:   ✅ READ  ✅ WRITE  ✅ DELETE  ✅ TEST │
│  Admin:       ✅ READ  ❌ WRITE  ❌ DELETE  ❌ TEST │
│  Manager:     ✅ READ  ❌ WRITE  ❌ DELETE  ❌ TEST │
│  Staff:       ❌ READ  ❌ WRITE  ❌ DELETE  ❌ TEST │
└─────────────────────────────────────────────────────┘
```

---

## 📊 Statistics Dashboard

```
┌──────────────────────────────────────────────────────┐
│  INTEGRATION SETTINGS OVERVIEW                       │
├──────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐│
│  │ 📄       │ │ 🔌       │ │ 🌿       │ │ 🔄      ││
│  │ POS      │ │ API      │ │ Git      │ │ Sync    ││
│  │ Parsers  │ │ Configs  │ │ Repos    │ │ Jobs    ││
│  │    3     │ │    5     │ │    2     │ │    4    ││
│  └──────────┘ └──────────┘ └──────────┘ └─────────┘│
│                                                       │
│  ┌──────────┐                                        │
│  │ 📜       │                                        │
│  │ Audit    │                                        │
│  │ Logs     │                                        │
│  │   127    │                                        │
│  └──────────┘                                        │
└──────────────────────────────────────────────────────┘
```

---

## ✅ Quick Reference

### Common Actions:
- **Add Configuration:** Click [+] button on each section
- **Test Connection:** Click [Test] button, view status badge
- **Edit Entry:** Click [Edit] button, modal opens
- **Delete Entry:** Click [Delete] button, confirm
- **Trigger Action:** Click action button (Push/Pull/Sync/Deploy)
- **View Logs:** Scroll to logs section, view details

### Status Indicators:
- **🟢 Green Badge:** Success, Connected, OK
- **🔴 Red Badge:** Failed, Error, Disconnected
- **⚪ Gray Badge:** Untested, Pending, Inactive

### Data Flow:
```
User Action → Frontend Button → JavaScript Handler →
Backend API → Database Update → Audit Log → UI Refresh
```

---

**Visual Guide Complete** ✅
**Last Updated:** June 14, 2026
