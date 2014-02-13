<?php
namespace WP_Deploy_Command;

class Command_Runner {

	public $commands;

	function __construct() {
		$this->commands = array();
	}

	/**
	 * Accepted arguments:
	 *
	 * [condition] : bool (Default: true)
	 * command / array( command, cwd ) : string / array( string, string )
	 * [exit_on_fail] : bool (Default: true)
	 * [success_message] : string (Default: none)
	 * [fail_message] : string (Default: none)
	 *
	 * - If present condition needs to be first.
	 * - Command can either be a string, or an array containing the
	 * command string and the working directory in which it will be exec
	 * - Exit on failure needs to be boolean. If missing, it's set as true
	 * - The success and fail messages need to be strings and always in
	 * this order.
	 *
	 * [args] (bracketed arguments) are optional.
	 *
	 */
	function add() {

		$args = func_get_args();

		if ( is_bool( $args[0] ) ) {
			$condition = array_shift( $args );
			if ( ! $condition )
				return;
		}

		if ( empty( $args ) )
			return;

		$command = $args[0];

		$messages = array_filter(
			array_slice( $args, 1 ),
			function( $a ) {
				return is_string( $a );
			} );

		$exit_on_failure = function( $default ) use ( $args ) {
			foreach ( $args as $arg ) {
				if ( is_bool( $arg ) )
					return $arg;
			}
			return $default;
		};

		$meta = array(
			'command' => ( is_array( $command ) ? $command[0] : $command ),
			'cwd' => ( is_array( $command ) ? $command[1] : false ),
			'exit' => $exit_on_failure( true ),
			'messages' => ( count( $messages ) ? $messages : false ),
		);

		$this->commands[] = $meta;
	}

	function run() {
		foreach ( $this->commands as $command ) {
			self::launch( $command );
		}
	}

	private static function launch( $meta ) {

		if ( $meta['cwd'] ) {
			$code = proc_close( proc_open( $meta['command'], array( STDIN, STDOUT, STDERR ), $pipes, $cwd ) );
		} else {
			$code = proc_close( proc_open( $meta['command'], array( STDIN, STDOUT, STDERR ), $pipes ) );
		}

		if ( $code && $meta['exit'] )
			exit( $code );

		if ( ! $meta['messages'] )
			return;

		$success = array_shift( $meta['messages'] );
		$fail = ! empty( $messages ) ? array_shift( $meta['messages'] ) : $success;

		if ( $code ) {
			\WP_CLI::warning( $fail );
		} else {
			\WP_CLI::line( "Success: $success" );
		}
	}

	public static function get_result( $command ) {
		$cwd = null;
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
		);
		$pipes = array();
		$handler = proc_open( $command, $descriptors, $pipes, $cwd );

		$output = stream_get_contents( $pipes[1] );

		proc_close( $handler );

		return trim( $output );
	}

}
