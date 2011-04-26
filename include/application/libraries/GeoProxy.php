<?php

$incdir = realpath(dirname(__FILE__));
require_once $incdir . '/GeoGeom.php';
require_once $incdir . '/GeoGdat.php';

$cfgdir = realpath(dirname(__FILE__) . '/../../../config');
require_once $cfgdir . '/backend.google.inc.php';
require_once $cfgdir . '/backend.log.inc.php';
require_once $cfgdir . '/backend.redis.inc.php';

class GeoProxy
{
  private $redisServer;
  private $redisPort;
  private $redisConn;
  
  private static $instance;
  
  private function __construct()
  {
	  if (defined('CFG_GEOPROXY_REDIS_MASTER')) {
		  $this->redisServer = CFG_GEOPROXY_REDIS_MASTER;
	  } else {
		  self::log(LOG_CRIT, __FILE__, __LINE__, 
		            "CFG_GEOPROXY_REDIS_MASTER is not defined");
		  return false;
	  }
	  $this->redisPort = '6379';
	  $this->redisConn = new Redis();
	  
	  $this->redisConnect();
  }
  
  private function redisConnect()
  {
	  $this->redisConn->connect($this->redisServer,
	                            $this->redisPort);
  }
  
  public static function singleton() 
  {
	  if (!isset(self::$instance)) {
		  $c = __CLASS__;
		  self::$instance = new $c;
	  }
	  return self::$instance;
  }
  
  // Encode a string to URL-safe base64
  private static function encodeBase64UrlSafe($value)
  {
	  return str_replace(array('+', '/'), array('-', '_'),
	                     base64_encode($value));
  }
  
  // Decode a string from URL-safe base64
  private static function decodeBase64UrlSafe($value)
  {
	  return base64_decode(str_replace(array('-', '_'), array('+', '/'),
	                                   $value));
  }
  
  // Sign a URL with a given crypto key
  // Note that this URL must be properly URL-encoded
  // 
  // exemple: 
  //echo signUrl("http://maps.google.com/maps/api/geocode/json?address=New+York&sensor=false&client=clientID", 'vNIXE0xscrmjlyV-12Nj_BvUPaw=');
  //
  public static function signUrl($myUrlToSign, $privateKey)
  {
	  // parse the url
	  $url = parse_url($myUrlToSign);
	  
    $urlPartToSign = $url['path'] . "?" . $url['query'];
    
    // Decode the private key into its binary format
    $decodedKey = self::decodeBase64UrlSafe($privateKey);
    
    // Create a signature using the private key and the URL-encoded
    // string using HMAC SHA1. This signature will be binary.
    $signature = hash_hmac("sha1", $urlPartToSign, $decodedKey,  true);
    
    $encodedSignature = self::encodeBase64UrlSafe($signature);
    
    return $myUrlToSign."&signature=".$encodedSignature;
  }
  
