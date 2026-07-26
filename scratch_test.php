<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'staff';
// mock current_user to avoid DB dependency if possible, or just let it hit DB if user_id 1 is valid
ob_start();
try {
    include 'c:/xampp/htdocs/group31petron_system_official4/public/notifications.php';
} catch (Exception $e) {
    echo $e->getMessage();
}
$html = ob_get_clean();
file_put_contents('c:/xampp/htdocs/group31petron_system_official4/scratch_out.html', $html);
echo "Done";
