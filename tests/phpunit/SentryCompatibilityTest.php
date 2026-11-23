<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\ContentSecurityPolicy;

/**
 * @group Sentry
 * @covers SentryHooks
 */
class SentryCompatibilityTest extends MediaWikiIntegrationTestCase {
	/** @dataProvider provideEndpoints */
	public function testCspUsesUrlUtils( $eventGate, $dsn, $expectedUrl, $host ) {
		$this->setMwGlobals( [
			'wgSentryEventGateUri' => $eventGate,
			'wgSentryDsn' => $dsn,
		] );
		$urlUtils = $this->createMock( get_class( MediaWikiServices::getInstance()->getUrlUtils() ) );
		$urlUtils->expects( $this->once() )->method( 'parse' )
			->with( $expectedUrl )->willReturn( [ 'host' => $host ] );
		$this->setService( 'UrlUtils', $urlUtils );
		$csp = $this->createMock( ContentSecurityPolicy::class );
		$csp->expects( $this->once() )->method( 'addDefaultSrc' )->with( $host );
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )->method( 'addModules' )->with( [ 'sentry.init' ] );
		$out->method( 'getCSP' )->willReturn( $csp );
		$skin = null;
		SentryHooks::onBeforePageDisplay( $out, $skin );
	}

	public static function provideEndpoints() {
		return [
			'event gate takes precedence' => [
				'https://events.example.test/v1/events', 'https://key@sentry.example.test/1',
				'https://events.example.test/v1/events', 'events.example.test',
			],
			'sentry strips secret' => [
				null, 'https://public:secret@sentry.example.test/1',
				'https://public@sentry.example.test/1', 'sentry.example.test',
			],
		];
	}

	/** @dataProvider provideBrowserDsns */
	public function testBrowserConfigNeverExposesSecret( $dsn, $expectedDsn ) {
		$this->setMwGlobals( [
			'wgSentryDsn' => $dsn,
			'wgSentryEventGateUri' => null,
			'wgSentryWhitelist' => [ 'wiki.example.test' ],
			'wgSentryLogOnError' => true,
		] );
		$vars = [];
		SentryHooks::onResourceLoaderGetConfigVars( $vars );
		$this->assertSame( [
			'eventGateUri' => null,
			'dsn' => $expectedDsn,
			'whitelist' => [ 'wiki.example.test' ],
			'logOnError' => true,
		], $vars['wgSentry'] );
	}

	public static function provideBrowserDsns() {
		return [
			'private DSN' => [ 'https://public:secret@sentry.example.test/1', 'https://public@sentry.example.test/1' ],
			'public DSN' => [ 'https://public@sentry.example.test/1', 'https://public@sentry.example.test/1' ],
			'disabled' => [ null, null ],
		];
	}

	public function testInvalidEndpointDoesNotModifyCsp() {
		$this->setMwGlobals( [
			'wgSentryEventGateUri' => 'not a URL',
			'wgSentryDsn' => null,
		] );
		$csp = $this->createMock( ContentSecurityPolicy::class );
		$csp->expects( $this->never() )->method( 'addDefaultSrc' );
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )->method( 'addModules' )->with( [ 'sentry.init' ] );
		$out->method( 'getCSP' )->willReturn( $csp );
		$skin = null;
		SentryHooks::onBeforePageDisplay( $out, $skin );
	}

	public function testInvalidDsnDoesNotReplaceOriginalError() {
		$this->setMwGlobals( [
			'wgSentryDsn' => 'not a valid DSN',
			'wgSentryLogPhpErrors' => true,
		] );
		SentryHooks::onLogException( new RuntimeException( 'Original application error' ), false );
		$this->addToAssertionCount( 1 );
	}
}
