( function () {
	QUnit.module( 'sentry', QUnit.newMwEnvironment() );

	QUnit.test( 'initSentry()', function ( assert ) {
		window.Sentry = window.Sentry || undefined; // sinon.js won't stub nonexistent properties
		this.sandbox.stub( window, 'Sentry', {
			config: this.sandbox.stub().returnsThis(),
			install: this.sandbox.stub().returnsThis()
		} );

		this.sandbox.stub( mw.loader, 'using' ).returns( $.Deferred().resolve() );

		return mw.sentry.initSentry().then( function ( sentry /* , traceKitOnError */ ) {
			assert.strictEqual( sentry, Sentry, 'initSentry() returns Sentry as a promise' );
			assert.true( Sentry.config.called, 'Sentry is configured' );
			assert.true( Sentry.install.called, 'Sentry is installed' );

			Sentry.config.reset();
			Sentry.install.reset();

			return mw.sentry.initSentry();
		} ).then( function ( sentry /* , traceKitOnError */ ) {
			assert.strictEqual( sentry, Sentry, 'initSentry() returns Sentry on second invocation' );
			assert.strictEqual( Sentry.config.called, false, 'Sentry is not configured twice' );
			assert.strictEqual( Sentry.install.called, false, 'Sentry is not installed twice' );
		} );
	} );

	QUnit.test( 'report()', function ( assert ) {
		var sentry = { captureException: this.sandbox.stub() };

		this.sandbox.stub( mw.sentry, 'initSentry' ).returns( $.Deferred().resolve( sentry ) );

		mw.sentry.report( 'some-topic', { exception: 42, source: 'Deep Thought' } );
		assert.strictEqual( sentry.captureException.lastCall.args[ 0 ], 42, 'Exception matches' );
		assert.strictEqual( sentry.captureException.lastCall.args[ 1 ].tags.source, 'Deep Thought', 'Source matches' );

		mw.sentry.report( 'some-topic', { exception: 42, source: 'Deep Thought', module: 'foo' } );
		assert.strictEqual( sentry.captureException.lastCall.args[ 1 ].tags.module, 'foo', 'Module matches' );

		mw.sentry.report( 'some-topic', { exception: 42, source: 'Deep Thought', context: { foo: 'bar' } } );
		assert.strictEqual( sentry.captureException.lastCall.args[ 1 ].tags.foo, 'bar', 'Custom context matches' );
	} );
}() );
