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
    $decodedKey = decodeBase64UrlSafe($privateKey);
    
    // Create a signature using the private key and the URL-encoded
    // string using HMAC SHA1. This signature will be binary.
    $signature = hash_hmac("sha1",$urlPartToSign, $decodedKey,  true);
    
    $encodedSignature = encodeBase64UrlSafe($signature);
    
    return $myUrlToSign."&signature=".$encodedSignature;
  }
  
  public function reverseGeocodeData($_lat, $_lng, $_lang)
  {
    if ($geomid = GeoGeom::intersectInRedis($this->redisConn, $_lat, $_lng)) {
      // found a geom in redis matching these coordinates
      $geom = GeoGeom::constructFromRedis($this->redisConn, $geomid);
      
      // will try to get back the gdat for this $_lang
      if ($gdatid = $this->redisConn->hGet("geom:$geomid:gdat", $_lang)) {
	$gdat = GeoGdat::constructFromRedis($this->redisConn, $gdatid);
	return $gdat;
      } else {
	// no entry for lang=$_lang in hash geom:$geomid:gdat
	// will try with another lang
	
	$avail_gdats = $this->redisConn->hVals("geom:$geomid:gdat");
	if (count($avail_gdats) >= 1) {
	  $gdatid = $avail_gdats[0];
	  $gdat = GeoGdat::constructFromRedis($this->redisConn, $gdatid);
	  
	  // geocode for this missing language
	  return $this->geocodeData($gdat->formatted_address, $_lang);
	}
      }
    }
    return false;
  }

  public function getGdat($_id)
  {
    return GeoGdat::constructFromRedis($this->redisConn, $_id);
  }
  
  // query must be urlencoded
  public function geocodeData($_query, $_lang)
  {
    if ($gdatid = GeoGdat::existsInRedis($this->redisConn, 
					 $_query, $_lang)) {
      self::log(__FILE__, __LINE__,
		"data found in redis");
      $gdat = GeoGdat::constructFromRedis($this->redisConn,
					  $gdatid);
      return $gdat; 
    } else {
      $gdatGoogle = GeoGdat::retrieveFromGoogle($_query, $_lang);
      // XXX warning is:
      // XXX several result returned
      // XXX bounds is not defined in result (or whatever field in that case)
      // XXX print_r($gdatGoogle);
      $gdat = GeoGdat::constructFromGoogle($gdatGoogle, $_lang);
      
      self::log(__FILE__, __LINE__,
		"gdat->fa = [$gdat->formatted_address]");

      if ($gdatid = GeoGdat::existsInRedis($this->redisConn,
					   rawurlencode($gdat->formatted_address), 
					   $_lang)) {
	self::log(__FILE__, __LINE__,
		  "found in redis with another key, will index this new key");
	
	$gdat = GeoGdat::constructFromRedis($this->redisConn, $gdatid);
      } else {
	// really don't exist in Redis, have to store it !
	self::log(__FILE__, __LINE__,
		  "not found in redis, found in google, will store this new gdat");
	$gdatid = $gdat->storeInRedis($this->redisConn);
      }
      // now index this new key for this gdat
      GeoGdat::indexInRedis($this->redisConn, $_query, $_lang, $gdatid);
      GeoGdat::indexInRedis($this->redisConn, 
			    rawurlencode($gdat->formatted_address), 
			    $_lang, $gdatid);
    }  
    return $gdat;
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
  
  private function mapR2I($_resource)
  {
    // maps resource name to redis index
  }

  public function getGdatIDs($_filters) 
  {
    $filternames = array_keys($_filters);
    $nbfilters = count($filternames);
    $keys = array();    
    
    if (in_array('query', $filternames)) {
      GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
		    "received query filter with query=[".
		    $_filters['query']. "]");
    }
    
    if (in_array('query', $filternames)) {
      // encode query
      $_filters['query'] =  rawurlencode($_filters['query']);
    }
    
    // special cases
    if (in_array('lang', $filternames) && in_array('query', $filternames)) {
      // lang & query => geocode
      $this->geocodeData($_filters['query'], $_filters['lang']);
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
	$this->reverseGeocodeData($_filters['lat'], $_filters['lng'],
				  $_filters['lang']);
	
	foreach ($geomids as $geomid) {
	  $tgdatids = $this->redisConn->hVals("geom:$geomid:gdat");
	  foreach ($tgdatids as $tgdatid) {
	    $gdatids[] = $tgdatid;
	  }
	}
	
	// build a temp set, in order to intersect it with lang
	
	$id = $this->redisConn->incr('next:tmp:id');
	foreach ($gdatids as $gdatid) {
	  $this->redisConn->sAdd("tmp:$id", $gdatid);
	}
	
	$this->redisConn->expire("tmp:$id", 30);
	return $this->redisConn->sInter("tmp:$id", 
					"idx:gdatByLang:".$_filters['lang']);
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

