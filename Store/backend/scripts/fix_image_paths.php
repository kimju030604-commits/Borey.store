<?php
// CLI-only maintenance script \u2014 block web access
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';
$result = mysqli_query($conn, "UPDATE products SET image = REPLACE(image, 'img/products/', 'assets/img/products/') WHERE image LIKE 'img/products/%'");
echo 'Updated rows: ' . mysqli_affected_rows($conn) . PHP_EOL;
mysqli_close($conn);
