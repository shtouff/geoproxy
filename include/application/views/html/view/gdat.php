<h2><?php echo $gdat->formatted_address; ?></h2>
<h3>lang: <?php echo $gdat->lang; ?></h3>
<h3>ext: <?php echo $gdat->ext; ?></h3>
<h3>types: </h3>
 
<?php foreach ($gdat->types as $type):?> 
<li><?php echo $type; ?></li>
<?php endforeach;?>
  <h3>addresse: </h3>
<?php foreach ($gdat->address_components as $ad):?>
<li><?php echo $ad->long_name ."[" .$ad->short_name ."]"; ?></li>
<?php endforeach;?>

<h3>geometry: </h3>


<li>location: <?php echo $gdat->geometry->location_type; ?>
<ul>
<li>lat:  <?php echo $gdat->geometry->location->lat; ?></li>
<li>lng:  <?php echo $gdat->geometry->location->lng; ?></li>
</ul>
</li>

<li>viewport: 
<ul>
<li>SW lat: <?php echo $gdat->geometry->viewport->southwest->lat; ?>
<li>SW lng: <?php echo $gdat->geometry->viewport->southwest->lng; ?>
<li>NE lat: <?php echo $gdat->geometry->viewport->northeast->lat; ?>
<li>NE lng: <?php echo $gdat->geometry->viewport->northeast->lng; ?>
</ul>
</li>

<li>bounds: 
<ul>
<li>SW lat: <?php echo $gdat->geometry->bounds->southwest->lat; ?>
<li>SW lng: <?php echo $gdat->geometry->bounds->southwest->lng; ?>
<li>NE lat: <?php echo $gdat->geometry->bounds->northeast->lat; ?>
<li>NE lng: <?php echo $gdat->geometry->bounds->northeast->lng; ?>
</ul>
</li>

<hr/>
