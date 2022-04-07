<?php

use Wikimedia\Rdbms\DBQueryError;

class SentryHooks {

	/**
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgSentryEventGateUri, $wgSentryDsn, $wgSentryWhitelist, $wgSentryLogOnError;

		$vars['wgSentry'] = [
			'eventGateUri' => $wgSentryEventGateUri,
			'dsn' => self::getPublicDsnFromFullDsn( $wgSentryDsn ),
			'whitelist' => $wgSentryWhitelist,
			'logOnError' => $wgSentryLogOnError,
		];
	}

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( &$out, &$skin ) {
		global $wgSentryEventGateUri, $wgSentryDsn;
		$out->addModules( [ 'sentry.init' ] );
		if ( $wgSentryEventGateUri ) {
			$parts = wfParseUrl( $wgSentryEventGateUri );
			if ( $parts && isset( $parts['host'] ) ) {
				$out->getCSP()->addDefaultSrc( $parts['host'] );
			}
		} elseif ( $wgSentryDsn ) {
			// We only need to allow this if EventGate is not set.
			$parts = wfParseUrl( self::getPublicDsnFromFullDsn( $wgSentryDsn ) );
			if ( $parts && isset( $parts['host'] ) ) {
				$out->getCSP()->addDefaultSrc( $parts['host'] );
			}
		}
	}

	/**
	 * For JS logging, the private key must be omitted from the DSN.
	 * @param string $dsn
	 * @return string
	 */
	protected static function getPublicDsnFromFullDsn( $dsn ) {
		$slash_pos = strpos( $dsn, '//' );
		$colon_pos = strpos( $dsn, ':', $slash_pos );
		$at_pos = strpos( $dsn, '@' );
		if ( $colon_pos < 1 || $at_pos < 1 || $colon_pos > $at_pos ) {
			// something wrong - maybe $dsn is already public?
			return $dsn;
		}
		return substr( $dsn, 0, $colon_pos ) . substr( $dsn, $at_pos );
	}

	/**
	 * @param Exception|Throwable $e
	 * @param bool $suppressed True if the error is below the level set in error_reporting().
	 */
	public static function onLogException( $e, $suppressed ) {
		global $wgSentryDsn, $wgSentryLogPhpErrors;

		if ( !$wgSentryLogPhpErrors || $suppressed ) {
			return;
		}

		$client = \Sentry\ClientBuilder::create( [ 'dsn' => $wgSentryDsn ] )->getClient();
		$scope = new \Sentry\State\Scope;

		$scope->setTags([
			'host' => wfHostname(),
			'wiki' => WikiMap::getCurrentWikiId(),
			'version' => MW_VERSION,
		]);

		/** @phan-suppress-next-line PhanUndeclaredProperty */
		if ( isset( $e->_mwLogId ) ) {
			$scope->setExtra( 'event_id', $e->_mwLogId );
		}
		if ( $e instanceof DBQueryError ) {
			$scope->setExtra( 'culprit', $e->fname );
		}

		$result = $client->captureException( $e, $scope = $scope );
		if ( $result === null ) {
			// sentry-php >= 2.0 doesn't return error by library itself.
			wfDebugLog( 'sentry', 'Sentry error during catpture exception' );
		}
	}
}
