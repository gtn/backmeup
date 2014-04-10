<?php
class block_backmeup extends block_base {
	public function init() {
		$this->title = get_string('pluginname', 'block_backmeup');
	}

	public function cron() {
		mtrace( "BACKMEUP CRON RUNNING" );
			
		global $CFG;
			
		//path to directory to scan
		$directory = $CFG->dataroot."/bmu/temp/exportdata/";
			
		//get all files in specified directory
		$files = glob($directory . "*",GLOB_ONLYDIR);
			
		//print each file name
		foreach($files as $folder)
		{
			if(filemtime($file) < time() - 24 * 60 * 60) {
				mtrace(filemtime($folder));
				remove_dir($folder,true);
				rmdir($folder);
			}
		}

		return true;
	}
}
?>