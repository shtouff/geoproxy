
<?php if (count($gdatids) > 0):?>
<h3> list of gdatids found: </h3>
<?php else: ?>
<h3> no gdatid found !</h3>
<?php endif; ?>

<?php foreach ($gdatids as $id):?>

<li>
 <a href="/index.php/html/view/<?php echo $id;?>"><?php echo $id;?></a>
</li>

<?php endforeach;?>

