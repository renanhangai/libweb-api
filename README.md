# libweb\api

Generate REST APIs with ease using slim framework
```php
<?php
$app = new \libweb\api\App;
$app->get( "/", function() { return "hello world"; });
$app->run();
```

Will output
```json
{
	"status": "success",
	"data":   "hello world",
}
```


Methods
==========

- `mapClass( $base, $class )`

	Ex:
	```php
	$app->mapClass( "/test", "\\test\\api\\Test" );
	```

	Will be mapped to
	```php
	$obj = new \test\api\Test( $app );

	// "example.com/test/data"
	$obj->GET_data()

	// "example.com/test/info-name"
	$obj->GET_infoName()

	// "example.com/test/sub/dir/data"
	$obj->GET_sub_dir_data()

	// "example.com/test/sub-info/dir-name/data-user"
	$obj->GET_subInfo_dirName_dataUser()
	```


- `mapPath( $base, $dir, $classTemplate )`

	Every path will be mapped to a file
	Ex:
	```php
	$app->mapPath( "/test", "/project/test/", "\\myproject\\api\\test{path}{class}API" );
	```

	When entering to "example.com/test/user/books/data"
	Will be mapped to 
	```php
	require_once "/project/test/user/Books.php";
	$obj = new \myproject\api\test\user\BooksAPI( $app );
	$obj->GET_data()
	```