<?php
require_once dirname(__FILE__) . '/inc.php';
require_once dirname(__FILE__) . '/lib.php';
require_once dirname(__FILE__) . '/sharelib.php';
 require_once($CFG->dirroot.'/course/lib.php');
 require_once($CFG->dirroot.'/mod/resource/lib.php');
 global $DB,$user,$COURSE;
 
		
	$uname = optional_param('username', 0, PARAM_USERNAME);  //100
	$pword = optional_param('password', 0, PARAM_ALPHANUM);	//32
	$action = optional_param('action',"auth",PARAM_ALPHANUM);
	
	if ($uname!="0" && $pword!="0"){

		if (bmu_check_login($uname,$pword)){
			if($action == "auth")
				bmu_write_auth_xml(true);
			else if($action == "list") {
				//create directory for the temp
				$exportdir = make_upload_directory(bmu_data_file_area_name());
				
				// create directory for the zip-files:
    			$zipdir = make_upload_directory(bmu_zip_area_name());
			    // Delete everything inside
    			remove_dir($zipdir, true);
    			// Put a / on the end
    			if (substr($zipdir, -1) != "/")
        			$zipdir .= "/";
				
				$courses = enrol_get_users_courses($user->id, 'visible DESC,sortorder ASC', '*', false, 21);
				$modules = array();
				foreach($DB->get_records("modules") as $module)
					$modules[$module->name] = $module->id;
				
				bmu_write_xml_header();
				echo '<courses>';
				
				foreach($courses as $course) {
					$sections = get_all_sections($course->id);
					foreach($sections as $section) {
						if($section->sequence && $section->visible) {
							$sequences = explode(',', $section->sequence);
							//Create Directories for each Course
							if($sequences) {
								$coursedir = $exportdir."/".$course->fullname;
								if(!is_dir($coursedir))
									mkdir($coursedir);
							}
							foreach($sequences as $sequence) {
								$sequence = $DB->get_record('course_modules',array("id"=>$sequence,"visible"=>1));
								if($sequence) {
									switch($sequence->module) {
										// page
										case $modules['page']:
											$page = $DB->get_record('page',array("id"=>$sequence->instance));
											if($page) {
												//create html file
												$pagefile = fopen($coursedir."/".$page->name.".html", 'w');
												//write content to file
												fwrite($pagefile, $page->content);
												fclose($pagefile);
											}
											break;
										// resource
										case $modules['resource']:
											//get file
											$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
											if (!has_capability('mod/resource:view', $context))
												continue;
									
											$fs = get_file_storage();
											$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
											$file = reset($files);
    										unset($files);
    								
    										//copy file
    										$newfile=$coursedir."/".$file->get_filename();
    										$file->copy_content_to($newfile);
										
											break;
									}//end switch
								}
							}
						}
					}
					// ZIP
    				$zipname = bmu_valid_zip_name($user->username . "_" . $course->fullname . "_" . date("o_m_d_H_i") . ".zip");

					$zipfiles = array();
					$zipfiles[] = $coursedir;
					
    				// zip all the files:
    				zip_files($zipfiles, $zipdir . $zipname);
    				
    				echo '<course>';
						echo '<name>'.$course->fullname.'</name>';
						echo '<url>'.$CFG->wwwroot . '/blocks/backmeup/portfoliofile.php/' . bmu_zip_area_name() . '/' . $zipname.'</url>';
					echo '</course>';

				}
				echo '</courses>';
				remove_dir($exportdir);	
			}
			
		}
		else
			bmu_write_auth_xml(false);

	}else{
		bmu_write_auth_xml(false);
	}
function bmu_check_login($uname, $pword) {
	global $user,$DB;
	$conditions = array("username" => $uname,"password" => $pword);
	return ($user = $DB->get_record("user", $conditions)) ? true : false;
}
function bmu_write_xml_header() {
	header ("Content-Type:text/xml");

	echo '<?xml version="1.0" encoding="UTF-8" ?>'."\r\n";
}
function bmu_write_auth_xml($valid){
	
	bmu_write_xml_header();
	
	$inhalt='<result>';
	if($valid)
		$inhalt.='true';
	else
		$inhalt.='false';
	$inhalt.='</result> '."\r\n";
	echo $inhalt;

}
function bmu_data_file_area_name() {
    global $user;
    return "bmu/temp/exportdata/{$user->username}_".date("o_m_d H_i");
}
function bmu_zip_area_name() {
    global $user;
    return "bmu/temp/zip/{$user->username}";
}
function bmu_valid_zip_name($zipname) {
	$zipname = str_replace(" ","",$zipname);
	$zipname = str_replace("#","",$zipname);
	return $zipname;
}
?>