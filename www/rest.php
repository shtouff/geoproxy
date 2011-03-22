<?php

$incdir = realpath(dirname(__FILE__) . '/../include');
require_once $incdir . '/GeoProxy.php';

//header('Content-type: application/json');
header('Content-type: text/plain');


function get() {
  // examiner $_GET
  
  //$proxy = GeoProxy::singleton();
  
  //GeoProxy::log(LOG_DEBUG, __FILE__, __LINE__, "beurk");
  
  //print json_encode($proxy->geocodeData("paris", "fr"));

  $resource = $_GET['r'];
  $filters = array_diff($_GET, array('r' => $resource));
  
  print_r($resource);
  print_r($filters);

  $proxy = GeoProxy::singleton();
  print json_encode($proxy->getResource($resource, $filters));
}

function put() {
  // récupération des données brutes
  $raw_data = file_get_contents('php://input');
  // transformation en tableau à indexes ($put_data)
  parse_str($raw_data, $put_data);
}

function post() {
  // examiner $_POST
}

function delete() {
  // La méthode DELETE envoie ses paramètres dans l’URL ( accessible via $_SERVER['REQUEST_URI'])
}

// $method sera égal à 'get', 'post', 'put' ou 'delete'
switch ($method = strtolower($_SERVER['REQUEST_METHOD'])) {

case 'get':
  get();
  break;

case 'post':
  post();
  break;
  
case 'delete':
  delete();
  break;

case 'put':
  put();
  break;

default:
  GeoProxy::log(LOG_WARNING, __FILE__, __LINE__, 
		"method $method is not supported");
}
?>
