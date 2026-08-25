<?php
// ============================================================
// SuperAdmin – Admin Management Map View
// public/superadmin_admin_map.php
// Interactive map for station-based admin assignment
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_map';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Fetch stations with admin info ──────────────────────────
$stations = [];
try {
    $stmt = $pdo->query("
        SELECT 
            s.id, s.name, s.location, s.address, s.region, s.contact_number,
            s.latitude, s.longitude, s.status,
            COALESCE(u.id, 0) AS admin_id,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS admin_name,
            COALESCE(u.email, '') AS admin_email,
            COALESCE(u.phone_number, '') AS admin_phone,
            COALESCE(u.status, '') AS admin_status
        FROM stations s
        LEFT JOIN users u ON u.station_id = s.id AND u.role = 'admin'
        ORDER BY s.name
    ");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize nulls
    foreach ($stations as &$station) {
        $station['location']       = $station['location']       ?? '';
        $station['address']        = $station['address']        ?? $station['location'] ?? '';
        $station['status']         = $station['status']         ?? 'Active';
        $station['latitude']       = $station['latitude']       ?? null;
        $station['longitude']      = $station['longitude']      ?? null;
        $station['region']         = $station['region']         ?? '';
        $station['contact_number'] = $station['contact_number'] ?? '';
    }
    unset($station);

    error_log("Map loaded " . count($stations) . " stations");
} catch (Exception $e) {
    error_log("Failed to fetch stations for map: " . $e->getMessage());
}

// ── Fetch admins without stations ─────────────────────────
$unassigned_admins = [];
try {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name, email, phone_number, status
        FROM users
        WHERE role = 'admin' AND (station_id IS NULL OR station_id = 0)
        AND status = 'Active'
        ORDER BY first_name, last_name
    ");
    $unassigned_admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch unassigned admins: " . $e->getMessage());
}

// ── Fetch distinct regions dynamically ─────────────────────
$regions = [];
try {
    $regions = $pdo->query(
        "SELECT DISTINCT region FROM stations WHERE region IS NOT NULL AND region != '' ORDER BY region"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $regions = []; }

// ── Flash message ──────────────────────────────────────────
$flash = $_SESSION['admin_map_flash'] ?? null;
unset($_SESSION['admin_map_flash']);

// ── AJAX JSON POLLING ENDPOINT FOR SUPERADMIN ADMIN MAP ───────────────────────
if (isset($_GET['ajax_samap']) && $_GET['ajax_samap'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'stations_count' => count($stations ?? [])
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<!-- Leaflet.js CSS -->
<link rel="stylesheet" href="../assets/vendor/leaflet/css/leaflet.css" />
<!-- Leaflet MarkerCluster CSS -->
<link rel="stylesheet" href="../assets/vendor/leaflet.markercluster/MarkerCluster.css" />
<link rel="stylesheet" href="../assets/vendor/leaflet.markercluster/MarkerCluster.Default.css" />

<style>
/* ── Map Page Styles ── */
.map-page { padding: 0 24px 28px; height: calc(100vh - 130px); display: flex; flex-direction: column; }
.map-page-head { margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 16px !important; }
.map-page-head h1 { font-size: 22px !important; font-weight: 700 !important; color: var(--petron-blue) !important; margin: 0 !important; text-transform: uppercase !important; }
.map-page-head .sub { font-size: 13px; color: #666; margin-top: 4px; text-transform: none !important; }

/* Map Controls */
.map-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.map-controls input, .map-controls select { padding: 9px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; background: #fff; outline: none; }
.map-controls input:focus, .map-controls select:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.map-controls input { width: 240px; }

/* Map Container */
.map-container { 
    flex: 1; 
    background: #fff; 
    border: 1px solid #eaeaea; 
    border-radius: 16px; 
    overflow: hidden; 
    box-shadow: 0 2px 12px rgba(0,0,0,.05); 
    position: relative; 
}

#map { height: 100%; width: 100%; border-radius: 16px; }

/* Legend */
.map-legend { 
    position: absolute; 
    bottom: 30px; 
    right: 20px; 
    background: #fff; 
    padding: 16px 20px; 
    border-radius: 12px; 
    box-shadow: 0 4px 16px rgba(0,0,0,.12); 
    z-index: 1000; 
    font-size: 13px; 
}
.map-legend h3 { 
    font-size: 14px !important; 
    font-weight: 700 !important; 
    color: var(--petron-blue) !important; 
    margin: 0 0 10px !important; 
}
.legend-item { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    margin-bottom: 8px; 
}
.legend-item:last-child { margin-bottom: 0; }
.legend-dot { 
    width: 14px; 
    height: 14px; 
    border-radius: 50%; 
    flex-shrink: 0; 
}
.legend-dot.green { background: #28a745; }
.legend-dot.red { background: #cc0000; }
.legend-dot.yellow { background: #ffc107; }

/* Stats panel */
.map-stats { 
    position: absolute; 
    top: 20px; 
    left: 20px; 
    background: #fff; 
    padding: 16px 20px; 
    border-radius: 12px; 
    box-shadow: 0 4px 16px rgba(0,0,0,.12); 
    z-index: 1000; 
    }
.map-stats h3 { 
    font-size: 13px !important; 
    font-weight: 600 !important; 
    color: #666 !important; 
    margin: 0 0 12px !important; 
    text-transform: uppercase !important; 
    letter-spacing: .5px; 
}
.stat-row { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 8px; 
    font-size: 13px; 
}
.stat-row:last-child { margin-bottom: 0; }
.stat-label { color: #666; }
.stat-value { 
    font-weight: 700; 
    color: var(--petron-blue); 
    font-size: 15px; 
}

/* Buttons */
.map-btn { 
    display: inline-flex; 
    align-items: center; 
    gap: 6px; 
    padding: 9px 15px; 
    border-radius: 8px; 
    font-size: 13px; 
    font-weight: 600; 
    cursor: pointer; 
    border: 1px solid transparent; 
    transition: all .2s; 
    text-decoration: none; 
}
.map-btn-primary { background: #002F6C !important; color: #ffffff !important; border-color: #002F6C !important; }
.map-btn-primary *, .map-btn-primary i { color: #ffffff !important; }
.map-btn-primary:hover { background: #001a3d !important; border-color: #001a3d !important; }

.map-btn-danger { background: #dc2626 !important; color: #ffffff !important; border-color: #dc2626 !important; }
.map-btn-danger *, .map-btn-danger i { color: #ffffff !important; }
.map-btn-danger:hover { background: #b91c1c !important; border-color: #b91c1c !important; }

.map-btn-gray { background: #4b5563 !important; color: #ffffff !important; border-color: #4b5563 !important; }
.map-btn-gray *, .map-btn-gray i { color: #ffffff !important; }
.map-btn-gray:hover { background: #374151 !important; border-color: #374151 !important; }

.map-btn-secondary { background: #fff !important; color: var(--petron-blue) !important; border-color: var(--petron-blue) !important; }
.map-btn-secondary:hover { background: rgba(0,38,77,.06) !important; }

/* Modal */
.map-modal-overlay { 
    display: none; 
    position: fixed; 
    inset: 0; 
    background: rgba(0,0,0,.5); 
    z-index: 999999 !important; 
    align-items: center; 
    justify-content: center; 
    padding: 20px;
}
.map-modal-overlay.open { display: flex !important; }
.map-modal { 
    background: #fff; 
    border-radius: 20px; 
    width: min(560px, 95vw); 
    max-height: 90vh; 
    overflow-y: auto; 
    box-shadow: 0 20px 60px rgba(0,0,0,.25); 
    animation: modalSlideIn .25s ease; 
    margin: auto;
}

/* Leaflet Popup Styling - Clean, Centered & Unclipped */
.leaflet-popup-content-wrapper {
    border-radius: 14px !important;
    padding: 2px !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18) !important;
}
.leaflet-popup-content {
    margin: 10px 12px !important;
    line-height: 1.4 !important;
}
.leaflet-popup-tip {
    box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important;
}
@keyframes modalSlideIn { 
    from { opacity:0; transform:translateY(-20px); } 
    to { opacity:1; transform:translateY(0); } 
}
.map-modal-header { 
    padding: 22px 24px 16px; 
    border-bottom: 1px solid #eee; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
}
.map-modal-header h2 { 
    font-size: 17px !important; 
    font-weight: 700 !important; 
    color: var(--petron-blue) !important; 
    margin: 0 !important; 
    text-transform: uppercase !important; 
}
.map-modal-close { 
    background: none; 
    border: none; 
    font-size: 20px; 
    color: #999; 
    cursor: pointer; 
    padding: 4px 8px; 
    border-radius: 6px; 
}
.map-modal-close:hover { background: #f0f0f0; color: #333; }
.map-modal-body { padding: 22px 24px; }
.map-modal-footer { 
    padding: 16px 24px; 
    border-top: 1px solid #eee; 
    display: flex; 
    justify-content: flex-end; 
    gap: 10px; 
}

/* Info section in modal */
.info-section { 
    background: #f8fafc; 
    border: 1px solid #e8edf2; 
    border-radius: 10px; 
    padding: 14px 16px; 
    margin-bottom: 18px; 
}
.info-row { 
    display: flex; 
    justify-content: space-between; 
    font-size: 13px; 
    margin-bottom: 6px; 
}
.info-row:last-child { margin-bottom: 0; }
.info-label { color: #666; font-weight: 600; }
.info-value { color: #1a1a1a; }

/* Form elements */
.form-group { 
    display: flex; 
    flex-direction: column; 
    gap: 5px; 
    margin-bottom: 14px; 
}
.form-group label { 
    font-size: 12px; 
    font-weight: 600; 
    color: #444; 
    text-transform: uppercase; 
    letter-spacing: .3px; 
}
.form-group select { 
    padding: 10px 13px; 
    border: 1px solid #ddd; 
    border-radius: 10px; 
    font-size: 13px; 
    outline: none; 
    transition: border-color .2s; 
}
.form-group select:focus { 
    border-color: var(--petron-blue); 
    box-shadow: 0 0 0 3px rgba(0,38,77,.08); 
}

/* Badges */
.badge-active { 
    background: rgba(40,167,69,.12); 
    color: #1a7a35; 
    border: 1px solid rgba(40,167,69,.25); 
    padding: 3px 10px; 
    border-radius: 20px; 
    font-size: 11px; 
    font-weight: 600; 
}
.badge-inactive { 
    background: rgba(204,0,0,.1); 
    color: #cc0000; 
    border: 1px solid rgba(204,0,0,.2); 
    padding: 3px 10px; 
    border-radius: 20px; 
    font-size: 11px; 
    font-weight: 600; 
}
.badge-pending { 
    background: rgba(255,193,7,.15); 
    color: #b8860b; 
    border: 1px solid rgba(255,193,7,.3); 
    padding: 3px 10px; 
    border-radius: 20px; 
    font-size: 11px; 
    font-weight: 600; 
}

/* Flash message */
.map-flash { 
    padding: 12px 16px; 
    border-radius: 10px; 
    margin-bottom: 18px; 
    font-size: 13px; 
    font-weight: 500; 
    display: flex; 
    align-items: center; 
    gap: 10px; 
}
.map-flash.success { 
    background: rgba(40,167,69,.1); 
    border: 1px solid rgba(40,167,69,.3); 
    color: #1a7a35; 
}
.map-flash.error { 
    background: rgba(204,0,0,.08); 
    border: 1px solid rgba(204,0,0,.25); 
    color: #cc0000; 
}

@media (max-width: 768px) {
    .map-stats, .map-legend { 
        position: relative; 
        bottom: auto; 
        right: auto; 
        left: auto; 
        top: auto; 
        margin-bottom: 10px; 
    }
}

/* ── Selected / Pulsing Station Marker ── */
.marker-selected-pulse {
    position: relative;
    width: 28px !important;
    height: 28px !important;
}
.marker-selected-pulse .dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.5);
    position: relative;
    z-index: 2;
}
.marker-selected-pulse::before,
.marker-selected-pulse::after {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    animation: markerPulse 1.6s ease-out infinite;
    pointer-events: none;
    z-index: 1;
}
.marker-selected-pulse::before {
    width: 50px; height: 50px;
    background: rgba(40,167,69,0.35);
}
.marker-selected-pulse::after {
    width: 70px; height: 70px;
    background: rgba(40,167,69,0.15);
    animation-delay: 0.4s;
}
@keyframes markerPulse {
    0%   { transform: translate(-50%,-50%) scale(0.4); opacity: 1; }
    100% { transform: translate(-50%,-50%) scale(1);   opacity: 0; }
}

/* ── Geocoding toast notification ── */
#geocodeToast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,38,77,0.92);
    color: #fff;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 8px;
    backdrop-filter: blur(6px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    white-space: nowrap;
}
</style>

<div class="map-page">

<?php if ($flash): ?>
<div class="map-flash <?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($flash['msg']); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="map-page-head">
    <div>
        <h1 style="display:inline-flex; align-items:center; gap:10px;"><i class="fas fa-map-marked-alt" style="color:var(--petron-blue);"></i>Station Locator Map</h1>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="superadmin_admin_management.php" class="map-btn map-btn-secondary">
            <i class="fas fa-list"></i> List View
        </a>
    </div>
</div>

<!-- Map Controls -->
<div class="map-controls">
    <input type="text" id="searchMap" placeholder="Search by station name, city, or admin…" oninput="filterStations()">
    <select id="filterRegion" onchange="filterStations()">
        <option value="">All Regions</option>
        <?php foreach ($regions as $reg): ?>
        <option value="<?php echo htmlspecialchars(strtolower($reg)); ?>"><?php echo htmlspecialchars($reg); ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterStatus" onchange="filterStations()">
        <option value="">All Status</option>
        <option value="active">Active Admin</option>
        <option value="inactive">No Admin / Inactive</option>
        <option value="pending">Pending Validation</option>
    </select>
</div>

<!-- Map Container -->
<div class="map-container">
    <!-- Stats Panel -->
    <div class="map-stats">
        <h3>Quick Stats</h3>
        <div class="stat-row">
            <span class="stat-label">Total Stations:</span>
            <span class="stat-value" id="totalStations">0</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">With Admin:</span>
            <span class="stat-value" id="withAdmin">0</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Without Admin:</span>
            <span class="stat-value" id="withoutAdmin">0</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Filtered:</span>
            <span class="stat-value" id="filteredStations">0</span>
        </div>
    </div>

    <!-- Legend -->
    <div class="map-legend">
        <h3>Station Status</h3>
        <div class="legend-item">
            <div class="legend-dot green"></div>
            <span>Active Admin assigned</span>
        </div>
        <div class="legend-item">
            <div class="legend-dot red"></div>
            <span>No Admin / Inactive</span>
        </div>
        <div class="legend-item">
            <div class="legend-dot yellow"></div>
            <span>Pending validation</span>
        </div>
    </div>

    <!-- Map -->
    <div id="map"></div>
<div id="geocodeToast"><i class="fas fa-spinner fa-spin"></i> <span id="geocodeToastMsg">Finding exact location…</span></div>
</div>

</div><!-- /.map-page -->

<!-- ══════════════════════════════════════════════════════════
     STATION DETAIL MODAL
══════════════════════════════════════════════════════════ -->
<div class="map-modal-overlay" id="stationModal">
  <div class="map-modal">
    <div class="map-modal-header">
      <h2><i class="fas fa-building" style="margin-right:8px;"></i><span id="modalStationName">Station Details</span></h2>
    </div>
    <div class="map-modal-body">
      <div id="modalAlert" style="display:none;" class="map-flash error"></div>

      <!-- Station Information -->
      <div class="info-section">
        <div class="info-row">
          <span class="info-label">Address:</span>
          <span class="info-value" id="modalAddress">—</span>
        </div>
        <div class="info-row">
          <span class="info-label">Region:</span>
          <span class="info-value" id="modalRegion">—</span>
        </div>
        <div class="info-row">
          <span class="info-label">Contact:</span>
          <span class="info-value" id="modalContact">—</span>
        </div>
        <div class="info-row">
          <span class="info-label">Current Admin:</span>
          <span class="info-value" id="modalCurrentAdmin">—</span>
        </div>
        <div class="info-row">
          <span class="info-label">Admin Email:</span>
          <span class="info-value" id="modalAdminEmail" style="font-size:12px;">—</span>
        </div>
        <div class="info-row">
          <span class="info-label">Status:</span>
          <span id="modalStatus">—</span>
        </div>
      </div>

      <!-- Admin Assignment Form -->
      <form id="assignAdminForm" onsubmit="submitAssignment(event)">
        <input type="hidden" id="assignStationId" name="station_id">
        <div class="form-group">
          <label>Assign Admin <span style="color:#cc0000;">*</span></label>
          <select id="assignAdminSelect" name="admin_id" required>
            <option value="">— Select Admin —</option>
            <?php foreach ($unassigned_admins as $adm): ?>
            <option value="<?php echo (int)$adm['id']; ?>">
              <?php echo htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']); ?>
              (<?php echo htmlspecialchars($adm['email']); ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="background:#f8fafc;border:1px solid #e8edf2;border-radius:10px;padding:14px 16px;margin-top:10px;font-size:12px;color:#555;">
          <i class="fas fa-info-circle" style="color:var(--petron-blue);margin-right:6px;"></i>
          <strong>Rule:</strong> Only 1 Admin per station. If a station already has an Admin, you can reassign to a different Admin.
        </div>
      </form>
    </div>
    <div class="map-modal-footer">
      <button type="button" class="map-btn map-btn-gray" onclick="closeModal('stationModal')">
        <i class="fas fa-times"></i> Close
      </button>
      <button type="button" class="map-btn map-btn-danger" id="unassignBtn" onclick="unassignAdmin()" style="display:none">
        <i class="fas fa-user-times"></i> Unassign Admin
      </button>
      <button type="button" class="map-btn map-btn-primary" onclick="document.getElementById('assignAdminForm').requestSubmit()" id="assignBtn">
        <i class="fas fa-user-check"></i> Assign Admin
      </button>
    </div>
  </div>
</div>

<!-- Leaflet.js JavaScript -->
<script src="../assets/vendor/leaflet/js/leaflet.js"></script>
<!-- Leaflet MarkerCluster JavaScript -->
<script src="../assets/vendor/leaflet.markercluster/leaflet.markercluster.js"></script>

<script>
// ══════════════════════════════════════════════════════════════
// Station Data (from PHP)
// ══════════════════════════════════════════════════════════════
const stations = <?php echo json_encode($stations); ?>;

// Mock coordinates for Philippine stations (since DB doesn't have lat/lng yet)
// In production, these should come from database
const stationCoordinates = {
    // NCR
    'default_ncr': { lat: 14.5995, lng: 120.9842, region: 'NCR' },
    // Add more as needed
};

// Generate coordinates based on region or use random coordinates around Philippines
function getCoordinates(station) {
    // If station has explicit coordinates in DB, use them
    if (station.latitude && station.longitude) {
        return { 
            lat: parseFloat(station.latitude), 
            lng: parseFloat(station.longitude) 
        };
    }
    
    // Otherwise, generate based on region with more precise coordinates
    const regionBase = {
        'NCR': { lat: 14.5995, lng: 120.9842 },
        'Region I': { lat: 16.4023, lng: 120.5960 },  // Baguio
        'Region II': { lat: 17.6129, lng: 121.7270 }, // Tuguegarao
        'Region III': { lat: 15.4817, lng: 120.7119 }, // Angeles
        'Region IV-A': { lat: 14.1008, lng: 121.0794 }, // Batangas
        'Region IV-B': { lat: 13.4132, lng: 121.6014 }, // Puerto Princesa
        'Region V': { lat: 13.4214, lng: 123.4136 },  // Naga
        'Region VI': { lat: 10.7202, lng: 122.5621 }, // Iloilo
        'Region VII': { lat: 10.3157, lng: 123.8854 }, // Cebu
        'Region VIII': { lat: 11.2503, lng: 125.0039 }, // Tacloban
        'Region IX': { lat: 6.9104, lng: 122.0790 },  // Zamboanga
        'Region X': { lat: 8.4829, lng: 124.6496 },   // Cagayan de Oro
        'Region XI': { lat: 7.0731, lng: 125.6128 },  // Davao
        'Region XII': { lat: 6.1164, lng: 125.1716 }, // General Santos
        'Region XIII': { lat: 9.3371, lng: 125.5272 }, // Butuan (Caraga)
        'CAR': { lat: 16.4023, lng: 120.5960 },       // Baguio
        'BARMM': { lat: 7.2257, lng: 124.2452 }       // Cotabato
    };

    const base = regionBase[station.region] || regionBase['NCR'];
    
    // Add smaller random offset to reduce marker overlap
    // Using 0.05 degree offset (roughly 5km) instead of 0.3
    const offset = 0.05;
    return {
        lat: base.lat + (Math.random() - 0.5) * offset,
        lng: base.lng + (Math.random() - 0.5) * offset
    };
}

// ══════════════════════════════════════════════════════════════
// Initialize Map
// ══════════════════════════════════════════════════════════════
let map;
let markers = [];
let markerLayer;
let markerClusterGroup;

function initMap() {
    // Initialize map centered on Philippines with better zoom
    map = L.map('map', {
        center: [12.8797, 121.7740],
        zoom: 6,
        minZoom: 5,  // Prevent zooming out too far
        maxZoom: 19  // Allow street-level zoom to see roads and buildings
    });

    // Add tile layer (OpenStreetMap)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19  // Street-level detail
    }).addTo(map);

    // Create marker cluster group for better performance and organization
    markerClusterGroup = L.markerClusterGroup({
        maxClusterRadius: 80,  // Cluster radius in pixels
        spiderfyOnMaxZoom: true,
        showCoverageOnHover: false,
        zoomToBoundsOnClick: true,
        iconCreateFunction: function(cluster) {
            var childCount = cluster.getChildCount();
            var className = 'marker-cluster marker-cluster-';
            if (childCount < 10) {
                className += 'small';
            } else if (childCount < 100) {
                className += 'medium';
            } else {
                className += 'large';
            }
            return L.divIcon({ 
                html: '<div><span>' + childCount + '</span></div>', 
                className: className, 
                iconSize: L.point(40, 40) 
            });
        }
    });

    // Add cluster group to map
    map.addLayer(markerClusterGroup);

    // Add stations to map
    addStationsToMap();
    
    // Update stats
    updateStats();
    
    // Auto-fit bounds to show all markers if stations exist
    if (markers.length > 0) {
        const group = L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.1));
    }
}

// ══════════════════════════════════════════════════════════════
// Add Stations to Map
// ══════════════════════════════════════════════════════════════
let selectedMarker = null;

function setSelectedMarker(marker) {
    // Reset old selected marker icon
    if (selectedMarker && selectedMarker !== marker) {
        const prev = selectedMarker.stationData;
        const prevStatus = getStationStatus(prev);
        const prevColor = getMarkerColor(prevStatus);
        selectedMarker.setIcon(createMarkerIcon(prevColor, false));
    }
    selectedMarker = marker;
    const status = getStationStatus(marker.stationData);
    const color = getMarkerColor(status);
    marker.setIcon(createMarkerIcon(color, true));
}

function createMarkerIcon(color, selected) {
    if (selected) {
        return L.divIcon({
            className: 'custom-marker',
            html: `<div class="marker-selected-pulse"><div class="dot" style="background-color:${color};"></div></div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });
    }
    return L.divIcon({
        className: 'custom-marker',
        html: `<div style="background-color:${color};width:20px;height:20px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>`,
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });
}

function addStationsToMap() {
    markers = [];
    markerClusterGroup.clearLayers();

    stations.forEach(station => {
        const coords = getCoordinates(station);
        const status = getStationStatus(station);
        const color = getMarkerColor(status);
        
        const icon = createMarkerIcon(color, false);

        // Create marker with autoPan padding so popup is never cut off at top
        const marker = L.marker([coords.lat, coords.lng], { icon: icon })
            .bindPopup(createPopupContent(station), { 
                maxWidth: 330,
                autoPan: true,
                autoPanPaddingTopLeft: L.point(40, 90),
                autoPanPaddingBottomRight: L.point(40, 40)
            })
            .on('click', () => {
                setSelectedMarker(marker);
                openStationModal(station);
            })
            .on('popupopen', () => geocodeStationOnDemand(station.id));

        marker.stationData = station;
        marker.stationStatus = status;
        markers.push(marker);
        
        markerClusterGroup.addLayer(marker);
    });
}

// ══════════════════════════════════════════════════════════════
// Get Station Status
// ══════════════════════════════════════════════════════════════
function getStationStatus(station) {
    if (!station.admin_id || station.admin_id === 0) {
        return 'inactive'; // No admin
    }
    if (station.admin_status && station.admin_status.toLowerCase() === 'active') {
        return 'active'; // Active admin
    }
    if (station.admin_status && station.admin_status.toLowerCase() === 'inactive') {
        return 'inactive'; // Inactive admin
    }
    return 'pending'; // Pending validation
}

// ══════════════════════════════════════════════════════════════
// Get Marker Color
// ══════════════════════════════════════════════════════════════
function getMarkerColor(status) {
    switch (status) {
        case 'active': return '#28a745'; // Green
        case 'inactive': return '#cc0000'; // Red
        case 'pending': return '#ffc107'; // Yellow
        default: return '#999'; // Gray
    }
}

// ══════════════════════════════════════════════════════════════
// Create Popup Content
// ══════════════════════════════════════════════════════════════
function createPopupContent(station) {
    const status = getStationStatus(station);
    const statusBadge = status === 'active' 
        ? '<span class="badge-active"><i class="fas fa-circle" style="font-size:7px;"></i> Active Admin</span>'
        : status === 'pending'
        ? '<span class="badge-pending"><i class="fas fa-circle" style="font-size:7px;"></i> Pending</span>'
        : '<span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;"></i> No Admin</span>';
    
    const coords = getCoordinates(station);
    const coordText = station.latitude && station.longitude 
        ? `<div style="font-size:10px;color:#999;margin-top:4px;"><i class="fas fa-map-marker-alt"></i> ${coords.lat.toFixed(4)}, ${coords.lng.toFixed(4)}</div>`
        : `<div style="font-size:10px;color:#ff9800;margin-top:4px;"><i class="fas fa-exclamation-triangle"></i> Using estimated coordinates</div>`;

    // Google Maps directions URL — opens with station as destination
    const directionsUrl = (coords.lat && coords.lng)
        ? `https://www.google.com/maps/dir/?api=1&destination=${coords.lat},${coords.lng}&travelmode=driving`
        : `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent((station.location || station.name) + ', Philippines')}&travelmode=driving`;

    // Google Maps view URL
    const gmapsUrl = (coords.lat && coords.lng)
        ? `https://www.google.com/maps?q=${coords.lat},${coords.lng}&z=19`
        : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(station.name + ', Philippines')}`;

    const addressLine = station.address || station.location || '';

    return `
        <div style="padding:10px;font-family:inherit;">

            <!-- Station Title -->
            <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #eee;">
                <div style="background:var(--petron-blue);color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                    <i class="fas fa-gas-pump" style="font-size:13px;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:13px;color:var(--petron-blue);line-height:1.3;">
                        ${escapeHtml(station.name)}
                    </div>
                    <div style="font-size:11px;color:#888;margin-top:2px;">${escapeHtml(station.region || '—')}</div>
                </div>
            </div>

            <!-- Address -->
            ${addressLine ? `
            <div style="font-size:12px;color:#444;margin-bottom:6px;display:flex;gap:6px;align-items:flex-start;">
                <i class="fas fa-map-marker-alt" style="color:#cc0000;margin-top:2px;flex-shrink:0;"></i>
                <span>${escapeHtml(addressLine)}</span>
            </div>` : ''}

            <!-- Admin -->
            <div style="font-size:12px;color:#555;margin-bottom:6px;display:flex;gap:6px;align-items:center;">
                <i class="fas fa-user-tie" style="color:var(--petron-blue);flex-shrink:0;"></i>
                <span>${station.admin_name ? escapeHtml(station.admin_name) : '<em style="color:#aaa;">No admin assigned</em>'}</span>
            </div>

            <!-- Status badge -->
            <div style="margin-bottom:8px;">${statusBadge}</div>

            <!-- Coordinates -->
            ${coordText}

            <!-- Divider -->
            <div style="height:1px;background:#eee;margin:10px 0;"></div>

            <!-- Action Buttons -->
            <div style="display:flex;flex-direction:column;gap:6px;">

                <!-- Get Directions — Primary CTA -->
                <a href="${directionsUrl}" target="_blank"
                   style="display:flex;align-items:center;justify-content:center;gap:7px;
                          background:linear-gradient(135deg,#1a73e8,#0d47a1);
                          color:#fff;padding:9px 12px;border-radius:8px;
                          font-size:12px;font-weight:700;text-decoration:none;
                          box-shadow:0 2px 8px rgba(26,115,232,0.4);
                          transition:all .2s;">
                    <i class="fas fa-route" style="font-size:13px;"></i>
                    Get Directions to this Petron Station
                </a>

                <!-- Bottom row: Manage + View on Map -->
                <div style="display:flex;gap:6px;">
                    <button onclick="openStationModal(${station.id})"
                            style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;
                                   background:var(--petron-blue);color:#fff;border:none;
                                   padding:7px 8px;border-radius:7px;font-size:11px;
                                   font-weight:600;cursor:pointer;">
                        <i class="fas fa-user-check"></i> Manage Admin
                    </button>
                    <a href="${gmapsUrl}" target="_blank"
                       style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;
                              background:#fff;color:#555;border:1px solid #ddd;
                              padding:7px 8px;border-radius:7px;font-size:11px;
                              font-weight:600;text-decoration:none;">
                        <i class="fas fa-map" style="color:#34a853;"></i> View on Map
                    </a>
                </div>
            </div>
        </div>
    `;
}

// ══════════════════════════════════════════════════════════════
// Filter Stations
// ══════════════════════════════════════════════════════════════
function filterStations() {
    const searchTerm = document.getElementById('searchMap').value.toLowerCase().trim();
    const regionFilter = document.getElementById('filterRegion').value.toLowerCase();
    const statusFilter = document.getElementById('filterStatus').value.toLowerCase();

    let visibleCount = 0;
    let firstMatchingMarker = null;
    let matchingMarkers = [];
    
    // Clear cluster group
    markerClusterGroup.clearLayers();

    markers.forEach(marker => {
        const station = marker.stationData;
        const status = marker.stationStatus;

        // Search filter
        const matchesSearch = !searchTerm || 
            (station.name && station.name.toLowerCase().includes(searchTerm)) ||
            (station.location && station.location.toLowerCase().includes(searchTerm)) ||
            (station.admin_name && station.admin_name.toLowerCase().includes(searchTerm));

        // Region filter
        const matchesRegion = !regionFilter || 
            (station.region && station.region.toLowerCase() === regionFilter);

        // Status filter
        const matchesStatus = !statusFilter || status === statusFilter;

        // Show/hide marker
        if (matchesSearch && matchesRegion && matchesStatus) {
            markerClusterGroup.addLayer(marker);
            visibleCount++;
            matchingMarkers.push(marker);
            
            // Track first matching marker for search zoom
            if (!firstMatchingMarker && searchTerm) {
                firstMatchingMarker = marker;
            }
        }
    });

    // Update filtered count
    document.getElementById('filteredStations').textContent = visibleCount;
    
    // If searching and found exactly one match, zoom to it and open popup
    if (searchTerm && visibleCount === 1 && firstMatchingMarker) {
        const station = firstMatchingMarker.stationData;
        const coords = getCoordinates(station);

        // Highlight selected marker with pulse
        setSelectedMarker(firstMatchingMarker);

        // Zoom to street level
        map.setView([coords.lat, coords.lng], 17, {
            animate: true,
            duration: 0.5
        });
        
        // Open popup + trigger on-demand geocoding for exact position
        setTimeout(() => {
            firstMatchingMarker.openPopup();
            geocodeStationOnDemand(station.id);
        }, 600);
    }
    // If searching and found multiple matches, zoom to show all
    else if (searchTerm && visibleCount > 1 && matchingMarkers.length > 0) {
        const group = L.featureGroup(matchingMarkers);
        map.fitBounds(group.getBounds().pad(0.15));
    }
    // Auto-fit bounds to visible markers (no search term)
    else if (visibleCount > 0 && markerClusterGroup.getLayers().length > 0) {
        map.fitBounds(markerClusterGroup.getBounds().pad(0.1));
    }
}

// ══════════════════════════════════════════════════════════════
// Update Stats
// ══════════════════════════════════════════════════════════════
function updateStats() {
    const total = stations.length;
    const withAdmin = stations.filter(s => s.admin_id && s.admin_id > 0 && s.admin_status.toLowerCase() === 'active').length;
    const withoutAdmin = total - withAdmin;

    document.getElementById('totalStations').textContent = total;
    document.getElementById('withAdmin').textContent = withAdmin;
    document.getElementById('withoutAdmin').textContent = withoutAdmin;
    document.getElementById('filteredStations').textContent = total;
}

// ══════════════════════════════════════════════════════════════
// Modal Functions
// ══════════════════════════════════════════════════════════════
function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    // Lock body scroll while modal is open
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    // Restore body scroll
    document.body.style.overflow = '';
}

// Close modal on outside click
document.querySelectorAll('.map-modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) {
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});

// ══════════════════════════════════════════════════════════════
// Open Station Modal
// ══════════════════════════════════════════════════════════════
function openStationModal(stationId) {
    // Find station by ID (handle both direct object and ID)
    const station = typeof stationId === 'object' ? stationId : stations.find(s => s.id == stationId);
    
    if (!station) {
        console.error('Station not found:', stationId);
        return;
    }

    // Zoom to station on map - pan down slightly so popup won't be clipped by top controls
    const coords = getCoordinates(station);
    map.setView([coords.lat, coords.lng], 17, {
        animate: true,
        duration: 0.4
    });
    // Small downward pan to keep popup body clear of filter controls
    map.panBy([0, -80], { animate: false });

    // Trigger on-demand exact geocoding in background
    geocodeStationOnDemand(station.id);

    // Reset alert
    document.getElementById('modalAlert').style.display = 'none';

    // Populate station details
    document.getElementById('modalStationName').textContent = station.name;
    document.getElementById('modalAddress').textContent = station.address || station.location || '—';
    document.getElementById('modalRegion').textContent = station.region || '—';
    document.getElementById('modalContact').textContent = station.admin_phone || '—';
    document.getElementById('modalCurrentAdmin').textContent = station.admin_name || 'None';
    document.getElementById('modalAdminEmail').textContent = station.admin_email || '—';
    
    const status = getStationStatus(station);
    const statusBadge = status === 'active' 
        ? '<span class="badge-active"><i class="fas fa-circle" style="font-size:7px;"></i> Active Admin</span>'
        : status === 'pending'
        ? '<span class="badge-pending"><i class="fas fa-circle" style="font-size:7px;"></i> Pending</span>'
        : '<span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;"></i> No Admin</span>';
    document.getElementById('modalStatus').innerHTML = statusBadge;

    // Set station ID for assignment
    document.getElementById('assignStationId').value = station.id;

    // Reset admin selection
    document.getElementById('assignAdminSelect').value = '';

    // Show/hide unassign button
    const unassignBtn = document.getElementById('unassignBtn');
    if (unassignBtn) {
        if (station.admin_id && parseInt(station.admin_id) > 0) {
            unassignBtn.style.display = 'inline-flex';
        } else {
            unassignBtn.style.display = 'none';
        }
    }

    openModal('stationModal');
}

// ══════════════════════════════════════════════════════════════
// Submit Admin Assignment
// ══════════════════════════════════════════════════════════════
async function submitAssignment(e) {
    e.preventDefault();
    const btn = document.getElementById('assignBtn');
    const alert = document.getElementById('modalAlert');
    alert.style.display = 'none';

    const stationId = document.getElementById('assignStationId').value;
    const adminId = document.getElementById('assignAdminSelect').value;

    if (!adminId) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select an admin.';
        alert.style.display = 'flex';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning…';

    const fd = new FormData();
    fd.append('action', 'assign_admin_to_station');
    fd.append('station_id', stationId);
    fd.append('admin_id', adminId);
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res = await fetch('../backend/api/superadmin_admin_map_api.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.ok) {
            closeModal('stationModal');
            showPageFlash('success', data.message || 'Admin assigned successfully.');
            setTimeout(() => location.reload(), 1200);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to assign admin.');
            alert.style.display = 'flex';
        }
    } catch (err) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        alert.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-user-check"></i> Assign Admin';
}

// ── Unassign Admin ──────────────────────────────────────────
async function unassignAdmin() {
    const stationId = document.getElementById('assignStationId').value;
    if (!stationId) return;

    if (!confirm('Are you sure you want to unassign the admin from this station?')) return;

    const btn = document.getElementById('unassignBtn');
    const alert = document.getElementById('modalAlert');
    alert.style.display = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Unassigning…';

    const fd = new FormData();
    fd.append('action', 'unassign_admin_from_station');
    fd.append('station_id', stationId);
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res = await fetch('../backend/api/superadmin_admin_map_api.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.ok) {
            closeModal('stationModal');
            showPageFlash('success', data.message || 'Admin unassigned successfully.');
            setTimeout(() => location.reload(), 1200);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to unassign admin.');
            alert.style.display = 'flex';
        }
    } catch (err) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        alert.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-user-times"></i> Unassign Admin';
}

// ── On-demand Geocoding ─────────────────────────────────────
async function geocodeStationOnDemand(stationId) {
    const station = stations.find(s => s.id == stationId);
    if (!station) return;

    if (station.isGeocodedReal) return;

    // Set flag immediately to prevent duplicate requests
    station.isGeocodedReal = true;

    // Show loading toast
    showGeocodeToast('Finding exact street location for this station…');

    const fd = new FormData();
    fd.append('action', 'geocode_station');
    fd.append('station_id', stationId);
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res = await fetch('../backend/api/superadmin_admin_map_api.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.ok && data.latitude && data.longitude) {
            const newLat = parseFloat(data.latitude);
            const newLng = parseFloat(data.longitude);

            station.latitude = newLat;
            station.longitude = newLng;

            // Find marker
            const marker = markers.find(m => m.stationData.id == stationId);
            if (marker) {
                // Remove from cluster group to update Leaflet's spatial index
                markerClusterGroup.removeLayer(marker);

                // Update marker location
                marker.setLatLng([newLat, newLng]);
                marker.setPopupContent(createPopupContent(station));

                // Apply selected pulse icon
                const status = getStationStatus(station);
                const color = getMarkerColor(status);
                marker.setIcon(createMarkerIcon(color, true));
                selectedMarker = marker;

                // Add back to cluster group
                markerClusterGroup.addLayer(marker);

                // Zoom level 19 for exact street and building layout
                map.setView([newLat, newLng], 19, {
                    animate: true,
                    duration: 0.8
                });

                // Auto-open popup at exact location
                setTimeout(() => {
                    marker.openPopup();
                    hideGeocodeToast();
                }, 900);
            }

            // Update modal address block dynamically with badge
            const addressEl = document.getElementById('modalAddress');
            if (addressEl) {
                addressEl.innerHTML = `${escapeHtml(station.address || station.location || '—')} <span style="display:inline-block;background:#d4edda;color:#155724;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:6px;"><i class="fas fa-check-circle"></i> Exact Location Verified</span>`;
            }
        } else {
            station.isGeocodedReal = false;
        }
    } catch (err) {
        console.error('Dynamic geocoding error:', err);
        station.isGeocodedReal = false;
        hideGeocodeToast();
    }
}

function showGeocodeToast(msg) {
    const el = document.getElementById('geocodeToast');
    const msgEl = document.getElementById('geocodeToastMsg');
    if (el && msgEl) {
        msgEl.textContent = msg || 'Finding exact location…';
        el.style.display = 'flex';
        clearTimeout(el._t);
    }
}
function hideGeocodeToast() {
    const el = document.getElementById('geocodeToast');
    if (el) {
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 1500);
    }
}

// ══════════════════════════════════════════════════════════════
// Page Flash
// ══════════════════════════════════════════════════════════════
function showPageFlash(type, msg) {
    let el = document.getElementById('pageFlash');
    if (!el) {
        el = document.createElement('div');
        el.id = 'pageFlash';
        el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;max-width:600px;';
        document.body.appendChild(el);
    }
    el.className = 'map-flash ' + type;
    el.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 4000);
}

// ══════════════════════════════════════════════════════════════
// Utility Functions
// ══════════════════════════════════════════════════════════════
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ══════════════════════════════════════════════════════════════
// Initialize on Page Load
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    initMap();
});
</script>

<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
function autoRefreshSuperadminAdminMap() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_samap', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                // Polling active
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshSuperadminAdminMap, 2000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
