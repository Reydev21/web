<?php



// ---- DATABASE CONNECTION ----
$host = "localhost"; // usually localhost
$db   = "point of sale";
$user = "root";      // change if needed
$pass = "";          // your MySQL password

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}
?>
