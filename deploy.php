<?php

/**
 * deploys
 *
 * @package wp-deploy-flow
 * @author Arnaud Sellenet
 */
class WP_Deploy_Flow_Command extends WP_CLI_Command {

	private static $_env;

	private static $_settings;

	/**
	 * List of unprefixed constants that need to be defined in wp-config.php.
	 * About the value:
	 * * true: constant is required.
	 * * false: constant is optional.
	 * * 'constant_name': if missing, copy value from that constant
	 * TODO: Add subcommand dependency
	 */
	static $constants = array(
		'url' => true,
		'path' => true,
		'ssh_host' => true,
		'ssh_user' => true,
		'ssh_path' => true,
		'ssh_db_host' => 'ssh_host',
		'ssh_db_user' => 'ssh_user',
		'ssh_db_path' => 'ssh_path',
		'db_host' => true,
		'db_user' => true,
		'db_port' => false,
		'db_name' => true,
		'db_password' => true,
		'locked' => false,
		'remove_admin' => false,
		'post_push_script' => false,
	);

	/**
	 * Push local to remote.
	 *
	 * @synopsis <environment> --what=<what> [--upload=<upload>] [--cleanup]
	 */
	public function push( $args, $assoc_args ) {

		self::$_settings = self::_get_sanitized_args( $args, $assoc_args );

		if ( self::$_settings['locked'] === true ) {
			WP_CLI::error( "$env environment is locked, you cannot push to it." );
			return;
		}

		/**
		 * 'what' accepts comma separated values.
		 */
		$what = explode( ',', $assoc_args['what'] );
		foreach ( $what as $item ) {
			$args = array();
			if ( ! empty( $assoc_args['cleanup'] ) )
				array_push( $args, true );

			if ( method_exists( __CLASS__, "_push_$item" ) ) {
				call_user_func_array( "self::_push_$item", $args );
			} else {
				WP_CLI::line( "Don't know how to deploy: $item" );
			}
		}

		/** TODO Remove this. */
		/* if ( $remove_admin === true ) { */
		/* 	$com = "ssh $ssh_user@$ssh_host \"cd $ssh_path;rm -Rf wp-login.php\""; */
		/* 	WP_CLI::line( $com ); */
		/* 	WP_CLI::launch( $com ); */
		/* } */

		$const = strtoupper( self::$_env ) . '_POST_PUSH_SCRIPT';
		if ( defined( $const ) ) {
			WP_CLI::line( 'Shit is going down' );
			$subcommand = constant( $const );
			$ssh_user = self::$_settings['ssh_user'];
			$ssh_host = self::$_settings['ssh_host'];
			$command = "ssh $ssh_user@$ssh_host '$subcommand'";

			self::_run_commands( $command );
		}

	}

	/**
	 * Dump a db or the uploads dir.
	 *
	 * @synopsis <environment> --what=<what> [--file=<file>]
	 */
	public function dump( $args, $assoc_args ) {

		self::$_settings = self::_get_sanitized_args( $args, $assoc_args );

		/**
		 * 'what' accepts comma separated values.
		 */
		$what = explode( ',', $assoc_args['what'] );
		foreach ( $what as $item ) {
			if ( method_exists( __CLASS__, "_dump_{$item}" ) ) {
				call_user_func( "self::_dump_{$item}", self::$_settings['file'] );
			} else {
				WP_CLI::line( "Don't know how to dump: $item" );
			}
		}
	}

	private static function _push_db( $cleanup = false ) {

		extract( self::$_settings );
		$env = self::$_env;
		$backup_name = time() . "_$env";
		$dump_file = date( 'Y_m_d-H_i' ) . "_$env.sql";
		$server_file = "{$env}_push_" . self::_get_unique_env_id() . '.sql';

		WP_CLI::line( "Pushing the db to $env ..." );

		$path = untrailingslashit( $path );

		/** TODO: Add command description here.. */
		$commands = array(
			array(
				"ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $server_file'",
				true, 'Deploying the db on server.', 'Failed deploying the db to server.'
			),
			array( "rm $dump_file", 'Removing the local dump.' ),
		);

		if ( $cleanup ) {
			array_push( $commands, array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; rm $server_file'" ) );
		} else {
			WP_CLI::line( "\n=Deploying the uploads to server." );
		}

		self::_dump_db( $dump_file );
		self::_rsync( $dump_file, "$ssh_db_user@$ssh_db_host:$ssh_db_path/$server_file" );
		self::_run_commands( $commands );
	}

	private function _push_uploads() {

		WP_CLI::line( "\n=Deploying the uploads to server." );
		$uploads_dir = wp_upload_dir();

		$uploads_path = self::$_settings['archive'] ? self::_dump_uploads() : $uploads_dir['basedir'];
		self::_rsync( $uploads_path );

		WP_CLI::success( "Deployed the '{$uploads_dir['basedir']}' to server." );
	}

	private static function _dump_db( $dump_file = '' ) {

		$env = self::$_env;
		$backup_file = time() . "_$env.sql";
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );
		$path = self::$_settings['path'];
		$url = self::$_settings['url'];
		$dump_file = empty( $dump_file ) ? date( 'Y_m_d-H_i' ) . "_$env.sql" : $dump_file;

		$commands = array(
			array( "wp db export $backup_file", true, 'Exported local backup.' ),
			array( "wp search-replace --network $siteurl $url", true, "Replaced $siteurl with $url on local db." ),
			array( "wp search-replace --network $abspath $path", true, "Replaced $abspath with with $path on local db." ),
			array( "wp db dump $dump_file", true, 'Dumped the db which will be deployed.' ),
			array( "wp db import $backup_file", true, 'Imported local backup.' ),
			array( "rm $backup_file", 'Removed backup file.' )
		);

		self::_run_commands( $commands );
	}

