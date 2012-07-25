<?php 

require_once dirname(__FILE__).'/inc.php';
require_once dirname(__FILE__).'/lib/sharelib.php';
require_once $CFG->libdir . '/filelib.php';

$relativepath = get_file_argument('portfoliofile.php'); // the check of the parameter to PARAM_PATH is executed inside get_file_argument

if (!$relativepath) {
	error('No valid arguments supplied or incorrect server configuration');
} else if ($relativepath{0} != '/') {
	error('No valid arguments supplied, path does not start with slash!');
}

// relative path must start with '/', because of backup/restore!!!

// extract relative path components
$args = explode('/', trim($relativepath, '/'));

if( $args[0] != 'bmu') {
	error('No valid arguments supplied');
}

if($args[1] == 'temp') {
	if($args[2] == 'exportdata') {
		//$args[3] = $access_user_id = clean_param($args[3], PARAM_INT);
		//DO CHECK!!!!
		/*if($access_user_id == $USER->id) {
		 // check ok, allowed to access the file
		}
		else {
		error('No valid arguments supplied');
		}*/
	}
	else {
		error('No valid arguments supplied');
	}
}

$filepath = $CFG->dataroot . '/' . implode('/', $args);



if (!file_exists($filepath)) {
	if(isset($course)) {
		not_found($course->id);
	}
	else {
		echo $filepath;
		not_found();
	}
}
register_shutdown_function('rmdir_recursive',$CFG->dataroot."/".$args[0]."/".$args[1]."/".$args[2]."/".$args[3]);
send_temp_file($filepath, basename($filepath),false);

function not_found($courseid = 0) {
	global $CFG;
	header('HTTP/1.0 404 not found');
	if($courseid > 0) {
		error(get_string('filenotfound', 'error'), $CFG->wwwroot.'/course/view.php?id='.$courseid); //this is not displayed on IIS??
	}
	else {
		error(get_string('filenotfound', 'error')); //this is not displayed on IIS??
	}
}
function rmdir_recursive($dir) {
	foreach(scandir($dir) as $file) {
		if ('.' === $file || '..' === $file) continue;
		if (is_dir("$dir/$file")) rmdir_recursive("$dir/$file");
	}
	@rmdir($dir);
}