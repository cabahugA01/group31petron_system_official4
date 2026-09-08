<?php
/**
 * Bug Condition Exploration Test - Notification Character Encoding
 * 
 * Property 1: UTF-8 Characters Display Correctly (NOT as Mojibake)
 * 
 * CRITICAL: This test MUST FAIL on unfixed code - failure confirms the bug exists.
 * DO NOT attempt to fix the test or the code when it fails.
 * 
 * EXPECTED OUTCOME on UNFIXED code: TEST FAILS
 * EXPECTED OUTCOME after fix: TEST PASSES
 * 
 * Bug Condition: Notifications containing non-ASCII UTF-8 characters (é, ñ, ₱, 🔔)
 * Expected Behavior: UTF-8 characters display correctly without mojibake
 * 
 * Validates: Requirements 2.1, 2.2, 2.3, 2.4
 */

require_once __DIR__ . '/../public/db_connect.php';

class NotificationEncodingExplorationTest {
    private $pdo;
    private $test_user_id = 1;
    private $test_notifications = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function setUp() {
        // Create test notifications with UTF-8 characters
        $this->test_notifications = [
            [
                'title' => 'Café Delivery',
                'message' => 'Your Café order has arrived',
                'expected_chars' => ['é'],
                'mojibake_patterns' => ['Ã©', 'Ã', '©']
            ],
            [
                'title' => 'Price Update',
                'message' => 'Price increased by ₱500',
                'expected_chars' => ['₱'],
                'mojibake_patterns' => ['â‚±', 'â', '‚', '±']
            ],
            [
                'title' => 'Empleado señalado',
                'message' => 'Nuevo empleado para este año',
                'expected_chars' => ['ñ'],
                'mojibake_patterns' => ['Ã±', 'Ã', '±']
            ],
            [
                'title' => '🔔 Alert Notification',
                'message' => 'New alert 📢 for you',
                'expected_chars' => ['🔔', '📢'],
                'mojibake_patterns' => ['', 'ðŸ””', 'ðŸ”¢']
            ],
            [
                'title' => 'Naïve résumé café',
                'message' => 'Multiple accents: é ñ ü ö',
                'expected_chars' => ['ï', 'é', 'ñ', 'ü', 'ö'],
                'mojibake_patterns' => ['Ã¯', 'Ã©', 'Ã±', 'Ã¼', 'Ã¶']
            ]
        ];
        
        // Insert test notifications into database
        foreach ($this->test_notifications as &$notif) {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, event_type, severity, status, created_at)
                VALUES (?, 'info', ?, ?, 'test', 'medium', 'unread', NOW())
            ");
            $stmt->execute([
                $this->test_user_id,
                $notif['title'],
                $notif['message']
            ]);
            $notif['id'] = $this->pdo->lastInsertId();
        }
    }
    
    public function tearDown() {
        // Clean up test notifications
        foreach ($this->test_notifications as $notif) {
            $this->pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$notif['id']]);
        }
    }
    
    public function testUTF8CharactersDisplayCorrectly() {
        echo "\n=== Bug Condition Exploration Test: UTF-8 Character Encoding ===\n\n";
        
        $failures = [];
        $counterexamples = [];
        
        foreach ($this->test_notifications as $notif) {
            echo "Testing: {$notif['title']}\n";
            
            // Fetch notification from database (simulating what header.php does)
            $stmt = $this->pdo->prepare("
                SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
                FROM notifications
                WHERE id = ?
            ");
            $stmt->execute([$notif['id']]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if UTF-8 characters are preserved in database fetch
            $title_has_utf8 = $this->containsUTF8($fetched['title']);
            $message_has_utf8 = $this->containsUTF8($fetched['message']);
            
            echo "  Title from DB: " . $fetched['title'] . "\n";
            echo "  Message from DB: " . $fetched['message'] . "\n";
            
            // Simulate htmlspecialchars() encoding (as done in header.php line 4290)
            $encoded_title = htmlspecialchars($fetched['title'] ?? 'Notification');
            $encoded_message = htmlspecialchars($fetched['message'] ?? '');
            
            echo "  After htmlspecialchars() - Title: " . $encoded_title . "\n";
            echo "  After htmlspecialchars() - Message: " . $encoded_message . "\n";
            
            // Check for mojibake patterns (common UTF-8 to ISO-8859-1 double-encoding)
            $title_has_mojibake = $this->hasMojibake($encoded_title, $notif['mojibake_patterns']);
            $message_has_mojibake = $this->hasMojibake($encoded_message, $notif['mojibake_patterns']);
            
            // Check if expected UTF-8 characters are present
            $title_has_expected = $this->hasExpectedChars($encoded_title, $notif['expected_chars']);
            $message_has_expected = $this->hasExpectedChars($encoded_message, $notif['expected_chars']);
            
            if ($title_has_mojibake || $message_has_mojibake) {
                $failures[] = $notif['title'];
                $counterexamples[] = [
                    'test_case' => $notif['title'],
                    'title_input' => $notif['title'],
                    'title_output' => $encoded_title,
                    'message_input' => $notif['message'],
                    'message_output' => $encoded_message,
                    'mojibake_detected' => true,
                    'reason' => 'UTF-8 characters corrupted to mojibake patterns'
                ];
                echo "  ❌ FAIL: Mojibake detected\n";
            } elseif (!$title_has_expected || !$message_has_expected) {
                $failures[] = $notif['title'];
                $counterexamples[] = [
                    'test_case' => $notif['title'],
                    'title_input' => $notif['title'],
                    'title_output' => $encoded_title,
                    'message_input' => $notif['message'],
                    'message_output' => $encoded_message,
                    'expected_chars_missing' => true,
                    'reason' => 'Expected UTF-8 characters not found in output'
                ];
                echo "  ❌ FAIL: Expected UTF-8 characters missing or corrupted\n";
            } else {
                echo "  ✅ PASS: UTF-8 characters preserved correctly\n";
            }
            
            echo "\n";
        }
        
        // Report results
        if (!empty($failures)) {
            echo "=== COUNTEREXAMPLES FOUND ===\n";
            echo "The following notifications display UTF-8 characters incorrectly:\n\n";
            foreach ($counterexamples as $ce) {
                echo "Test Case: {$ce['test_case']}\n";
                echo "  Input Title: {$ce['title_input']}\n";
                echo "  Output Title: {$ce['title_output']}\n";
                echo "  Input Message: {$ce['message_input']}\n";
                echo "  Output Message: {$ce['message_output']}\n";
                echo "  Reason: {$ce['reason']}\n\n";
            }
            
            echo "\n=== TEST RESULT ===\n";
            echo "❌ EXPLORATION TEST FAILED (Expected on unfixed code)\n";
            echo "Bug confirmed: UTF-8 characters are displaying as mojibake\n";
            echo "Failed test cases: " . count($failures) . " out of " . count($this->test_notifications) . "\n";
            return false;
        } else {
            echo "=== TEST RESULT ===\n";
            echo "✅ EXPLORATION TEST PASSED\n";
            echo "All UTF-8 characters display correctly\n";
            return true;
        }
    }
    
    private function containsUTF8($text) {
        // Check if string contains non-ASCII characters (code points > 127)
        return preg_match('/[^\x00-\x7F]/', $text);
    }
    
    private function hasMojibake($text, $patterns) {
        foreach ($patterns as $pattern) {
            if (strpos($text, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function hasExpectedChars($text, $expected_chars) {
        foreach ($expected_chars as $char) {
            if (strpos($text, $char) === false && mb_strpos($text, $char) === false) {
                return false;
            }
        }
        return true;
    }
}

// Run the test
try {
    echo "Starting Notification UTF-8 Encoding Exploration Test\n";
    echo "====================================================\n";
    
    $test = new NotificationEncodingExplorationTest($pdo);
    $test->setUp();
    $result = $test->testUTF8CharactersDisplayCorrectly();
    $test->tearDown();
    
    exit($result ? 0 : 1);
} catch (Exception $e) {
    echo "Test execution error: " . $e->getMessage() . "\n";
    exit(2);
}
