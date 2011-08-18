<?php

$incdir = realpath(dirname(__FILE__));
require_once $incdir . '/GeoGeom.php';
require_once $incdir . '/GeoGdat.php';
require_once $incdir . '/GeoGadc.php';
require_once $incdir . '/GeoLocation.php';
require_once $incdir . '/GeoBounds.php';

require_once $incdir . '/GeoAbstractFactory.php';
require_once $incdir . '/GeoGdatFactory.php';
require_once $incdir . '/GeoGeomFactory.php';
require_once $incdir . '/GeoGadcFactory.php';

require_once $incdir . '/RedisQuerier.php';
require_once $incdir . '/GoogleQuerier.php';

$cfgdir = realpath(dirname(__FILE__) . '/../../config');
require_once $cfgdir . '/backend.google.inc.php';
require_once $cfgdir . '/backend.log.inc.php';
require_once $cfgdir . '/backend.redis.inc.php';

class GeoProxy
{
	// redis querier
	private $rq;
	
	// google querier
	private $gq;
	
	// singleton instance
	private static $instance;
  
	// singleton, so private constructor
  private function __construct()
  {
	  $rqconfig = new RedisQuerierConfig(array('writeserver' => CFG_GEOPROXY_REDIS_MASTER,
	                                           'writeport' => 6379,
	                                           'readserver' => CFG_GEOPROXY_REDIS_MASTER,
	                                           'readport' => 6379));
	  $this->rq = new RedisQuerier($rqconfig);
	  $this->gq = new GoogleQuerier();
  }
  
  public static function getInstance() 
  {
	  if (!isset(self::$instance)) {
		  $c = __CLASS__;
		  self::$instance = new $c;
	  }
	  return self::$instance;
  }
  
  // returns an array of gdatids matching this query with this lang
  // query must be urlencoded
  public function geocodeData($_query, $_lang)
  {
	  $gdatids = array();
	  $filters = array("query" => $_query,
	                   "lang" => $_lang);
	  
	  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
	            "asking for query = [$_query] ($_lang)");

	  if ($tgdatids = $this->rq->search("gdat", $filters)) {
		  // premier cas, les données sont déja dans redis
		  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
		            "data found in redis");
		  