  public function reverseGeocodeData($_lat, $_lng, $_lang)
  {
	  $gdatids = array();
	  
	  if ($geomid = GeoGeom::intersectInRedis($this->redisConn, $_lat, $_lng)) {
		  // found a geom in redis matching these coordinates
		  $geom = GeoGeom::constructFromRedis($this->redisConn, $geomid);
		  
		  // will try to get back the gdat for this $_lang
		  if ($gdatid = $this->redisConn->hGet("geom:$geomid:gdat", $_lang)) {
			  //$gdat = GeoGdat::constructFromRedis($this->redisConn, $gdatid);
			  $gdatids[] = $gdatid;
		  } else {
			  // no entry for lang=$_lang in hash geom:$geomid:gdat
			  // will try with another lang
			  
			  $avail_gdats = $this->redisConn->hVals("geom:$geomid:gdat");
			  if (count($avail_gdats) >= 1) {
				  $gdatid = $avail_gdats[0];
				  $gdat = GeoGdat::constructFromRedis($this->redisConn, $gdatid);
				  
				  // geocode for this missing language
				  return $this->geocodeData(rawurlencode($gdat->formatted_address),
				                            $_lang);
			  }
		  }
	  } else {
		  // no geomid found. Google reverse geocode ?
		  $providerGdats = $this->googleReverseGeocode($_lat, $_lng, $_lang);
		  $first = true;
		  foreach ($providerGdats as $providerGdat) {
			  // pour chaque resultat google, on cree un objet, 
			  $gdat = GeoGdat::constructFromGoogle($providerGdat, $_lang);
			  
			  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
			            "gdat->fa = [$gdat->formatted_address]");
			  
			  // s'il existe déja des données dans redis correspondant a la formatted address
			  // de l'objet créé, alors on ne stocke pas ce nouvel objet, mais on indexe les donnees
			  // existentes avec ces nouvelles coordonnées, seulement pour le premier résultat
			  // google, qui est le plus accurate
			  if ($tgdatids = GeoGdat::existsInRedis($this->redisConn,
			                                         rawurlencode($gdat->formatted_address), 
			                                         $_lang)) {
				  
				  
				  if (!$first) {
					  continue;
				  }
				  
				  foreach ($tgdatids as $id) {
					  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
					            "found data in redis with another key, will index this new key");
					  GeoGeom::indexInRedis($this->redisConn, $_lat, $_lng, $id);
					  $gdatids[] = $id;
				  }
				  
				  if ($first) {
					  $first = false;
				  }
			  } else {
				  // les données n'existent vraiment pas dans redis, il faut socker ce nouvel objet
				  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
				            "not found in redis, found in google, will store this new gdat");
				  $gdatid = $gdat->storeInRedis($this->redisConn);
				  
				  // now index this new key for this gdat
				  if ($first) {
					  // adds this gdat as a result, and also index it with searched coords
					  // only if it is the first, ie the most accurate one
					  $gdatids[] = $gdatid;
					  GeoGeom::indexInRedis($this->redisConn, $_lat, $_lng, $gdatid);
					  $first = false;
				  }
				  // celle là c'est OK.
				  GeoGdat::indexInRedis($this->redisConn, 
				                        rawurlencode($gdat->formatted_address),
				                        $gdatid);
			  }
		  }
	  }
	  return $gdatids;
  }
  
  public function getGdat($_id)
  {
	  return GeoGdat::constructFromRedis($this->redisConn, $_id);
  }
  
  // va chercher chez google, et renvoie un array de resultats
  public function googleReverseGeocode($_lat, $_lng, $_lang)
  {
	  self::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	            "asking google for: [$_lat:$_lng]");
	  
	  $headers = array("Host: maps.google.com");
	  $url = sprintf('http://%s/maps/api/geocode/json?sensor=%s&latlng=%s,%s&language=%s',
	                 'comtools3:6080',
	                 'false',
	                 $_lat, $_lng,
	                 $_lang
	                 );
	  
	  if (GOOGLE_MAPS_SIGN) {
		  $url .= ("&client=" . GOOGLE_MAPS_ID);
		  $url = self::signUrl($url, GOOGLE_MAPS_KEY);
		  self::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		            "url signing requested, url is now: [". $url ."]");
	  }
	  
	  $ch = curl_init();
	  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
	  curl_setopt($ch, CURLOPT_URL, $url);
	  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	  
	  if (! $json = curl_exec($ch)) {
		  self::log(LOG_CRIT, __CLASS__, __FUNCTION__,
		            "Curl error: " . curl_errno($ch));
		  curl_close($ch);
		  return ($result = array());
	  }
	  $google = json_decode($json);
	  
	  switch ($google->status) {
	  case "OK":
		  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
		            "data found in google");
		  if (count($google->results) > 1) {
			  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
			            "more than 1 result found");
		  }
		  curl_close($ch);
		  return $google->results;
		  
	  case "OVER_QUERY_LIMIT":
		  self::log(LOG_CRIT, __CLASS__, __FUNCTION__,
		            "OVER_QUERY_LIMIT reached !"
		            );
		  break;
		  
	  case "ZERO_RESULTS":
		  self::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		            "ZERO_RESULTS found !"
		            );
		  break;
		  
	  default:
		  self::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		            "Google unknown status: " . $google->status);
	  }
	  curl_close($ch);
	  return ($result = array());
  }
  
  // va chercher chez google, et renvoie un array de resultats
  // $_query must be url encoded
  public function googleGeoCode($_query, $_lang)
  {
	  self::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	            "asking google for: [". $_query ."]");
	  
	  $headers = array("Host: maps.google.com");
	  $url = sprintf('http://%s/maps/api/geocode/json?sensor=%s&address=%s&language=%s',
	                 'comtools3:6080',
	                 'false',
	                 $_query,
	                 $_lang
	                 );
	  
	  if (GOOGLE_MAPS_SIGN) {
		  $url .= ("&client=" . GOOGLE_MAPS_ID);
		  $url = self::signUrl($url, GOOGLE_MAPS_KEY);
		  self::log(LOG_DEBUG, __FILE__, __LINE__,
		            "url signing requested, url is now: [". $url ."]");
	  }
	  
	  $ch = curl_init();
	  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
	  curl_setopt($ch, CURLOPT_URL, $url);
	  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	  
	  if (! $json = curl_exec($ch)) {
		  self::log(LOG_CRIT, __FILE__, __LINE__,
		            "Curl error: " . curl_errno($ch));
		  curl_close($ch);
		  return ($result = array());
	  }
	  $google = json_decode($json);
	  
	  switch ($google->status) {
		  
	  case "OK":
		  self::log(__FILE__, __LINE__,
		            "data found in google");
		  if (count($google->results) > 1) {
			  self::log(__FILE__, __LINE__,
			            "more than 1 result found, using only the first");
		  }
		  curl_close($ch);
		  return $google->results;
		  
	  case "OVER_QUERY_LIMIT":
		  self::log(LOG_CRIT, __FILE__, __LINE__,
		            "OVER_QUERY_LIMIT reached !"
		            );
		  break;
		  
	  case "ZERO_RESULTS":
		  self::log(LOG_WARNING, __FILE__, __LINE__,
		            "ZERO_RESULTS found !"
		            );
		  break;
		  
	  default:
		  self::log(LOG_WARNING, __FILE__, __LINE__,
		            "Google unknown status: " . $google->status);
	  }
	  curl_close($ch);
	  return ($result = array());
  }
  
  // returns an array of gdatids matching this query with this lang
  // query must be urlencoded
  public function geocodeData($_query, $_lang)
  {
	  $gdatids = array();
	  
	  if ($tgdatids = GeoGdat::existsInRedis($this->redisConn, 
	                                         $_query, $_lang)) {
		  
		  // premier cas, les données sont déja dans redis
		  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
		            "data found in redis");

		  foreach ($tgdatids as $gdatid) {
			  $gdatids[] = $gdatid;
		  }
	  } else {

		  // deuxième cas, les données ne sont pas dans redis
		  
		  // on tape dans google
		  $providerGdats = $this->googleGeocode($_query, $_lang);
		  foreach ($providerGdats as $providerGdat) {
			  // pour chaque resultat google, on cree un objet, 
			  $gdat = GeoGdat::constructFromGoogle($providerGdat, $_lang);
			  
			  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
			            "gdat->fa = [$gdat->formatted_address]");
			  
			  // s'il existe déja des données dans redis correspondant à la formatted address 
			  // de l'objet créé, alors on ne stocke pas ce nouvel objet, mais on indexe les donnees
			  // existentes avec cette nouvelle query
			  if ($tgdatids = GeoGdat::existsInRedis($this->redisConn,
			                                         rawurlencode($gdat->formatted_address), 
			                                         $_lang)) {
				  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
				            "found data in redis with another key, will index this new key");
				  
				  // XXX redis sadd index:gdatByQuery:$query $id
				  foreach ($tgdatids as $id) {
					  GeoGdat::indexInRedis($this->redisConn, $_query, $id);
					  $gdatids[] = $id;
				  }
			  } else {
				  // les données n'existent vraiment pas dans redis, il faut socker ce nouvel objet
				  self::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
				            "not found in redis, found in google, will store this new gdat");
				  $gdatid = $gdat->storeInRedis($this->redisConn);
				  $gdatids[] = $gdatid;
				  
				  // now index this new key for this gdat
				  GeoGdat::indexInRedis($this->redisConn, $_query, $gdatid);
				  GeoGdat::indexInRedis($this->redisConn, 
				                        rawurlencode($gdat->formatted_address),
				                        $gdatid);
			  }
		  }
	  }
	  return $gdatids;
  }
  
  private function mapF2I($_filter)
  {
    // maps filter name to redis index
    $map = array("lang"		=> "idx:gdatByLang",
                 "ext"		=> "idx:gdatByExt",
                 "lat"		=> "idx:geomByLat",
                 "lng"		=> "idx:geomByLng",
                 "type"		=> "idx:gdatByType",
                 "serial"	=> "idx:geomBySerial",
                 "query"	=> "idx:gdatByQuery",
                 );
    
    return $map["$_filter"];
  }
  
  public function createGdat($_data) 
  {
	  $gdat = GeoGdat::constructFromArray($_data);
	  $gdatid = $gdat->storeInRedis($this->redisConn);
	  GeoGdat::indexInRedis($this->redisConn,
	                        rawurlencode($gdat->formatted_address),
	                        $gdatid);
	  
	  return $gdatid;
  }
  
  public function deleteGdat($_gdatid)
  {
	  $gdat = GeoGdat::constructFromRedis($this->redisConn, $g_gdatid);
	  $gdat->deleteFromRedis($this->redisConn);
  }

  public function getGdatIDs($_filters) 
  {
	  $filternames = array_keys($_filters);
	  $nbfilters = count($filternames);
	  $keys = array();    
	  
	  if (in_array('query', $filternames)) {
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                  "received query filter with query=[".
		                $_filters['query']. "]");
	  }
	  
	  if (in_array('query', $filternames)) {
		  // encode query
		  $_filters['query'] =  rawurlencode($_filters['query']);
	  }
	  
	  // special cases
	  if (in_array('lang', $filternames) && in_array('query', $filternames)) {
		  // lang & query => geocode needed
		  return ($this->geocodeData($_filters['query'], $_filters['lang']));
	  } else if (in_array('serial', $filternames)) {
		  // serial => use reverse indirection to get gdatids back
		  $gdatids = array();
		  $serialset = $this->mapF2I('serial') .":". $_filters['serial'];
		  $geomids = $this->redisConn->sMembers($serialset);
		  
		  foreach ($geomids as $geomid) {
			  $tgdatids = array();
			  if (!in_array('lang', $filternames)) {
				  $tgdatids = $this->redisConn->hVals("geom:$geomid:gdat");
			  } else {
				  if ($tgdatid = $this->redisConn->hGet("geom:$geomid:gdat", 
				                                        $_filters['lang'])) {
					  $tgdatids[] = $tgdatid;
				  }
			  }
			  
			  foreach ($tgdatids as $tgdatid) {
				  $gdatids[] = $tgdatid;
			  }
		  }
		  return $gdatids;
	  } else if (in_array('lat', $filternames) && 
	             in_array('lng', $filternames)) {
		  
		  // lat & lng => reversegeocode
		  
		  $gdatids = array();
		  $latset = $this->mapF2I('lat') .":". $_filters['lat'];
		  $lngset = $this->mapF2I('lng') .":". $_filters['lng'];
		  $geomids = $this->redisConn->sInter($latset, $lngset);
		  foreach ($geomids as $geomid) {
			  $tgdatids = $this->redisConn->hVals("geom:$geomid:gdat");
			  foreach ($tgdatids as $tgdatid) {
				  $gdatids[] = $tgdatid;
			  }
		  }
		  
		  if (!in_array('lang', $filternames)) {
			  return $gdatids;
		  } else {
			  // XXX 20/04/2011
			  // ouh la !! le résultat il part ou ? 
			  $gdatids = $this->reverseGeocodeData($_filters['lat'], $_filters['lng'],
			                                       $_filters['lang']);
			  
			  return $gdatids;

			  /* foreach ($geomids as $geomid) { */
				/*   $tgdatids = $this->redisConn->hVals("geom:$geomid:gdat"); */
				/*   foreach ($tgdatids as $tgdatid) { */
				/* 	  $gdatids[] = $tgdatid; */
				/*   } */
			  /* } */
			  
			  /* // build a temp set, in order to intersect it with lang */
			  
			  /* $id = $this->redisConn->incr('next:tmp:id'); */
			  /* foreach ($gdatids as $gdatid) { */
				/*   $this->redisConn->sAdd("tmp:$id", $gdatid); */
			  /* } */
			  
			  /* $this->redisConn->expire("tmp:$id", 30); */
			  /* return $this->redisConn->sInter("tmp:$id",  */
			  /*                                 "idx:gdatByLang:".$_filters['lang']); */
		  }
	  }
	  
	  foreach ($filternames as $filter) {
		  $value = $_filters[$filter];
		  $keys[] = $this->mapF2I($filter) . ":$value";
	  }
	  
	  switch ($nbfilters = count($filternames)) {
    case 1:
	    self::log(LOG_DEBUG, __FILE__, __LINE__,
		"will sMembers for key=$keys[0]");
	    return $this->redisConn->smembers($keys[0]);
	    
    case 2:
	    $result = $this->redisConn->sInter($keys[0], $keys[1]); 
	    self::log(LOG_DEBUG, __FILE__, __LINE__,
	              "sInter between key=$keys[0] and key=$keys[1]: ".
	              count($result) ." result(s)");
	    return $result;
	    
    case 3:
	    self::log(LOG_DEBUG, __FILE__, __LINE__,
	              "will sInter between key=$keys[0] and key=$keys[1] and key=$keys[2]");
	    return $this->redisConn->sInter($keys[0], $keys[1], $keys[2]);
	    
    case 4:
	    self::log(LOG_DEBUG, __FILE__, __LINE__,
	              "will sInter between key=$keys[0] and key=$keys[1] and key=$keys[2] and key=$keys[3]");
	    return $this->redisConn->sInter($keys[0], $keys[1], $keys[2], $keys[3]);
	    
    default:
	    self::log(LOG_WARNING, __FILE__, __LINE__,
	              "this number of filters ($nbfilters) is not supported");
    }
	  
	  return $ret = array();
  }
  
  
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
  
  function getLocalitiesByName($query) 
  {
	  // renvoie une liste de labels de ville commencant par $query > 3 chars
	  // recherche uniquement dans le cache redis
	  // peut être utilisé en Ajax pour obtenir une liste
	  
	  // hash list de 5
    
    //$this->redisConnection->sMembers 
    $result = array();
    
    if (strlen($query) < 3) {
      return $result;
    }
    
    $code = rawurlencode($query);
    $set = 3; // 3 premier chars de $_prefix
    
    $result = array("paris", "lyon", "marseille", "bordeaux", "lille", "toulon");   
    return $result;
  }

  public function indexLocalitiesByNameLang() 
  {
    $key = "idx:gdatByExt:none";

    $gdatids = $this->redisConnection->sMembers($key);
    foreach ($gdatids as $gdatid) {
      // parcours tous les 
      $gdat = GeoGdat::constructFromRedis($this->redisConnection,
					  $gdatid);
      
      $locality = $country = "";
      foreach ($gdat->address_components as $gadc) {
	      if ( array_search("locality", $gadc->types)) {
		      $locality = $gadc->lname;
	      } 
	      
	      if ( array_search("country", $gadc->types)) {
		      $country = $gadc->lname;
	      }
      }
      
      if (! empty($locality) && ! empty($country)) {
	      // index
	      $prefix = substr();
      }
    }
  }
}

?>

