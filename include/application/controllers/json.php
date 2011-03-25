<?php
class Json extends CI_Controller {
  
  public function index()
  {
  }

  // list gdat ids, possibly filtered
  public function filter()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    
    $filters = $this->uri->uri_to_assoc(3);
    
    $proxy = GeoProxy::singleton();
    $gdatids = $proxy->getGdatIDs($filters);
    
    $this->output->set_output(json_encode($gdatids));
  }  

  // create a new gdat
  public function create() 
  {

  }

  // modifies an existing gdat
  public function modify()
  {

  }

  // view a list of particular gdatids
  public function view()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    
    $gdatids = func_get_args();
    $proxy = GeoProxy::singleton();
    
    foreach ($gdatids as $id) {
      $gdats[] = $proxy->getGdat($id);
    }

    $this->output->set_output(json_encode($gdats));
  }
}
?>
