<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Please login first");
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

echo "<h2>Debug Notifications</h2>";
echo "<p>Your User ID: " . $_SESSION['user_id'] . "</p>";

// Check notifications database
$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
if ($notifConn->connect_error) {
    echo "<p style='color: red;'>Cannot connect to notifications database: " . $notifConn->connect_error . "</p>";
} else {
    echo "<p style='color: green;'>Connected to notifications database successfully</p>";
    
    // Check if user's notification table exists
    $notifTableName = $_SESSION['user_id'];
    $tableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
    
    if ($tableCheck->num_rows > 0) {
        echo "<p style='color: green;'>Your notification table exists: $notifTableName</p>";
        
        // Show notifications
        $result = $notifConn->query("SELECT * FROM `$notifTableName` ORDER BY created_at DESC");
        if ($result->num_rows > 0) {
            echo "<h3>Your Notifications:</h3>";
            echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Message</th><th>Link</th><th>Type</th><th>Created At</th><th>Read</th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['message']) . "</td>";
                echo "<td>" . htmlspecialchars($row['link']) . "</td>";
                echo "<td>" . $row['type'] . "</td>";
                echo "<td>" . $row['created_at'] . "</td>";
                echo "<td>" . ($row['is_read'] ? 'Yes' : 'No') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No notifications found in your table.</p>";
        }
    } else {
        echo "<p style='color: orange;'>Your notification table does not exist: $notifTableName</p>";
        echo "<p>It will be created automatically when you receive your first notification.</p>";
    }
    
    $notifConn->close();
}

// Show all tables in notifications database
echo "<h3>All Tables in Notifications Database:</h3>";
$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
$result = $notifConn->query("SHOW TABLES");
if ($result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>" . $row[0] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No tables found in notifications database.</p>";
}
$notifConn->close();

echo "<br><a href='dashboard.php'>Back to Dashboard</a>";
?>