<?php
/**
 * @file plugins/importexport/bpress/BPressImportDom.inc.php
 *
 * Copyright (c) 2017 Simon Fraser University Library
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class Bpress
 * @ingroup plugins_importexport_bpress
 *
 * @brief BPress XML import DOM functions
 */
import('lib.pkp.classes.xml.XMLCustomWriter');

class NlmImportDom {
	
	function importArticle(&$journal, &$user, &$xmlArticle, $articleFileName, $pdfPath, $htmlPath, $imagePath, $peripheralPath) {
		$frontNode = $xmlArticle->getChildByName('front');
		$articleNode = $frontNode->getChildByName('article-meta');
		$dependentItems = array();
		$errors = array();
		
		$result = NlmImportDom::handleArticleNode($journal, $user, $xmlArticle, $articleNode, $articleFileName, $pdfPath, $htmlPath, $imagePath, $peripheralPath, $dependentItems, $errors);
		if (!$result) {
			NlmImportDom::cleanupFailure($dependentItems);
		}
		return $result;
	}
	
	/**
	 * Handle the Article node and build the article object found in the XML.
	 * @param Journal $journal
	 * @param User $user
	 * @param DOMNode $articleNode
	 * @param string $pdfPath
	 * @param string $peripheralPath
	 * @param array $dependentItems
	 * @param array $errors
	 * @return Article the imported article
	 */
	function handleArticleNode(&$journal, &$user, &$xmlArticleDOM, &$articleNode, $articleFileName, $pdfPath, $htmlPath, $imagePath, $peripheralPath, &$dependentItems, &$errors) {
		
		if (!$journal || !$user || !$articleNode || !$articleFileName || !$pdfPath || !$peripheralPath) {
			return null;
		}
		
		// Process issue first
		$issue =& NlmImportDom::handleIssue($journal, $articleNode, $peripheralPath, $dependentItems, $errors);
		
		// Ensure we have an issue
		if (!$issue) {
			$articleTitle = NlmImportDom::getArticleTitle($articleNode);
			$errors[] = array('plugins.importexport.nlm.import.error.missingIssue', array('title' => $articleTitle));
			return null;
		}
		
		// Process article section
		$section =& NlmImportDom::handleSection($journal, $articleNode, $dependentItems, $errors);
		
		// Ensure we have a section
		if (!$section) {
			$articleTitle = NlmImportDom::getArticleTitle($articleNode);
			$errors[] = array('plugins.importexport.nlm.import.error.missingSection', array('title' => $articleTitle));
			return null;
		}
		
		// We have an issue and section, we can now process the article
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$primaryLocale = $journal->getPrimaryLocale();
		
		import('classes.article.Article');
		$article = new Article();
		$article->setLocale($primaryLocale);
		$article->setLanguage('en');
		$article->setJournalId($journal->getId());
		$article->setSectionId($section->getId());
		$article->setUserId($user->getId());
		$article->setStatus(STATUS_PUBLISHED);
		$article->setSubmissionProgress(0);
		$article->setDateSubmitted($issue->getDatePublished());
		$article->stampStatusModified();
		
		// Handle pages
		$firstPageNode = $articleNode->getChildByName('fpage');
		$lastPageNode = $articleNode->getChildByName('lpage');
		
		if ($firstPageNode && $lastPageNode) {
			$article->setPages($firstPageNode->getValue() . '-' . $lastPageNode->getValue());
		} elseif ($firstPageNode) {
			$article->setPages($firstPageNode->getValue());
		}
		
		// Look for a CRL DOI and assign it to the article
		$articleDOI = null;
		$publisherDOI = null;
		for ($index=0; ($node = $articleNode->getChildByName('article-id', $index)); $index++) {
			$articleIdType = $node->getAttribute('pub-id-type');
			if ($articleIdType == 'doi') {
				$articleDOI = $node->getValue();
				$anotherArticle = $publishedArticleDao->getPublishedArticleByPubId('doi', $articleDOI, $journal->getId());
				if ($anotherArticle) {
					$errors[] = array('plugins.importexport.nlm.import.error.duplicatePublicArticleId', array('otherArticleTitle' => $anotherArticle->getLocalizedTitle()));
					$hasErrors = true;
				}
			} elseif ($articleIdType == 'publisher-id') {
				$publisherDOI = $node->getValue();
				$matches1 = array();
				$matches2 = array();
				if (!preg_match("/crl/", $publisherDOI, $matches1) && !preg_match("/^07/", $publisherDOI, $matches2)) {
					if ($firstPageNode) {
						$publisherDOI = "10.5860/crl." . $issue->getVolume() . "." . $issue->getNumber() . "." . $firstPageNode->getValue();
					}
				}
			}
		}
		
		if ($articleDOI) {
			$article->setStoredPubId('doi', $articleDOI);
		} elseif ($publisherDOI) {
			$matches = array();
			if (!preg_match("/10\.5860/", $publisherDOI, $matches)) {
				$publisherDOI = "10.5860/" . $publisherDOI;
			}
			$article->setStoredPubId('doi', $publisherDOI);
		} else {
			$article->setStoredPubId('doi', '');
		}
		
		// Get article title and (optionally) subtitle
		$title = NlmImportDom::processTitle($articleNode);
		$article->setTitle($title, $primaryLocale);
		
		// Get article abstract if it exists
		$abstractText = '';
		$abstractNode = $articleNode->getChildByName('abstract');
		if ($abstractNode) {
			$abstractText = $abstractNode->getValue();
			Request::cleanUserVar($abstractText);
			$abstractText = trim($abstractText);
			if ($abstractText) $article->setAbstract($abstractText, $primaryLocale);
		}
		
		// Add article
		$articleDao->insertArticle($article);
		$dependentItems[] = array('article', $article);
		
		// Process authors and assign to article
		$allAuthors = array();
		NlmImportDom::processAuthors($articleNode, $article, $journal, $allAuthors, true);
		
		// Create completed submission workflow records
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');
		
		$initialCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_INITIAL', ASSOC_TYPE_ARTICLE, $article->getId());
		$initialCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($initialCopyeditSignoff);
		
		$authorCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $article->getId());
		$authorCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($authorCopyeditSignoff);
		
