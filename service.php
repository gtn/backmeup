<?php
require_once dirname(__FILE__) . '/inc.php';
require_once dirname(__FILE__) . '/lib/lib.php';
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
			//create directory for the temp files
			$tempdir = bmu_data_file_area_name(); //relative path
			$tempdir_absolute = make_upload_directory($tempdir); //absolute path

			remove_dir($tempdir_absolute, true);

			$courses = enrol_get_users_courses($user->id, 'visible DESC,sortorder ASC', '*', false, 21);
			$modules = bmu_get_supported_modules();

			Header('Content-type: text/xml');
			$xml = new SimpleXMLElement("<backmeup></backmeup>");
			$xml_courses = $xml->addChild("courses");
			//scorm manifest
			$scorm_manifest = scorm_create_manifest();
			$scorm_courses = $scorm_manifest->addChild('organizations');
			$resources = $scorm_manifest->addChild('resources');
			foreach($courses as $course) {
				$xml_course = $xml_courses->addChild("course");
				$xml_course->addAttribute("id", $course->id);
				$xml_course->addAttribute("name", $course->fullname);
				
				$scorm_course = $scorm_courses->addChild("organization");
				$scorm_course->addAttribute('identifier', 'COURSE-'.$course->id);
				$scorm_course->addChild('title',$course->fullname);
				
				
				$sections = get_all_sections($course->id);
				foreach($sections as $section) {
					$xml_section = $xml_course->addChild("section");
					$xml_section->addAttribute("id", $section->id);
					$xml_section->addAttribute("name", get_section_name($course, $section));
					$xml_section->addAttribute("summary", strip_tags($section->summary));
					
					$scorm_section = scorm_create_item($scorm_course, 'ITEM-section-'.$course->id.'-'.$section->id, get_section_name($course,$section));
					if($section->sequence && $section->visible) {
						$sequences = explode(',', $section->sequence);
						//Create Directories for each Course
						if($sequences) {
							$coursedir = $tempdir_absolute."/".filenameReplaceBadChars($course->fullname);
							if(!is_dir($coursedir))
								mkdir($coursedir);
							$portfoliofile = $CFG->wwwroot . '/blocks/backmeup/portfoliofile.php/' . $tempdir . '/' . rawurlencode(filenameReplaceBadChars($course->fullname));
						}
						foreach($sequences as $sequence) {
							$sequence = $DB->get_record('course_modules',array("id"=>$sequence,"visible"=>1));
							if($sequence && bmu_sequence_available($sequence, $course->id) && in_array($sequence->module,$modules)) {
								$xml_sequence = $xml_section->addChild("sequence");
								$xml_sequence->addAttribute("id", $sequence->id);
								$xml_sequence->addChild("indent",$sequence->indent);
								
								switch($sequence->module) {
									// url
									case $modules['url']:
										$url = $DB->get_record('url',array("id"=>$sequence->instance));
										if($url) {
											//create html file
											$pagefile = fopen($coursedir."/".filenameReplaceBadChars($url->name).".html", 'w');
											//write content to file
											fwrite($pagefile, "<a href='".$url->externalurl."'>".$url->externalurl."</a>");
											fclose($pagefile);
											$xml_sequence->addChild("name",$url->name);
											$xml_sequence->addChild("type","url");
											$xml_sequence->addChild("intro",$url->intro);
											$xml_data = $xml_sequence->addChild("data");
											$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(filenameReplaceBadChars($url->name)).'.html');
											$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$url->timemodified));
											$xml_file->addAttribute("mime","text/html");
											
											scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($url->name).".html");
											$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $url->name,'RES-'.$course->id.'-'.$sequence->id);
										}
										break;
										// page
									case $modules['page']:
										$page = $DB->get_record('page',array("id"=>$sequence->instance));
										if($page) {
											bmu_create_html_file($coursedir,$page->name,$page->content);
												
											$xml_sequence->addChild("name",$page->name);
											$xml_sequence->addChild("type","page");
											$xml_sequence->addChild("intro",$page->intro);
											$xml_data = $xml_sequence->addChild("data");
											$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(filenameReplaceBadChars($page->name)).'.html');
											$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$page->timemodified));
											$xml_file->addAttribute("mime","text/html");
											
											scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($page->name).".html");
											$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $page->name,'RES-'.$course->id.'-'.$sequence->id);
										}
										break;
										// assign
									case $modules['assign']:
										$assign = $DB->get_record('assign',array("id"=>$sequence->instance));
										$submission = $DB->get_record('assign_submission',array("userid"=>$user->id,"assignment"=>$assign->id));
										if($submission) {
											$xml_sequence->addChild("name",$assign->name);
											$xml_sequence->addChild("type","assign");
											$xml_sequence->addChild("intro",$assign->intro);
											$xml_data = $xml_sequence->addChild("data");
												
											// check if files are submitted
											$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
											$fs = get_file_storage();
											$files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files');

											foreach($files as $file) {
												if($file->get_userid() == $user->id && $file->get_filename() != '.') {
													//copy file
													$newfile=$coursedir."/".$file->get_filename();
													$file->copy_content_to($newfile);

													$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode($file->get_filename()));
													$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$submission->timemodified));
													$xml_file->addAttribute("mime", $file->get_mimetype());
													
													scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid(), "/".filenameReplaceBadChars($course->fullname)."/".$file->get_filename());
													$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequencefile-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid(), $file->get_filename(),'RES-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid());
												}
											}
												
											// check if online-texts are submitted
											if($onlinetext = $DB->get_record('assignsubmission_onlinetext',array("assignment"=>$assign->id,"submission"=>$submission->id))) {
												bmu_create_html_file($coursedir,$assign->name,$onlinetext->onlinetext);

												$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode(filenameReplaceBadChars($assign->name)).'.html');
												$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$submission->timemodified));
												$xml_file->addAttribute("mime", "text/html");
												
												scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($assign->name).".html");
												$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $assign->name,'RES-'.$course->id.'-'.$sequence->id);
											}
										}
										break;
										// resource
									case $modules['resource']:
										//get resource
										$resource = $DB->get_record('resource',array("id"=>$sequence->instance));
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

										$xml_sequence->addChild("name",$resource->name);
										$xml_sequence->addChild("type","resource");
										$xml_sequence->addChild("intro",$resource->intro);
										$xml_data = $xml_sequence->addChild("data");
										$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode($file->get_filename()));
										$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$resource->timemodified));
										$xml_file->addAttribute("mime", $file->get_mimetype());
										
										scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".$file->get_filename());
										$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $file->get_filename(),'RES-'.$course->id.'-'.$sequence->id);
										
										break;
									case $modules['folder']:
										$folder = $DB->get_record('folder',array("id"=>$sequence->instance));

										$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
										$dir = $fs->get_area_tree($context->id, 'mod_folder', 'content', 0);

										$xml_sequence->addChild("name",filenameReplaceBadChars($folder->name));
										$xml_sequence->addChild("type","folder");
										$xml_sequence->addChild("intro",$folder->intro);
										$xml_data = $xml_sequence->addChild("data");
										
										$scorm_folder = scorm_create_item($scorm_section, 'ITEM-folder-'.$course->id.'-'.$folder->id, filenameReplaceBadChars($folder->name));
										
										xmllize_tree($xml_data,$dir,$coursedir,$portfoliofile,$folder->name,$scorm_folder,$course,$resources);

										break;
								}//end switch
							}
						}
					}
				}
			}
			//scorm file erstellen
			bmu_create_scorm_file($tempdir_absolute,$scorm_manifest);
			
			$xml_scorm = $xml->addChild("scorm");
			$portfoliofile = $CFG->wwwroot . '/blocks/backmeup/portfoliofile.php/' . $tempdir . '/';
			$xml_scorm->addChild("file",$portfoliofile."imsmanifest.xml");
			$xml_scorm->addChild("file",$portfoliofile."adlcp_rootv1p2.xsd");
			$xml_scorm->addChild("file",$portfoliofile."ims_xml.xsd");
			$xml_scorm->addChild("file",$portfoliofile."imscp_rootv1p1p2.xsd");
			$xml_scorm->addChild("file",$portfoliofile."imsmd_rootv1p2p1.xsd");
			echo $xml->asXML();
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
function bmu_write_auth_xml($valid){
	Header('Content-type: text/xml');
	$xml = new SimpleXMLElement("<result>".var_export($valid, true)."</result>");
	echo $xml->asXML();
}
function bmu_get_supported_modules() {
	global $DB;
	$modules=array();
	foreach($DB->get_records("modules") as $module)
		if(in_array($module->name,array("url","page","resource","folder","assign")))
			$modules[$module->name] = $module->id;
	
	return $modules;
}
function bmu_data_file_area_name() {
	global $user;
	return "bmu/temp/exportdata/{$user->username}_".date("o_m_d_H_i");
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
function bmu_sequence_available($item, $courseid) {
	global $CFG,$user;
	$modcontext = get_context_instance(CONTEXT_MODULE, $item->id);
	$available = true;
	// Test dates
	if ($item->availablefrom) {
		if (time() < $item->availablefrom) {
			return false;
		}
	}

	if ($item->availableuntil) {
		if (time() >= $item->availableuntil) {
			return false;
		}
	}
	if (!$item->visible and
			!has_capability('moodle/course:viewhiddenactivities', $modcontext, $user->id)) {
		return false;
	} else if (!empty($CFG->enablegroupmembersonly) and !empty($item->groupmembersonly)
			and !has_capability('moodle/site:accessallgroups', $modcontext, $user->id)) {
		// If the activity has 'group members only' and you don't have accessallgroups...
		$groups = groups_get_user_groups($courseid, $user->id);
		if (!isset($groups[$item->groupingid])) {
			return false;
		}
	}
	return true;
}
function xmllize_tree($parent_element,$dir,$coursedir,$portfoliofile,$foldername,$parent_scorm,$course,$resources) {
	$newfile_dir=$coursedir."/".rawurldecode(filenameReplaceBadChars($foldername,true))."/";
	if(!is_dir($newfile_dir))
		mkdir($newfile_dir);
	foreach ($dir['subdirs'] as $subdir) {
		
		$scorm_subfolder = scorm_create_item($parent_scorm, 'ITEM-subfolder-'.$course->id, rawurlencode(filenameReplaceBadChars($subdir['dirname'])));
		xmllize_tree($parent_element, $subdir, $coursedir, $portfoliofile,rawurlencode(filenameReplaceBadChars($foldername))."/".rawurlencode(filenameReplaceBadChars($subdir['dirname'])),$scorm_subfolder,$course,$resources);
	}
	foreach ($dir['files'] as $file) {
		//copy file
		$newfile = $newfile_dir . $file->get_filename();
		$file->copy_content_to($newfile);
		$url = $portfoliofile ."/". rawurlencode(filenameReplaceBadChars($foldername)) . "/" .rawurlencode($file->get_filename());
		$xml_file = $parent_element->addChild("file",$url);
		$xml_file->addAttribute("path",$foldername);
		$xml_file->addAttribute("modified", date("d.m.Y H:i:s",$file->get_timemodified()));
		$xml_file->addAttribute("mime",$file->get_mimetype());
		
		scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$file->get_id(), "/".filenameReplaceBadChars($course->fullname)."/".$foldername."/".$file->get_filename());
		$scorm_sequence = scorm_create_item($parent_scorm, 'ITEM-folderfile-'.$course->id.'-'.$file->get_id(), $file->get_filename(),'RES-'.$course->id.'-'.$file->get_id());
		
	}
}
function bmu_create_html_file($path,$filename,$content) {
	//create html file
	$pagefile = fopen($path."/".filenameReplaceBadChars($filename).".html", 'w');
	//write content to file
	fwrite($pagefile, utf8_decode($content));
	fclose($pagefile);
}
function bmu_create_scorm_file($path,$xml) {
	
	// copy all necessary files
	copy("scorm/adlcp_rootv1p2.xsd", $path . "/adlcp_rootv1p2.xsd");
	copy("scorm/ims_xml.xsd", $path . "/ims_xml.xsd");
	copy("scorm/imscp_rootv1p1p2.xsd", $path . "/imscp_rootv1p1p2.xsd");
	copy("scorm/imsmd_rootv1p2p1.xsd", $path . "/imsmd_rootv1p2p1.xsd");
	
	$xmlfile = fopen($path."/imsmanifest.xml","w");
	$xml_stringcontent = $xml->asXML();
	$xml_stringcontent = str_replace("adlcp=", "xmlns:adlcp=", $xml_stringcontent);
	$xml_stringcontent = str_replace("xsi", "xmlns:xsi", $xml_stringcontent);
	$xml_stringcontent = str_replace('schemaLocation=""', 'xsi:schemaLocation="http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd
					  http://www.imsglobal.org/xsd/imsmd_rootv1p2p1 imsmd_rootv1p2p1.xsd
					  http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd"', $xml_stringcontent);
	$xml_stringcontent = str_replace('<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-8"?>', $xml_stringcontent);
	$xml_stringcontent = str_replace('scormtype=','adlcp:scormtype=',$xml_stringcontent);
	fwrite($xmlfile,$xml_stringcontent);
	fclose($xmlfile);
}
function scorm_create_ressource($resources, $ridentifier, $filename) {
	// at an external ressource no file is needed inside resource
	$resource = $resources->addChild('resource');
	$resource->addAttribute('identifier', $ridentifier);
	$resource->addAttribute('type', 'webcontent');
	$resource->addAttribute('adlcp:scormtype', 'asset');
	$resource->addAttribute('href', $filename);
	$file = $resource->addChild('file');
	$file->addAttribute('href', $filename);
	return true;
}

function scorm_create_item($pitem, $identifier, $titletext, $residentifier = '') {
	// at an external ressource no file is needed inside resource
	$item = $pitem->addChild('item');
	$item->addAttribute('identifier', $identifier);
	$item->addAttribute('isvisible', 'true');
	if ($residentifier != '') {
		$item->addAttribute('identifierref', $residentifier);
	}
	$title = $item->addChild('title',$titletext);
	return $item;
}
function scorm_spch($text) {
	return htmlentities($text, ENT_QUOTES, "UTF-8");
}

function scorm_spch_text($text) {
	$text = htmlentities($text, ENT_QUOTES, "UTF-8");
	$text = str_replace('&amp;', '&', $text);
	$text = str_replace('&lt;', '<', $text);
	$text = str_replace('&gt;', '>', $text);
	$text = str_replace('&quot;', '"', $text);
	return $text;
}

function scorm_titlespch($text) {
	return clean_param($text, PARAM_ALPHANUM);
}
function scorm_create_manifest() {
	global $user;
	$manifest = new SimpleXMLElement('<manifest></manifest>');
	$manifest->addAttribute('identifier', $user->username . 'Export');
	$manifest->addAttribute('version', '1.1');
	$manifest->addAttribute('xmlns', 'http://www.imsproject.org/xsd/imscp_rootv1p1p2');
	$manifest->addAttribute('xmlns:adlcp', 'http://www.adlnet.org/xsd/adlcp_rootv1p2');
	$manifest->addAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
	$manifest->addAttribute('xsi:schemaLocation', '');
	return $manifest;
}
?>