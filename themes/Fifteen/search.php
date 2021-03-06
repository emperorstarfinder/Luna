<?php

// Make sure no one attempts to run this view directly.
if (!defined('FORUM'))
	exit;

?>
<nav class="navbar navbar-default" role="navigation">
	<div class="navbar-header">
		<a href="search.php" class="navbar-brand"><span class="fa fa-fw fa-search"></span> <?php _e('Search', 'luna') ?></a>
	</div>
	<div class="collapse navbar-collapse hidden-xs" id="search-nav">
		<ul class="navbar-form navbar-right">
			<a class="btn btn-default" href="search.php?section=advanced"><?php _e('Advanced', 'luna') ?></a>
		</ul>
	</div>
</nav>
<form id="search" method="get" action="search.php?section=simple">
	<div class="panel panel-default">
		<div class="panel-body">
			<fieldset>
				<input type="hidden" name="action" value="search" />
				<div class="input-group"><input class="form-control" type="text" name="keywords" placeholder="<?php _e('Search', 'luna') ?>" maxlength="100" /><span class="input-group-btn"><button class="btn btn-primary" type="submit" name="search" accesskey="s" /><span class="fa fa-fw fa-search"></span> <?php _e('Search', 'luna') ?></button></span></div>
			</fieldset>
		</div>
	</div>
</form>
