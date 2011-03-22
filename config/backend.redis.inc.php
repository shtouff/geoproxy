<?php

require_once (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'backend.servers.inc.php');

// redis geoproxy server
define("CFG_GEOPROXY_REDIS_MASTER", $backend_servers['localhost']);

?>
