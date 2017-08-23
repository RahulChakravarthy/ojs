<?php
/**
 * @file plugins/importexport/bpress/BPressImportPlugin.inc.php
 *
 * Copyright (c) 2017 Simon Fraser University Library
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class BpressImportPlugin
 * @ingroup plugins_importexport_nlm
 *
 * @brief NLM 2.3 XML import plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

class BPressImportPlugin extends ImportExportPlugin {	
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}
	
	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'BPressImportPlugin';
	}
	
	function getDisplayName() {
		return __('plugins.importexport.bpress.displayName');
	}
	
	function getDescription() {
		return __('plugins.importexport.bpress.description');
	}
	
	/**
	 * Execute import tasks using the command-line interface.
	 * @param $args Parameters to the plugin
	 */
	function executeCLI($scriptName, &$args) {
		
		if (sizeof($args) != 3) {
			$this->usage($scriptName);
			exit();
		}
		
		$journalPath = array_shift($args);
		$userName = array_shift($args);
		$directoryName = array_shift($args);
		
		if (!$journalPath || !$userName || !$directoryName) {
			$this->usage($scriptName);
			exit();
		}
		
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journal =& $journalDao->getByPath($journalPath);
		if (!$journal) {
			echo __('plugins.importexport.bpress.unknownJournal', array('journal' => $journalPath)) . "\n";
			exit();
		}
		
		$userDao = DAORegistry::getDAO('UserDAO');
		$user =& $userDao->getByUsername($userName);
		if (!$user) {
			echo __('plugins.importexport.bpress.unknownUser', array('username' => $userName)) . "\n";
			exit();
		}
		
		if (!file_exists($directoryName) && is_dir($directoryName) ) {
			echo __('plugins.importexport.bpress.directoryDoesNotExist', array('directory' => $directoryName)) . "\n";
			exit();
		}
		
		
		$this->import('BPressImportDom');
		echo 'Import Starting ...' . "\n";
		// Import issues from oldest to newest
		$importJournal = array();
		$journalHandler = opendir($directoryName);
		while ($importIssues[] = readdir($journalHandler));

		$allIssueIds = array();

		foreach ($importIssues as $issueName){ //Processes each issue in the journal folder
			if (preg_match('/^[a-zA-Z0-9\s]+$/', $issueName)){
				$issueName = $directoryName . $issueName;
				
				$importDirectories = array();
				$directoryHandle = opendir($issueName);
				while ($importDirectories[] = readdir($directoryHandle));
				sort($importDirectories, SORT_NATURAL);
				closedir($directoryHandle);
				
				$curIssueId = 0;
				$currSectionId = 0;
				$allSections = array();
				
				foreach ($importDirectories as $entry) { //Processes each submission in the issue folder
					$curDirectoryPath = $issueName . '/' . $entry;
					// We have a directory, but not a hidden one or . or ..
					
					if (is_dir($curDirectoryPath) && !preg_match('/^\./', $entry) && $entry) {
						// Process all article XML files
						// We assume that files in the directory are read in alpha-numerical order, i.e.
						// corresponding to page numbers of published articles, i.e. article order in the TOC
						$submissionHandler = opendir($curDirectoryPath);
						$submissionFiles = array();
						while ($submissionFiles[] = readdir($submissionHandler));
						$xmlFiles = array();
						$pdfFiles = array();
						foreach($submissionFiles as $file){
							if (preg_match('/\.xml$/', $file)){
								$xmlFiles[] = $file;
							} elseif (preg_match('/\.pdf$/', $file)){
								$pdfFiles[] = $file;
							}
						}			
						closedir($submissionHandler);
						
						foreach ($xmlFiles as $xmlEntry) {
							// Set full path to article XML file
							$xmlArticleFile = $curDirectoryPath . "/" . $xmlEntry;
							
							//This only works for one pdf path, scale it to work with multiple pdf paths 
							$pdfArticleFile = $curDirectoryPath . "/" . $pdfFiles[0];
							
							if (is_file($xmlArticleFile)) {
								$xmlArticle = $this->getDocument($xmlArticleFile);
									if ($xmlArticle) {
										$number = null;
										preg_match_all('/\d+/',basename(dirname(dirname($xmlArticleFile))), $number);
										$number = array_shift(array_shift($number));
										
										$volume = null;
										preg_match_all('/\d+/',basename(dirname(dirname(dirname($xmlArticleFile)))), $volume);
										$volume = array_shift(array_shift($volume));
										
										$returner = BPressImportDom::importArticle($journal, $user, $xmlArticle, $xmlEntry, $pdfArticleFile, $curDirectoryPath, $curDirectoryPath, $curDirectoryPath, $volume, $number);
									if ($returner && is_array($returner)) {
										$issue = $returner['issue'];
										$section = $returner['section'];
										$article = $returner['article'];
										
										$issueId = $issue->getId();
										$sectionId = $section->getId();
										
										if ($curIssueId != $issueId) {
											$allIssues[] = $issueId;
											$curIssueId = $issueId;
											$issueTitle = $issue->getIssueIdentification();
											echo __('plugins.importexport.bpress.issueImport', array('title' => $issueTitle)) . "\n\n";
										}
										if ($currSectionId != $sectionId){
											$currSectionId = $sectionId;
											$sectionTitle = $section->getLocalizedTitle();
											echo __('plugins.importexport.bpress.sectionImport', array('title' => $sectionTitle)) . "\n\n";
										}
										
										if (!in_array($sectionId, $allSections)) {
											$allSections[] = $sectionId;
										}
										
										$articleTitle = $article->getLocalizedTitle();
										echo __('plugins.importexport.bpress.articleImported', array('title' => $articleTitle)) . "\n\n";
									}
								}
							}
						}
					}
				}
				
				// Add default custom section ordering for TOC
				$sectionDao =& DAORegistry::getDAO('SectionDAO');
				$numSections = 0;
				
				// Add each section in page number order for articles
				foreach ($allSections as $curSectionId) {
					$sectionDao->insertCustomSectionOrder($issueId, $curSectionId, ++$numSections);
				}
			}
		}
		
		// Setup default custom issue order
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issueDao->setDefaultCustomIssueOrders($journal->getId());
		echo 'Import Complete...';
		exit();
	}
	
	/**
	 * Display the command-line usage information
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.bpress.cliUsage', array(
				'scriptName' => $scriptName,
				'pluginName' => $this->getName()
		)) . "\n";
	}
	
	function &getDocument($fileName) {
		$parser = new XMLParser();
		$returner =& $parser->parse($fileName);
		return $returner;
	}
}
?>