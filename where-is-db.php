<?php
$link = mysqli_connect('127.0.0.1', 'gretchen', 'bunga');
if (!$link) {
    die('MySQL ERROR: ' . mysqli_connect_error());
}

$result = mysqli_query($link, "SHOW VARIABLES LIKE 'datadir'");
$row = mysqli_fetch_assoc($result);

echo "📁 Your MySQL data is stored here: <br><b>" . $row['Value'] . "</b>";
?>
