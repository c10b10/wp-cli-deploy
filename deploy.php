<?php

/**
 * deploys
 *
 * @package wp-deploy-flow
 * @author Arnaud Sellenet
 */
/*
 * MAIN TODO: Change to args array method, and manage setting dependecies in the
 * command. Keep methods independent of the environment.
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
		'uploads_path' => false,
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
	 * Pushes the local database and / or uploads from local to remote.
	 *
	 * ## OPTIONS

	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.
	 *
	 * `--what`=<what>
	 * : What needs to be dumped. Suports multiple comma sepparated values. Valid
	 * options are: 'db' (dumps the databse with the url and paths replaced) and
	 * 'uploads' (creates an archive of the uploads folder in the current directory).
	 *
	 * [`--file`=<file>]
	 * : [REMOVED] Optional. What should the dump be called. Default: '%date_time% _%env%.sql' for 'db', 'uploads.tar.gz' for
	 * 'uploads'.

	 * ## EXAMPLE

	 *    # Dumps database for to "staging" environment. You must have STAGING_*
	 *    # constants defined for this to work
	 *    wp deploy dump staging --what=db
	 *
	 * @synopsis <environment> --what=<what> [--upload=<upload>] [--cleanup] [--safe]
	 */
	public function push( $args, $assoc_args ) {

		/** TODO: See about those extra cleanup, safe, etc. */

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
				$args['cleanup'] = true;

			if ( ! empty( $assoc_args['safe'] ) )
				$args['safe'] = true;

			if ( method_exists( __CLASS__, "_push_$item" ) ) {
				call_user_func( "self::_push_$item", $args );
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
	 * Dumps the local database and / or uploads from local to remote. The
	 * database will be prepared for upload to the specified environment.
	 *
	 * ## OPTIONS

	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.php.
	 *
	 * `--what`=<what>
	 * : What needs to be dumped. Suports multiple comma sepparated values. Valid
	 * options are: 'db' (dumps the databse with the url and paths replaced) and
	 * 'uploads' (creates an archive of the uploads folder in the current directory).
	 *
	 * [`--file`=<file>]
	 * : [REMOVED] Optional. What should the dump be called. Default: '%date_time% _%env%.sql' for 'db', 'uploads.tar.gz' for
	 * 'uploads'.

	 * ## EXAMPLE

	 *    # Dumps database for to "staging" environment. You must have STAGING_*
	 *    # constants defined for this to work
	 *    wp deploy dump staging --what=db
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

	private static function _push_db( $args = array() ) {

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

		if ( ! empty( $args['cleanup'] ) ) {
			array_push( $commands, array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; rm $server_file'" ) );
		} else {
			WP_CLI::line( "\n=Deploying the uploads to server." );
		}

		self::_dump_db( $dump_file );
		self::_rsync( $dump_file, "$ssh_db_user@$ssh_db_host:$ssh_db_path/$server_file" );
		self::_run_commands( $commands );
	}

	private function _push_uploads( $args = array() ) {

		/** TODO Use uploads path to move directily where it should be. */
		WP_CLI::line( "\n=Deploying the uploads to server." );
		$uploads_dir = wp_upload_dir();

		$uploads_path = self::$_settings['archive'] ? self::_dump_uploads() : $uploads_dir['basedir'];
		$uploads_path = self::_launch( "cd $uploads_path; pwd -P;" );

		$settings = self::$_settings;
		$destination = empty( $args['safe'] ) ? "{$settings['ssh_user']}@{$settings['ssh_host']}:{$settings['uploads_path']}" : false;
		self::_rsync( $uploads_path, $destination );

		WP_CLI::success( "Deployed the '{$uploads_dir['basedir']}' to server." );
	}

	private static function _dump_db( $dump_file = '' ) {

		$env = self::$_env;
		$backup_file = ABSPATH . time() . "_$env.sql";
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );
		$path = self::$_settings['path'];
		$url = self::$_settings['url'];
		$dump_file = ABSPATH . empty( $dump_file ) ? date( 'Y_m_d-H_i' ) . "_$env.sql" : $dump_file;

		$commands = array(
			array( "wp db export $backup_file", true, 'Exported local backup.' ),
			array( "wp search-replace --network $siteurl $url", true, "Replaced $siteurl with $url on local db." ),
			array( "wp search-replace --network $abspath $path", true, "Replaced $abspath with with $path on local db." ),
			array( "wp db export $dump_file", true, 'Dumped the db which will be deployed.' ),
			array( "wp db import $backup_file", true, 'Imported local backup.' ),
			array( "rm $backup_file", 'Removed backup file.' )
		);

		if ( $siteurl == $url )
			unset( $commands[1] );

		if ( $abspath == $path )
			unset( $commands[2] );

		if ( ! isset( $commands[1] ) && ! isset( $commands[2] ) )
			unset( $commands[4] );

		self::_run_commands( $commands );
	}

	/**
	 * Pulls the database and / or uploads from remote to local. After pulling
	 * the uploads, they need to copied to the correct location.
	 *
	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.
	 *
	 * `--what`=<what>:
	 * : What needs to be pull. Suports multiple comma sepparated values. This
	 * determines the order of execution for deployments. Valid options are: 'db'
	 * (pulls the databse with the url and paths replaced) and 'uploads' (pulls
	 * the uploads folder).
	 *
	 * `--backup`=<backup>
	 * : Optional. Wether the local db should be backup up beofore importing
	 * the new db. Defaults to true.
	 *
	 * ## EXAMPLES
	 *
	 *    # Pulls database and uploads folder
	 *    wp deploy pull staging --what=db,uploads
	 *
	 *    # Pull the remote db without prior local backup
	 *    wp deploy pull staging --what=db --backup=false
	 *
	 * @synopsis <environment> --what=<what> [--cleanup] [--backup=<backup>]
	 */
	public function pull( $args, $assoc_args ) {

		/**
		 * TODO: Add backup to the same pull dir of both db and uploads
		 * TODO: Add --deploy flag when deploy is wanted
		 * TODO: Never remove local copies
		 */

		self::$_settings = self::_get_sanitized_args( $args, $assoc_args );

		/**
		 * 'what' accepts comma separated values.
		 */
		$what = explode( ',', $assoc_args['what'] );
		foreach ( $what as $item ) {
			$args = array( false, true );
			if ( ! empty( $assoc_args['cleanup'] ) )
				$args[0] = true;
			if ( isset( $assoc_args['backup'] ) ) {
				$backup = filter_var( $assoc_args['backup'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( isset( $backup ) )
					$args[1] = $backup;
			}

			if ( method_exists( __CLASS__, "_pull_$item" ) ) {
				call_user_func_array( "self::_pull_$item", $args );
			} else {
				WP_CLI::warning( "Don't know how to pull: $item" );
			}
		}
	}

	private static function _pull_db( $cleanup = false, $backup = true ) {
		/** Add preserve file on server for rsync. */
		extract( self::$_settings );
		$env = self::$_env;
		$local_path = ABSPATH . "{$env}_pull_" . self::_get_unique_env_id();
		$server_file = "{$env}.sql";
		$backup_name = date( 'Y_m_d-H_i' ) . '_bk.sql';
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );

		WP_CLI::line( "Pulling the $env db to local." );

		$path = untrailingslashit( $path );

		$commands = array(
			array(
				"ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; mysqldump --user=$db_user --password=$db_password --host=$db_host $db_name > $server_file'",
				true, "Dumped the remote db to $server_file.", 'Failed dumping the remote db.'
			),
			array(
				"mkdir -p $local_path"
			),
			array(
				"rsync --recursive -ave ssh $ssh_db_user@$ssh_db_host:$ssh_db_path/$server_file $local_path/$server_file",
				true, 'Copied the db from server.',
			),
			array(
				"wp db import $local_path/$server_file",
				true, 'Imported the remote db.'
			),
			array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; rm $server_file'" ),
		);

		/** Remove local dump only if requested. TODO Why? */
		if ( $cleanup ) {
			array_push( $commands, array( "rm $local_path/$server_file", 'Removing the local dump.' ) );
		}

		if ( $backup ) {
			array_unshift( $commands, array(
				"wp db export $backup_name",
				true, "Backed up the local db to $backup_name"
			) );
		}

		self::_run_commands( $commands );

		if ( $siteurl != $url )
			self::_run_commands( array( "wp search-replace --network $url $siteurl", true, "Replaced $url with $site on imported db." ) );

		if ( $path != $abspath )
			self::_run_commands( array( "wp search-replace --network $path $abspath", true, "Replaced $path with with $abspath on local db." ) );
	}

	private static function _pull_uploads( $cleanup = false, $backup = true ) {
		/** Pull uploads. */
		extract( self::$_settings );

		WP_CLI::line( "\n=Deploying the uploads to server." );

		$source = self::$_settings['uploads_path'];
		$local_path = ABSPATH . "{$env}_pull_" . self::_get_unique_env_id();
		self::_rsync( "$ssh_user@$ssh_host:$source", $local_path );
		WP_CLI::success( "Pulled the staging 'uploads' dir to '$local_path'." );

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
		if ( ! isset( $out['uploads_path'] ) ) {
			$uploads_path = wp_upload_dir();
			$out['uploads_path'] = trailingslashit( $out['path'] )
				. substr( $uploads_path['basedir'], strlen( ABSPATH ) );
		}

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
			array( "mv $context_dir/$archive_name.tar.gz " . ABSPATH ),
		);

		self::_run_commands( $commands );

		return ABSPATH . "$archive_name.tar.gz";
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

	private static function _launch( $command ) {

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

	private function _rsync( $source, $destination = false, $msg = false, $compress = true ) {

		extract( self::$_settings );
		/** TODO Manage by flag. */
		$exclude = array(
			'.git',
			'cache',
			'.DS_Store',
			'thumbs.db'
		);
		$destination = ! is_string( $destination ) ? "$ssh_user@$ssh_host:$ssh_path" : $destination;
		$msg = empty( $msg ) ? "Copied $source to $ssh_host:$destination" : $msg;
		$compress = $compress ? ' -z' : '';

		/** Exclude files from rsync. */
		$exclude = '--exclude '
			. implode(
				' --exclude ',
				array_map( 'escapeshellarg', $exclude )
			);
		$commands = array(
			array(
				"mkdir -p $destination"
			),
			array(
				sprintf(
					"rsync -av$compress -e ssh --delete %s %s %s",
					$source,
					$destination,
					$exclude
				),
				true,
				$msg
			),
		);

		self::_run_commands( $commands );
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
