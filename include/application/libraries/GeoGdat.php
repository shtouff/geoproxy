<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed'); 

require_once 'GeoGeom.php';
require_once 'GeoGdat.php';
require_once 'GeoGadc.php';

class GeoGdat {

  public $geometry;
  public $formatted_address;
  public $address_components;
  public $types;
  public $ext;
  public $lang;
  
  // private constructor since we don't want everyone creates 
  // this kind of object
  private function __construct() {
  }
  
  public static function constructFromGoogle($_gdat, $_lang) {
    
    GeoProxy::log(__FILE__, __LINE__,
		  "constructFromGoogle");
    
    $gdat = new GeoGdat();
    
    $gdat->geometry = GeoGeom::constructFromGoogle($_gdat->geometry);
    $gdat->formatted_address = $_gdat->formatted_address;
    $gdat->types = $_gdat->types;
    $gdat->lang = $_lang;
    $gdat->ext = "none";
    $gdat->address_components = $_gdat->address_components;

    return $gdat;
  }
  
  public static function indexInRedis($_redis, $_query, $_lang, $_gdatid) {
    $hash = rawurlencode($_query);
    //$_redis->set("pk:gdatByQuery:$hash:$_lang", $_gdatid);
    $_redis->sAdd("idx:gdatByQuery:$hash", $_gdatid);
    $_redis->sAdd("idx:gdatByLang:$_lang", $_gdatid);
    GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
		  "query [$_query] cached, with gdat:id=[$_gdatid]");
  }
  
  // va chercher chez google
  public static function retrieveFromGoogle($_query, $_lang)
  {
    $url = sprintf('http://%s/maps/api/geocode/json?sensor=%s&address=%s&language=%s',
		   'maps.google.com',
		   'false',
		   rawurlencode($_query),
		   $_lang
		   );
    
    $json = file_get_contents($url);
    $google = json_decode($json);
    
    switch ($google->status) {
      
    case "OK":
      GeoProxy::log(__FILE__, __LINE__,
		    "data found in google");
      if (count($google->results) > 1) {
	GeoProxy::log(__FILE__, __LINE__,
		      "more than 1 result found, using only the first");
      }
      return $google->results[0];
      
    default:
      GeoProxy::log(__FILE__, __LINE__,
		    "unknown status: " . $_data->status);
    }
    return false;
  }
  
  public static function constructFromRedis($_redis, $_gdatid) {
    
    $gdat = new GeoGdat();
    
    // geometry
    $geomid = $_redis->get("gdat:$_gdatid:geom");
    $gdat->geometry = GeoGeom::constructFromRedis($_redis, $geomid);
    
    $gdat->formatted_address = $_redis->get("gdat:$_gdatid:fa");
    $gdat->lang = $_redis->get("gdat:$_gdatid:lang");
    $gdat->ext = $_redis->get("gdat:$_gdatid:ext");
    $gdat->types = $_redis->sMembers("gdat:$_gdatid:types");
    
    // address components
    $gadcids = $_redis->lGetRange("gdat:$_gdatid:adc", 0, -1);
    foreach ($gadcids as $gadcid) {
      $gdat->address_components[] = GeoGadc::constructFromRedis($_redis,
								$gadcid);
    }
    
    return $gdat;
  }
  
  // search if a gdat exists in redis
  // return gdat:id if it actually exists
  // return false else
  public static function existsInRedis($_redis, $_query, $_lang) {
    
    /* $pk = 'pk:gdatByQuery'; */
    /* $key = $pk .':'. rawurlencode($_query) .':'. $_lang; */
    
    /* if (!$_redis->exists($key)) { */
    /*   return false; */
    /* } */
    
    /* return $_redis->get($key); */

    $key1 = 'idx:gdatByQuery:'. rawurlencode($_query); 
    $key2 = 'idx:gdatByLang:'. $_lang; 

    $result = $_redis->sInter($key1, $key2);    
    switch ($num = count($result)) {
      
    case 0:
      return false;
      
    case 1:
      return $result[0];
      
    default:
      GeoProxy::log(LOG_WARNING, __FILE__, __LINE__,
		    "more than 1 id found ($num actually)");
    }
  }

  public function storeInRedis($_redis) {

    $id = $_redis->incr('next:gdat:id');

    // set keys
    $_redis->set("gdat:$id:fa", $this->formatted_address);
    $_redis->set("gdat:$id:ext", $this->ext);
    $_redis->set("gdat:$id:lang", $this->lang);
    $_redis->set("gdat:$id:ext", $this->ext);
    
    foreach ($this->types as $type) {
      $_redis->sAdd("gdat:$id:types", $type);
      // update index for this type
      $_redis->sAdd("idx:gdatByType:$type", $id);
    }

    // address components
    foreach ($this->address_components as $adc) {
      if (! $gadcid = GeoGadc::existsInRedis($_redis, $adc)) {
	$gadc = GeoGadc::constructFromGoogle($adc);
	$gadcid = $gadc->storeInRedis($_redis);
      }

      // attach to gdat, in a linked list to preserve order
      $_redis->rPush("gdat:$id:adc", $gadcid);
    }

    // geometry
    // first try to find an already existing geometry
    if (! $geomid = GeoGeom::existsInRedis($_redis, $this->geometry)) {
      $geom = GeoGeom::constructFromGoogle($this->geometry);
      $geomid = $geom->storeInRedis($_redis);
    }
    // attach gdat -> geom
    $_redis->set("gdat:$id:geom", $geomid);
    // reverse path
    $_redis->hset("geom:$geomid:gdat", $this->lang, $id);
    
    // update indexes
    $lang = $this->lang;
    $ext = $this->ext;
    $_redis->sadd("idx:gdatByLang:$lang", $id);
    $_redis->sadd("idx:gdatByExt:$ext", $id);

    return $id;
  }
}
?>