		$finalCopyeditSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_FINAL', ASSOC_TYPE_ARTICLE, $article->getId());
		$finalCopyeditSignoff->setUserId(0);
		$signoffDao->updateObject($finalCopyeditSignoff);
		
		$layoutSignoff = $signoffDao->build('SIGNOFF_LAYOUT', ASSOC_TYPE_ARTICLE, $article->getId());
		$layoutSignoff->setUserId(0);
		$signoffDao->updateObject($layoutSignoff);
		
		$authorProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_AUTHOR', ASSOC_TYPE_ARTICLE, $article->getId());
		$authorProofSignoff->setUserId(0);
		$signoffDao->updateObject($authorProofSignoff);
		
		$proofreaderProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_PROOFREADER', ASSOC_TYPE_ARTICLE, $article->getId());
		$proofreaderProofSignoff->setUserId(0);
		$signoffDao->updateObject($proofreaderProofSignoff);
		
		$layoutProofSignoff = $signoffDao->build('SIGNOFF_PROOFREADING_LAYOUT', ASSOC_TYPE_ARTICLE, $article->getId());
		$layoutProofSignoff->setUserId(0);
		$signoffDao->updateObject($layoutProofSignoff);
		
		// Log the import in the article event log
		import('classes.article.log.ArticleLog');
		ArticleLog::logEventHeadless(
				$journal, $user->getId(), $article,
				ARTICLE_LOG_ARTICLE_IMPORT,
				'log.imported',
				array('userName' => $user->getFullName(), 'articleId' => $article->getId())
				);
		
		// Insert published article entry
		$publishedArticle = new PublishedArticle();
		$publishedArticle->setId($article->getId());
		$publishedArticle->setSectionId($article->getSectionId());
		$publishedArticle->setIssueId($issue->getId());
		$publishedArticle->setDatePublished($issue->getDatePublished());
		$publishedArticle->setAccessStatus(ARTICLE_ACCESS_OPEN);
		
		if ($firstPageNode) {
			$publishedArticle->setSeq($firstPageNode->getValue());
		} else {
			$publishedArticle->setSeq(REALLY_BIG_NUMBER + $article->getId());
		}
		
		$publishedArticleDao->insertPublishedArticle($publishedArticle);
		
		// Process copyright and license
		$permissionsNode = $articleNode->getChildByName('permissions');
		
		if ($permissionsNode) {
			$copyrightNode = $permissionsNode->getChildByName('copyright-statement');
		} else {
			$copyrightNode = $articleNode->getChildByName('copyright-statement');
		}
		$copyrightText = $copyrightNode ? $copyrightNode->getValue() : null;
		
		if ($copyrightText) {
			// Extract license URL if it exists
			$matches = array();
			if (preg_match('/href=\"(.*?)\"/', $copyrightText, $matches)) {
				if (count($matches) > 1) {
					$licenseUrl = $matches[1];
					Request::cleanUserVar($licenseUrl);
					$licenseUrl = filter_var(trim($licenseUrl), FILTER_VALIDATE_URL);
					if ($licenseUrl) $article->setLicenseURL($licenseUrl);
				}
			}
			
			// Remove any additional XML tags from the copyright statement
			$copyrightText = strip_tags($copyrightText);
			$article->setCopyrightHolder($copyrightText, $primaryLocale);
		}
		
		$copyrightYearNode = $articleNode->getChildByName('copyright-year');
		$copyrightYear = $copyrightYearNode ? $copyrightYearNode->getValue() : null;
		if ($copyrightYear) $article->setCopyrightYear($copyrightYear);
		
		// Setup default copyright/license metadata if not provided by XML
		$article->initializePermissions();
		
