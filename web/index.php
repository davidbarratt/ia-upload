<?php

namespace IaUpload;

use Silex\Application;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Wikimedia\SimpleI18n\I18nContext;
use Wikimedia\SimpleI18n\JsonCache;
use Wikimedia\SimpleI18n\TwigExtension;

require_once __DIR__ . '/../vendor/autoload.php';

// Set memory limit to 256M to be sure that all files could be uploaded.
ini_set( 'memory_limit', '256M' );
date_default_timezone_set( 'UTC' );

$configFile = __DIR__ . '/../config.ini';
$config = parse_ini_file( $configFile );
if ( $config === false ) {
	echo "Unable to parse config file at $configFile";
	exit( 1 );
}

$app = new Application();

// Ensure the tool is accessed over HTTPS.
$app->before( function ( Request $request, Application $app ) {
	if ( $request->headers->get( 'X-Forwarded-Proto' ) == 'http' ) {
		$uri = 'https://' . $request->getHost() . $request->headers->get( 'X-Original-URI' );
		return $app->redirect( $uri );
	}
}, Application::EARLY_EVENT );

// Sessions.
$request = Request::createFromGlobals();
$app->register( new SessionServiceProvider(), [
	'session.storage.options' => [
		// Cookie lifetime to match default $wgCookieExpiration.
		'cookie_lifetime' => 30 * 24 * 60 * 60,
		'name' => 'ia-upload-session',
		'cookie_path' => $request->getBaseUrl(),
		'cookie_httponly' => true,
		'cookie_secure' => $request->getHost() !== 'localhost',
	]
] );

// Twig views.
$app->register( new TwigServiceProvider(), [
	'twig.path' => __DIR__ . '/../views',
] );

// Logging and debugging.
$app->register( new MonologServiceProvider() );
$app['debug'] = isset( $config['debug'] ) && $config['debug'];

// Internationalisation.
$app['i18n'] = new I18nContext( new JsonCache( __DIR__ . '/../i18n' ) );
$app['twig']->addExtension( new TwigExtension( $app['i18n'] ) );

// Routes.
$commonController = new CommonsController( $app, $config );
$oauthController = new OAuthController( $app, $config );

$app->get( '/', function() use( $app ) {
	return $app->redirect( 'commons/init' );
} )->bind( 'home' );

$app->get( 'commons/init', function( Request $request ) use ( $commonController ) {
	return $commonController->init( $request );
} )->bind( 'commons-init' );

$app->get( 'commons/fill', function( Request $request ) use ( $commonController ) {
	return $commonController->fill( $request );
} )->bind( 'commons-fill' );

$app->post( 'commons/save', function( Request $request ) use ( $commonController ) {
	return $commonController->save( $request );
} )->bind( 'commons-save' );

$app->get( 'log/{iaId}', function( Request $request, $iaId ) use ( $commonController ) {
	return $commonController->logview( $request, $iaId );
} )->bind( 'log' );

$app->get( 'oauth/init', function( Request $request ) use ( $oauthController ) {
	return $oauthController->init( $request );
} )->bind( 'oauth-init' );

$app->get( 'oauth/callback', function( Request $request ) use ( $oauthController ) {
	return $oauthController->callback( $request );
} )->bind( 'oauth-callback' );

$app->get( 'logout', function( Request $request ) use ( $oauthController ) {
	return $oauthController->logout( $request );
} )->bind( 'logout' );

// Add tool labs' IPs as trusted.
// See https://wikitech.wikimedia.org/wiki/Help:Tool_Labs/Web#Web_proxy_servers
Request::setTrustedProxies( [ '10.68.21.49', '10.68.21.81' ] );

$app->run();
