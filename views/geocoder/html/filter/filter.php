
<?php if (count($results) > 0):?>
<h3> list of gdatids found: </h3>
<?php else: ?>
<h3> no gdatid found !</h3>
<?php endif; ?>

<?php foreach ($results as $id):?>

<li>
 <a href="<?php echo site_url("geocoder/view/output/html/$id") ?>"><?php echo $id;?></a>
</li>

<?php endforeach;?>