		// Update copyright/license info with details included in XML
		$articleDao->updateLocaleFields($article);
		
		// Process galleys
		$pdfGalleyNode = $articleNode->getChildByName('self-uri');
		$pdfGalleyFile = $pdfGalleyNode ? $pdfGalleyNode->getAttribute('xlink:href') : null;
		
		// PDF galleys by default for all journals
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($article->getId());
		
		if (!$pdfGalleyFile) {
			$articleBaseName = basename($articleFileName, '.xml');
			$pdfGalleyFile = $articleBaseName . '.pdf';
		}
		
		if ($pdfGalleyFile) {
			$pdfGalleyPath = $pdfPath . '/' . $pdfGalleyFile;
			$result = NlmImportDom::handlePDFGalleyNode($journal, $pdfGalleyPath, $article, $errors, $articleFileManager);
		}
		
		// For CRL News, also process XHTML galleys for newer issues
		if (($journal->getPath() == 'crlnews') && (is_dir($imagePath))) {
			$htmlGalleyPath = $htmlPath . '/' . $articleFileName;
			$result = NlmImportDom::handleHTMLGalley($journal, $xmlArticleDOM, $articleNode, $htmlGalleyPath, $imagePath, $article, $errors, $articleFileManager);
		}
		
		// Index the article
		import('classes.search.ArticleSearchIndex');
		$articleSearchIndex = new ArticleSearchIndex();
		$articleSearchIndex->articleMetadataChanged($article);
		$articleSearchIndex->articleFilesChanged($article);
		$articleSearchIndex->articleChangesFinished();
		
