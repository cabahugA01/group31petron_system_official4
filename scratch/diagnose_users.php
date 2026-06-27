<?php
$content = file_get_contents(__DIR__ . '/../public/users.php');

// 1. Find Modal addModal
$pos_add = strpos($content, '<div class="modal" id="addModal">');
if ($pos_add !== false) {
    echo "Found addModal at position $pos_add\n";
    $pos_edit = strpos($content, '<div class="modal" id="editModal">', $pos_add);
    if ($pos_edit !== false) {
        echo "Found editModal at position $pos_edit\n";
        echo "Length of addModal HTML: " . ($pos_edit - $pos_add) . "\n";
        file_put_contents(__DIR__ . '/add_modal_html.txt', substr($content, $pos_add, $pos_edit - $pos_add));
        echo "Saved addModal HTML to add_modal_html.txt\n";
    }
} else {
    echo "Could not find addModal\n";
}

// 2. Find JS toggleStationField
$pos_js = strpos($content, "function toggleStationField()");
if ($pos_js !== false) {
    echo "Found toggleStationField at position $pos_js\n";
    $pos_open = strpos($content, "function openViewModal", $pos_js);
    if ($pos_open !== false) {
        echo "Found openViewModal at position $pos_open\n";
        echo "Length of JS block: " . ($pos_open - $pos_js) . "\n";
        file_put_contents(__DIR__ . '/js_block.txt', substr($content, $pos_js, $pos_open - $pos_js));
        echo "Saved JS block to js_block.txt\n";
    }
} else {
    echo "Could not find toggleStationField\n";
}