	/**
	 * @synopsis <environment> --what=<what> [--cleanup] [--no-backup]
	 */
	public function pull( $args, $assoc_args ) {

		self::$_settings = self::_get_sanitized_args( $args, $assoc_args );

		/**
		 * 'what' accepts comma separated values.
		 */
		$what = explode( ',', $assoc_args['what'] );
		foreach ( $what as $item ) {
			$args = array( false, true );
			if ( ! empty( $assoc_args['cleanup'] ) )
				$args[0] = true;
			if ( ! empty( $assoc_args['no-backup'] ) )
				$args[1] = false;

			if ( method_exists( __CLASS__, "_pull_$item" ) ) {
				call_user_func_array( "self::_pull_$item", $args );
			} else {
				WP_CLI::warning( "Don't know how to pull: $item" );
			}
		}
	}

	/** 
	 * TODO: Change to args array method, and manage setting dependecies in the 
	 * command. Keep methods independent of the environment.
	 */
	private static function _pull_db( $cleanup = false, $backup = true ) {
		/** Add preserve file on server for rsync. */
		extract( self::$_settings );
		$env = self::$_env;
		$server_file = "{$env}_pull_" . self::_get_unique_env_id() . '.sql';
		$backup_name = date( 'Y_m_d-H_i' ) . '_bk.sql';
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );

		WP_CLI::line( "Pushing the db to $env ..." );

		$path = untrailingslashit( $path );

		$command = array(
			array(
				"ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; mysqldump --user=$db_user --password=$db_password --host=$db_host $db_name > $server_file'",
				true, "Dumped the remote db to $server_file.", 'Failed dumping the remote db.'
			),
			array(
				"rsync -ave ssh $ssh_db_user@$ssh_db_host:$ssh_db_path/$server_file $server_file",
				true, 'Copied the db from server.',
			),
			array(
				"wp db import $server_file",
				true, 'Imported the remote db.'
			),
			array( "wp search-replace --network $url $siteurl", true, "Replaced $url with $site on imported db." ),
			array( "wp search-replace --network $path $abspath", true, "Replaced $path with with $abspath on local db." ),
			array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; rm $server_file'" ),
		);

		/** Remove local dump only if requested. */
		if ( $cleanup ) {
			array_push( $commands, array( "rm $server_file", 'Removing the local dump.' ) );
		}

		if ( $backup ) {
			array_unshift( $commands, array(
				"wp db export $backup_name",
				true, "Backed up the local db to $backup_name"
			) );
		}

