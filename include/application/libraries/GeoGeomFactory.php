<?php

class RedisGeoGeomFactoryData extends RedisGeoAbstractFactoryData
{
}

class JSONGeoGeomFactoryData extends JSONGeoAbstractFactoryData
{
}

class GeoGeomFactory extends GeoAbstractFactory
{
	protected function makeFromRedis($_redisConn, $_redisID) 
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering");

    $geom = new GeoGeom();

    $geom->serial = $_redisConn->get("geom:$_redisID:serial");
    $geom->location_type = $_redisConn->get("geom:$_redisID:loc:type");
    $geom->location = new GeoLocation($_redisConn->get("geom:$_redisID:loc:lat"),
                                      $_redisConn->get("geom:$_redisID:loc:lng"));
    
    $sw = new GeoLocation($_redisConn->get("geom:$_redisID:vport:sw:lat"),
                          $_redisConn->get("geom:$_redisID:vport:sw:lng"));
    $ne = new GeoLocation($_redisConn->get("geom:$_redisID:vport:ne:lat"),
                          $_redisConn->get("geom:$_redisID:vport:ne:lng"));
    $geom->viewport = new GeoBounds($sw, $ne);
    
    $sw = new GeoLocation($_redisConn->get("geom:$_redisID:bounds:sw:lat"),
                          $_redisConn->get("geom:$_redisID:bounds:sw:lng"));
    $ne = new GeoLocation($_redisConn->get("geom:$_redisID:bounds:ne:lat"),
                          $_redisConn->get("geom:$_redisID:bounds:ne:lng"));
    $geom->bounds = new GeoBounds($sw, $ne);
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $geom;
	}

	protected function makeFromJSON($_json)
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
	                "entering");
		
		$geom = new GeoGeom();
	  
		$geom->location_type = $_json['location_type'];
		$geom->location = new GeoLocation($_json['location']['lat'],
		                                  $_json['location']['lng']);
		
		if (isset($_json['viewport'])) {
			GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
		                "viewport exists in array, propagating to object");
		  $sw = new GeoLocation($_json['viewport']['southwest']['lat'],
		                        $_json['viewport']['southwest']['lng']);
		  $ne = new GeoLocation($_json['viewport']['northeast']['lat'],
		                        $_json['viewport']['northeast']['lng']);
		  $geom->viewport = new GeoBounds($sw, $ne);
		} else {
			GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
			              "viewport doesn't exist in array");
		}
		
		if (isset($_json['bounds'])) {
			GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
			              "bounds exists in array, propagating to object");
			$sw = new GeoLocation($_json['bounds']['southwest']['lat'],
		                        $_json['bounds']['southwest']['lng']);
			$ne = new GeoLocation($_json['bounds']['northeast']['lat'],
			                      $_json['bounds']['northeast']['lng']);
			$geom->bounds = new GeoBounds($sw, $ne);
		} else {
			GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__, 
			              "bounds doesn't exist in array");
	  }
	  return $geom;
	}	                                
}
?>