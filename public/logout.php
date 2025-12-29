<?php
require_once __DIR__ . "/../core/bootstrap.php";
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
auth_logout();
redirect("/index.php");
