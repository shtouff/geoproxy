<?php
class Forms extends CI_Controller {
  
  public function index()
  {
	  $this->load->helper('url');
	  
	  redirect('/html/filter/', 'location');
  }

  public function filter()
  {
	  $this->load->helper('url');
	  
	  $filters = $this->input->post(NULL, true);
	  $segs = "";
	  foreach($filters as $filter => $value) {
		  if ($value != "")
			  $segs .= "$filter/$value/";
	  }
		  
	  redirect('/html/filter/' . $segs, 'location');
  } 
}
?>
