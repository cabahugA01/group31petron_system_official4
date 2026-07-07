<?php
session_start();

// Clear receipt data from session
unset($_SESSION['receipt_data']);
unset($_SESSION['receipt_generated']);

// Return success response
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Receipt session cleared']);
?>
