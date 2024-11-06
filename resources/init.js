/* eslint camelcase: ["error", {properties: "never"}] */
( function () {
	var sentryPromise,
		errorCount = 0,
		sentryDisabled = false,
		EVENT_GATE_SHELL = {
			$schema: '/client/error/1.0.0',
			meta: { stream: 'client.error' }
		};

	/**
	 * @return {jQuery.Deferred} a deferred that resolves with the Sentry object
	 */
	function initSentry() {
		if ( !sentryPromise ) {
			sentryPromise = mw.loader.using( 'sentry.browser' ).then( function () {
				var config = mw.config.get( 'wgSentry' ),
					options = {};

				// If EventGate is configured, this extension will send errors to it.
				// However, it needs to configure the sentry dsn to initialize Sentry
				if ( config.eventGateUri ) {
					config.dsn =
						config.dsn ||
						'https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@sentry.io/project';
				} else if ( !config.dsn ) {
					mw.log.error( 'See README for how to configure Sentry server' );
				}

				if ( config.whitelist ) {
					options.allowUrls = config.whitelist.slice( 0 );
					options.allowUrls.push( location.host );
				}
				options.enabled = config.logOnError;

				options.release = mw.config.get( 'wgVersion' );
				options.environment = mw.config.get( 'debug' ) ?
					'development' :
					'production';

				options.initialScope = {
					tags: {
						skin: mw.config.get( 'skin' ),
						action: mw.config.get( 'wgAction' ),
						ns: mw.config.get( 'wgNamespaceNumber' ),
						page_name: mw.config.get( 'wgPageName' ),
						user_groups: mw.config.get( 'wgUserGroups' ),
						language: mw.config.get( 'wgUserLanguage' )
					}
				};

				options.beforeSend = function ( event, hint ) {
					var eventGateData;
					// don't flood the server / freeze the client when something generates
					// an endless stream of errors
					if ( errorCount++ >= 5 || sentryDisabled ) {
						sentryDisabled = true;
						return null; // Drop the event
					}

					if ( config.eventGateUri ) {
						// hijack the actual sending and POST to EventGate
						eventGateData = Object.assign( {}, EVENT_GATE_SHELL, event );

						// use a flatter format, Sentry's tag syntax doesn't play well with Logstash
						delete eventGateData.tags;
						$.each( event.tags, function ( tagName, tagValue ) {
							eventGateData[ 'tag_' + tagName ] = tagValue;
						} );

						// Sentry's culprit field is based on script URL, which is not very useful
						// with ResourceLoader. Provide function-based fields for grouping instead.
						eventGateData.culprit_function = null;
						eventGateData.culprit_stack = '';
						if (
							event.exception &&
							event.exception.values &&
							event.exception.values.length
						) {
							var frames = event.exception.values[ 0 ].stacktrace.frames;
							if ( frames && frames.length ) {
								eventGateData.culprit_function =
									frames[ frames.length - 1 ].function;
								eventGateData.culprit_stack = $.map( frames, function ( frame ) {
									return frame.function;
								} )
									.reverse()
									.join( ' < ' );
							}
						}

						$.post( config.eventGateUri, eventGateData ).fail( function ( error ) {
							mw.log.warn( 'POSTing error to EventGate failed', error );
						} );
						return null; // Drop the event
					}

					return event; // Continue sending to Sentry
				};

				// Initialize Sentry
				try {
					Sentry.init( Object.assign( { dsn: config.dsn }, options ) );
				} catch ( e ) {
					mw.log.error( e );
					return $.Deferred().reject( e );
				}

				return $.Deferred().resolve( Sentry );
			} );
		}
		return sentryPromise;
	}

	/**
	 * @param {string} topic mw.track() queue name
	 * @param {Object} data
	 * @param {Mixed} data.exception The exception which has been caught
	 * @param {string} data.id An identifier for the exception
	 * @param {string} data.source Describes what type of function caught the exception
	 * @param {string} [data.module] Name of the module which threw the exception
	 * @param {Object} [data.context] Additional key-value pairs to be recorded as Sentry tags
	 */
	function report( topic, data ) {
		mw.sentry.initSentry().done( function ( Sentry ) {
			var tags = { source: data.source };

			if ( data.module ) {
				tags.module = data.module;
			}
			Object.assign( tags, data.context );

			Sentry.withScope( function ( scope ) {
				scope.setTags( tags );
				Sentry.captureException( data.exception );
			} );
		} );
	}

	/**
	 * Handles global.error events.
	 *
	 * @param {string} topic mw.track() queue name
	 * @param {Object} data
	 */
	function handleGlobalError( topic, data ) {
		mw.sentry.initSentry().done( function ( Sentry ) {
			var error = data.errorObject || new Error( data.errorMessage );

			Sentry.withScope( function ( scope ) {
				scope.setExtra( {
					url: data.url,
					lineNumber: data.lineNumber,
					columnNumber: data.columnNumber
				} );
				Sentry.captureException( error );
			} );
		} );
	}

	// make these available for unit tests
	mw.sentry = { initSentry: initSentry, report: report };

	mw.trackSubscribe( 'resourceloader.exception', report );

	mw.trackSubscribe( 'global.error', handleGlobalError );

	mw.trackSubscribe( 'eventlogging.error', function ( topic, error ) {
		mw.sentry.initSentry().done( function ( Sentry ) {
			Sentry.withScope( function ( scope ) {
				scope.setTag( 'source', 'EventLogging' );
				Sentry.captureMessage( error );
			} );
		} );
	} );
}() );
