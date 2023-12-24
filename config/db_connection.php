<?php
function OpenCon()
{
    $dbhost = $_ENV['DB_HOST'];
    $dbuser = $_ENV['DB_USER'];
    $dbpass = $_ENV['DB_PASS'];
    $dbname = $_ENV['DB_NAME'];
    $conn = new mysqli($dbhost, $dbuser, $dbpass);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $sql = "CREATE DATABASE IF NOT EXISTS $dbname";
    $result = $conn->query($sql);
    if ($result === FALSE) {
        die("Error creating database: " . $conn->error);
    }

    $conn->select_db($dbname);

    return $conn;
}
function CloseCon($conn)
{
    $conn->close();
}

?>