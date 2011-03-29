<?php
class Html extends CI_Controller {
  
  public function index()
  {
  }

  // list gdat ids, possibly filtered
  public function filter()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    
    $filters = $this->uri->uri_to_assoc(3);
    
    $this->load->view('html/filter/header');
    
    $proxy = GeoProxy::singleton();
    $data['gdatids'] = $proxy->getGdatIDs($filters);
    $this->load->view('html/filter/filter', $data);

    $this->load->view('html/filter/footer');
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
    
    $this->load->view('html/view/header');
    
    $gdatids = func_get_args();
    $proxy = GeoProxy::singleton();
    
    foreach ($gdatids as $id) {
      $data['gdat'] = $proxy->getGdat($id);
      $this->load->view('html/view/gdat', $data);
    }

    $this->load->view('html/view/footer');

  }
}
?>
