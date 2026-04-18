<?php
require_once 'includes/config.php';
$c = getDbConnection();
$tables = ['dissertations','dissertation_submissions','dissertation_feedback','dissertation_ethics','dissertation_defense','dissertation_similarity_checks','dissertation_guidelines','dissertation_notifications','research_coordinators'];
foreach ($tables as $t) {
    $r = $c->query("SHOW TABLES LIKE '$t'");
    echo $t . ': ' . ($r->num_rows > 0 ? 'EXISTS' : 'MISSING') . PHP_EOL;
}
// Check role ENUM
$r = $c->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
echo 'users.role: ' . $r->fetch_assoc()['Type'] . PHP_EOL;
// Check guidelines count
$r = $c->query("SELECT COUNT(*) as cnt FROM dissertation_guidelines");
echo 'Guidelines records: ' . $r->fetch_assoc()['cnt'] . PHP_EOL;
