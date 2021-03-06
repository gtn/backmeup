<?php

require_once dirname(__FILE__) . '/inc.php';
require_once dirname(__FILE__) . '/lib/lib.php';
require_once $CFG->dirroot.'/lib/moodlelib.php';
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/resource/lib.php');
require_once($CFG->dirroot.'/mod/wiki/locallib.php');

global $DB,$user,$COURSE,$CFG;

$uname = optional_param('username', 0, PARAM_USERNAME);  //100
$pword = optional_param('password', 0, PARAM_RAW);	//32
$action = optional_param('action',"auth",PARAM_ALPHANUM);
$folder = optional_param('folder','',PARAM_PATH);

if($folder != '' && strstr($folder, "bmu/temp/exportdata") == 0) {
	if(file_exists($CFG->dataroot."/".$folder)) {
		remove_dir($CFG->dataroot."/".$folder,true);
		rmdir($CFG->dataroot."/".$folder);
	}
}
else if ($uname!="0" && $pword!="0"){

	if ($user = authenticate_user_login($uname,$pword)){
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
				$scorm_course->addChild('title',bmu_prepare_xml_string($course->fullname));

				$modinfo = get_fast_modinfo($course);
				
				$sections = $modinfo->get_section_info_all();
//				$sections = get_all_sections($course->id);
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
							
							/*if(!empty($section->summary)) {
							
								$images = bmu_get_images($section->summary);
								foreach($images as $filename => $url) {
									if(strpos($url, $CFG->wwwroot) === 0) {
										$fs = get_file_storage();
										$urlparts = explode("/",$url);
										if($file = $fs->get_file($wikicontext->id, 'course', 'section', $urlparts[8], "/", $filename)) {
											file_put_contents($coursedir."/".$filename,$file->get_content());
											$newimages[] = $filename;
										}
									} else {
										if($file = @file_get_contents($url)) {
											file_put_contents($coursedir."/".$filename,$file);
											$newimages[] = $filename;
										}
									}
									$section->summary = str_replace($url, $filename, $section->summary);
								}
							
								bmu_create_html_file($coursedir,filenameReplaceBadChars(get_section_name($course, $section)),$section->summary);
								
								$xml_summary = $xml_section->addChild("summary");
								$xml_data = $xml_summary->addChild("data");
								foreach($newimages as $newimg) {
									$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(rawurldecode($newimg)));
								}
								$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode(filenameReplaceBadChars(get_section_name($course, $section))).".html");
							
								scorm_create_ressource($resources, 'RES-'.$course->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars(get_section_name($course, $section)).".html");
								$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id, get_section_name($course, $section),'RES-'.$course->id);
							}*/
						}
						
						foreach($sequences as $sequence) {
							$sequence = $DB->get_record('course_modules',array("id"=>$sequence,"visible"=>1));
							if($sequence && bmu_sequence_available($sequence, $course->id) && in_array($sequence->module,$modules)) {
								$xml_sequence = $xml_section->addChild("sequence");
								$xml_sequence->addAttribute("id", $sequence->id);
								$xml_sequence->addChild("indent",$sequence->indent);

								switch($sequence->module) {
									// wiki
									case $modules['wiki']:
										$wiki = wiki_get_wiki($sequence->instance);
										$cm = get_coursemodule_from_instance("wiki", $sequence->instance);
										//$wikicontext = get_context_instance(CONTEXT_MODULE, $cm->id);
										$wikicontext = context_module::instance($cm->id);
										$wikicontent="";
										$newimages=array();
										$subwikis = wiki_get_subwikis($sequence->instance);
										foreach($subwikis as $subwiki) {
											$pages = $DB->get_records('wiki_pages',array("subwikiid"=>$subwiki->id));
											foreach($pages as $page) {

												$wikicontent .= '<h1 id="wiki_printable_title">' . $page->title . '</h1>';

												$version = wiki_get_current_version($page->id);
												$content = wiki_parse_content($version->contentformat, $version->content, array('printable' => true, 'swid' => $subwiki->id, 'pageid' => $page->id, 'pretty_print' => true));

												$wikicontent .= '<div id="wiki_printable_content">';
												$wikicontent .= $content['parsed_text'];
												$wikicontent .= '</div>';

												$wikicontent = file_rewrite_pluginfile_urls($wikicontent, 'pluginfile.php', $wikicontext->id, 'mod_wiki', 'attachments', $subwiki->id);

												$images = bmu_get_images($wikicontent);
												foreach($images as $filename => $url) {
													if(strpos($url, $CFG->wwwroot) === 0) {
														$fs = get_file_storage();
														$urlparts = explode("/",$url);
														if($file = $fs->get_file($wikicontext->id, 'mod_wiki', 'attachments', $urlparts[8], "/", $filename)) {
															file_put_contents($coursedir."/".$filename,$file->get_content());
															$newimages[] = $filename;
														}

													} else {
														if($file = @file_get_contents($url)) {
															file_put_contents($coursedir."/".$filename,$file);
															$newimages[] = $filename;
														}

													}
													$wikicontent = str_replace($url, $filename, $wikicontent);
												}
											}
										}
										bmu_create_html_file($coursedir,$wiki->name,$wikicontent,$wiki->intro);
										$xml_sequence->addChild("name",$wiki->name);
										$xml_sequence->addChild("type","Wiki");
										$xml_sequence->addChild("intro",$wiki->intro);
										$xml_data = $xml_sequence->addChild("data");

										foreach($newimages as $newimg) {
											$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(rawurldecode($newimg)));
										}

										$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(filenameReplaceBadChars($wiki->name)).'.html');
										$xml_file->addAttribute("modified", date("c",$wiki->timemodified));
										$xml_file->addAttribute("mime","text/html");

										scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($wiki->name).".html");
										$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $wiki->name,'RES-'.$course->id.'-'.$sequence->id);
										unset($wiki,$cm,$wikicontent,$wikicontext,$images,$pages,$subwikis);
										break;
										// url
									case $modules['url']:
										$url = $DB->get_record('url',array("id"=>$sequence->instance));
										if($url) {
											//create html file
											$urlcontent = "<a href='".$url->externalurl."'>".$url->externalurl."</a>";
											bmu_create_html_file($coursedir,filenameReplaceBadChars($url->name),$urlcontent,$url->intro);
											$xml_sequence->addChild("name",$url->name);
											$xml_sequence->addChild("type","Url");
											$xml_sequence->addChild("intro",$url->intro);
											$xml_data = $xml_sequence->addChild("data");
											$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(filenameReplaceBadChars($url->name)).'.html');
											$xml_file->addAttribute("modified", date("c",$url->timemodified));
											$xml_file->addAttribute("mime","text/html");

											scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($url->name).".html");
											$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $url->name,'RES-'.$course->id.'-'.$sequence->id);
										}
										unset($url);
										break;
										// page
									case $modules['page']:
										$page = $DB->get_record('page',array("id"=>$sequence->instance));
										if($page) {
											$cm = get_coursemodule_from_instance("page", $sequence->instance);
										//	$pagecontext = get_context_instance(CONTEXT_MODULE, $cm->id);
											$pagecontext = context_module::instance($cm->id);
											$page->content = file_rewrite_pluginfile_urls($page->content, 'pluginfile.php', $pagecontext->id, 'mod_page', 'content', 0);

											$images = bmu_get_images($page->content);
											$newimages = array();
											foreach($images as $filename => $url) {
												if(strpos($url, $CFG->wwwroot) === 0) {
													$fs = get_file_storage();
													$urlparts = explode("/",$url);
													if($file = $fs->get_file($pagecontext->id, 'mod_page', 'content', 0, "/", $filename)) {
														file_put_contents($coursedir."/".$filename,$file->get_content());
														$newimages[] = $filename;
													}
												} else {
													if($file = @file_get_contents($url)) {
														file_put_contents($coursedir."/".$filename,$file);
														$newimages[] = $filename;
													}
												}
												$page->content = str_replace($url, $filename, $page->content);
												
											}
											bmu_create_html_file($coursedir,$page->name,$page->content,$page->intro);

											$xml_sequence->addChild("name",$page->name);
											$xml_sequence->addChild("type","Page");
											$xml_sequence->addChild("intro",$page->intro);
											$xml_data = $xml_sequence->addChild("data");
											foreach($newimages as $newimg) {
												$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(rawurldecode($newimg)));
											}
											$xml_file = $xml_data->addChild("file",$portfoliofile.'/'.rawurlencode(filenameReplaceBadChars($page->name)).'.html');
											$xml_file->addAttribute("modified", date("c",$page->timemodified));
											$xml_file->addAttribute("mime","text/html");

											scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($page->name).".html");
											$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $page->name,'RES-'.$course->id.'-'.$sequence->id);
										}
										unset($page,$cm,$pagecontext,$images,$file,$filename);
										break;
										// assign
									case $modules['assign']:
										$assign = $DB->get_record('assign',array("id"=>$sequence->instance));
										$submission = $DB->get_record('assign_submission',array("userid"=>$user->id,"assignment"=>$assign->id));
										if($submission) {
											$xml_sequence->addChild("name",$assign->name);
											$xml_sequence->addChild("type","Assign");
											$xml_sequence->addChild("intro",$assign->intro);
											$xml_data = $xml_sequence->addChild("data");

											// check if files are submitted
											//$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
											//$context = context_module::instance_by_id($sequence->id);
												$context = context_module::instance($sequence->id);
											$fs = get_file_storage();
											$files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files');

											foreach($files as $file) {
												if($file->get_userid() == $user->id && $file->get_filename() != '.') {
													//copy file
													$newfile=$coursedir."/".$file->get_filename();
													$file->copy_content_to($newfile);

													$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode($file->get_filename()));
													$xml_file->addAttribute("modified", date("c",$submission->timemodified));
													$xml_file->addAttribute("mime", $file->get_mimetype());

													scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid(), "/".filenameReplaceBadChars($course->fullname)."/".$file->get_filename());
													$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequencefile-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid(), $file->get_filename(),'RES-'.$course->id.'-'.$sequence->id.'-'.$file->get_itemid());
												}
											}

											// check if online-texts are submitted
											if($onlinetext = $DB->get_record('assignsubmission_onlinetext',array("assignment"=>$assign->id,"submission"=>$submission->id))) {
												bmu_create_html_file($coursedir,$assign->name,$onlinetext->onlinetext);

												$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode(filenameReplaceBadChars($assign->name)).'.html');
												$xml_file->addAttribute("modified", date("c",$submission->timemodified));
												$xml_file->addAttribute("mime", "text/html");

												scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".filenameReplaceBadChars($assign->name).".html");
												$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $assign->name,'RES-'.$course->id.'-'.$sequence->id);
											}
										}
										unset($assign,$submission,$context,$files,$onlinetext);
										break;
										// resource
									case $modules['resource']:
										//get resource
										$resource = $DB->get_record('resource',array("id"=>$sequence->instance));
										//get file
										//$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
										$context = context_module::instance($sequence->id);
										
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
										$xml_sequence->addChild("type","Resource");
										$xml_sequence->addChild("intro",$resource->intro);
										$xml_data = $xml_sequence->addChild("data");
										$xml_file = $xml_data->addChild("file",$portfoliofile."/".rawurlencode($file->get_filename()));
										$xml_file->addAttribute("modified", date("c",$resource->timemodified));
										$xml_file->addAttribute("mime", $file->get_mimetype());

										scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$sequence->id, "/".filenameReplaceBadChars($course->fullname)."/".$file->get_filename());
										$scorm_sequence = scorm_create_item($scorm_section, 'ITEM-sequence-'.$course->id.'-'.$sequence->id, $file->get_filename(),'RES-'.$course->id.'-'.$sequence->id);

										unset($resource,$context,$files);
										break;
									case $modules['folder']:
										$folder = $DB->get_record('folder',array("id"=>$sequence->instance));

										//$context = get_context_instance(CONTEXT_MODULE, $sequence->id);
										$context = context_module::instance($sequence->id);
										
										$fs = get_file_storage();
										$dir = $fs->get_area_tree($context->id, 'mod_folder', 'content', 0);

										$xml_sequence->addChild("name",filenameReplaceBadChars($folder->name));
										$xml_sequence->addChild("type","Folder");
										$xml_sequence->addChild("intro",$folder->intro);
										$xml_data = $xml_sequence->addChild("data");

										$scorm_folder = scorm_create_item($scorm_section, 'ITEM-folder-'.$course->id.'-'.$folder->id, filenameReplaceBadChars($folder->name));

										xmllize_tree($xml_data,$dir,$coursedir,$portfoliofile,$folder->name,$scorm_folder,$course,$resources);
										unset($folder,$context,$dir);
										break;
									/*case $modules['forum']:
										 $forum = $DB->get_record('forum',array("id"=>$sequence->instance));
										$cm = get_coursemodule_from_instance("forum",$sequence->instance,$course->id);
										$context = get_context_instance(CONTEXT_MODULE, $cm->id);
										
										$discussions = $DB->get_records('forum_discussions',array('forum'=>$sequence->instance));
										ob_start();
										forum_print_latest_discussions($course,$forum,0,'plain');
										$ausgabe = ob_get_contents();
										ob_end_clean();
										echo $ausgabe;
										foreach($discussions as $discussion) {
										if(forum_user_can_see_discussion($forum, $discussion, $context, $user)) {
										//create html file
										$posts = $DB->get_records('forum_posts',array('discussion'=>$discussion->id));
										
										}
										}
										break;
										*/
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
			$xml_scorm->addChild("file",$portfoliofile."reload_scorm_editor.html");

			bmu_create_theme_files($tempdir_absolute);
			$xml_theme = $xml->addChild("theme");
			$xml_theme->addChild("file",$portfoliofile."theme/backmeup.css");
			$xml_theme->addChild("file",$portfoliofile."theme/bmu_logo.jpg");
			
			$xml_delete = $xml->addChild("delete");
			$xml_delete->addChild("file",$CFG->wwwroot . '/blocks/backmeup/service.php?folder='.$tempdir);
			
			echo $xml->asXML();
		}
			
	}
	else
		bmu_write_auth_xml(false);

}else{
	bmu_write_auth_xml(false);
}
function bmu_get_images($data) {
	$images = array();
	preg_match_all('/(img|src)=("|\')[^"\'>]+/i', $data, $media);
	unset($data);
	$data=preg_replace('/(img|src)("|\'|="|=\')(.*)/i',"$3",$media[0]);
	foreach($data as $url)
	{
		$info = pathinfo($url);
			
		if (isset($info['extension']) && $info['dirname']!=='.')
		{
			if ((strcasecmp($info['extension'],'jpg')==0) ||
					(strcasecmp($info['extension'],'jpeg')==0) ||
					(strcasecmp($info['extension'],'gif')==0) ||
					(strcasecmp($info['extension'],'png')==0)) {
				$filename = filenameReplaceBadChars($info['filename'].'.'.$info['extension']);
				$images[$filename] = $url;
			}
		}
	}
	return $images;
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
		if(in_array($module->name,array("url","page","resource","folder","assign","wiki")))
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
	//$modcontext = get_context_instance(CONTEXT_MODULE, $item->id);
	//$modcontext = context_module::instance_by_id($item->id);
	$modcontext = context_module::instance($item->id);
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
		$url = $portfoliofile ."/". filenameReplaceBadChars($foldername,true) . "/" .rawurlencode($file->get_filename());
		$url = str_replace(' ','%20', $url);
		$xml_file = $parent_element->addChild("file",$url);
		$xml_file->addAttribute("path",$foldername);
		$xml_file->addAttribute("modified", date("c",$file->get_timemodified()));
		$xml_file->addAttribute("mime",$file->get_mimetype());

		scorm_create_ressource($resources, 'RES-'.$course->id.'-'.$file->get_id(), "/".filenameReplaceBadChars($course->fullname)."/".$foldername."/".$file->get_filename());
		$scorm_sequence = scorm_create_item($parent_scorm, 'ITEM-folderfile-'.$course->id.'-'.$file->get_id(), $file->get_filename(),'RES-'.$course->id.'-'.$file->get_id());

	}
}
function bmu_create_file($path,$filename,$content) {
	//create html file
	$file = fopen($path."/".filenameReplaceBadChars($filename), 'w');
	//write content to file
	fwrite($file, utf8_decode($content));
	fclose($file);
}
function bmu_create_html_file($path,$filename,$content,$description="") {
	$htmlcontent = '<html>
	<head>
	<link rel="stylesheet" type="text/css" href="../theme/backmeup.css">
	</head>
	<body>
	<div id="backmeup">
	<div id="backmeupheader"><img src="../theme/bmu_logo.jpg" alt="backmeup" /></div>
	<div class="backmeupborder">
	<h1>'.$filename.'</h1>
	<div class="description">'.$description.'</div>
	<div class="content">'.$content.'</div>
	</div>
	</div>
	</body>
	</html>';
	bmu_create_file($path,$filename.".html",$htmlcontent);
}
function bmu_create_theme_files($path) {
	mkdir($path."/theme");
	// copy all necessary files
	copy("theme/backmeup.css", $path . "/theme/backmeup.css");
	copy("theme/bmu_logo.jpg", $path . "/theme/bmu_logo.jpg");
}
function bmu_create_scorm_file($path,$xml) {

	// copy all necessary files
	copy("scorm/adlcp_rootv1p2.xsd", $path . "/adlcp_rootv1p2.xsd");
	copy("scorm/ims_xml.xsd", $path . "/ims_xml.xsd");
	copy("scorm/imscp_rootv1p1p2.xsd", $path . "/imscp_rootv1p1p2.xsd");
	copy("scorm/imsmd_rootv1p2p1.xsd", $path . "/imsmd_rootv1p2p1.xsd");
	copy("scorm/reload_scorm_editor.html", $path . "/reload_scorm_editor.html");

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
function bmu_replace_umlaute($text) {
	$umlautArray = Array("/ä/","/ö/","/ü/","/Ä/","/Ö/","/Ü/","/ß/");
	$replaceArray = Array("ae","oe","ue","Ae","Oe","Ue","ss");
	return preg_replace($umlautArray , $replaceArray , $text);
}
function bmu_prepare_xml_string($text) {
	$characterArray = Array("&", "\"", "'", "<", ">");
	$replaceArray = Array(" ", " "," "," "," ");
	return str_replace($characterArray, $replaceArray, $text);
}
function scorm_create_item($pitem, $identifier, $titletext, $residentifier = '') {
	// at an external ressource no file is needed inside resource
	$item = $pitem->addChild('item');
	$item->addAttribute('identifier', $identifier);
	$item->addAttribute('isvisible', 'true');
	if ($residentifier != '') {
		$item->addAttribute('identifierref', $residentifier);
	}
	$title = $item->addChild('title',bmu_replace_umlaute($titletext));
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
function bmu_replace_img_urls($content) {

}
?>