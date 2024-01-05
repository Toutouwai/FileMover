$(document).ready(function() {
	
	const labels = ProcessWire.config.FileMover.labels;

	// Get label with replacements according to context
	function getContextualLabel(string, count) {
		string = string.replace('{n}', count);
		const noun = count > 1 ? labels.noun_plural : labels.noun_singular;
		string = string.replace('{noun}', noun);
		return string;
	}

	// Cancel all active selects
	function cancelSelect() {
		$('.fm-select-active').removeClass('fm-select-active buttons-visible');
		$('.fm-select-item').remove();
		$('.fm-buttons').html('');
	}

	// Show buttons icon clicked
	$(document).on('click', '.fm-show-buttons', function(event) {
		event.preventDefault();
		event.stopPropagation();

		const $inputfield_wrap = $(this).closest('.Inputfield');
		const $buttons_wrap = $inputfield_wrap.find('.fm-buttons');
		const fieldtype = $inputfield_wrap.hasClass('InputfieldImage') ? 'images' : 'files';
		const storage_key = 'fa_items_' + fieldtype;

		if($inputfield_wrap.hasClass('buttons-visible')) {
			// Hide and clear the buttons wrapper
			$inputfield_wrap.removeClass('buttons-visible');
			$buttons_wrap.html('');
		} else {
			// Show the buttons wrapper
			$inputfield_wrap.addClass('buttons-visible');
			// Populate the buttons wrapper
			let items = localStorage.getItem(storage_key);
			let html = '';
			// Some items are already selected
			if(items) {
				items = JSON.parse(items);
				let label = getContextualLabel(labels.move, items.length);
				html += `<button type="button" class="ui-button" data-fm-type="move"><i class="fa fa-fw fa-arrow-circle-right"></i> ${label}</button>`;
				label = getContextualLabel(labels.copy, items.length);
				html += `<button type="button" class="ui-button" data-fm-type="copy"><i class="fa fa-fw fa-clone"></i> ${label}</button>`;
				label = getContextualLabel(labels.clear, items.length);
				html += `<button type="button" class="ui-button ui-priority-secondary" data-fm-type="clear"><i class="fa fa-fw fa-minus-circle"></i> ${label}</button>`;
			}
			// Show select button
			else {
				html += `<button type="button" class="ui-button" data-fm-type="select"><i class="fa fa-fw fa-hand-pointer-o"></i> ${labels.select}</button>`;
			}
			$buttons_wrap.html(html);
		}

	});

	// FileMover button clicked
	$(document).on('click', '.fm-buttons button', function() {

		const $inputfield_wrap = $(this).closest('.Inputfield');
		const $buttons_wrap = $inputfield_wrap.find('.fm-buttons');
		const fieldtype = $inputfield_wrap.hasClass('InputfieldImage') ? 'images' : 'files';
		const storage_key = 'fa_items_' + fieldtype;
		let items = null;

		// Do different things depending on the button type
		switch ($(this).data('fm-type')) {

			case 'select':
				cancelSelect();
				$inputfield_wrap.addClass('fm-select-active');
				$inputfield_wrap.find('.InputfieldImageList li, .InputfieldFileList li').prepend('<div class="fm-select-item"></div>');
				let html = '';
				html += `<button type="button" class="ui-button" data-fm-type="select-done"><i class="fa fa-fw fa-check-circle"></i> ${labels.done}</button>`;
				html += `<button type="button" class="ui-button ui-priority-secondary" data-fm-type="select-cancel"><i class="fa fa-fw fa-times-circle"></i> ${labels.cancel}</button>`;
				$buttons_wrap.html(html);
				break;

			case 'select-done':
				const $selected = $inputfield_wrap.find('.fm-selected');
				items = [];
				$selected.each(function() {
					let item = null;
					if(fieldtype === 'images') {
						item = $(this).parent().find('.gridImage__overflow img').data('original');
						// Remove query string
						item = item.split('?')[0];
					} else {
						item = $(this).parent().find('.InputfieldFileName').attr('href');
					}
					if(item) items.push(item);
				});
				localStorage.setItem(storage_key, JSON.stringify(items));
				cancelSelect();
				break;

			case 'select-cancel':
				cancelSelect();
				break;

			case 'move':
			case 'copy':
				items = localStorage.getItem(storage_key);
				items = JSON.parse(items);
				const value = {
					action: $(this).data('fm-type'),
					field: $inputfield_wrap.data('fm-field'),
					page: $inputfield_wrap.data('fm-page'),
					items: items,
				};
				$('<input>').attr({
					type: 'hidden',
					name: 'fm_action',
					value: JSON.stringify(value),
				}).insertAfter($buttons_wrap);
				localStorage.removeItem(storage_key);
				$inputfield_wrap.removeClass('buttons-visible');
				$buttons_wrap.html('');
				$('#submit_save').trigger('click');
				break;

			case 'clear':
				localStorage.removeItem(storage_key);
				$inputfield_wrap.removeClass('buttons-visible');
				$buttons_wrap.html('');
				break;

		}
	});

	// Select item
	$(document).on('click', '.fm-select-item', function(event) {
		$(this).toggleClass('fm-selected');
	});

	// Hover buttons
	$(document).on({
		mouseenter: function () {
			const $inputfield_wrap = $(this).closest('.Inputfield');
			const fieldtype = $inputfield_wrap.hasClass('InputfieldImage') ? 'images' : 'files';
			const storage_key = 'fa_items_' + fieldtype;
			let items = localStorage.getItem(storage_key);
			let title = '';
			if(items) {
				items = JSON.parse(items);
				for(const [index, item] of items.entries()) {
					const n = item.lastIndexOf('/');
					items[index] = item.substring(n + 1);
				}
				title = items.join('<br>');
			}
			$(this).attr('title', title);
			UIkit.tooltip(this, {cls: 'uk-active fm-nowrap'}).show();
		},
		mouseleave: function () {
			UIkit.tooltip(this).hide();
		}
	}, '.fm-buttons button');

});
