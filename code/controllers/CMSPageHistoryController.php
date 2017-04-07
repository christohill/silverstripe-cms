<?php

/**
 * @package cms
 * @subpackage controllers
 */
class CMSPageHistoryController extends CMSMain {

	private static $url_segment = 'pages/history';
	private static $url_rule = '/$Action/$ID/$VersionID/$OtherVersionID';
	private static $url_priority = 42;
	private static $menu_title = 'History';
	private static $required_permission_codes = 'CMS_ACCESS_CMSMain';
	private static $session_namespace = 'CMSMain';

	private static $allowed_actions = array(
		'EditForm',
		'VersionsForm',
		'CompareVersionsForm',
		'show',
		'compare'
	);

	private static $url_handlers = array(
		'EditForm/$ID/$VersionID' => 'EditForm',
		'$Action/$ID/$VersionID/$OtherVersionID' => 'handleAction',
	);

	/**
	 * Current version ID for this request. Can be 0 for latest version
	 *
	 * @var int
	 */
	protected $versionID = null;

	public function getResponseNegotiator() {
		$negotiator = parent::getResponseNegotiator();
		$controller = $this;
		$negotiator->setCallback('CurrentForm', function() use(&$controller) {
			$form = $controller->getEditForm();
			if ($form) {
				return $form->forTemplate();
			} else {
				return $controller->renderWith($controller->getTemplatesWithSuffix('_Content'));
			}
		});
		$negotiator->setCallback('default', function() use(&$controller) {
			return $controller->renderWith($controller->getViewer('show'));
		});
		return $negotiator;
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function show($request) {
		// Record id and version for this request
		$id = $request->param('ID');
		$this->setCurrentPageID($id);
		$versionID = $request->param('VersionID');
		$this->setVersionID($versionID);

		$form = $this->getEditForm();

		$negotiator = $this->getResponseNegotiator();
		$controller = $this;
		$negotiator->setCallback('CurrentForm', function() use(&$controller, &$form) {
			return $form
				? $form->forTemplate()
				: $controller->renderWith($controller->getTemplatesWithSuffix('_Content'));
		});
		$negotiator->setCallback('default', function() use(&$controller, &$form) {
			return $controller
				->customise(array('EditForm' => $form))
				->renderWith($controller->getViewer('show'));
		});

		return $negotiator->respond($request);
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function compare($request) {
		$id = $request->param('ID');
		$this->setCurrentPageID($id);

		$form = $this->CompareVersionsForm(
			$request->param('VersionID'),
			$request->param('OtherVersionID')
		);

		$negotiator = $this->getResponseNegotiator();
		$controller = $this;
		$negotiator->setCallback('CurrentForm', function() use(&$controller, &$form) {
			return $form
				? $form->forTemplate()
				: $controller->renderWith($controller->getTemplatesWithSuffix('_Content'));
		});
		$negotiator->setCallback('default', function() use(&$controller, &$form) {
			return $controller
				->customise(array('EditForm' => $form))
				->renderWith($controller->getViewer('show'));
		});

		return $negotiator->respond($request);
	}

	public function getSilverStripeNavigator() {
		$record = $this->getRecord($this->currentPageID(), $this->getRequest()->param('VersionID'));
		if($record) {
			$navigator = new SilverStripeNavigator($record);
			return $navigator->renderWith($this->getTemplatesWithSuffix('_SilverStripeNavigator'));
		} else {
			return false;
		}
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return Form
	 */
	public function EditForm($request = null) {
		if ($request) {
			// Validate VersionID is present
			$versionID = $request->param('VersionID');
			if (!isset($versionID)) {
				$this->httpError(400);
				return null;
			}
			$this->setVersionID($versionID);
		}
		return parent::EditForm($request);
	}

	/**
	 * Returns the read only version of the edit form. Detaches all {@link FormAction}
	 * instances attached since only action relates to revert.
	 *
	 * Permission checking is done at the {@link CMSMain::getEditForm()} level.
	 *
	 * @param int $id ID of the record to show
	 * @param array $fields optional
	 * @param int $versionID
	 * @param int $compareID Compare mode
	 *
	 * @return Form
	 */
	public function getEditForm($id = null, $fields = null, $versionID = null, $compareID = null) {
		if (!$id) {
			$id = $this->currentPageID();
		}
		if (!$versionID) {
			$versionID = $this->getVersionID();
		}

		$record = $this->getRecord($id, $versionID);
		if (!$record) {
			return $this->EmptyForm();
		}

		// Refresh version ID
		$versionID = $record->Version;
		$this->setVersionID($versionID);

		// Get edit form
		$form = parent::getEditForm($record, $record->getCMSFields());
		// Respect permission failures from parent implementation
		if (!($form instanceof Form)) {
			return $form;
		}

		// TODO: move to the SilverStripeNavigator structure so the new preview can pick it up.
		//$nav = new SilverStripeNavigatorItem_ArchiveLink($record);

		$actions = new FieldList(
			$revert = FormAction::create(
				'doRollback',
				_t('CMSPageHistoryController.REVERTTOTHISVERSION', 'Revert to this version')
			)->setUseButtonTag(true)
		);
		$actions->setForm($form);
		$form->setActions($actions);

		$fields = $form->Fields();
		$fields->removeByName("Status");
		$fields->push(new HiddenField("ID"));
		$fields->push(new HiddenField("Version"));

		$fields = $fields->makeReadonly();

		if($compareID) {
			$link = Controller::join_links(
				$this->Link('show'),
				$id
			);

			$view = _t('CMSPageHistoryController.VIEW',"view");

			$message = _t(
				'CMSPageHistoryController.COMPARINGVERSION',
				"Comparing versions {version1} and {version2}.",
				array(
					'version1' => sprintf('%s (<a href="%s">%s</a>)', $versionID, Controller::join_links($link, $versionID), $view),
					'version2' => sprintf('%s (<a href="%s">%s</a>)', $compareID, Controller::join_links($link, $compareID), $view)
				)
			);

			$revert->setReadonly(true);
		} else {
			if($record->isLatestVersion()) {
				$message = _t('CMSPageHistoryController.VIEWINGLATEST', 'Currently viewing the latest version.');
			} else {
				$message = _t(
					'CMSPageHistoryController.VIEWINGVERSION',
					"Currently viewing version {version}.",
					array('version' => $versionID)
				);
			}
		}

		$fields->addFieldToTab('Root.Main',
			new LiteralField('CurrentlyViewingMessage', $this->customise(array(
				'Content' => $message,
				'Classes' => 'notice'
			))->renderWith(array('CMSMain_notice'))),
			"Title"
		);

		$form->setFields($fields->makeReadonly());
		$form->loadDataFrom(array(
			"ID" => $id,
			"Version" => $versionID,
		));

		if ($record->isLatestVersion()) {
			$revert->setReadonly(true);
		}

		$form->removeExtraClass('cms-content');

		$form->setFormAction(Controller::join_links($form->FormAction(), $id, $versionID));

		return $form;
	}


	/**
	 * Version select form. Main interface between selecting versions to view
	 * and comparing multiple versions.
	 *
	 * Because we can reload the page directly to a compare view (history/compare/1/2/3)
	 * this form has to adapt to those parameters as well.
	 *
	 * @return Form
	 */
	public function VersionsForm() {
		$id = $this->currentPageID();
		$page = $this->getRecord($id);
		$versionsHtml = '';

		$action = $this->getRequest()->param('Action');
		$versionID = $this->getRequest()->param('VersionID');
		$otherVersionID = $this->getRequest()->param('OtherVersionID');

		$showUnpublishedChecked = 0;
		$compareModeChecked = ($action == "compare");

		if($page) {
			$versions = $page->allVersions();
			$versionID = (!$versionID) ? $page->Version : $versionID;

			if($versions) {
				foreach($versions as $k => $version) {
					$active = false;

					if($version->Version == $versionID || $version->Version == $otherVersionID) {
						$active = true;

						if(!$version->WasPublished) $showUnpublishedChecked = 1;
					}

					$version->Active = ($active);
				}
			}

			$vd = new ViewableData();

			$versionsHtml = $vd->customise(array(
				'Versions' => $versions
			))->renderWith('CMSPageHistoryController_versions');
		}

		$fields = new FieldList(
			new CheckboxField(
				'ShowUnpublished',
				_t('CMSPageHistoryController.SHOWUNPUBLISHED','Show unpublished versions'),
				$showUnpublishedChecked
			),
			new CheckboxField(
				'CompareMode',
				_t('CMSPageHistoryController.COMPAREMODE', 'Compare mode (select two)'),
				$compareModeChecked
			),
			new LiteralField('VersionsHtml', $versionsHtml),
			$hiddenID = new HiddenField('ID', false, "")
		);

		$form = CMSForm::create(
			$this,
			'VersionsForm',
			$fields,
			new FieldList()
		)->setHTMLID('Form_VersionsForm');
		$form->setResponseNegotiator($this->getResponseNegotiator());
		$form->loadDataFrom($this->getRequest()->requestVars());
		$hiddenID->setValue($id);
		$form->unsetValidator();

		$form
			->addExtraClass('cms-versions-form') // placeholder, necessary for $.metadata() to work
			->setAttribute('data-link-tmpl-compare', Controller::join_links($this->Link('compare'), '%s', '%s', '%s'))
			->setAttribute('data-link-tmpl-show', Controller::join_links($this->Link('show'), '%s', '%s'));

		return $form;
	}

	/**
	 * @param int $versionID
	 * @param int $otherVersionID
	 * @return mixed
	 */
	public function CompareVersionsForm($versionID, $otherVersionID) {
		if($versionID > $otherVersionID) {
			$toVersion = $versionID;
			$fromVersion = $otherVersionID;
		} else {
			$toVersion = $otherVersionID;
			$fromVersion = $versionID;
		}

		if(!$toVersion || !$fromVersion) return false;

		$id = $this->currentPageID();
		$page = DataObject::get_by_id("SiteTree", $id);

 		if($page && $page->exists()) {
			if(!$page->canView()) {
				return Security::permissionFailure($this);
			}

			$record = $page->compareVersions($fromVersion, $toVersion);
		}

		$fromVersionRecord = Versioned::get_version('SiteTree', $id, $fromVersion);
		$toVersionRecord = Versioned::get_version('SiteTree', $id, $toVersion);

		if(!$fromVersionRecord) {
			user_error("Can't find version $fromVersion of page $id", E_USER_ERROR);
		}

		if(!$toVersionRecord) {
			user_error("Can't find version $toVersion of page $id", E_USER_ERROR);
		}

		if(isset($record)) {
			$form = $this->getEditForm($id, null, $fromVersion, $toVersion);
			$form->setActions(new FieldList());
			$form->addExtraClass('compare');

			// Comparison views shouldn't be editable.
			// Its important to convert fields *before* loading data,
			// as the comparison output is HTML and not valid values for the various field types
			$readonlyFields = $form->Fields()->makeReadonly();
			$form->setFields($readonlyFields);

			$form->loadDataFrom($record);
			$form->loadDataFrom(array(
				"ID" => $id,
				"Version" => $fromVersion,
			));

			foreach($form->Fields()->dataFields() as $field) {
				$field->dontEscape = true;
			}

			return $form;
		}

		return false;
	}

	public function Breadcrumbs($unlinked = false) {
		$crumbs = parent::Breadcrumbs($unlinked);
		$crumbs[0]->Title = _t('CMSPagesController.MENUTITLE');
		return $crumbs;
	}

	/**
	 * Set current version ID
	 *
	 * @param int $versionID
	 * @return $this
	 */
	public function setVersionID($versionID) {
		$this->versionID = $versionID;
		return $this;
	}

	/**
	 * Get current version ID
	 *
	 * @return int
	 */
	public function getVersionID() {
		return $this->versionID;
	}

}
