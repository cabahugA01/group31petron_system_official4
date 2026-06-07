<?php
/**
 * Redirect Shim for Manager Customer History
 * 
 * This file redirects legacy links from the sidebar menu to the new
 * unified manager_customers.php page with the history section active.
 */
header('Location: manager_customers.php?section=history');
exit;
