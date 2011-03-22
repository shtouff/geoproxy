<?php

require_once (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'backend.servers.inc.php');

// DB CONFIG GENERAL
define("CFG_DBHOST", $backend_servers['comtools1']);
define("CFG_DBUSER","covoituragedb");
define("CFG_DBPASS","covoituragedbpw");
define("CFG_DBDTB","covoiturage_dev");

// DB BLOG CONFIG
define("CFG_DBBLOGHOST", $backend_servers['comtools1']);
define("CFG_DBBLOGUSER","covoituragedb");
define("CFG_DBBLOGPASS","covoituragedbpw");
define("CFG_DBBLOGDTB","covoiturage_blog_dev");

// DB ARCHIVE CONFIG
define("CFG_DBARCHIVEHOST", $backend_servers['comtools1']);
define("CFG_DBARCHIVEUSER","covoituragedb");
define("CFG_DBARCHIVEPASS","covoituragedbpw");
define("CFG_DBARCHIVEDTB","covoiturage_archives_dev");

// DB PHOTO CONFIG
define("CFG_DBPHOTOHOST", $backend_servers['comtools1']);
define("CFG_DBPHOTOUSER","covoituragedb");
define("CFG_DBPHOTOPASS","covoituragedbpw");
define("CFG_DBPHOTODTB","covoiturage_photos_dev");

// DB STATS CONFIG
define("CFG_DBSTATSHOST", $backend_servers['comtools1']);
define("CFG_DBSTATSUSER","covoituragedb");
define("CFG_DBSTATSPASS","covoituragedbpw");
define("CFG_DBSTATSDTB","covoiturage_dev");

// DB SEARCH CONFIG
define("CFG_DBSEARCHHOST", $backend_servers['comtools1']);
define("CFG_DBSEARCHUSER","covoituragedb");
define("CFG_DBSEARCHPASS","covoituragedbpw");
define("CFG_DBSEARCHDTB","covoiturage_dev");

define("CFG_DBSEARCHHOSTS", $backend_servers['comtools1']);

// DB CSM DEV
define("CFG_DBCSMHOST", $backend_servers['comtools1']);
define("CFG_DBCSMUSER","covoituragedb");
define("CFG_DBCSMPASS","covoituragedbpw");
define("CFG_DBCSMDTB","covoiturage_csm_dev");

// DB SMSD DEV
define("CFG_DBSMSDHOST", $backend_servers['comtools1']);
define("CFG_DBSMSDUSER","covoituragedb");
define("CFG_DBSMSDPASS","covoituragedbpw");
define("CFG_DBSMSDDTB","covoiturage_smsd_dev");
?>