		$returner = array ('issue' => $issue, 'section' => $section, 'article' => $article);
		return $returner;
	}
	
	/**
	 * Handle issue data and create new issue if it doesn't already exist
	 * @param Journal $journal
	 * @param DOMNode $articleNode
	 * @param string $peripheralPath
	 * @param array $dependentItems
	 * @param array $errors
	 * @return Issue the imported issue
	 */
	function handleIssue(&$journal, &$articleNode, $peripheralPath, &$dependentItems, &$errors) {
		$primaryLocale = $journal->getPrimaryLocale();
		$seasonMap = array(
				'Spring' => '03',
				'Summer' => '06',
				'Fall' => '09',
				'Autumn' => '09',
				'Winter' => '12'
		);
		
		// Get volume and issue info
		$node = $articleNode->getChildByName('volume');
		$volume = $node ? $node->getValue() : null;
		$node = $articleNode->getChildByName('issue');
		$number = $node ? $node->getValue() : null;
		
		// Ensure we have a volume and issue number
		if (!$volume || !$number) {
			$articleTitle = NlmImportDom::getArticleTitle();
			$errors[] = array('plugins.importexport.nlm.import.error.missingVolumeNumber', array('title' => $articleTitle));
			return null;
		}
		
		// If this issue already exists, return it
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issues =& $issueDao->getPublishedIssuesByNumber($journal->getId(), $volume, $number);
		if (!$issues->eof()) {
			$issue = $issues->next();
			return $issue;
		}
		
		// Determine issue publication date based on article publication date
		$pubDateNode = $articleNode->getChildByName('pub-date');
		$node = $pubDateNode->getChildByName('season');
		$season = $node ? $node->getValue() : null;
		$node = $pubDateNode->getChildByName('year');
		$year = $node ? $node->getValue() : null;
		$node = $pubDateNode->getChildByName('month');
		$month = $node ? $node->getValue() : null;
		$node = $pubDateNode->getChildByName('day');
		$day = $node ? $node->getValue() : null;
		
		// Ensure we have a year
		if (!$year || !is_numeric($year)) {
			$articleTitle = NlmImportDom::getArticleTitle();
			$errors[] = array('plugins.importexport.nlm.import.error.missingPubDate', array('title' => $articleTitle));
			return null;
		}
		
		// Ensure we have a month or season
		if (!$month || !is_numeric($month)) {
			if (!$season) {
				$articleTitle = NlmImportDom::getArticleTitle();
				$errors[] = array('plugins.importexport.nlm.import.error.missingPubDate', array('title' => $articleTitle));
				return null;
			}
			if (!array_key_exists($season, $seasonMap)) {
				// Sometimes season is provided as a number range, e.g. 07-08
				$month = (int)$season;
			} else {
				$month = $seasonMap["$season"];
				if (empty($month)) $month = '1';
			}
		}
		
		// Ensure we have a day
		if (!$day) $day = "1";
		
		// Construct issue title based on season or month
		if (array_key_exists($season, $seasonMap)) $issueTitle = $season;
		else $issueTitle = date("F", mktime(0, 0, 0, $month, 1, 2017));
		
		// Ensure two digit months and days for issue publication date
		if (preg_match('/^\d$/', $month)) { $month = '0' . $month; }
		if (preg_match('/^\d$/', $day)) { $day = '0' . $day; }
		$publishedDate = strtotime($year . '-' . $month . '-' . $day);
		
		// Create new issue
		import('classes.issue.Issue');
		$issue = new Issue();
		$issue->setJournalId($journal->getId());
		$issue->setVolume((int)$volume);
		$issue->setNumber((int)$number);
		$issue->setYear((int)$year);
		$issue->setTitle($issueTitle, $primaryLocale);
		$issue->setPublished(1);
		$issue->setCurrent(0);
		$issue->setDatePublished($publishedDate);
		$issue->stampModified();
		$issue->setAccessStatus(ISSUE_ACCESS_OPEN);
		$issue->setShowVolume(1);
		$issue->setShowNumber(1);
		$issue->setShowYear(1);
		$issue->setShowTitle(1);
		$issueDao->insertIssue($issue);
		
		if (!$issue->getId()) {
			return null;
		}
		
		$coverPage = false;
		
		// Handle issue cover image and caption, if they exist
		if (file_exists($peripheralPath) && is_dir($peripheralPath) ) {
			$directoryHandle = opendir($peripheralPath);
			
			while (($entry = readdir($directoryHandle)) !== false) {
				$curFilePath = $peripheralPath . "/" . $entry;
				
				// We have a high-res TIFF cover image
				if (preg_match('/cover.tif/', $curFilePath)) {
					import('classes.file.PublicFileManager');
					$publicFileManager = new PublicFileManager();
					
					// Since TIFF has poor browser support, convert to compressed JPG
					// Note: Requires ImageMagick support in PHP
					$image = new Imagick($curFilePath);
					$image->setImageFormat('jpg');
					$image->setImageCompressionQuality('70');
					$image->resizeImage(650, 0, imagick::FILTER_LANCZOS, 1);
					$baseImagePath = dirname($curFilePath);
					$baseImageName = basename($curFilePath, '.tif');
					$coverImageFileName = $baseImageName . '.jpg';
					$coverImagePath = $baseImagePath . '/' . $coverImageFileName;
					$image->writeImage($coverImagePath);
					
					// Copy converted PNG cover image to journal's /public folder
					$publicFileName = 'cover_issue_' . $issue->getId() . '_' . $primaryLocale . '.jpg';
					$publicFileManager->copyJournalFile($journal->getId(), $coverImagePath, $publicFileName);
					
					$issue->setOriginalFileName($publicFileManager->truncateFileName($coverImageFileName, 127), $primaryLocale);
					$issue->setFileName($publicFileName, $primaryLocale);
					$issue->setShowCoverPage(1, $primaryLocale);
					
					// Store the image dimensions
					list($width, $height) = getimagesize($publicFileManager->getJournalFilesPath($journal->getId()) . '/' . $publicFileName);
					$issue->setWidth($width, $primaryLocale);
					$issue->setHeight($height, $primaryLocale);
					
					$coverPage = true;
					
					// We have a cover caption
				} elseif (preg_match('/covercaption.html/', $curFilePath)) {
					$coverCaption = file_get_contents($curFilePath);
					
					// Convert CRL News text to UTF8
					if (preg_match('/crlnews/', $curFilePath)) {
						$coverCaption = iconv('Windows-1252', 'UTF-8', $coverCaption);
					}
					
					// Convert RBML and RBM text to UTF8
					if (preg_match('/rbm/', $curFilePath)) {
						$coverCaption = iconv('ISO-8859-1', 'UTF-8', $coverCaption);
					}
				}
			}
			
			// If there was a cover page caption, add caption text
			if ($coverPage && isset($coverCaption)) {
				// Replace newlines with spaces
				$coverCaption = trim(preg_replace('/\s+/', ' ', $coverCaption));
				
				// Strip all tags except paragraphs and simple formatting
				$coverCaption = strip_tags($coverCaption, "<p><a><i><b><u><em><strong><sup><sub>");
				
				$issue->setCoverPageDescription(trim($coverCaption), $primaryLocale);
			}
		}
		
		$issueDao->updateIssue($issue);
		return $issue;
	}
	
	/**
	 * Handle section data and create new section if it doesn't already exist
	 * @param Journal $journal
	 * @param DOMNode $articleNode
	 * @param array $dependentItems
	 * @param array $errors
	 * @return Section
	 */
	function handleSection(&$journal, &$articleNode, &$dependentItems, &$errors) {
		// Get volume and issue info
		$categoriesNode = $articleNode->getChildByName('article-categories');
		$sectionName = null;
		
		$subjectGroupNode = $categoriesNode ? $categoriesNode->getChildByName('subj-group') : null;
		$subjectNode = $subjectGroupNode ? $subjectGroupNode->getChildByName('subject') : null;
		
		if ($subjectNode) {
			$sectionName = ucwords(strtolower($subjectNode->getValue()));
			
			// Check for possible sub-heading
			$subGroupNode = $subjectGroupNode->getChildByName('subj-group');
			if ($subGroupNode) {
				$subSubjectNode = $subGroupNode->getChildByName('subject');
				if ($subSubjectNode) $sectionName .= ': ' . ucwords(strtolower($subSubjectNode->getValue()));
			}
		}
		
		// Check for subject tag not nested by subject group tag
		if (!$sectionName) {
			$sectionNode = $categoriesNode ? $categoriesNode->getChildByName('subject') : null;
			if ($sectionNode) $sectionName = ucwords(strtolower($sectionNode->getValue()));
		}
		
		if ($sectionName) {
			Request::cleanUserVar($sectionName);
			$sectionName = trim($sectionName);
		} else {
			$sectionName = 'Articles';
		}
		
		// Ensure we have a section name
		if (!$sectionName) {
			$articleTitle = NlmImportDom::getArticleTitle($articleNode);
			$errors[] = array('plugins.importexport.nlm.import.error.missingSection', array('title' => $articleTitle));
			return null;
		}
		
		// If this section already exists, return it
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$section =& $sectionDao->getSectionByTitle($sectionName, $journal->getId(), $journal->getPrimaryLocale());
		if ($section) return $section;
		
		// Otherwise, create a new section
		import('classes.journal.Section');
		AppLocale::requireComponents(LOCALE_COMPONENT_OJS_DEFAULT);
		$section = new Section();
		$section->setJournalId($journal->getId());
		$section->setTitle($sectionName, $journal->getPrimaryLocale());
		$section->setAbbrev(strtoupper(substr($sectionName, 0, 3)), $journal->getPrimaryLocale());
		$section->setAbstractsNotRequired(true);
		$section->setMetaIndexed(true);
		$section->setMetaReviewed(false);
		$section->setPolicy(__('section.default.policy'), $journal->getPrimaryLocale());
		$section->setEditorRestricted(true);
		$section->setHideTitle(false);
		$section->setHideAuthor(false);
		$section->setHideAbout(true);
		$section->setDisableComments(true);
		
		$sectionDao->insertSection($section);
		
		if ($section->getId()) {
			return $section;
		} else {
			return null;
		}
	}
	
	/**
	 * Handle a title group node
	 * @param $articleNode DOMElement
	 * @return string
	 */
	function processTitle(&$articleNode) {
		$titleGroupNode = $articleNode->getChildByName('title-group');
		$titleNode = $titleGroupNode->getChildByName('article-title');
		$subtitleNode = $titleGroupNode->getChildByName('subtitle');
		$title = $titleNode->getValue();
		Request::cleanUserVar($title);
		$title = trim($title);
		$subtitle = '';
		if ($subtitleNode) {
			$subtitle = $subtitleNode->getValue();
			Request::cleanUserVar($subtitle);
			$subtitle = trim($subtitle);
		}
		if ($subtitle) $title = $title . ': ' . $subtitle;
		
		// Remove any superscript footnote/endnote digits from end of titles
		
		// First strip superscript <sup> tags
		$title = strip_tags($title, "<i><b>");
		
		// Then remove trailing digit
		$titleLength = strlen($title);
		if ($titleLength > 2) {
			// Ensure that we don't strip valid numbers from titles (e.g. year)
			// Get the last and second-to-last characters
			$lastChar = substr($title, -1, 1);
			$secondToLastChar = substr($title, -2, 1);
			
			// Ensure that last character is a digit
			if (is_numeric($lastChar)) {
				// Ensure that second-to-last character is a letter
				if (!is_numeric($secondToLastChar)) {
					$title = rtrim($title, "1..9");
				}
			}
		}
		return $title;
	}
	
	/**
	 * Handle an author node (i.e. convert an author from DOM to DAO).
	 * @param $journal Journal
	 * @param $authorNode DOMElement
	 * @param $article Article
	 * @param $allAuthors returned array of Authors
	 * @param $insertAuthors bool
	 */
	function processAuthors(&$articleNode, &$article, &$journal, &$allAuthors, $insertAuthors = true) {
		import('classes.article.Author');
		$authorDao =& DAORegistry::getDAO('AuthorDAO');
		$primaryLocale = $journal->getPrimaryLocale();
		
		$checkContributorNode = $articleNode->getChildByName('contrib-group');
		if (!$checkContributorNode) {
			// No authors present, create default 'N/A' author
			$author =& NlmImportDom::createEmptyAuthor($article);
			$authorDao->insertAuthor($author);
		} else {
			$useAuthorNotes = false;
			for ($contribIndex=0; ($contributorNode = $articleNode->getChildByName('contrib-group', $contribIndex)); $contribIndex++) {
				$authors = array();
				$emails = array();
				$affiliations = array();
				
				if (!$contributorNode) {
					// No authors present, create default 'N/A' author
					$author =& NlmImportDom::createEmptyAuthor($article);
					$authorDao->insertAuthor($author);
				} else {
					// Otherwise, parse all author names first
					for ($index=0; ($node = $contributorNode->getChildByName('contrib', $index)); $index++) {
						if (!$node) continue;
						$author =& NlmImportDom::handleAuthorNode($journal, $node, $article, $contribIndex);
						if ($author) $authors[] = $author;
					}
					
					// Then parse author affiliation and email info
					for ($index=0; ($node = $contributorNode->getChildByName('aff', $index)); $index++) {
						if (!$node) continue;
						$affiliation = $node->getValue();
						
						// Manually extract email if provided via email tags
						$pattern = '/\<email\>(.*)\<\/email\>/';
						preg_match($pattern, $affiliation, $matches);
						if (isset($matches[1])) {
							$email = filter_var(trim($matches[1]), FILTER_VALIDATE_EMAIL);
							if ($email) $emails[] = $email;
						}
						
						Request::cleanUserVar($affiliation);
						$affiliation = strip_tags(trim($affiliation));
						if ($affiliation) $affiliations[] = $affiliation;
					}
					
					// Check if author note node used instead of affiliation node
					$authorNotes = null;
					$correspEmail = null;
					if (empty($affiliations)) {
						$authorNotesNode = $articleNode->getChildByName('author-notes');
						if ($authorNotesNode) {
							// Check for a correspondence node with email address
							$correspNode = $authorNotesNode->getChildByName('corresp');
							if ($correspNode) {
								$correspText = $correspNode->getValue();
								
								// Manually extract email if provided via email tags
								$pattern = '/\<email\>(.*)\<\/email\>/';
								preg_match($pattern, $correspText, $matches);
								if (isset($matches[1])) {
									$correspEmail = filter_var(trim($matches[1]), FILTER_VALIDATE_EMAIL);
								}
							} else {
								$authorNotes = $authorNotesNode->getValue();
								Request::cleanUserVar($authorNotes);
								$authorNotes = trim($authorNotes);
							}
						}
					}
					
					// Assign email and affiliation info to authors
					$index = 0;
					$useEmails = (count($authors) == count($emails)) ? true : false;
					$useAffiliations = (count($authors) == count($affiliations)) ? true : false;
					foreach ($authors as $author) {
						// Number of emails match number of authors
						if ($useEmails) $author->setEmail($emails[$index]);
						// Number of affiliations match number of authors
						if ($useAffiliations) {
							$author->setAffiliation($affiliations[$index], $primaryLocale);
							// One affiliation text as composite for all authors, assign to first author
						} elseif ((count($affiliations)) == 1 && ($index == 0)) {
							$author->setAffiliation($affiliations[$index], $primaryLocale);
							// One email specified in correspondence node
						} elseif ($correspEmail && ($index == 0)) {
							$author->setEmail($correspEmail);
							// One set of author notes as composite for all authors, assign to first author
						} elseif ($authorNotes && ($index == 0) && !$useAuthorNotes) {
							$author->setAffiliation($authorNotes, $primaryLocale);
							$useAuthorNotes = true;
						}
						if ($insertAuthors) $authorDao->insertAuthor($author);
						$allAuthors[] = $author;
						$index++;
					}
				}
			}
		}
	}
	
	/**
	 * Handle an author node (i.e. convert an author from DOM to DAO).
	 * @param $journal Journal
	 * @param $authorNode DOMElement
	 * @param $article Article
	 * @param $authorIndex int 0 for first author, 1 for second, ...
	 */
	function handleAuthorNode(&$journal, &$authorNode, &$article, $authorIndex) {
		$author = new Author();
		$nameNode = $authorNode->getChildByName('name');
		if (!$nameNode) {
			$collabNode = $authorNode->getChildByName('collab');
			if ($collabNode) {
				$collabText = substr($collabNode->getValue(), 0, 90);
				$author->setFirstName('');
				$author->setLastName((string)$collabText);
			} else {
				$author->setFirstName('');
				$author->setLastName('American Library Association');
			}
		} else {
			if (($node = $nameNode->getChildByName('given-names'))) $author->setFirstName((string)$node->getValue());
			if (($node = $nameNode->getChildByName('surname'))) $author->setLastName((string)$node->getValue());
		}
		$author->setSequence($authorIndex + 1); // 1-based
		$author->setSubmissionId($article->getId());
		$author->setEmail('ala@ala.org');
		$author->setPrimaryContact($authorIndex == 0 ? 1:0);
		return $author;
	}
	
	/**
	 * Add 'empty' author for articles with no author information
	 * @param $article Article
	 * @return Author
	 */
	function createEmptyAuthor(&$article) {
		$author = new Author();
		$author->setFirstName('');
		$author->setLastName('American Library Association');
		$author->setSequence(1);
		$author->setSubmissionId($article->getId());
		$author->setEmail('ala@ala.org');
		$author->setPrimaryContact(1);
		return $author;
	}
	
	/**
	 * Import an HTML galley and assoiated Media Objects (images).
	 * @param string $htmlFile the full path to the HTML galley file
	 * @param Article $article
	 * @param Journal $journal
	 * @param string $metapressDirectoryPath the base directory for this Metapress object.
	 * @return ArticleHTMLgalley (&$journal, $pdfGalleyPath, &$article, &$errors, &$articleFileManager)
	 */
	function handleHTMLGalley(&$journal, &$xmlArticleDOM, &$articleNode, $htmlGalleyPath, $imagePath, &$article, &$errors, &$articleFileManager) {
		$primaryLocale = $journal->getPrimaryLocale();
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galley = new ArticleHTMLGalley();
		
		$galley->setArticleId($article->getId());
		$galley->setSequence(2);  // Assume we have already imported a PDF at this point.
		$galley->setLocale($article->getLocale());
		$galley->setLabel('HTML');
		
		// Add JPG file extension to referenced images
		$xmlArticle = file_get_contents($htmlGalleyPath);
		
		if ($xmlArticle===false) {
			$errors[] = array('plugins.importexport.nlm.import.error.galleyMissing', array('title' => $article->getLocalizedTitle()));
			return false;
		}
		
		$imageFixMatches = 0;
		if (preg_match("/<graphic[^\/>]*?href=\"(.*?)\"\/>/", $xmlArticle)) {
			$xmlArticle = preg_replace("/<graphic[^\/>]*?href=\"(.*?)\"\/>/", "<graphic xlink:href=\"$1.jpg\"/>", $xmlArticle, -1, $imageFixMatches);
		}
		
		// Fix hrefs missing http://
		$urlFixMatches = 0;
		if (preg_match("/href=\"www/", $xmlArticle)) {
			$xmlArticle = preg_replace("/href=\"www/", "href=\"http://www", $xmlArticle, -1, $urlFixMatches);
		}
		
		// Replace <sub-article> sections with actual article text for Internet Reviews
		$subArticleFix = false;
		$testNode = $xmlArticleDOM->getChildByName('sub-article');
		if ($testNode) {
			$header = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
			$header .= '<!DOCTYPE article PUBLIC "-//NLM//DTD Journal Publishing DTD v2.3 20070202//EN" "journalpublishing.dtd">' . "\n";
			$header .= '<article xml:lang="en" article-type="other" xmlns:xlink="http://www.w3.org/1999/xlink">';
			
			$frontMatches = array();
			preg_match("/<front>(.*?)<\/front>/si", $xmlArticle, $frontMatches);
			$frontMatter = $frontMatches[0];
			
			$subArticleMatches = array();
			preg_match_all("/<sub-article[^\/>]*?(.*?)<\/sub-article>/si", $xmlArticle, $subArticleMatches);
			
			// Parse all sub-articles
			$subArticles = array();
			for ($index=0; ($node = $xmlArticleDOM->getChildByName('sub-article', $index)); $index++) {
				$subArticleFrontNode = $node->getChildByName('front');
				$subArticleMetaNode = $subArticleFrontNode->getChildByName('article-meta');
				$subTitle = '';
				$subAuthors = array();
				$subBody = '';
				
				// Retrieve title
				$subTitle = NlmImportDom::processTitle($subArticleMetaNode);
				
				// Retrieve authors
				NlmImportDom::processAuthors($subArticleMetaNode, $article, $journal, $subAuthors, false);
				
				// Retrieve body text
				$subBodyMatches = array();
				$subArticleText = $subArticleMatches[0][$index];
				preg_match("/<body>(.*?)<\/body>/si", $subArticleText, $subBodyMatches);
				$subBody = $subBodyMatches[1];
				
				$subArticles[] = array('title' => $subTitle, 'authors' => $subAuthors, 'body' => $subBody);
			}
			
			$xmlArticle = $header . "\n";
			$xmlArticle .= $frontMatter . "\n";
			$xmlArticle .= '<body>';
			
			// Add all subarticle contents to main body text
			foreach ($subArticles as $subArticle) {
				
				$xmlArticle .= '<sec>';
				
				// Write out title
				$subArticleTitle = $subArticle['title'];
				$subArticleTitle = htmlentities(strip_tags($subArticleTitle), ENT_COMPAT, "UTF-8", false);
				$xmlArticle .= "\n" . '<title>' . $subArticleTitle . '</title>' . "\n";
				
				// Write out all authors
				$subAuthors = $subArticle['authors'];
				foreach ($subAuthors as $subAuthor) {
					$xmlArticle .= '<p>' . $subAuthor->getFullName();
					if ($subAuthor->getAffiliation($primaryLocale)) $xmlArticle .= ', ' . htmlentities(strip_tags($subAuthor->getAffiliation($primaryLocale)), ENT_COMPAT, "UTF-8", false);
					$xmlArticle .= '</p>';
				}
				
				// Write out body text
				$xmlArticle .= $subArticle['body'];
				
				$xmlArticle .= '</sec>';
			}
			
			$xmlArticle .= '</body></article>';
			
			$subArticleFix = true;
		}
		
		// Write fixed XML to file
		if ($imageFixMatches > 0 || $urlFixMatches > 0 || $subArticleFix) {
			$fixedFilePath = preg_replace("/\.xml$/", "_fixed.xml", $htmlGalleyPath);
			file_put_contents($fixedFilePath, $xmlArticle);
			$htmlGalleyPath = $fixedFilePath;
		}
		
		if (($fileId = $articleFileManager->copyPublicFile($htmlGalleyPath, 'application/xml'))===false) {
			$errors[] = array('plugins.importexport.nlm.import.error.couldNotCopy', array('url' => $htmlGalleyPath));
			return false;
		}
		
		if (!isset($fileId)) {
			$errors[] = array('plugins.importexport.nlm.import.error.galleyMissing', array('title' => $article->getLocalizedTitle()));
			return false;
		}
		
		$galley->setFileId($fileId);
		$galleyId = $galleyDao->insertGalley($galley);
		
		// Convert all TIFF images for the issue to JPG
		if (is_dir($imagePath)) {
			$handle = opendir($imagePath);
			while (($entry = readdir($handle)) !== false) {
				$imageFile = $imagePath . '/' . $entry;
				if (is_file($imageFile) && !preg_match('/^\./', $entry)) { // it' a file, not . or ..
					
					// If we've already converted all TIFF images, skip this step
					//if (preg_match('/\.jpg/', $entry)) break;
					
					// Resize large images to max 650px width
					// Note: Requires ImageMagick support in PHP
					if (preg_match('/\.tif/i', $entry)) {
						$image = new Imagick($imageFile);
						$image->setImageFormat('jpg');
						$image->setImageCompressionQuality('70');
						
						$dimensions = $image->getImageGeometry();
						$width = $dimensions['width'];
						if ($width > 600) $image->resizeImage(600, 0, imagick::FILTER_LANCZOS, 1);
						
						$baseImagePath = dirname($imageFile);
						$baseImageName = basename($imageFile, '.tif');
						if (!$baseImageName) $baseImageName = basename($imageFile, '.TIF');
						$newImageFileName = $baseImageName . '.jpg';
						$newImagePath = $baseImagePath . '/' . $newImageFileName;
						$image->writeImage($newImagePath);
					}
				}
			}
		}
		
		// Upload converted JPGs for current article
		if ($imageFixMatches > 0) {
			
			$matches = array();
			preg_match_all("/<graphic[^\/>]*?href=\"(.*?)\"\/>/", $xmlArticle, $matches);
			
			if (count($matches > 1)) {
				array_shift($matches);
				
				foreach ($matches[0] as $galleyImageFilename) {
					$galleyImagePath = $imagePath . '/' . $galleyImageFilename;
					
					if ($fileId = $articleFileManager->copyPublicFile($galleyImagePath, 'image/jpeg')) {
						$galleyDao->insertGalleyImage($galleyId, $fileId);
						$galley->setImageFiles($galleyDao->getGalleyImages($galleyId));
						$galleyDao->updateGalley($galley);
					}
				}
			}
		}
		
		return true;
	}
	
	/**
	 * Import a remote PDF Galley.
	 * @param Journal $journal
	 * @param string $pdfGalleyPath
	 * @param Article $article
	 * @param array $errors
	 * @param ArticleFileManager $articleFileManager
	 * @return boolean
	 */
	function handlePDFGalleyNode(&$journal, $pdfGalleyPath, &$article, &$errors, &$articleFileManager) {
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		
		import('classes.article.ArticleGalley');
		$galley = new ArticleGalley();
		$galley->setArticleId($article->getId());
		$galley->setSequence(1);
		$galley->setLocale($article->getLocale());
		$galley->setLabel('PDF');
		
		if (($fileId = $articleFileManager->copyPublicFile($pdfGalleyPath, 'application/pdf'))===false) {
			$errors[] = array('plugins.importexport.nlm.import.error.couldNotCopy', array('url' => $pdfGalleyPath));
			return false;
		}
		
		if (!isset($fileId)) {
			$errors[] = array('plugins.importexport.nlm.import.error.galleyMissing', array('title' => $article->getLocalizedTitle()));
			return false;
		}
		
		$galley->setFileId($fileId);
		$galleyDao->insertGalley($galley);
		
		return true;
	}
	
	function getArticleTitle (&$articleNode) {
		$titleGroupNode = $articleNode->getChildByName('title-group');
		$titleNode = $titleGroupNode->getChildByName('article-title');
		$title = $titleNode->getValue();
		Request::cleanUserVar($title);
		$title = trim($title);
		return $title;
	}
	
	function cleanupFailure (&$dependentItems) {
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		
		foreach ($dependentItems as $dependentItem) {
			$type = array_shift($dependentItem);
			$object = array_shift($dependentItem);
			
			switch ($type) {
				case 'issue':
					$issueDao->deleteIssue($object);
					break;
				case 'article':
					$articleDao->deleteArticle($object);
					break;
				default:
					fatalError ('cleanupFailure: Unimplemented type');
			}
		}
	}
}

?>