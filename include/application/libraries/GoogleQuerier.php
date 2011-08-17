<?php

class GoogleQuerier 
{
	public function __construct() {}

	// va chercher chez google, et renvoie un array de resultats
	// $_query must be urlencoded
	public function geocode($_query, $_lang)
	{
		GeoProxy::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
		              "asking for: [". $_query ."] ($_lang)");
		
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
			GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__,
			              "url signing requested, url is now: [". $url ."]");
		} 
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		if (! $json = curl_exec($ch)) {
			GeoProxy::log(LOG_CRIT, __FILE__, __LINE__,
			              "Curl error: " . curl_errno($ch));
			curl_close($ch);
			return ($result = array());
		}
		$google = json_decode($json, true);
		
		switch ($google['status']) {
			
		case "OK":
			GeoProxy::log(LOG_INFO, __CLASS__, __FUNCTION__,
			              "data found in google");
			curl_close($ch);
			return $google['results'];
			
		case "OVER_QUERY_LIMIT":
			GeoProxy::log(LOG_CRIT, __CLASS__, __FUNCTION__,
			              "OVER_QUERY_LIMIT reached !"
			              );
			break;
		  
		case "ZERO_RESULTS":
			GeoProxy::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		                "ZERO_RESULTS found !"
			              );
			break;
			
		default:
			GeoProxy::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		                "Google unknown status: " . $google->status);
	  }
	  curl_close($ch);
	  return ($result = array());
	}
	
	// va chercher chez google, et renvoie un array de resultats
	public function reverseGeocode($_lat, $_lng, $_lang)
	{
		GeoProxy::log(LOG_NOTICE, __CLASS__, __FUNCTION__,
		              "asking for: [$_lat:$_lng] ($_lang)");
		
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
		  GeoProxy::log(LOG_DEBUG, __CLASS__, __FUNCTION__,
		                "url signing requested, url is now: [". $url ."]");
	  }
	  
	  $ch = curl_init();
	  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
	  curl_setopt($ch, CURLOPT_URL, $url);
	  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	  
	  if (! $json = curl_exec($ch)) {
		  GeoProxy::log(LOG_CRIT, __CLASS__, __FUNCTION__,
		                "Curl error: " . curl_errno($ch));
		  curl_close($ch);
		  return ($result = array());
	  }
	  $google = json_decode($json, true);
	  
	  switch ($google['status']) {
	  case "OK":
		  GeoProxy::log(LOG_INFO, __CLASS__, __FUNCTION__,
		                "data found in google");
		  curl_close($ch);
		  return $google['results'];
		  
	  case "OVER_QUERY_LIMIT":
		  GeoProxy::log(LOG_CRIT, __CLASS__, __FUNCTION__,
		                "OVER_QUERY_LIMIT reached !"
		                );
		  break;
		  
	  case "ZERO_RESULTS":
		  GeoProxy::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		                "ZERO_RESULTS found !"
		                );
		  break;
		  
	  default:
		  GeoProxy::log(LOG_WARNING, __CLASS__, __FUNCTION__,
		                "Google unknown status: " . $google->status);
	  }
	  curl_close($ch);
	  return ($result = array());
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
}