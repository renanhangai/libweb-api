<?php
namespace LibWeb\api;

class Response {

	private $code_          = 200;
	private $data_          = null;
	private $headers_       = array();
	private $raw_           = false;
	
	/// Get the response data
	public function getData() { return $this->data_; }
	/// Get the headers
	public function getHeaders() { return $this->headers_; }
	/// Get the status
	public function getCode() { return $this->code_; }
	/// Check if response is raw
	public function getRaw() { return $this->raw_; }
	/// Set the data to be sent
	public function data( $data ) {
		$this->raw_  = false;
		$this->data_ = $data;
	}
	/// Set the response as file
	public function file( $path, $name = null, $options = array() ) {
		if ( $name == null )
			$name = basename( $path );
		$this->headers_['content-disposition'] = ( @$options["inline"] ? 'inline' : 'attachment' ).'; filename="'.$name.'"';
		$this->raw_  = true;
		$this->data_ = function() use ( $path, $options ) {
			readfile( $path );
			if ( @$options["unlink"] )
				unlink( $path );
		};
	}
	/// Set the response as a temporary file that will be unlinked
	public function tmpfile( $path, $name = null, $options = array() ) {
		return $this->file( $path, $name, array_merge( $options, array( "unlink" => true ) ) );
	}
	/// Set the response as file
	public function stream( $name, $stream, $options = array() ) {
		$this->headers_['content-disposition'] = ( @$options["inline"] ? 'inline' : 'attachment' ).'; filename="'.$name.'"';
		$this->raw_  = true;
		if ( is_resource( $stream ) ) {
			$this->data_ = function() use ( $stream, $options ) {
				fpassthru( $stream );
			};
		} else if ( is_callable( $stream ) ) {
			$handle = fopen( "php://temp", "r+" );
			call_user_func( $stream, $handle );
			$stats = fstat( $handle );
			$this->headers_['content-length'] = @$stats['size'];
			rewind( $handle );
			$this->data_ = function() use ( $handle ) {
				fpassthru( $handle );
				fclose( $handle );
			};
		} else {
			throw new \InvalidArgumentException( "Excepted stream or function" );
		}
	}
	/// Send a raw data
	public function raw( $data ) {
		$this->raw_  = true;
		$this->data_ = $data;
	}
	/// Set the response code
	public function code( $code ) {
		$this->code_ = $code;
	}
	/// Set the header
	public function header( $name, $value ) {
		$name = strtolower( trim( $name ) );
		$this->headers_[ $name ] = $value;
	}
	/// Set a cookie
	public function cookie( $name, $value, $expiresAfter = null ) {
		if ( $expiresAfter === null )
			$expires = 0;
		else if ( is_int( $expiresAfter ) )
			$expires = time() + $expiresAfter;
		setcookie( $name, $value, $expires, "/", "", false, true );
	}
};