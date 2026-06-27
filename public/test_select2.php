<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Select2 Test</title>
    <!-- Select2 CSS -->
    <link href="../assets/vendor/select2/select2.min.css" rel="stylesheet" />
</head>
<body style="padding: 40px; font-family: Arial, sans-serif;">
    <h1>Select2 Test Page</h1>
    
    <p>This is a simple test to verify Select2 is working:</p>
    
    <label>Select a station:</label>
    <select id="testSelector" style="width: 400px;">
        <option value="">Choose a station</option>
        <option value="1">Manila Central Station</option>
        <option value="2">Quezon City Station</option>
        <option value="3">Cebu Station</option>
        <option value="4">Davao Station</option>
        <option value="5">Baguio Station</option>
    </select>
    
    <div id="result" style="margin-top: 20px; padding: 15px; background: #f0f0f0; border-radius: 8px;">
        <strong>Status:</strong> <span id="status">Loading...</span>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Select2 -->
    <script src="../assets/vendor/select2/select2.min.js"></script>
    
    <script>
    $(document).ready(function() {
        console.log('=== SELECT2 TEST START ===');
        console.log('jQuery loaded:', typeof $ !== 'undefined');
        console.log('jQuery version:', $.fn.jquery);
        console.log('Select2 available:', typeof $.fn.select2 !== 'undefined');
        
        $('#status').html('jQuery loaded: ' + (typeof $ !== 'undefined') + '<br>' +
                         'Select2 available: ' + (typeof $.fn.select2 !== 'undefined'));
        
        if (typeof $.fn.select2 !== 'undefined') {
            $('#testSelector').select2({
                placeholder: 'Choose a station',
                allowClear: true,
                minimumResultsForSearch: 0  // Always show search
            });
            
            $('#status').html('<span style="color: green;">✅ Select2 initialized successfully!</span><br>' +
                             'jQuery version: ' + $.fn.jquery + '<br>' +
                             'Try clicking the dropdown and typing to search.');
            
            console.log('✅ Select2 initialized!');
        } else {
            $('#status').html('<span style="color: red;">❌ Select2 failed to load</span>');
            console.error('❌ Select2 not loaded!');
        }
    });
    </script>
</body>
</html>
