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
		
		// Import issues from oldest to newest
		$importDirectories = array();
		$directoryHandle = opendir($directoryName);
		while ($importDirectories[] = readdir($directoryHandle));
		sort($importDirectories, SORT_NATURAL);
		closedir($directoryHandle);
		
		foreach ($importDirectories as $entry) {
			$curDirectoryPath = $directoryName . "/" . $entry;
			// We have a directory, but not a hidden one or . or ..
			var_dump($entry . ':   '. !preg_match('/^\./', $entry));
			if (is_dir($curDirectoryPath) && !preg_match('/^\./', $entry)) {
				// We have a directory of XML files
				if (preg_match('/\.xml$/', $curDirectoryPath)) {
					// Set corresponding directories for PDF and peripheral files
					$curDirectoryBasePath = dirname($curDirectoryPath);
					$curDirectoryBaseName = basename($curDirectoryPath, '.xml');
					$pdfDirectoryPath = $curDirectoryBasePath . '/' . $curDirectoryBaseName . '.pdf';
					$peripheralDirectoryPath = $curDirectoryBasePath . '/' . $curDirectoryBaseName . '.peripherals';
					$htmlDirectoryPath = $curDirectoryPath;
					$imageDirectoryPath = $curDirectoryBasePath . '/' . $curDirectoryBaseName . '.graphics';
					// Process all article XML files
					// We assume that files in the directory are read in alpha-numerical order, i.e.
					// corresponding to page numbers of published articles, i.e. article order in the TOC
					$xmlDirectoryHandle = opendir($curDirectoryPath);
					$curIssueId = 0;
					$featuresSectionId = null;
					$departmentsSectionId = null;
					$correctionSections = array();
					$allSections = array();
					
					// Import articles from first to last in TOC
					$xmlFiles = array();
					while ($xmlFiles[] = readdir($xmlDirectoryHandle));
					sort($xmlFiles, SORT_NATURAL);
					closedir($xmlDirectoryHandle);
					
					foreach ($xmlFiles as $xmlEntry) {
						// Set full path to article XML file
						$xmlArticleFile = $curDirectoryPath . "/" . $xmlEntry;
						
						if (is_file($xmlArticleFile)) {
							$xmlArticle = $this->getDocument($xmlArticleFile);

							if ($xmlArticle) {
								$returner = NlmImportDom::importArticle($journal, $user, $xmlArticle, $xmlEntry, $pdfDirectoryPath, $htmlDirectoryPath, $imageDirectoryPath, $peripheralDirectoryPath);
								if ($returner && is_array($returner)) {
									$issue = $returner['issue'];
									$section = $returner['section'];
									$article = $returner['article'];
									
									$issueId = $issue->getId();
									$sectionId = $section->getId();
									
									if ($curIssueId != $issueId) {
										$curIssueId = $issueId;
										$issueTitle = $issue->getIssueIdentification();
										echo __('plugins.importexport.nlm.issueImport', array('title' => $issueTitle)) . "\n\n";
									}
									
									if (!in_array($sectionId, $allSections)) {
										
										$regularSection = true;
										
										// CRL News: Features should be first; Departments and Corrections should always be last
										if ($journal->getPath() == 'crlnews') {
											$sectionTitle = $section->getTitle($journal->getPrimaryLocale());
											if (preg_match("/Departments/i", $sectionTitle)) {
												$departmentsSectionId = $sectionId;
												$regularSection = false;
											}
											if (preg_match("/^Features$/i", $sectionTitle)) {
												$featuresSectionId = $sectionId;
												$regularSection = false;
											}
											if (preg_match("/Correction(.*)/i", $sectionTitle)) {
												if (!in_array($sectionId, $correctionSections)) $correctionSections[] = $sectionId;
												$regularSection = false;
											}
										}
										
										// Add section to list of sections
										if ($regularSection) {
											$allSections[] = $sectionId;
										}
										
										$sectionTitle = $section->getLocalizedTitle();
										echo __('plugins.importexport.nlm.sectionImport', array('title' => $sectionTitle)) . "\n\n";
									}
									
									$articleTitle = $article->getLocalizedTitle();
									echo __('plugins.importexport.nlm.articleImported', array('title' => $articleTitle)) . "\n\n";
								}
							}
						}
					}
					
					// Add default custom section ordering for TOC
					$sectionDao =& DAORegistry::getDAO('SectionDAO');
					$numSections = 0;
					
					// CRL News: Features should always be first in TOC; Departments and Corrections should always be last
					if ($featuresSectionId) {
						$numSections++;
						$sectionDao->insertCustomSectionOrder($issueId, $featuresSectionId, $numSections);
					}
					
					// Add each section in page number order for articles
					foreach ($allSections as $curSectionId) {
						$numSections++;
						$sectionDao->insertCustomSectionOrder($issueId, $curSectionId, $numSections);
					}
					
					// CRL News: Departments and Corrections should always be last
					if ($departmentsSectionId) {
						$numSections++;
						$sectionDao->insertCustomSectionOrder($issueId, $departmentsSectionId, $numSections);
					}
					foreach ($correctionSections as $correctionSectionId) {
						$numSections++;
						$sectionDao->insertCustomSectionOrder($issueId, $correctionSectionId, $numSections);
					}
					
				}
			}
		}
		// Setup default custom issue order
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issueDao->setDefaultCustomIssueOrders($journal->getId());
		
		exit();
	}
	
	/**
	 * Display the command-line usage information
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.nlm.cliUsage', array(
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