<?php

require_once (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'backend.servers.inc.php');

// MEMCACHE MAIN SERVER
define("MEMCACHE_SERVERS", $backend_servers['comtools1']);

define("MEMCACHE_LOG_FILE","/space/log/memcache.log");
define("ENABLE_MEMCACHE_LOG",0);
define("ENABLE_MEMCACHESESSION_LOG",0);
?>
