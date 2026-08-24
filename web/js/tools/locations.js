(function (Q, $, window, undefined) {

	/**
	 * Places Tools
	 * @module Places-tools
	 */

	var Users = Q.Users;
	var Streams = Q.Streams;
	var Places = Q.Places;

	/**
	 * Manage related Places/location streams, optionally with nested areas and tables.
	 * @class Places locations
	 * @constructor
	 * @param {Object} [options]
	 * @param {String} [options.publisherId=null] Defaults to Q.Users.communityId
	 * @param {String} [options.streamName="Places/user/locations"] Category stream name
	 * @param {String} [options.relationType="Places/locations"] Relation type for locations
	 * @param {Boolean} [options.showCurrent=false] Show current geolocation row
	 * @param {Object} [options.locations] Permissions for location streams
	 * @param {Boolean} [options.locations.creatable=false]
	 * @param {Boolean} [options.locations.editable=false]
	 * @param {Boolean} [options.locations.closeable=false]
	 * @param {Object|null} [options.areas=null] Permissions for areas; null skips nesting
	 * @param {Object|null} [options.tables=null] Permissions for tables; null skips nesting (requires areas)
	 */
	Q.Tool.define("Places/locations", function (options) {
		var tool = this;
		var state = this.state;

		tool.element.setAttribute('data-show-current', state.showCurrent ? 'true' : 'false');
		tool.element.setAttribute('data-show-areas', state.areas != null ? 'true' : 'false');
		tool.element.setAttribute('data-show-tables',
			(state.areas != null && state.tables != null) ? 'true' : 'false'
		);

		tool.refresh();
	},

	{
		publisherId: Users.communityId,
		streamName: 'Places/user/locations',
		relationType: 'Places/locations',
		showCurrent: false,
		locations: {
			creatable: false,
			editable: false,
			closeable: false
		},
		areas: null,
		tables: null,
		onCurrent: new Q.Event()
	},

	{
		/**
		 * @method refresh
		 */
		refresh: function () {
			var tool = this;
			var state = this.state;
			var loc = state.locations || {};

			Q.Template.render('Places/locations', {
				currentLocationText: Q.getObject('location.myCurrentLocation', tool.text)
					|| 'My current location'
			}, function (err, html) {
				if (err) {
					return;
				}

				Q.replace(tool.element, html);

				tool.$('.Places_locations_current').on(Q.Pointer.fastclick, function () {
					tool._selectCurrent($(this));
				});

				var previewOptions = {
					editable: !!loc.editable,
					closeable: !!loc.closeable,
					imagepicker: { showSize: '40' }
				};
				if (loc.closeable) {
					previewOptions.beforeClose = function () {
						var preview = this;
						tool._confirmRemove(function () {
							tool._unrelateLocation(preview);
						});
					};
				}
				if (loc.editable) {
					previewOptions.actions = {
						position: 'mr',
						actions: {
							edit: function () {
								var preview = Q.Tool.from(
									$(this).closest('.Streams_preview_tool')[0],
									'Streams/preview'
								);
								if (preview) {
									tool.editLocation(preview, this);
								}
							}
						}
					};
				}

				var relatedOptions = {
					publisherId: state.publisherId,
					streamName: state.streamName,
					relationType: state.relationType,
					isCategory: true,
					editable: !!loc.editable,
					realtime: true,
					sortable: false,
					previewOptions,
					onRefresh: function () {
						if (state.areas != null) {
							tool.wrapExpandable(this, 'location');
						}
					}
				};
				if (loc.creatable) {
					relatedOptions.creatable = {
						'Places/location': {
							publisherId: state.publisherId,
							addIconSize: 40,
							title: Q.getObject('locations.newLocation', tool.text)
								|| Q.getObject('location.create.action', tool.text)
								|| 'Add Location',
							preprocess: function (_proceed, previewTool) {
								tool.composeLocation(_proceed, previewTool && previewTool.element);
							}
						}
					};
				}

				tool.$('.Places_locations_related')
					.tool('Streams/related', relatedOptions, tool.prefix + 'locations')
					.activate();
			});
		},

		/**
		 * Options for areas related under a location
		 * @method _areasRelatedOptions
		 * @param {String} publisherId
		 * @param {String} streamName
		 * @return {Object}
		 */
		_areasRelatedOptions: function (publisherId, streamName) {
			var tool = this;
			var state = tool.state;
			var areas = state.areas || {};

			var previewOptions = {
				editable: !!areas.editable,
				closeable: !!areas.closeable,
				imagepicker: { showSize: '40' }
			};
			if (areas.closeable) {
				previewOptions.beforeClose = tool._confirmRemove.bind(tool);
			}

			var options = {
				publisherId,
				streamName,
				relationType: 'Places/areas',
				isCategory: true,
				editable: !!areas.editable,
				realtime: true,
				sortable: false,
				previewOptions,
				onRefresh: function () {
					if (state.areas != null && state.tables != null) {
						tool.wrapExpandable(this, 'area');
					}
				}
			};
			if (areas.creatable) {
				options.creatable = {
					'Places/area': {
						publisherId: state.publisherId,
						addIconSize: 40,
						title: Q.getObject('locations.newArea', tool.text)
							|| Q.getObject('areas.newArea', tool.text)
							|| 'New area',
						attributes: {
							location: { publisherId, streamName }
						},
						preprocess: function (_proceed) {
							tool.promptTitle('area', this, _proceed);
						}
					}
				};
			}
			return options;
		},

		/**
		 * Options for tables related under an area
		 * @method _tablesRelatedOptions
		 * @param {String} publisherId Area publisher id
		 * @param {String} streamName Area stream name
		 * @param {Object} location Parent location {publisherId, streamName}
		 * @return {Object}
		 */
		_tablesRelatedOptions: function (publisherId, streamName, location) {
			var tool = this;
			var state = tool.state;
			var tables = state.tables || {};

			var previewOptions = {
				editable: !!tables.editable,
				closeable: !!tables.closeable,
				imagepicker: { showSize: '40' }
			};
			if (tables.closeable) {
				previewOptions.beforeClose = tool._confirmRemove.bind(tool);
			}

			var options = {
				publisherId,
				streamName,
				relationType: 'Places/table',
				isCategory: true,
				editable: !!tables.editable,
				realtime: true,
				sortable: false,
				previewOptions
			};
			if (tables.creatable) {
				options.creatable = {
					'Places/table': {
						publisherId: state.publisherId,
						addIconSize: 40,
						title: Q.getObject('locations.newTable', tool.text) || 'New table',
						attributes: {
							location: {
								publisherId: location.publisherId,
								streamName: location.streamName
							},
							area: { publisherId, streamName }
						},
						preprocess: function (_proceed) {
							tool.promptTitle('table', this, _proceed);
						}
					}
				};
			}
			return options;
		},

		/**
		 * Wrap non-composer previews in a CSS expandable node
		 * @method wrapExpandable
		 * @param {Q.Tool} relatedTool
		 * @param {String} level "location" or "area"
		 */
		wrapExpandable: function (relatedTool, level) {
			var tool = this;

			// Streams/related may insert next to a nested preview (inside a node title).
			// Lift any preview that is not this node's owner out to be wrapped separately.
			$(relatedTool.element).children('.Places_locations_node').each(function () {
				var $node = $(this);
				var $title = $node.children('.Places_locations_node_title');
				var $previews = $title.children('.Streams_preview_tool')
					.not('.Streams_related_composer, .Streams_preview_composer, .Streams_related_loading, .Q_working');
				if ($previews.length <= 1) {
					return;
				}

				var ownerPublisherId = $node.attr('data-publisherId');
				var ownerStreamName = $node.attr('data-streamName');
				if (!ownerPublisherId || !ownerStreamName) {
					// Nodes wrapped before owner attrs existed: first preview is the owner
					var firstPreview = Q.Tool.from($previews[0], 'Streams/preview');
					if (firstPreview) {
						ownerPublisherId = firstPreview.state.publisherId;
						ownerStreamName = firstPreview.state.streamName;
						$node.attr({
							'data-publisherId': ownerPublisherId,
							'data-streamName': ownerStreamName
						});
					}
				}

				$previews.each(function () {
					var previewTool = Q.Tool.from(this, 'Streams/preview');
					if (previewTool
					&& ownerPublisherId
					&& ownerStreamName
					&& previewTool.state.publisherId === ownerPublisherId
					&& previewTool.state.streamName === ownerStreamName) {
						return;
					}
					var $stray = $(this);
					$stray.removeData('Places_locations_wrapped');
					$node.after($stray);
				});
			});

			$(relatedTool.element)
			.children('.Streams_preview_tool')
			.not('.Streams_related_composer, .Streams_preview_composer, .Streams_related_loading, .Q_working')
			.each(function () {
				var $preview = $(this);
				if ($preview.parent().hasClass('Places_locations_node_title')) {
					return;
				}
				if ($preview.data('Places_locations_wrapped')) {
					return;
				}

				var previewTool = Q.Tool.from(this, 'Streams/preview');
				if (!previewTool || !previewTool.state.streamName) {
					return;
				}
				$preview.data('Places_locations_wrapped', true);

				Q.Template.render('Places/locations/node', { level }, function (err, html) {
					if (err) {
						$preview.removeData('Places_locations_wrapped');
						return;
					}
					// Preview may have been moved/removed while the template rendered
					if (!$preview.closest(relatedTool.element).length) {
						$preview.removeData('Places_locations_wrapped');
						return;
					}

					var $node = $(html);
					var $title = $node.find('.Places_locations_node_title');
					var $content = $node.find('.Places_locations_node_content');
					var $chevron = $node.find('.Places_locations_chevron');

					$node.attr({
						'data-publisherId': previewTool.state.publisherId,
						'data-streamName': previewTool.state.streamName
					});

					$preview.before($node);
					$title.prepend($preview);

					// When the preview stream is removed, drop the expandable shell too
					previewTool.Q.beforeRemove.set(function () {
						var node = $node[0];
						if (!node || !node.parentNode
						|| node.getAttribute('data-removing')) {
							return;
						}
						node.setAttribute('data-removing', 'true');
						// After this preview finishes leaving the DOM, remove the
						// leftover wrapper (chevron, nested related, etc.).
						setTimeout(function () {
							if (node.parentNode) {
								Q.removeElement(node, true);
							}
						}, 0);
					}, tool);

					$chevron.on(Q.Pointer.fastclick, function (event) {
						event.stopPropagation();
						Q.Pointer.cancelClick(true, event);

						var expanded = $node.attr('data-expanded') === 'true';
						$node.attr('data-expanded', expanded ? 'false' : 'true');
						if (expanded || $content.data('Places_locations_loaded')) {
							return;
						}
						$content.data('Places_locations_loaded', true);
						tool._loadNested(level, previewTool, $content);
					});
				});
			});
		},

		/**
		 * Confirm before removing a related stream
		 * @method _confirmRemove
		 * @param {Function} _delete Proceed with removal
		 */
		_confirmRemove: function (_delete) {
			var tool = this;
			Q.confirm(
				Q.getObject('locations.AreYouSure', tool.text),
				function (result) {
					if (result) {
						_delete();
					}
				}
			);
		},

		/**
		 * Unrelate a location from the category without closing the stream
		 * @method _unrelateLocation
		 * @param {Q.Tool} preview Streams/preview tool
		 */
		_unrelateLocation: function (preview) {
			var related = preview.state.related;
			var publisherId = preview.state.publisherId;
			var streamName = preview.state.streamName;
			if (!related || !publisherId || !streamName) {
				return;
			}

			preview.element.addClass('Q_working');
			Q.Masks.show(preview, {
				shouldCover: preview.element,
				className: 'Q_removing'
			});

			Streams.unrelate(
				related.publisherId,
				related.streamName,
				related.type,
				publisherId,
				streamName,
				function (err) {
					preview.element.removeClass('Q_working');
					Q.Masks.hide(preview);
					if (err) {
						return console.error(err);
					}
					var relatedElement = $(preview.element)
						.closest('.Streams_related_tool')[0];
					var relatedTool = relatedElement
						? Q.Tool.from(relatedElement, 'Streams/related')
						: null;
					if (relatedTool) {
						relatedTool.removeRelation(publisherId, streamName);
					} else {
						Q.removeElement(preview.element, true);
					}
				}
			);
		},

		/**
		 * Activate nested Streams/related inside an expandable content
		 * @method _loadNested
		 * @param {String} level
		 * @param {Q.Tool} previewTool
		 * @param {jQuery} $content
		 */
		_loadNested: function (level, previewTool, $content) {
			var tool = this;
			var publisherId = previewTool.state.publisherId;
			var streamName = previewTool.state.streamName;
			var options;
			if (level === 'location') {
				options = tool._areasRelatedOptions(publisherId, streamName);
			} else {
				var relatedElement = $(previewTool.element)
					.closest('.Streams_related_tool')[0];
				var relatedTool = relatedElement
					? Q.Tool.from(relatedElement, 'Streams/related')
					: null;
				options = tool._tablesRelatedOptions(publisherId, streamName, {
					publisherId: relatedTool
						? relatedTool.state.publisherId
						: publisherId,
					streamName: relatedTool
						? relatedTool.state.streamName
						: streamName
				});
			}
			var toolId = tool.prefix + level + '_'
				+ Q.normalize(publisherId + '_' + streamName);

			$content.tool('Streams/related', options, toolId).activate();
		},

		/**
		 * Open Places/address to create a new location via Places/location POST
		 * @method composeLocation
		 * @param {Function} _proceed Streams/preview creatable callback (cancel with false)
		 * @param {HTMLElement} [trigger] Invocation trigger element inside columns
		 */
		composeLocation: function (_proceed, trigger) {
			var tool = this;
			var state = tool.state;

			var invokeObj = Q.invoke({
				title: Q.getObject('locations.addressTitle', tool.text)
					|| Q.getObject('location.dialog.title', tool.text)
					|| 'Choose Location',
				trigger: trigger || tool.element,
				className: 'Places_locations_address_invoke',
				fullscreen: Q.info.isMobile,
				content: $('<div />').tool('Places/address', {
					filter: {
						placeholders: null,
						fullscreen: false
					},
					onChoose: function (place) {
						if (!place || !place.id) {
							return;
						}
						Q.req('Places/location', function (err, response) {
							var msg = Q.firstErrorMessage(err, response && response.errors);
							if (msg) {
								Q.alert(msg);
								return;
							}
							Q.handle(_proceed, null, [false]);
							if (invokeObj && invokeObj.close) {
								invokeObj.close();
							}
						}, {
							method: 'post',
							fields: {
								placeId: place.id,
								publisherId: state.publisherId,
								streamName: state.streamName,
								type: state.relationType
							}
						});
					}
				})
			});
		},

		/**
		 * Edit an existing location via Places/address
		 * @method editLocation
		 * @param {Q.Tool} preview Streams/preview tool
		 * @param {HTMLElement} [trigger] Invocation trigger element inside columns
		 */
		editLocation: function (preview, trigger) {
			var tool = this;
			var publisherId = preview.state.publisherId;
			var streamName = preview.state.streamName;

			Streams.get(publisherId, streamName, function (err) {
				if (err) {
					return;
				}
				var stream = this;
				var place = null;
				var placeId = stream.getAttribute('placeId');
				if (placeId) {
					place = {
						id: placeId,
						name: stream.getAttribute('venue') || stream.fields.title,
						description: stream.getAttribute('address') || ''
					};
				}

				// Places/address applies state.place via _choose, which fires onChoose once on open.
				// Skip that initial apply so we only save/close when the user picks a place.
				var skipInitialChoose = !!place;

				var invokeObj = Q.invoke({
					title: Q.getObject('locations.editAddressTitle', tool.text)
						|| Q.getObject('location.dialog.title', tool.text)
						|| 'Choose Location',
					trigger: trigger || preview.element,
					className: 'Places_locations_address_invoke',
					fullscreen: Q.info.isMobile,
					content: $('<div />').tool('Places/address', {
						latitude: stream.getAttribute('latitude'),
						longitude: stream.getAttribute('longitude'),
						filter: {
							placeholders: null,
							fullscreen: false
						},
						place,
						onChoose: function (chosen) {
							if (!chosen || !chosen.id) {
								return;
							}
							if (skipInitialChoose) {
								skipInitialChoose = false;
								return;
							}
							tool._geocodePlace(chosen, function (attributes) {
								stream.set('title', chosen.name || attributes.venue);
								stream.setAttribute(attributes);
								stream.save({
									onRefresh: function () {
										var locationPreview = Q.Tool.from(
											preview.element,
											'Places/location/preview'
										);
										if (locationPreview) {
											locationPreview.refresh(this);
										} else {
											preview.preview();
										}
										if (invokeObj && invokeObj.close) {
											invokeObj.close();
										}
									}
								});
							});
						}
					})
				});
			});
		},

		/**
		 * Geocode a Google place into Places/location attributes
		 * @method _geocodePlace
		 * @param {Object} place
		 * @param {Function} callback
		 */
		_geocodePlace: function (place, callback) {
			Places.Coordinates.from({
				placeId: place.id
			}).geocode(function (err, results) {
				var msg = Q.firstErrorMessage(err);
				if (msg) {
					throw new Q.Error(msg);
				}
				var result = results && results[0];
				if (!result) {
					return;
				}
				var attributes = {
					types: result.types,
					latitude: result.geometry.location.lat(),
					longitude: result.geometry.location.lng(),
					locationType: result.geometry.type,
					venue: place.name,
					address: result.formatted_address || result.address || place.description || ''
				};
				if (result.place_id) {
					attributes.placeId = result.place_id;
				}
				Q.handle(callback, null, [attributes]);
			});
		},

		/**
		 * Prompt for a title when creating area or table
		 * @method promptTitle
		 * @param {String} kind "area" or "table"
		 * @param {Q.Tool} previewTool composer Streams/preview
		 * @param {Function} _proceed
		 */
		promptTitle: function (kind, previewTool, _proceed) {
			var tool = this;
			var state = tool.state;
			var locationsText = tool.text.locations;
			var areasText = tool.text.areas;

			var promptMessage = kind === 'area'
				? (locationsText.areaPrompt || areasText.promptTitle || 'Enter a name for the area:')
				: (locationsText.tablePrompt || 'Enter a name for the table:');
			var dialogTitle = kind === 'area'
				? (locationsText.addArea || areasText.addNewArea || 'Add new area')
				: (locationsText.addTable || 'Add new table');
			var okLabel = locationsText.add || areasText.add || 'Add';
			var absent = kind === 'area'
				? (locationsText.areaAbsent || areasText.absent || 'Please set a title')
				: (locationsText.tableAbsent || 'Please set a title');
			var exist = kind === 'area'
				? (locationsText.areaExist || areasText.exist || 'Already exists')
				: (locationsText.tableExist || 'Already exists');
			var errorTitle = locationsText.error || areasText.error || 'Error';

			var relatedElement = $(previewTool.element).closest('.Streams_related_tool')[0];
			var relatedTool = relatedElement
				? Q.Tool.from(relatedElement, 'Streams/related')
				: null;

			Q.prompt(null, function (title) {
				if (!title) {
					return false;
				}

				var existing = [];
				if (relatedTool) {
					existing = relatedTool.$('.Streams_preview_title').map(function () {
						return $.trim($(this).text());
					}).get() || [];
				}
				if (existing.indexOf(title) >= 0) {
					Q.alert(exist, {
						title: errorTitle,
						onClose: function () {
							tool.promptTitle(kind, previewTool, _proceed);
						}
					});
					return false;
				}

				Q.handle(_proceed, null, [{
					title,
					publisherId: state.publisherId,
					attributes: Q.getObject(['creatable', 'attributes'], previewTool.state)
				}]);
			}, {
				title: dialogTitle,
				ok: okLabel,
				className: 'Places_locations_title_prompt'
			});
		},

		/**
		 * Select current geolocation (visual only unless extended later)
		 * @method _selectCurrent
		 * @param {jQuery} $row
		 */
		_selectCurrent: function ($row) {
			var tool = this;
			$row.addClass('Q_working');
			navigator.geolocation.getCurrentPosition(function (pos) {
				$row.removeClass('Q_working').addClass('Q_selected');
				$(tool.element).find('.Places_locations_current').not($row)
					.removeClass('Q_selected');
				Q.handle(tool.state.onCurrent, tool, [pos && pos.coords]);
			}, function () {
				$row.removeClass('Q_working');
			}, {
				enableHighAccuracy: false,
				timeout: 5000,
				maximumAge: 0
			});
		}
	});

	Q.Template.set('Places/locations',
		`<div class="Places_locations_current" data-location="current">{{currentLocationText}}</div>
		<div class="Places_locations_related"></div>`
	);

	Q.Template.set('Places/locations/node',
		`<div class="Places_locations_node" data-expanded="false" data-level="{{level}}">
			<div class="Places_locations_node_title">
				<i class="qp-places-cheveron-outline-down Places_locations_chevron"></i>
			</div>
			<div class="Places_locations_node_content"></div>
		</div>`
	);

})(Q, Q.jQuery, window);
