/**
 * Koneek - Settings Page Dynamic UI.
 *
 * Manages a list of provider connection cards. Each card is independent: it is
 * saved or removed on its own via AJAX. Handles add/remove, per-card provider
 * and model field toggling, per-card connection testing, and per-card saving.
 *
 * @package Koneek
 */
( function() {
	'use strict';

	var data = window.koneek_settings_data || {};

	var settingsData = {
		providerModels: data.providerModels || {},
		providerLabels: data.providerLabels || {},
		manualModelProviders: data.manualModelProviders || [ 'custom_openai' ],
		customUrlProviders: data.customUrlProviders || [ 'custom_openai' ],
		timeoutProviders: data.timeoutProviders || [ 'custom_openai' ],
		ajaxUrl: data.ajaxUrl || '/wp-admin/admin-ajax.php',
		saveNonce: data.saveNonce || '',
		strings: data.strings || {}
	};

	var rowCounter = 0;

	function str( key, fallback ) {
		return settingsData.strings[ key ] || fallback;
	}

	function usesManualModel( provider ) {
		return settingsData.manualModelProviders.indexOf( provider ) !== -1;
	}

	function requiresCustomUrl( provider ) {
		return settingsData.customUrlProviders.indexOf( provider ) !== -1;
	}

	function supportsTimeout( provider ) {
		return settingsData.timeoutProviders.indexOf( provider ) !== -1;
	}

	function show( el ) {
		if ( el ) { el.style.display = ''; }
	}

	function hide( el ) {
		if ( el ) { el.style.display = 'none'; }
	}

	function q( row, selector ) {
		return row ? row.querySelector( selector ) : null;
	}

	/**
	 * Rebuilds the model dropdown for a row based on its provider.
	 *
	 * @param {HTMLElement} row      The connection row.
	 * @param {string}      provider The provider slug.
	 */
	function rebuildModelDropdown( row, provider ) {
		var dropdown = q( row, '.koneek_select_model' );
		if ( ! dropdown ) { return; }

		var models = settingsData.providerModels[ provider ];
		if ( ! models || typeof models !== 'object' ) { return; }

		var keys = Object.keys( models );
		dropdown.innerHTML = '';

		for ( var i = 0; i < keys.length; i++ ) {
			var option = document.createElement( 'option' );
			option.value = keys[ i ];
			option.textContent = models[ keys[ i ] ];
			dropdown.appendChild( option );
		}

		var customOption = document.createElement( 'option' );
		customOption.value = 'custom';
		customOption.textContent = str( 'custom', 'Custom' );
		dropdown.appendChild( customOption );

		dropdown.selectedIndex = 0;
	}

	/**
	 * Updates the display name shown in the card header.
	 *
	 * Uses the user-entered name when present, otherwise the provider label.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function updateDisplayName( row ) {
		var titleEl = q( row, '.koneek_display_name' );
		if ( ! titleEl ) { return; }

		var nameEl = q( row, '.koneek_input_name' );
		var name = nameEl ? nameEl.value.trim() : '';

		if ( name ) {
			titleEl.textContent = name;
			return;
		}

		var providerSelect = q( row, '.koneek_select_provider' );
		var provider = providerSelect ? providerSelect.value : '';
		if ( settingsData.providerLabels[ provider ] ) {
			titleEl.textContent = settingsData.providerLabels[ provider ];
		}
	}

	/**
	 * Updates the model subtitle shown in the collapsed tile.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function updateModelSubtitle( row ) {
		var subtitleEl = q( row, '.koneek_model_label' );
		if ( ! subtitleEl ) { return; }

		var providerSelect = q( row, '.koneek_select_provider' );
		var provider = providerSelect ? providerSelect.value : '';
		var model = resolveModel( row, provider );

		if ( model ) {
			subtitleEl.textContent = model;
		} else if ( settingsData.providerLabels[ provider ] ) {
			subtitleEl.textContent = settingsData.providerLabels[ provider ];
		}
	}

	/**
	 * Updates the priority badge in the card header.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function updatePriorityBadge( row ) {
		var badge = q( row, '.koneek_priority_badge' );
		var priorityEl = q( row, '.koneek_input_priority' );
		if ( badge && priorityEl ) {
			badge.textContent = priorityEl.value || '1';
		}
	}

	/**
	 * Applies a server-returned priority map to all saved cards and reorders the
	 * tiles to match. Cards are matched by their stored connection id.
	 *
	 * @param {Array} priorities List of { id, priority } objects.
	 */
	function applyPriorities( priorities ) {
		if ( ! priorities || ! priorities.length ) { return; }

		var container = document.getElementById( 'koneek_connections' );
		if ( ! container ) { return; }

		// Update each matching card's priority input + badge.
		for ( var i = 0; i < priorities.length; i++ ) {
			var entry = priorities[ i ];
			var card = container.querySelector( '.koneek_connection[data-id="' + entry.id + '"]' );
			if ( ! card ) { continue; }

			var priorityEl = q( card, '.koneek_input_priority' );
			if ( priorityEl ) { priorityEl.value = entry.priority; }
			updatePriorityBadge( card );
		}

		reorderCards( container );
	}

	/**
	 * Reorders the cards in the DOM by ascending priority so the tile grid
	 * reflects the fallback order. Unsaved cards keep their relative position
	 * at the end.
	 *
	 * @param {HTMLElement} container The cards container.
	 */
	function reorderCards( container ) {
		var cards = Array.prototype.slice.call( container.querySelectorAll( '.koneek_connection' ) );

		cards.sort( function( a, b ) {
			var pa = parseInt( ( q( a, '.koneek_input_priority' ) || {} ).value, 10 ) || 0;
			var pb = parseInt( ( q( b, '.koneek_input_priority' ) || {} ).value, 10 ) || 0;
			return pa - pb;
		} );

		for ( var i = 0; i < cards.length; i++ ) {
			container.appendChild( cards[ i ] );
		}
	}

	/**
	 * Expands or collapses a card.
	 *
	 * @param {HTMLElement} row       The connection row.
	 * @param {boolean}     collapsed Whether the card should be collapsed.
	 */
	function setCollapsed( row, collapsed ) {
		if ( collapsed ) {
			row.classList.add( 'koneek_collapsed' );
		} else {
			row.classList.remove( 'koneek_collapsed' );
		}

		var toggle = q( row, '.koneek_collapse_toggle' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		}
	}

	/**
	 * Applies provider-dependent field visibility for a row.
	 *
	 * @param {HTMLElement} row    The connection row.
	 * @param {boolean}     isInit Whether this is the initial render.
	 */
	function applyProviderState( row, isInit ) {
		var providerSelect = q( row, '.koneek_select_provider' );
		if ( ! providerSelect ) { return; }

		var provider = providerSelect.value;
		var manualModel = usesManualModel( provider );

		updateDisplayName( row );

		var dropdownWrap = q( row, '.koneek_model_dropdown_wrap' );
		var customModelWrap = q( row, '.koneek_custom_model_wrap' );
		var textWrap = q( row, '.koneek_model_text_wrap' );
		var urlRow = q( row, '.koneek_row_custom_url' );
		var timeoutRow = q( row, '.koneek_row_timeout' );

		if ( manualModel ) {
			hide( dropdownWrap );
			hide( customModelWrap );
			show( textWrap );
		} else {
			show( dropdownWrap );
			hide( textWrap );

			if ( isInit !== true ) {
				hide( customModelWrap );
				rebuildModelDropdown( row, provider );
			}
		}

		if ( requiresCustomUrl( provider ) ) {
			show( urlRow );
		} else {
			hide( urlRow );
		}

		if ( supportsTimeout( provider ) ) {
			show( timeoutRow );
		} else {
			hide( timeoutRow );
		}
	}

	/**
	 * Applies model-dropdown-dependent visibility (Custom model input).
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function applyModelState( row ) {
		var providerSelect = q( row, '.koneek_select_provider' );
		var customModelWrap = q( row, '.koneek_custom_model_wrap' );

		if ( providerSelect && usesManualModel( providerSelect.value ) ) {
			hide( customModelWrap );
			return;
		}

		var dropdown = q( row, '.koneek_select_model' );
		if ( ! dropdown ) { return; }

		if ( dropdown.value === 'custom' ) {
			show( customModelWrap );
		} else {
			hide( customModelWrap );
		}
	}

	/**
	 * Marks a card dirty (edited since last save) and clears any status text.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function markDirty( row ) {
		var status = q( row, '.koneek_card_status' );
		if ( status ) {
			status.textContent = '';
			status.style.color = '';
		}
	}

	/**
	 * Binds change/click listeners for a single row.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function bindRow( row ) {
		var toggle = q( row, '.koneek_collapse_toggle' );
		if ( toggle ) {
			toggle.addEventListener( 'click', function() {
				setCollapsed( row, ! row.classList.contains( 'koneek_collapsed' ) );
			} );
		}

		var nameInput = q( row, '.koneek_input_name' );
		if ( nameInput ) {
			nameInput.addEventListener( 'input', function() {
				updateDisplayName( row );
			} );
		}

		var priorityInput = q( row, '.koneek_input_priority' );
		if ( priorityInput ) {
			priorityInput.addEventListener( 'input', function() {
				updatePriorityBadge( row );
			} );
		}

		var providerSelect = q( row, '.koneek_select_provider' );
		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', function() {
				applyProviderState( row, false );
				markDirty( row );
			} );
		}

		var modelDropdown = q( row, '.koneek_select_model' );
		if ( modelDropdown ) {
			modelDropdown.addEventListener( 'change', function() {
				applyModelState( row );
				markDirty( row );
			} );
		}

		var removeBtn = q( row, '.koneek_button_remove' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function() {
				onRemoveRow( row );
			} );
		}

		var testBtn = q( row, '.koneek_button_test' );
		if ( testBtn ) {
			testBtn.addEventListener( 'click', function() {
				onTestConnection( row, testBtn );
			} );
		}

		var saveBtn = q( row, '.koneek_button_save' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function() {
				onSaveRow( row, saveBtn );
			} );
		}

		// Any text edit clears the status.
		var inputs = row.querySelectorAll( 'input' );
		for ( var i = 0; i < inputs.length; i++ ) {
			inputs[ i ].addEventListener( 'input', function() { markDirty( row ); } );
		}

		applyProviderState( row, true );
		applyModelState( row );
	}

	/**
	 * Removes a card. Saved cards are deleted server-side; unsaved cards are
	 * just discarded from the DOM.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function onRemoveRow( row ) {
		var container = document.getElementById( 'koneek_connections' );
		if ( ! container ) { return; }

		var existingIdEl = q( row, '.koneek_existing_id' );
		var existingId = existingIdEl ? existingIdEl.value : '';

		if ( ! existingId ) {
			// Unsaved card: just discard, but keep at least one card present.
			if ( ! window.confirm( str( 'removeUnsaved', 'Discard this provider connection?' ) ) ) {
				return;
			}
			removeOrReset( container, row );
			return;
		}

		if ( ! window.confirm( str( 'removeConfirm', 'Remove this provider connection?' ) ) ) {
			return;
		}

		var removeBtn = q( row, '.koneek_button_remove' );
		if ( removeBtn ) { removeBtn.disabled = true; }

		var formData = new FormData();
		formData.append( 'action', 'koneek_delete_connection' );
		formData.append( 'nonce', settingsData.saveNonce );
		formData.append( 'id', existingId );

		fetch( settingsData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
			.then( function( r ) { return r.json(); } )
			.then( function( payload ) {
				if ( payload.success ) {
					removeOrReset( container, row );
				} else {
					if ( removeBtn ) { removeBtn.disabled = false; }
					setStatus( row, ( payload.data && payload.data.message ) ? payload.data.message : 'Failed to remove.', false );
				}
			} )
			.catch( function( error ) {
				if ( removeBtn ) { removeBtn.disabled = false; }
				setStatus( row, str( 'requestFailed', 'Request failed: ' ) + error.message, false );
			} );
	}

	/**
	 * Removes a card from the DOM, or resets it when it's the only card left.
	 *
	 * @param {HTMLElement} container The cards container.
	 * @param {HTMLElement} row       The card to remove.
	 */
	function removeOrReset( container, row ) {
		var rows = container.querySelectorAll( '.koneek_connection' );
		if ( rows.length <= 1 ) {
			resetRow( row );
		} else {
			row.parentNode.removeChild( row );
		}
	}

	/**
	 * Resets a card to a blank, unsaved state.
	 *
	 * @param {HTMLElement} row The connection row.
	 */
	function resetRow( row ) {
		row.classList.add( 'koneek_unsaved' );
		row.setAttribute( 'data-id', '' );
		setCollapsed( row, false );

		var existingId = q( row, '.koneek_existing_id' );
		if ( existingId ) { existingId.value = ''; }

		var nameEl = q( row, '.koneek_input_name' );
		if ( nameEl ) { nameEl.value = ''; }

		var apiKey = q( row, '.koneek_input_api_key' );
		if ( apiKey ) { apiKey.value = ''; apiKey.placeholder = 'Enter API key'; }

		var provider = q( row, '.koneek_select_provider' );
		if ( provider ) {
			provider.selectedIndex = 0;
			applyProviderState( row, false );
		}

		updateDisplayName( row );
		setStatus( row, '', true );
	}

	/**
	 * Adds a new blank card by cloning the template.
	 */
	function onAddRow() {
		var container = document.getElementById( 'koneek_connections' );
		var template = document.getElementById( 'koneek_connection_template' );
		if ( ! container || ! template ) { return; }

		rowCounter += 1;
		var newIndex = 'new_' + rowCounter;

		var html = template.innerHTML.replace( /__INDEX__/g, newIndex );

		var wrapper = document.createElement( 'div' );
		wrapper.innerHTML = html.trim();
		var newRow = wrapper.firstChild;

		container.appendChild( newRow );

		var priorityInput = q( newRow, '.koneek_input_priority' );
		if ( priorityInput ) {
			priorityInput.value = container.querySelectorAll( '.koneek_connection' ).length;
		}

		bindRow( newRow );
	}

	/**
	 * Resolves the effective model value for a row.
	 *
	 * @param {HTMLElement} row      The connection row.
	 * @param {string}      provider The provider slug.
	 * @return {string}
	 */
	function resolveModel( row, provider ) {
		if ( usesManualModel( provider ) ) {
			var textEl = q( row, '.koneek_input_model_text' );
			return textEl ? textEl.value : '';
		}

		var dropdown = q( row, '.koneek_select_model' );
		if ( dropdown && dropdown.value === 'custom' ) {
			var customEl = q( row, '.koneek_input_custom_model' );
			return customEl ? customEl.value : '';
		}

		return dropdown ? dropdown.value : '';
	}

	/**
	 * Collects a card's current field values.
	 *
	 * @param {HTMLElement} row The connection row.
	 * @return {Object}
	 */
	function collectRow( row ) {
		var providerSelect = q( row, '.koneek_select_provider' );
		var provider = providerSelect ? providerSelect.value : '';
		var nameEl = q( row, '.koneek_input_name' );
		var priorityEl = q( row, '.koneek_input_priority' );
		var apiKeyEl = q( row, '.koneek_input_api_key' );
		var urlEl = q( row, '.koneek_input_custom_url' );
		var timeoutEl = q( row, '.koneek_input_timeout' );
		var existingIdEl = q( row, '.koneek_existing_id' );

		return {
			provider: provider,
			name: nameEl ? nameEl.value : '',
			priority: priorityEl ? priorityEl.value : '1',
			apiKey: apiKeyEl ? apiKeyEl.value : '',
			model: resolveModel( row, provider ),
			customUrl: urlEl ? urlEl.value : '',
			timeout: timeoutEl ? timeoutEl.value : '0',
			existingId: existingIdEl ? existingIdEl.value : ''
		};
	}

	/**
	 * Sets the status text on a card.
	 *
	 * @param {HTMLElement} row     The connection row.
	 * @param {string}      message The status message.
	 * @param {boolean}     ok      Whether it's a success (green) or error (red).
	 */
	function setStatus( row, message, ok ) {
		var status = q( row, '.koneek_card_status' );
		if ( ! status ) { return; }
		status.textContent = message;
		status.style.color = message ? ( ok ? '#00a32a' : '#d63638' ) : '';
	}

	/**
	 * Handles a per-card Save click.
	 *
	 * @param {HTMLElement} row     The connection row.
	 * @param {HTMLElement} saveBtn The save button.
	 */
	function onSaveRow( row, saveBtn ) {
		var spinner = q( row, '.koneek_spinner' );
		var values = collectRow( row );

		// The name feeds the API key option name in the database, so it may
		// only contain letters, numbers, and hyphens.
		if ( values.name && ! /^[A-Za-z0-9-]+$/.test( values.name ) ) {
			setStatus( row, str( 'invalidName', 'The name may only contain letters, numbers, and hyphens.' ), false );
			return;
		}

		saveBtn.disabled = true;
		if ( spinner ) { spinner.classList.add( 'is-active' ); }
		setStatus( row, str( 'saving', 'Saving…' ), true );

		var formData = new FormData();
		formData.append( 'action', 'koneek_save_connection' );
		formData.append( 'nonce', settingsData.saveNonce );
		formData.append( 'connection[provider]', values.provider );
		formData.append( 'connection[name]', values.name );
		formData.append( 'connection[priority]', values.priority );
		formData.append( 'connection[api_key]', values.apiKey );
		formData.append( 'connection[custom_api_url]', values.customUrl );
		formData.append( 'connection[timeout]', values.timeout );
		formData.append( 'connection[existing_id]', values.existingId );

		// Model is sent under the field name the server expects for this provider.
		if ( usesManualModel( values.provider ) ) {
			formData.append( 'connection[model_text]', values.model );
		} else {
			formData.append( 'connection[model]', values.model );
			formData.append( 'connection[custom_model]', values.model );
		}

		fetch( settingsData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
			.then( function( r ) { return r.json(); } )
			.then( function( payload ) {
				if ( payload.success ) {
					var d = payload.data || {};
					// Promote the card to a saved state.
					var existingId = q( row, '.koneek_existing_id' );
					if ( existingId && d.id ) { existingId.value = d.id; }
					if ( d.id ) { row.setAttribute( 'data-id', d.id ); }
					row.classList.remove( 'koneek_unsaved' );

					// Clear the password field; show the saved placeholder.
					var apiKey = q( row, '.koneek_input_api_key' );
					if ( apiKey && d.keySet ) {
						apiKey.value = '';
						apiKey.placeholder = '••••••••••••••••';
					}

					// Update header label/priority, then collapse to keep the list tidy.
					var titleEl = q( row, '.koneek_display_name' );
					if ( titleEl && d.displayName ) { titleEl.textContent = d.displayName; }
					updatePriorityBadge( row );
					updateModelSubtitle( row );

					// Apply any priority changes the server made to other cards
					// (e.g. a connection bumped to last by a priority collision),
					// then reorder the tiles to match.
					applyPriorities( d.priorities );

					setStatus( row, d.message || str( 'saved', 'Saved' ), true );

					window.setTimeout( function() {
						setCollapsed( row, true );
					}, 600 );
				} else {
					setStatus( row, ( payload.data && payload.data.message ) ? payload.data.message : 'Save failed.', false );
				}
			} )
			.catch( function( error ) {
				setStatus( row, str( 'requestFailed', 'Request failed: ' ) + error.message, false );
			} )
			.finally( function() {
				saveBtn.disabled = false;
				if ( spinner ) { spinner.classList.remove( 'is-active' ); }
			} );
	}

	/**
	 * Handles a per-card Test Connection click.
	 *
	 * @param {HTMLElement} row     The connection row.
	 * @param {HTMLElement} testBtn The clicked button.
	 */
	function onTestConnection( row, testBtn ) {
		var spinner = q( row, '.koneek_spinner' );
		var nonce = testBtn.getAttribute( 'data-nonce' ) || '';
		var values = collectRow( row );

		testBtn.disabled = true;
		if ( spinner ) { spinner.classList.add( 'is-active' ); }
		setStatus( row, '', true );

		var formData = new FormData();
		formData.append( 'action', 'koneek_test_connection' );
		formData.append( 'nonce', nonce );
		formData.append( 'provider', values.provider );
		formData.append( 'api_key', values.apiKey );
		formData.append( 'model', values.model );
		formData.append( 'custom_api_url', values.customUrl );
		formData.append( 'existing_id', values.existingId );

		fetch( settingsData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
			.then( function( r ) { return r.json(); } )
			.then( function( payload ) {
				if ( payload.success ) {
					setStatus( row, payload.data.message, true );
				} else {
					setStatus( row, ( payload.data && payload.data.message ) ? payload.data.message : 'Connection test failed.', false );
				}
			} )
			.catch( function( error ) {
				setStatus( row, str( 'requestFailed', 'Request failed: ' ) + error.message, false );
			} )
			.finally( function() {
				testBtn.disabled = false;
				if ( spinner ) { spinner.classList.remove( 'is-active' ); }
			} );
	}

	/**
	 * Initializes the settings page.
	 */
	function init() {
		var container = document.getElementById( 'koneek_connections' );
		if ( ! container ) { return; }

		var rows = container.querySelectorAll( '.koneek_connection' );
		for ( var i = 0; i < rows.length; i++ ) {
			bindRow( rows[ i ] );
		}

		var addBtn = document.getElementById( 'koneek_add_connection' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', onAddRow );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
