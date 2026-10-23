<?php
// Force errors to display on screen instead of a generic 500 error page
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h3>PHP Environment Diagnostics</h3>";
echo "<b>Loaded Configuration File (php.ini):</b> " . php_ini_loaded_file() . "<br>";
echo "<b>Scan Directory for .ini Files:</b> " . php_ini_scanned_files() . "<br><br>";

if (class_exists('MongoDB\Driver\Manager')) {
    echo "<span style='color:green; font-weight:bold;'>✔ MongoDB Extension is loaded successfully!</span>";
} else {
    echo "<span style='color:red; font-weight:bold;'>✘ MongoDB Extension is MISSING in this environment!</span>";
}
