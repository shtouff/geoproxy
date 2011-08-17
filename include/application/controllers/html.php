<?php
class Html extends CI_Controller {
  
  public function index()
  {
	  $this->load->helper('url');
	  
	  redirect('/html/filter/', 'location');
  }

  // list gdat ids, possibly filtered
  public function filter()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    $this->load->helper('form');

    // build filter from uri
    $filters = $this->uri->uri_to_assoc(3);
    foreach ($filters as $filter=>$value) {
      $filters[$filter] = rawurldecode($value);
    }
    
    $this->load->view('html/filter/header');
    $this->load->view('html/menu');
    
    $proxy = GeoProxy::getInstance();
    $data['gdatids'] = $proxy->getGdatIDs($filters);
    $this->load->view('html/filter/filter', $data);
    
    $this->load->view('html/filter/footer');
  }  

  // view a list of particular gdatids
  public function view()
  {
    require_once APPPATH . "/libraries/GeoProxy.php";
    
    $this->load->helper('form');
    
    $this->load->view('html/view/header');
    $this->load->view('html/menu');
    
    $gdatids = func_get_args();
    $proxy = GeoProxy::getInstance();
    
    foreach ($gdatids as $id) {
	    $data['gdat'] = $proxy->getGdat($id);
	    $data['gdatid'] = $id;
      $this->load->view('html/view/gdat', $data);
    }

    $this->load->view('html/view/footer');

  }
}
?>
