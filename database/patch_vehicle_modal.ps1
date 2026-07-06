$file = 'c:\xampp\htdocs\group31petron_system_official4\public\staff_transactions_hub.php'
$content = [System.IO.File]::ReadAllText($file, [System.Text.Encoding]::UTF8)

$old = '                <!-- Category -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Category <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" 
                           id="newVehicleCategory" 
                           class="txn-input" 
                           list="vehicleCategoryList"
                           placeholder="Type or select category..."
                           style="font-size:13px;"
                           autocomplete="off">
                    <datalist id="vehicleCategoryList">
                        <option value="Sedans / Hatchbacks">
                        <option value="SUVs">
                        <option value="Pickups">
                        <option value="Vans">
                        <option value="Light Trucks / Utility">
                        <option value="Motorcycles">
                        <option value="Tricycles / E-bikes">
                        <option value="Other">
                    </datalist>
                </div>

                <!-- Vehicle Name -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newVehicleName" class="txn-input"
                           placeholder="e.g. Toyota Innova, Kawasaki Dominar&#8230;"
                           maxlength="150"
                           style="font-size:13px;"
                           autocomplete="off">
                    <div style="font-size:10px;color:#94a3b8;margin-top:4px;">
                        Be specific -- include brand and model (e.g. "Honda XRM 125")
                    </div>
                </div>

                <!-- Description (Optional) -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Description
                    </label>
                    <textarea id="newVehicleDescription" class="txn-input"
                               placeholder="Additional details about this vehicle type (optional)..."
                               rows="2"
                               maxlength="255"
                               style="font-size:13px;resize:vertical;"
                               autocomplete="off"></textarea>
                </div>

                <!-- Reason for Request -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Reason for Request <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea id="newVehicleReason" class="txn-input"
                               placeholder="Why do you need this vehicle type added? (e.g., ''Customer owns this model'')"
                               rows="2"
                               maxlength="500"
                               style="font-size:13px;resize:vertical;"
                               autocomplete="off"></textarea>
                </div>'

$new = '                <!-- Vehicle Brand -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Brand <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newVehicleBrand" class="txn-input"
                           placeholder="e.g. Toyota, Honda, Mitsubishi..."
                           maxlength="100"
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Vehicle Model -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Model <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newVehicleModel" class="txn-input"
                           placeholder="e.g. Vios, Civic, L300..."
                           maxlength="100"
                           style="font-size:13px;"
                           autocomplete="off">
                </div>

                <!-- Vehicle Type -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Vehicle Type <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newVehicleType" class="txn-input"
                           list="vehicleCategoryList"
                           placeholder="e.g. Sedan, SUV, Van..."
                           style="font-size:13px;"
                           autocomplete="off">
                    <datalist id="vehicleCategoryList">
                        <option value="Sedan">
                        <option value="SUV">
                        <option value="Pickup">
                        <option value="Van">
                        <option value="Light Truck">
                        <option value="Motorcycle">
                        <option value="Tricycle">
                        <option value="Other">
                    </datalist>
                </div>

                <!-- Fuel Type -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Fuel Type <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="newVehicleFuelType" class="txn-select" style="font-size:13px;width:100%;">
                        <option value="Gasoline" selected>Gasoline</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Electric">Electric</option>
                        <option value="Hybrid">Hybrid</option>
                    </select>
                </div>

                <!-- Remarks -->
                <div style="margin-bottom:18px;">
                    <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:5px;">
                        Remarks
                    </label>
                    <textarea id="newVehicleRemarks" class="txn-input"
                               placeholder="Add any remarks or descriptions here..."
                               rows="2"
                               maxlength="500"
                               style="font-size:13px;resize:vertical;"
                               autocomplete="off"></textarea>
                </div>'

if ($content.Contains('id="newVehicleCategory"')) {
    Write-Host "Found old vehicle modal fields. Replacing..."
    $content = $content.Replace($old, $new)
    [System.IO.File]::WriteAllText($file, $content, [System.Text.Encoding]::UTF8)
    Write-Host "Done."
} else {
    Write-Host "Pattern not found - already replaced or different content."
    Write-Host ($content | Select-String 'newVehicle').Matches | ForEach-Object { $_.Value }
}
