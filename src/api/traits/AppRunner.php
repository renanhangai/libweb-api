<?php

namespace libweb\api\traits;

/**
 * A basic app runner class with a few methods already set so you can only include it.
 */
trait AppRunner {
	/**
	 * Perform route setup.
	 */
	public function setupRoutes() {
	}

	/**
	 * Create the app.
	 *
	 * @param array $config The configuration
	 *                      "debug": Whether or not turn on debug methods
	 *                      "logErrors": Wheter the lib should log the errors
	 */
	public static function create($config = array()) {
		$app = new static([
			'settings' => [
				'debug' => (bool) @$config['debug'],
				'displayErrorDetails' => (bool) @$config['debug'],
				'logErrors' => (bool) @$config['logErrors'],
			],
		]);
		if (@$config['cors']) {
			$app->cors();
		}
		$app->setupRoutes();
		return $app;
	}

	/**
	 * A main function to run on the main script.
	 */
	public static function main($argv) {
		$app = static::create(static::config($argv));
		$app->run();
	}

	/**
	 * Set the error handler.
	 */
	public function errorHandler($request, $response, $exception, $defaultHandler) {
		if ($this->getContainer()['settings']['logErrors']) {
			error_log($exception);
		}
		$response = $response->withStatus(500);
		try {
			return $this->formatResponse($request, $response, null, $exception, true);
		} catch (\Exception $exception) {
			// Does not send anything on error
			if (!$this->getContainer()['settings']['displayErrorDetails']) {
				return $response->withJson(['status' => 'error']);
			}
			return $defaultHandler($request, $response, $exception);
		}
	}

	/**
	 * Get configuration to run the app.
	 */
	public static function config($argv) {
		return [
			'debug' => false,
			'logErrors' => true,
		];
	}
}
