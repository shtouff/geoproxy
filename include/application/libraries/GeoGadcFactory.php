<?php

class RedisGeoGadcFactoryData extends RedisGeoAbstractFactoryData
{
}

class JSONGeoGadcFactoryData extends JSONGeoAbstractFactoryData
{
}

class GeoGadcFactory extends GeoAbstractFactory
{
	protected function makeFromRedis($_redisConn, $_redisID) 
	{
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
	                "entering"); 
	  
	  $gadc = new GeoGadc();
	  
	  $gadc->long_name = $_redisConn->get("gadc:$_redisID:lname");
	  $gadc->short_name = $_redisConn->get("gadc:$_redisID:sname");
	  $gadc->types = $_redisConn->sMembers("gadc:$_redisID:types");
	  sort($gadc->types);
	  
	  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
	  return $gadc;	 
	}

	protected function makeFromJSON($_json)
	{
		GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		              "entering");
		
		$gadc = new GeoGadc();
		
	  $gadc->short_name = $_json['short_name'];
	  $gadc->long_name = $_json['long_name'];
    $gadc->types = $_json['types'];
    sort($gadc->types);
    
    GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
                  "leaving");
    return $gadc;
	}	                                
}
?>