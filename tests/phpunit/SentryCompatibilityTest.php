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

	public function testInvalidDsnDoesNotReplaceOriginalError() {
		$this->setMwGlobals( [
			'wgSentryDsn' => 'not a valid DSN',
			'wgSentryLogPhpErrors' => true,
		] );
		SentryHooks::onLogException( new RuntimeException( 'Original application error' ), false );
		$this->addToAssertionCount( 1 );
	}
}
