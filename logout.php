<?php
session_start();
session_unset();
session_destroy();
header("Location: au_itrace_portal.php?tab=login");
exit;
?>
