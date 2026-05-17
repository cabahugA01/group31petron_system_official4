<?php
$f = 'public/manager_fuel_pump_master.php';
$c = file_get_contents($f);

$case_logic = <<<PHP
        /* -- UPDATE CALIBRATION -- */
        case 'update_calibration':
            \$fuel_type       = trim(\$_POST['fuel_type'] ?? '');
            \$new_calibration = (float)(\$_POST['new_calibration'] ?? 0);
            
            try {
                if (empty(\$fuel_type)) throw new Exception('Fuel type is required.');
                if (\$new_calibration < 0 || \$new_calibration > 50) throw new Exception('Calibration value must be between 0 and 50 liters.');

                \$pdo->beginTransaction();

                // 1. Update fuel_inventory (for general lookup)
                \$stmt = \$pdo->prepare("
                    UPDATE fuel_inventory 
                    SET latest_calibration = ?, last_updated = NOW() 
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ");
                \$stmt->execute([\$new_calibration, \$station_id, \$fuel_type]);

                // 2. Update fuel_pumps (for specific pump records)
                \$stmt2 = \$pdo->prepare("
                    UPDATE fuel_pumps 
                    SET calibration_value = ?, calibration_updated_at = NOW(), calibration_updated_by = ? 
                    WHERE station_id = ? AND fuel_type_id = (SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1)
                ");
                \$stmt2->execute([\$new_calibration, \$me['id'], \$station_id, \$fuel_type]);

                log_activity(\$pdo, \$me['id'], 'Update Calibration', "Updated calibration for {\$fuel_type} to {\$new_calibration} L.");
                \$pdo->commit();

                \$_SESSION['success'] = "✔ Calibration for {\$fuel_type} updated to " . number_format(\$new_calibration, 2) . " L successfully.";
            } catch (Exception \$e) {
                if (\$pdo->inTransaction()) \$pdo->rollBack();
                \$_SESSION['error'] = '✖ ' . \$e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

        /* -- ADJUST READING (Manager corrects liters_sold before approving) -- */
PHP;

$c = str_replace("        /* -- ADJUST READING (Manager corrects liters_sold before approving) -- */", $case_logic, $c);

file_put_contents($f, $c);
echo "Injected update_calibration case!\n";
