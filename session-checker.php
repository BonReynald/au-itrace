<?php
session_start();
if(!isset($_SESSION['username']))
{
	header ("location: au-itrace/login.php");
}
?>