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
	);

	/** First true-valued key is the default. */
	private static $_upload_types = array(
		'scp' => true,
		'rsync' => false
	);

	/**
	 * Push local to remote.
	 *
	 * @synopsis <environment> --what=<what> [--upload=<upload>]
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
			if ( method_exists( __CLASS__, "_push_$item" ) ) {
				call_user_func( "self::_push_$item" );
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

		$const = strtoupper( $env ) . '_POST_SCRIPT';
		if ( defined( $const ) ) {
			$subcommand = constant( $const );
			$command = "ssh $ssh_user@$ssh_host \"$subcommand\"";
			WP_CLI::line( $command );
			WP_CLI::launch( $command );
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

	private static function _push_db() {

		extract( self::$_settings );
		$env = self::$_env;
		$backup_name = time() . "_$env";
		$dump_name = date( 'Y_m_d-H_i' ) . "_$env";

		WP_CLI::line( "Pushing the db to $env ..." );

		$path = untrailingslashit( $path );
		$dump_name = date( 'Y_m_d-H_i' ) . "_$env";

		/** TODO: Add command description here.. */
		$commands = array(
			array( "scp $dump_name.sql $ssh_db_user@$ssh_db_host:$ssh_db_path", true, 'Copied the ready to deploy db to server.' ),
			array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql'", true, 'Deploying the db on server.', "Failed deploying the db to server. File '$dump_name.sql' is preserved on server." ),
			array( "ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; rm $dump_name.sql'" ),
			/* array( "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql; rm $dump_name.sql\"", true ), */
			array( "rm $dump_name.sql", 'Removing the local dump.' ),
		);

        self::_dump_db( $dump_name );
		self::_run_commands( $commands );
	}

	private function _push_uploads() {

		WP_CLI::line( "\n=Deploying the uploads to server." );
		$uploads_dir = wp_upload_dir();

		self::_upload_files( $uploads_dir['basedir'] );
		WP_CLI::success( "Deployed the '{$uploads_dir['basedir']}' to server." );
	}

    private static function _dump_db( $dump_name = '' ) {

        $env = self::$_env;
		$backup_name = time() . "_$env";
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = self::_trim_url( get_option( 'siteurl' ) );
		$path =  self::$_settings['path'];;
        $url = self::$_settings['url'];
		$dump_name = empty( $dump_name ) ? date( 'Y_m_d-H_i' ) . "_$env" : $dump_name;

		$commands = array(
			array( "wp db export $backup_name.sql", true, 'Exported local backup.' ),
			array( "wp search-replace --network $siteurl $url", true, "Replaced $siteurl with $url on local db." ),
			array( "wp search-replace --network $abspath $path", true, "Replaced $siteurl with with $path on local db." ),
			array( "wp db dump $dump_name.sql", true, 'Dumped the db which will be deployed.' ),
			array( "wp db import $backup_name.sql", true, 'Imported local backup.' ),
            array( "rm $backup_name.sql", 'Removed backup file.' )
        );

        self::_run_commands( $commands );
    }

    private static function _dump_uploads( $dump_name = '' ) {

        $uploads_dir = wp_upload_dir();
		$uploads_dir = pathinfo( $uploads_dir['basedir'] );

		self::_archive_file( $uploads_dir['filename'], $dump_name, $uploads_dir['dirname'] );
    }

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
    }

	private static function _run_commands( $commands ) {

		$commands = is_string( $commands ) ? array( $commands ) : $commands;

		foreach ( $commands as $command_info ) {
			$command = is_array( $command_info[0] ) ? $command_info[0][0] : $command_info[0];
			WP_CLI::line( "\n$ $command" );
			self::_verbose_launch( $command_info );
		}
	}

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

	private function _upload_files( $source_path ) {
		$upload_type = self::$_settings['upload_type'];
		call_user_func( "self::_{$upload_type}_files", $source_path );
	}

	private function _scp_files( $source_path ) {

		extract( self::$_settings );
		$remote_path = $ssh_path . '/';
		$path_info = pathinfo( $source_path );
		$dirpath = $path_info['dirname'];
		$basename = $path_info['basename'];

		$commands = array(
			array(
				"scp $basename.tar.gz $ssh_user@$ssh_host:$ssh_path",
				true,
				"Copied '$basename.tar.gz' to '$ssh_user@$ssh_host:$remote_path'.",
				"Failed copying '$basename.tar.gz' to server."
			),
			array( "rm $basename.tar.gz" ),
			array( "ssh $ssh_user@$ssh_host 'cd $ssh_path; tar -zxvf $basename.tar.gz; rm -rf $basename.tar.gz'" )
		);

        self::_archive_file( $basename, '', $dirpath );
		self::_run_commands( $commands );
	}

	private function _rsync_files( $source_path ) {

		extract( self::$_settings );
		$remote_path = $ssh_path . '/';
		/** TODO Manage by flag. */
		$exclude = array(
			'.git',
			'cache',
		);

		/** Exclude files from rsync. */
		$exclude = '--exclude '
			. implode(
				' --exclude ',
				array_map( 'escapeshellarg', $exclude )
			);
		$command = array(
			sprintf(
				'rsync -avz -e ssh %s %s@%s:%s %s',
				$source_path,
				$ssh_user,
				$ssh_host,
				$remote_path,
				$exclude
			),
			"Copied $source_path to $ssh_host:$remote_path"
		);

		self::_run_commands( array( $command ) );
	}

	/** TODO */
	private function pull( $args = array() ) {

		extract( self::_get_sanitized_args( $args ) );

		$const = strtoupper( ENVIRONMENT ) . '_LOCKED';
		if ( constant( $const ) === true ) {
			return WP_CLI::error( ENVIRONMENT . ' env is locked, you can not pull to your local copy' );
		}
		$host = $db_host . ':' . $db_port;

		$wpdb = new wpdb( $db_user, $db_password, $db_name, $host );
		$path = ABSPATH;
		$url = get_bloginfo( 'url' );
		$dist_path  = constant( self::_prefix_constant( 'path' ) ) . '/';
		$command = "ssh $ssh_user@$ssh_host  \"cd $dist_path;wp migrate to $path $url dump.sql\" && scp $ssh_user@$ssh_host:$dist_path/dump.sql .";
		WP_CLI::launch( $command );
		WP_CLI::launch( 'wp db import dump.sql' );
		self::pull_files( $args );
	}

	/** TODO */
	private function pull_files( $args = array() ) {

		WP_CLI::line( 'pulling files' );
		extract( self::_get_sanitized_args( $args ) );

		$const = strtoupper( ENVIRONMENT ) . '_LOCKED';
		if ( constant( $const ) === true ) {
			return WP_CLI::error( ENVIRONMENT . ' env is locked, you can not pull to your local copy' );
		}
		$host = $db_host.':'.$db_port;

		if ( $ssh_host ) {
			$dir = wp_upload_dir();
			$dist_path  = constant( self::_prefix_constant( 'path' ) ) . '/';
			$remote_path = $dist_path;
			$local_path = ABSPATH;

			WP_CLI::line( sprintf( 'Running rsync from %s:%s to %s', $ssh_host, $remote_path, $local_path ) );
			$com = sprintf( "rsync -avz -e ssh  %s@%s:%s %s  --delete --exclude '.git' --exclude 'wp-content/cache' --exclude 'wp-content/_wpremote_backups' --exclude 'wp-config.php'", $ssh_user, $ssh_host, $remote_path, $local_path );
			WP_CLI::line( $com );
			WP_CLI::launch( $com );
		}

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

		/** Use different upload methods. */
		$upload_types = self::$_upload_types;
		$out['upload_type'] = array_shift( array_keys( array_filter( $upload_types ) ) );
		if ( isset( $assoc_args['upload'] ) && in_array( $assoc_args['upload'], array_keys( $upload_types ) ) ) 
			$out['upload_type'] = $assoc_args['upload'];

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
}

WP_CLI::add_command( 'deploy', 'WP_Deploy_Flow_Command' );
WP_CLI::add_man_dir( __DIR__ . '/man', __DIR__ . '/man-src' );
