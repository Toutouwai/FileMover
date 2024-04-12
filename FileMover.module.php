<?php namespace ProcessWire;

class FileMover extends WireData implements Module, ConfigurableModule {

	protected $version;

	/**
	 * Ready
	 */
	public function ready() {
		$info = $this->wire()->modules->getModuleInfo($this);
		$this->version = $info['version'];

		$this->addHookBefore('InputfieldFile::renderReadyHook', $this, 'beforeRenderReady');
		$this->addHookBefore('ProcessPageEdit::processSaveRedirect', $this, 'beforeSaveRedirect');
	}

	/**
	 * Before InputfieldFile::renderReadyHook
	 *
	 * @param HookEvent $event
	 */
	protected function beforeRenderReady(HookEvent $event) {
		/** @var InputfieldFile $inputfield */
		$inputfield = $event->object;
		// Return early if this inputfield has already been prepped
		if($inputfield->fm_prepped) return;
		
		// Return early if role is now allowed
		$enable = false;
		if(!$this->allowed_roles || $this->user->isSuperuser()) {
			// All roles are allowed or user is superuser
			$enable = true;
		} else {
			foreach($this->allowed_roles as $allowed_role) {
				if($this->user->hasRole($allowed_role)) {
					$enable = true;
					break;
				}
			}
		}
		if(!$enable) return;

		$field = $inputfield->hasField;
		$page = $inputfield->hasPage;
		if(!$field || ! $page) return;

		// Only if process is an instance of WirePageEditor, but not ProcessProfile
		$process = $this->wire()->process;
		if($process instanceof ProcessProfile) return;
		if(!$process instanceof WirePageEditor) return;

		// Load assets
		$config = $this->wire()->config;
		$config->scripts->add($config->urls->$this . "FileMover.js?v=$this->version");
		$config->styles->add($config->urls->$this . "FileMover.css?v=$this->version");

		// JS config
		$data = [
			'labels' => [
				'select' => $this->_('Select items to later move or copy'),
				'select_item' => $this->_('Select'),
				'copy' => $this->_('Copy {n} selected {noun} to this field + save page'),
				'move' => $this->_('Move {n} selected {noun} to this field + save page'),
				'clear' => $this->_('Clear {n} selected {noun}'),
				'noun_singular' => $this->_('item'),
				'noun_plural' => $this->_('items'),
				'done' => $this->_('Done'),
				'cancel' => $this->_('Cancel'),
			],
		];
		$config->js($this->className, $data);

		// Add custom markup
		$label = $this->_('Show/hide File Mover buttons');
		$inputfield->label .= <<<EOT
<i class="fa fa-fw fa-arrow-circle-right fm-show-buttons" uk-tooltip="title: $label; pos: bottom-left; delay: 800"></i>
EOT;
		$inputfield->prependMarkup .= <<<EOT
<div class="fm-markup">
	<div class="fm-buttons"></div>
</div>
EOT;
		$inputfield->wrapAttr('data-fm-field', $field->name);
		$inputfield->wrapAttr('data-fm-page', $page->id);
		// Add custom property to indicate that this inputfield has already been prepped
		$inputfield->fm_prepped = true;
	}

	/**
	 * After Pages::saveReady
	 *
	 * @param HookEvent $event
	 */
	protected function afterSaveReady(HookEvent $event) {

	}

	/**
	 * Before ProcessPageEdit::processSaveRedirect
	 *
	 * @param HookEvent $event
	 */
	protected function beforeSaveRedirect(HookEvent $event) {
		$input = $this->wire()->input;
		$pages = $this->wire()->pages;
		$root_path = rtrim($this->wire()->config->paths->root, '/');
		$value = $input->post('fm_action');
		if(!$value) return;
		$data = wireDecodeJSON($value);

		$page = $pages->get($data['page']);
		$field = $this->wire()->fields->get($data['field']);
		$fm = $page->filesManager();
		/** @var Pagefiles $pagefiles */
		$pagefiles = $page->getUnformatted($field->name);
		$page->of(false);

		// How many more uploads allowed?
		if($field->maxFiles == 1) {
			$remaining_uploads = 1; // Single image/file field is allowed to be overwritten
		} elseif($field->maxFiles) {
			$remaining_uploads = $field->maxFiles - count($pagefiles);
		} else {
			$remaining_uploads = 9999;
		}

		// Determine allowed extensions
		$allowed_extensions = explode(' ', $field->extensions);

		// If it's a single image/file field and there's an existing image/file, remove it (as per the core upload behaviour)
		if($field->maxFiles == 1 && count($pagefiles)) $pagefiles->removeAll();

		// Process the items
		$processed = [];
		$rename = [];
		foreach($data['items'] as $item) {
			$path = $root_path . $item;
			$pagefile = $fm->getFile($path);

			// Abort if destination field is the same as the source field
			if($pagefile->page->id === $pagefiles->page->id && $pagefile->field->id === $pagefiles->field->id) {
				$this->error($this->_('The destination field for copy/move cannot be the same as the source field.'));
				return;
			}

			// Break if the field is full
			if($remaining_uploads < 1) {
				$message = sprintf($this->_('Max file upload limit reached for field "%s".'), $field->name);
				$this->warning($message);
				break;
			}

			// Check that extension is valid for the field
			if(!in_array($pagefile->ext, $allowed_extensions)) {
				$message = sprintf($this->_('%s is not an allowed extension for field "%s".'), $pagefile->ext, $field->name);
				$this->error($message);
				continue;
			}

			// Add the item to the field
			$new_pagefile = clone $pagefile;
			$pagefiles->add($new_pagefile);
			// Call install() due to this issue: https://github.com/processwire/processwire-issues/issues/1863
			$new_pagefile->install($new_pagefile->filename);

			// Decrement remaining uploads
			--$remaining_uploads;
			
			// Information needed to complete "move" action 
			$processed[] = $pagefile;
			// Files moved within the same page will be renamed
			if($data['action'] === 'move' && $pagefile->page->id === $pagefiles->page->id) {
				$rename[$pagefile->basename] = $new_pagefile;
			}
		}

		if($data['action'] === 'move') {

			// Delete moved items
			$source_page = null;
			$source_field = null;
			$pagefiles = null;
			foreach($processed as $pagefile) {
				if(!$source_page) $source_page = $pagefile->page;
				if(!$source_field) $source_field = $pagefile->field;
				if(!$pagefiles) $pagefiles = $pagefile->pagefiles;
				$source_page->of(false);
				$pagefiles->delete($pagefile);
			}
			$source_page->save($source_field);

			// Rename items moved within the same page
			foreach($rename as $basename => $pagefile) {
				$pagefile->rename($basename);
			}
		}

		// Save the field with the new items (this also completes any renaming)
		$page->save($field);
	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;

		$selectable_roles = $this->wire()->roles->find("name!=guest");
		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f_name = 'allowed_roles';
		$f->name = $f_name;
		$f->label = $this->_('Roles that are allowed to use File Mover');
		$f->description = $this->_('If this field is left empty then all roles are allowed.');
		foreach($selectable_roles as $selectable_role) $f->addOption($selectable_role->id, $selectable_role->name);
		$f->value = $this->$f_name;
		$inputfields->add($f);
	}

}