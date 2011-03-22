<?php

/* ## data model */

/* # sequencies */
/* next:gdat:id => string */
/* next:gadc:id => string */
/* next:geom:id => string */

/* # geocoding data, identified by X = incr next:gda:id */
/* # giving the same geometry, differs from one lang to another. */
/* gdat:X:geom => string (geom:id) */
/*       :fa => string (formatted_address) */
/*       :adc => linked list of gadc:id */
/*       :types => set of string */
/*       :md5 => string (md5(result from google): make unique combinaison of $lang of the result AND geometry) */
/*       :lang => string (lang of the data) */
/*       :ext => string (ext of the data) */
      
/* # address_components identified by X = incr next:gac:id */
/* gadc:X:serial => string (serial number) */
/* gadc:X:lname => string */
/*       :sname => string */
/*       :types => set of string */

/* # geometry data */
/* geom:X:serial => string (serial number) */
/* geom:X:gdat => hash of (lang => gdat:id)  */
/*       :loc:type => string (location_type) */
/*           :lat => string (location latitude) */
/*           :lng => string (location longitude) */
/*        :vport:sw:lat => string (viewport southwest) */
/*                 :lng => string */
/*              :ne:lat => string (viewport northeast) */
/*                 :lng => string */
/*        :bounds:sw:lat => string (bounds southwest) */
/*                  :lng => string */
/*               :ne:lat => string (bounds northeast) */
/*                  :lng => string */

/* # PKs */
/* pk:gdatByQuery:rawurlencode($query):$lang => string (gdat:id) */
/* pk:gdatByFormattedAddress:rawurlencode($formatted_address):$lang => string (gdat:id) */

/* # indexes */
/* idx:geomBySerial:md5($result) => set of string (gdat:id) */
/* # (use intersection of these two sets to get ids of geodata matching those coordinates) */
/* idx:geomByLat:$lat => set of string (gdat:id) */
/* idx:geomByLng:$ng => set of string (gdat:id) */
/* # tags: type (use these sets to know the id of geodata matching the tag. Use unions / intersections to simulate JOINS) */
/* idx:gdatByType:political => set of string (gdat:id) having type: political */
/* idx:gdatByType:locality => set of string (gdat:id) having type: locality */
/* # tags: ext */
/* idx:gdatByExt:castorama => set of string (gdat:id) having ext: castorama shop */
/* idx:gdatByExt:ratp => set of string (gdat:id) having ext: ratp station or office */
/* # tags: lang */
/* idx:gdatByLang:en => set of string (gdat:id) having lang: en */
/* idx:gdatByLang:fr => set of string (gdat:id) having lang: fr */
/* # and so on ... */


require_once 'GeoGeom.php';
require_once 'GeoGdat.php';

class GeoProxy
{
  private $redisServer;
  private $redisPort;
  private $redisConn;
  
  private static $instance;
  
  private function __construct()
  {
    $this->redisServer = 'localhost';
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
      $gdat = GeoGdat::constructFromGoogle($gdatGoogle, $_lang);
      
      self::log(__FILE__, __LINE__,
		"gdat->fa = [$gdat->formatted_address]");

      if ($gdatid = GeoGdat::existsInRedis($this->redisConn,
					   $gdat->formatted_address, 
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
      GeoGdat::indexInRedis($this->redisConn, $gdat->formatted_address, 
			    $_lang, $gdatid);
    }  
    return $gdat;
  }
  
  public static function log($_file, $_line, $_msg)
  {
    $s = sprintf("%s:%s %s\n", $_file, $_line, $_msg);
    error_log($s);
    print($s);
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

