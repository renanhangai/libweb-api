<?php

namespace libweb\api;

use libweb\api\util\Serializable;
use libweb\api\docs\Documentator;
use Webmozart\PathUtil\Path;

class App extends \Slim\App {
	/// CLasses for
	const REQUEST_CLASS = Request::class;
	const RESPONSE_CLASS = Response::class;
	const ATTRIBUTE_CONTAINER = 'container';

	/// Di Container
	/** @var \Psr\Container\ContainerInterface */
	private $diContainer;

	/**
	 * Construct the app using default response and request objects.
	 */
	public function __construct($container = array()) {
		parent::__construct($container);

		$this->diContainer = $this->createDiContainer();

		$container = $this->getContainer();
		$container['request'] = function ($container) {
			return $this->createRequest($container);
		};
		$container['response'] = function ($container) {
			return $this->createResponse($container);
		};
		$container->extend('errorHandler', function ($defaultHandler, $container) {
			return function ($request, $response, $exception) use ($defaultHandler, $container) {
				$handlerResponse = $this->errorHandler($request, $response, $exception, $defaultHandler);
				return $handlerResponse ?: $response;
			};
		});
		if ($this->diContainer) {
			$container['callableResolver'] = function () {
				return new util\CallableResolverDi($this->diContainer);
			};
		}
	}

	/**
	 * Create the DI container.
	 *
	 * @return \Psr\Container\ContainerInterface
	 */
	public function createDiContainer() {
		return null;
	}

	/// Get the DI container
	public function getDiContainer() {
		return $this->diContainer;
	}

	/// Create the request
	public function createRequest($container) {
		$requestClass = static::REQUEST_CLASS;
		$request = $requestClass::createFromEnvironment($container->get('environment'));
		if ($this->diContainer) {
			$request = $request->withAttribute(static::ATTRIBUTE_CONTAINER, $this->diContainer);
			$this->diContainer->set($requestClass, $request);
		}
		return $request;
	}

	/// Create the response
	public function createResponse($container) {
		$responseClass = static::RESPONSE_CLASS;
		$headers = new \Slim\Http\Headers(['Content-Type' => 'text/html; charset=UTF-8']);
		$response = new $responseClass(200, $headers);
		$response = $response
			->withApp($this)
			->withProtocolVersion($container->get('settings')['httpVersion']);
		if ($this->cors_) {
			$allowedHeaders = array('X-Requested-With, Content-Type, Accept, Origin, Authorization, Set-Cookie', $this->cors_->allowedHeaders);
			$response = $response
				->withHeader('Access-Control-Allow-Origin', $this->cors_->allowedOrigin)
				->withHeader('Access-Control-Allow-Headers', implode(', ', $allowedHeaders))
				->withHeader('Access-Control-Allow-Credentials', 'true')
				->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
				->withHeader('Access-Control-Expose-Headers', 'Content-Disposition, Content-Length');
		}
		return $response;
	}

	/**
	 * Make the app documentate itself.
	 */
	public function documentate($generator = null, array $options = array()) {
		if (!$this->documentator_) {
			$this->documentator_ = new Documentator();
		}
		if ($generator !== null) {
			$this->documentator_->addGenerator($generator, $options);
		}
	}

	// Error handler
	public function errorHandler($request, $response, $exception, $defaultHandler) {
		return $defaultHandler($request, $response, $exception);
	}