		  foreach ($tgdatids as $gdatid) {
			  $gdatids[] = $gdatid;
		  }
	  } else {
		  // deuxième cas, les données ne sont pas dans redis
		  // on tape dans google
		  $providerGdats = $this->gq->geocode($_query, $_lang);
		  foreach ($providerGdats as $providerGdat) {
			  // pour chaque resultat google, on cree un objet, 
			  $providerGdat['lang'] = $_lang;
			  $providerGdat['ext'] = 0;

			  $gdatfactory = new GeoGdatFactory();
			  $gdat = $gdatfactory->make(new JSONGeoGdatFactoryData($providerGdat));
			  
			  self::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
			            "gdat->fa = [$gdat->formatted_address]");
			  
			  // s'il existe déja des données dans redis correspondant à la formatted address 
			  // de l'objet créé, alors on ne stocke pas ce nouvel objet, mais on indexe les 
			  // donnees existentes avec cette nouvelle query
			  $filters = array("query" => rawurlencode($gdat->formatted_address),
			                   "lang" => $_lang);
			  if ($tgdatids = $this->rq->search("gdat", $filters)) {
				  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
				            "found data in redis with another key, will index this new key");
				  
				  foreach ($tgdatids as $id) {
					  $this->rq->indexGdatID($id, 'query', $_query);
					  $gdatids[] = $id;
				  }
			  } else {
				  // les données n'existent vraiment pas dans redis, il faut stocker ce nouvel objet
				  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
				            "not found in redis, found in google, will store this new gdat");
				  $gdatid = $this->rq->addGdat($gdat);
				  $gdatids[] = $gdatid;
				  
				  // now index this new key for this gdat
				  $this->rq->indexGdatID($gdatid, 'query', $_query);
				  $this->rq->indexGdatID($gdatid, 'query', rawurlencode($gdat->formatted_address));
			  }
		  }
	  } // deuxième cas
	  return $gdatids;
  }

  // returns an array of gdatids matching this lat/lng with lang
  public function reverseGeocodeData($_lat, $_lng, $_lang)
  {
	  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
	            "asking for latlng = [$_lat:$_lng] ($_lang)");

	  $gdatids = array();
	  $filters = array("lat" => $_lat,
	                   "lng" => $_lng,
	                   "lang" => $_lang);
	  
	  if ($tgdatids = $this->rq->search("gdat", $filters)) {
		  // premier cas, les données sont déja dans redis
		  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
		            "data found in redis");
		  
		  foreach ($tgdatids as $gdatid) {
			  $gdatids[] = $gdatid;
		  }
	  } else {
		  // no geomid found. Google reverse geocode ?
		  $providerGdats = $this->gq->reverseGeocode($_lat, $_lng, $_lang);
		  $first = true;
		  foreach ($providerGdats as $providerGdat) {
			  // pour chaque resultat google, on cree un objet, 
			  $providerGdat['lang'] = $_lang;
			  $providerGdat['ext'] = 0;
			  
			  $gdatfactory = new GeoGdatFactory();
			  $gdat = $gdatfactory->make(new JSONGeoGdatFactoryData($providerGdat));
			  
			  self::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
			            "gdat->fa = [$gdat->formatted_address]");
			  
			  // s'il existe déja des données dans redis correspondant a la formatted address
			  // de l'objet créé, alors on ne stocke pas ce nouvel objet, mais on indexe les donnees
			  // existentes avec ces nouvelles coordonnées, seulement pour le premier résultat
			  // google, qui est le plus accurate
			  $filters = array('lang' => $_lang,
			                   'query' => rawurlencode($gdat->formatted_address));
			  if ($tgdatids = $this->rq->search('gdat', $filters)) {
				  if (!$first) {
					  // on n'est pas sur le premier gdat renvoyé par google, et on a trouvé des données
					  // équivalentes dans Redis, donc on passe au suivant
					  continue;
				  }
				  
				  // on est sur le premier, donc on l'indexe aux coordonnées de recherche, même si ce
				  // n'est pas vrai
				  foreach ($tgdatids as $id) {
					  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
					            "found data in redis with another key, will index this new key");
					  $this->rq->indexGdatID($id, 'lat', $_lat);
					  $this->rq->indexGdatID($id, 'lng', $_lng);
					  $gdatids[] = $id;
				  }
				  
				  // le prochain ne sera pas le premier
				  $first = false;
			  } else {
				  // les données n'existent vraiment pas dans redis, il faut socker ce nouvel objet
				  self::log(LOG_INFO, __CLASS__, __FUNCTION__,
				            "not found in redis, found in google, will store this new gdat");
				  $gdatid = $this->rq->addGdat($gdat);
				  
				  // now index this new key for this gdat
				  if ($first) {
					  // adds this gdat as a result, and also index it with searched coords
					  // only if it is the first, ie the most accurate one
					  $gdatids[] = $gdatid;
					  $this->rq->indexGdatID($gdatid, 'lat', $_lat);
					  $this->rq->indexGdatID($gdatid, 'lng', $_lng);

					  $first = false;
				  }
				  // celle là c'est OK.
				  $this->rq->indexGdatID($gdatid, 'query', rawurlencode($gdat->formatted_address));
			  }
		  }
	  }
	  return $gdatids;
  }
  
  // get gdat object from gdatid
  public function getGdat($_id)
  {
	  $gdatfactory = new GeoGdatFactory();
	  return $gdatfactory->make(new RedisGeoGdatFactoryData($this->rq->getReadConn(), $_id));
  }

  // get array of gdat ID matching given filters
  public function getGdatIDs($_filters) 
  {
	  $filternames = array_keys($_filters);
	  $nbfilters = count($filternames);
	  $keys = array();    

	  if (in_array('query', $filternames) && (in_array('lng', $filternames) or
	                                          in_array('lat', $filternames))) {
		  // query can not be specified with lat and/or lng in the same time
		  throw new Exception('filter with both query and lat/lng');
	  }

	  $logbuf = "";
	  foreach ($filternames as $filtername) {
		  $logbuf .= " $filtername=[" . $_filters[$filtername] ."]";
	  }
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "received query filter with $logbuf");
	  
	  if (!in_array('lang', $filternames)) {
		  // default language == en
		  $_filters['lang'] = 'en';
	  }
	  
	  // first case: geocoding
	  if (in_array('query', $filternames)) {
		  // encode query
		  $_filters['query'] = rawurlencode($_filters['query']);
		  $this->geocodeData($_filters['query'], $_filters['lang']); 
	  } elseif (in_array('lat', $filternames) && in_array('lng', $filternames)) {
		  //second case: reverse geocoding 
		  $this->reverseGeocodeData($_filters['lat'], $_filters['lng'],
		                            $_filters['lang']);
	  }
	  
	  // at the end, search in Redis
	  return $this->rq->search("gdat", $_filters);
  }
  
  /*
  ** log a message to syslog
  **
  ** log(msg) => log $msg with LOG_INFO
  ** log(level, msg) => log $msg with $level
  ** log(msg, file, line) => log $msg in $file:$line with LOG_INFO
  ** log(level, msg, file, line) => log $msg in $file:$line with $level)
   */
	  public static function log()
  {
	  switch (func_num_args()) {
	  case 1:
		  $level = LOG_INFO;
		  $msg = func_get_arg(0);
		  break;
		  
	  case 2:
		  $level = func_get_arg(0);
		  $msg = func_get_arg(1);
		  break;
      
	  case 3:
		  $level = LOG_INFO;
		  $msg = sprintf("%s:%s %s\n", func_get_arg(0), 
		                 func_get_arg(1), func_get_arg(2));
		  break;
		  
	  case 4:
		  $level = func_get_arg(0);
		  $msg = sprintf("%s:%s %s\n", func_get_arg(1), 
		                 func_get_arg(2), func_get_arg(3));
		  break;
		  
	  default:
		  error_log(self."::".__FUNCTION__.": wrong number of args");
	  }
	  
	  if (!defined('CFG_LOGLEVEL')) {
		  define('CFG_LOGLEVEL', LOG_INFO);
	  }
	  
	  if ($level > CFG_LOGLEVEL)
		  return;
    
	  if (defined('CFG_LOGFACILITY')) {
		  openlog("geoproxy", LOG_PID, CFG_LOGFACILITY);
		  syslog($level, $msg);
		  closelog();
	  } else {
		  error_log($_msg);
	  }
  }
}

?>
