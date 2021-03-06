<div class="view">
<h2>ID: <?php echo $gdatid; ?></h2>
<h3>formatted address: <?php echo $gdat->formatted_address; ?></h3>
<h3>lang: <?php echo $gdat->lang; ?></h3>
<h3>ext: <?php echo $gdat->ext; ?></h3>
<h3>types: </h3>
 
<?php foreach ($gdat->types as $type):?> 
<li><?php echo $type; ?></li>
<?php endforeach;?>
<h3>address: </h3>
<?php foreach ($gdat->address_components as $ad):?>
<li>
<?php echo $ad->long_name ."[" .$ad->short_name ."]"; ?>
 <?php foreach ($ad->types as $type): ?>
 <?php echo $type; ?>
 <?php endforeach;?>
</li>
<?php endforeach;?>

<h3>geometry: </h3>

<table>
<tr>
<td>
<li>serial: <?php echo $gdat->geometry->serial; ?>

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
</td>
<td>
<img src="http://maps.google.com/maps/api/staticmap?center=<?php echo $gdat->geometry->location->lat ?>,<?php echo $gdat->geometry->location->lng?>&markers=color:blue%7Clabel:A%7C<?php echo $gdat->geometry->location->lat?>,<?php echo $gdat->geometry->location->lng?>&visible=<?php echo $gdat->geometry->viewport->southwest->lat?>,<?php echo $gdat->geometry->viewport->southwest->lng?>%7C<?php echo $gdat->geometry->viewport->northeast->lat?>,<?php echo $gdat->geometry->viewport->northeast->lng?>&size=640x350&sensor=false&maptype=terrain"/>
</td>
</tr>
</table>
</div>
<hr/>