	/**
	 * Overrides application run.
	 */
	public function run($silent = false) {
		if ($this->documentator_) {
			$this->documentator_->generate();
			echo "Documentation generated.\n";
			return;
		}
		// Handler for everything else
		if ($this->cors_) {
			$this->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($req, $res) {
				$handler = $this->notFoundHandler;
				return $handler($req, $res);
			});
		}
		return parent::run($silent);
	}

	/**
	 * Group functions by route.
	 *
	 * @return \Slim\Interfaces\RouteGroupInterface
	 */
	public function group($base, $callback) {
		if ($this->documentator_) {
			$this->documentator_->pushDocGroup($base, $callback);
		}
		$ret = parent::group($base, $callback);
		if ($this->documentator_) {
			$this->documentator_->popDocGroup();
		}
		return $ret;
	}

	/**
	 * PUT.
	 *
	 * @return \Slim\Interfaces\RouteInterface
	 */
	public function map(array $methods, $pattern, $callable) {
		if ($this->documentator_) {
			if (!$this->docSkipNext_) {
				$this->documentator_->addMap($methods, $pattern, $callable);
			}
			$this->docSkipNext_ = false;
		}
		return parent::map($methods, $pattern, $this->wrapHandler($callable));
	}

	/**
	 * Overrides the defaults methods.
	 */
	public function post($route, $callable) {
		return parent::post($route, $this->createHandler($callable));
	}

	public function delete($route, $callable) {
		return parent::delete($route, $this->createHandler($callable));
	}

	public function put($route, $callable) {
		return parent::put($route, $this->createHandler($callable));
	}

	public function get($route, $callable) {
		return parent::get($route, $this->createHandler($callable));
	}

	/**
	 * Create the handler.
	 */
	private function createHandler($callable) {
		return function ($request, $response, $params) use ($callable) {
			if (is_string($callable)) {
				list($class, $method) = explode(':', $callable, 2);
				if ($method) {
					return $this->_invokeMethod($class, $method, $request, $response, $params);
				}
			}
			return call_user_func_array($callable, [$request, $response, $params]);
		};
	}

	/**
	 * Map a class to an API path.
	 *
	 * @param $base The base path to load
	 * @param $dir The directory to resolve
	 * @param $classTemplate The template string for the class to resolve
	 *
	 * @return \Slim\Interfaces\RouteInterface The root route for the base path
	 *
	 * Every path will be mapped to a file
	 * Ex:
	 *     $app->mapClass( "/test", "\\test\\api\\Test" );
	 *
	 * Will be mapped to
	 *     $obj = new \test\api\Test( $app );
	 *
	 *     // "example.com/test/data"
	 *     $obj->GET_data()
	 *
	 *     // "example.com/test/info-name"
	 *     $obj->GET_infoName()
	 *
	 *     // "example.com/test/sub/dir/data"
	 *     $obj->GET_sub_dir_data()
	 *
	 *     // "example.com/test/sub-info/dir-name/data-user"
	 *     $obj->GET_subInfo_dirName_dataUser()
	 */
	public function mapClass($base, $class) {
		if (!class_exists($class)) {
			throw new \InvalidArgumentException("Cannot find class '$class'.");
		}
		if ($this->documentator_) {
			$this->documentator_->addClass($base, $class);
			$this->docSkipNext_ = true;
		}

		$app = $this;
		$pattern = Path::join($base, '{method:.*}');
		return $this->any($pattern, function ($request, $response, $params) use ($app, $class) {
			return $app->_dispatchApiClass($request, $response, $params, $class, $params['method']);
		});
	}

	/**
	 * Map a directory to a path on the API.
	 *
	 * @param $base The base path to load
	 * @param $dir The directory to resolve (If null, no new files will be included )
	 * @param $classTemplate The template string for the class to resolve
	 *
	 * @return \Slim\Interfaces\RouteInterface The root route for the base path
	 *
	 * Every path will be mapped to a file
	 * Ex:
	 *     $app->mapPath( "/test", "/project/test/", "\\myproject\\api\\test{path}{class}API" );
	 *
	 * When entering to "example.com/test/user/books/data"
	 * Will be mapped to
	 *    require_once "/project/test/user/Books.php"; // If $dir is null, no file will be required
	 *    $obj = new \myproject\api\test\user\BooksAPI( $app );
	 *    $obj->GET_data()
	 */
	public function mapPath($base, $dir, $classTemplate = '{path}{class}API') {
		$app = $this;
		$pattern = Path::join($base, '{path:.*}');
		return $this->any($pattern, function ($request, $response, $params) use ($app, $dir, $classTemplate) {
			// Normalize the parts for the path
			$path = Path::canonicalize($params['path']);
			$parts = explode('/', $path);
			if (count($parts) <= 1) {
				throw new \Slim\Exception\NotFoundException($request, $response);
			}
			$methodbase = array_pop($parts);
			$classbase = ucfirst(array_pop($parts));

			$pathdir = implode('/', $parts);

			// Try to find the file
			if ($dir !== null) {
				$classdir = Path::join($dir, $pathdir);
				$classpath = Path::join($classdir, $classbase . '.php');
				if (!file_exists($classpath)) {
					throw new \Slim\Exception\NotFoundException($request, $response);
				}
				require_once $classpath;
			}

			// Try to find the class
			$replace = array(
				'{path}' => str_replace('/', '\\', Path::join('/', $pathdir, '/')),
				'{class}' => $classbase,
			);
			$class = str_replace(array_keys($replace), array_values($replace), $classTemplate);
			if (!class_exists($class)) {
				throw new \Slim\Exception\NotFoundException($request, $response);
			}
			// Dispatch the object
			return $app->_dispatchApiClass($request, $response, $params, $class, $methodbase);
		});
	}

	/**
	 * Dispatch an API class using the method.
	 */
	public function _dispatchApiClass($request, $response, $params, $class, $methodbase) {
		$methodbase = str_replace(' ', '', lcfirst(ucwords(str_replace('-', ' ', $methodbase))));
		$methodbase = str_replace('/', '_', $methodbase);
		$methods = array($request->getMethod() . '_' . $methodbase, 'REQUEST' . '_' . $methodbase);
		return $this->_invokeMethod($class, $methods, $request, $response, $params);
	}

	/**
	 * Invoke the method on the class.
	 */
	public function _invokeMethod($class, $methodOrList, $request, $response, $params) {
		if (!class_exists($class)) {
			throw new \Slim\Exception\NotFoundException($request, $response);
		}
		if ($this->diContainer) {
			$obj = $this->diContainer->make($class);
		} else {
			$obj = new $class();
		}
		$args = [$request, $response, $params];
		if (is_array($methodOrList)) {
			foreach ($methodOrList as $method) {
				if (method_exists($obj, $method)) {
					return call_user_func_array([$obj, $method], $args);
				}
			}
		} else {
			if (method_exists($obj, $methodOrList)) {
				return call_user_func_array([$obj, $methodOrList], $args);
			}
		}
		throw new \Slim\Exception\NotFoundException($request, $response);
	}

	/**
	 * Format the response for the application.
	 */
	public function formatResponse($request, $response, $params, $data, $error = false) {
		/// Does nothing when already a response
		if ($data instanceof \Psr\Http\Message\ResponseInterface) {
			return $data;
		}
		if ($error) {
			if (!$data instanceof \JsonSerializable) {
				throw $data;
			}
			$response = $response->withStatus(500);
		}
		return $response->withJson(new Serializable($data));
	}

	/**
	 * Wrap a new callable for the map.
	 */
	public function wrapHandler($callable) {
		$app = $this;
		return function ($request, $response, $params) use ($callable, $app) {
			$resolver = $app->getContainer()->get('callableResolver');
			$args = [$request, $response, $params];

			$callable = $resolver->resolve($callable);
			try {
				$data = call_user_func_array($callable, $args);
				$error = false;
			} catch (\Exception $exception) {
				$data = $exception;
				$error = true;
			}
			return $app->formatResponse($request, $response, $params, $data, $error);
		};
	}

	/**
	 * Enable CORS on the current application.
	 *
	 * @param $allowedOrigin The allowed origin for cors request
	 */
	public function cors($allowedOrigin = '*', $extraAllowedHeaders = array()) {
		if ($this->cors_) {
			return;
		}
		if (is_array($extraAllowedHeaders)) {
			$extraAllowedHeaders = implode(', ', $extraAllowedHeaders);
		}
		$this->cors_ = (object) array(
			'allowedOrigin' => $allowedOrigin,
			'allowedHeaders' => $extraAllowedHeaders,
		);
		parent::options('/{route:.*}', function ($request, $response, $args) {
			return $response;
		});
	}

	// Variables
	private $cors_ = false;
	private $documentator_ = null;
	private $docSkipNext_ = false;
}