		self::_run_commands( $commands );
	}

	private static function _pull_uploads() {
	}

	private static function _dump_uploads( $dump_name = '' ) {

		$uploads_dir = wp_upload_dir();
		$uploads_dir = pathinfo( $uploads_dir['basedir'] );

		return self::_archive_file( $uploads_dir['filename'], $dump_name, $uploads_dir['dirname'] );
	}

	private static function _get_sanitized_args( $args, $assoc_args = null ) {

		self::$_env = $args[0];

		$errors = self::_validate_config();
		if ( $errors !== true ) {
			foreach ( $errors as $error ) {
				WP_Cli::error( $error );
			}
			return false;
		}

		$out = self::_get_constants();
		$out['env'] = self::$_env;
		$out['db_user'] = escapeshellarg( $out['db_user'] );
		$out['db_host'] = escapeshellarg( $out['db_host'] );
		$out['db_password'] = escapeshellarg( $out['db_password'] );

		$out['archive'] = isset( $assoc_args['archive'] ) ? true : false;
		$out['file'] = isset( $assoc_args['file'] ) ? $assoc_args['file'] : false;

		return $out;
	}

	private static function _validate_config() {

		/** Required constants have their value set to true. */
		$required = array_keys( array_filter(
			self::$constants,
			function($v) { return $v === true; }
		) );

		$errors = array();
		foreach ( $required as $const ) {
			$required_constant = self::_prefix_constant( $const );
			if ( ! defined( $required_constant ) ) {
				$errors[] = "$required_constant is not defined";
			}
		}
		if ( count( $errors ) == 0 ) return true;
		return $errors;
	}

	/** Generic. */
	private static function _archive_file( $file, $archive_name = '', $context_dir = '' ) {

		$path_info = pathinfo( $file );
		$dirpath = $path_info['dirname'];
		$archive_name = empty( $archive_name ) ? $path_info['basename'] : $archive_name;

		$tar_command = "tar -zcvf $archive_name.tar.gz $file";
		$tar_command = empty( $context_dir ) ? $tar_command : array( $tar_command, $context_dir );
		$commands = array(
			array( $tar_command, true ),
			array( "mv $context_dir/$archive_name.tar.gz ." ),
		);

		self::_run_commands( $commands );

		return "$archive_name.tar.gz";
	}

	/**
	 * Runs a list of commands.
	 */
	private static function _run_commands( $commands ) {

		/** Support both a commands array and a single command */
		if ( is_string( $commands ) ) {
			$commands = array( array( $commands ) );
		} elseif ( is_string( $commands[0] ) ) {
			$commands = array( $commands );
		}

		foreach ( $commands as $command_info ) {
			WP_CLI::line( "\n$ {$command_info[0]}" );
			self::_verbose_launch( $command_info );
		}
	}

	/** Generic alternate implementation of launch. */
	private function _verbose_launch( $command_info ) {

		$cwd = null;
		$command = array_shift( $command_info );
		if ( is_array( $command ) ) {
			$cwd = $command[1];
			$command = $command[0];
		}
		$exit_on_error = is_bool( $command_info[0] ) && $command_info[0];
		$messages = array_filter( $command_info, function( $v ) { return is_string( $v ); } );

		$code = proc_close( proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes, $cwd ) );

		if ( $code && $exit_on_error )
			exit( $code );

		if ( empty( $messages ) )
			return;

		$success = array_shift( $messages );
		$fail = ! empty( $messages ) ? array_shift( $messages ) : $success;

		if ( $code ) {
			WP_CLI::warning( $fail );
		} else {
			WP_CLI::line( "Success: $success" );
		}
	}

	private function _rsync( $source, $destination = false, $msg = false, $compress = false ) {

		extract( self::$_settings );
		/** TODO Manage by flag. */
		$exclude = array(
			'.git',
			'cache',
		);
		$destination = ! is_string( $destination ) ? "$ssh_user@$ssh_host:$ssh_path/$destination" : $destination;
		$msg = empty( $msg ) ? "Copied $source to $ssh_host:$destination" : $msg;
		$compress = $compress ? ' -z' : '';

		/** Exclude files from rsync. */
		$exclude = '--exclude '
			. implode(
				' --exclude ',
				array_map( 'escapeshellarg', $exclude )
			);
		$command = array(
			sprintf(
				"rsync -av$compress -e ssh --delete %s %s %s",
				$source,
				$destination,
				$exclude
			),
			true,
			$msg
		);

		self::_run_commands( $command );
	}

	private static function _get_constants() {

		$out = array();

		foreach ( self::$constants as $const => $requirement ) {
			if ( defined( self::_prefix_constant( $const ) ) ) {
				$out[$const] = constant( self::_prefix_constant( $const ) );
			} elseif ( is_string( $requirement ) ) {
				$out[$const] = constant( self::_prefix_constant( $requirement ) );
			}
		}
		return $out;
	}

	private static function _prefix_constant( $name ) {

		return strtoupper( self::$_env . '_' . $name );
	}

	private static function _trim_url( $url ) {

		/** In case scheme relative URI is passed, e.g., //www.google.com/ */
		$url = trim( $url, '/' );

		/** If scheme not included, prepend it */
		if ( ! preg_match( '#^http(s)?://#', $url ) ) {
			$url = 'http://' . $url;
		}

		/** Remove www. */
		$url_parts = parse_url( $url );
		$domain = preg_replace( '/^www\./', '', $url_parts['host'] );

		return $domain;
	}

	private static function _get_unique_env_id() {
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );

		return substr( sha1( DB_NAME . $siteurl ), 0, 8 );
	}
}

WP_CLI::add_command( 'deploy', 'WP_Deploy_Flow_Command' );
WP_CLI::add_man_dir( __DIR__ . '/man', __DIR__ . '/man-src' );
