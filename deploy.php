<?php

/**
 * deploys
 *
 * @package wp-deploy-flow
 * @author Arnaud Sellenet
 */
class WP_Deploy_Flow_Command extends WP_CLI_Command {

	protected static $_env;

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

	/**
	 * Push local to remote
	 *
	 * @synopsis <environment> --what=<what>
	 */
	public function push( $args, $assoc_args ) {

		$settings = self::_get_sanitized_args( $args );

		if ( $settings['locked'] === true ) {
			WP_CLI::error( "$env environment is locked, you cannot push to it." );
			return;
		}

		/**
		 * 'what' accepts comma separated values.
		 */
		$what = explode( ',', $assoc_args['what'] );
		foreach ( $what as $item ) {
			if ( method_exists( __CLASS__, "_push_$item" ) )
				call_user_func( "self::_push_$item", $settings );
			else
				WP_CLI::line( "Don't know how to deploy: $item" );
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

	private static function _push_db( $settings ) {

		extract( $settings );
		$env = self::$_env;
		$backup_name = time() . "_$env";
		$dump_name = date( 'Y_m_d-H_i' ) . "_$env";

		WP_CLI::line( "Pushing the db to $env ..." );

		$siteurl = self::_trim_url( get_option( 'siteurl' ) );
		$abspath = untrailingslashit( ABSPATH );
		$ssh_path = untrailingslashit( $ssh_path );

		/** TODO: Add command description here.. */
		$commands = array(
			array( "wp db export $backup_name.sql", true, 'Exporting local backup.' ),
			array( "wp search-replace $siteurl $url", true, "Replacing $siteurl with $url on local db." ),
			array( "wp search-replace $abspath $ssh_path", true, "Replacing $siteurl with with $ssh_path on local db." ),
			array( "wp db dump $dump_name.sql", true, 'Dumping the ready to deploy db.' ),

			array( "wp db import $backup_name.sql", true, 'Importing local backup.' ),
			array( "rm $backup_name.sql", 'Removing backup file.' ),

			array( "scp $dump_name.sql $ssh_db_user@$ssh_db_host:$ssh_db_path", true, 'Copied the ready to deploy db to server.' ),
			array( "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path; sudo mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql\"", 'Deploying the db on server.', "Failed deploying the db to server. File '$dump_name.sql' is preserved on server." ),
			/* array( "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql; rm $dump_name.sql\"", true ), */
			array( "rm $dump_name.sql", 'Removing the local dump.' ),
		);

		foreach ( $commands as $command_info ) {
			WP_CLI::line( $command_info[0] );
			self::_verbose_launch( $command_info );
		}
	}

	private function _verbose_launch( $command_info ) {

		$command = array_shift( $command_info );
		$exit_on_error = is_bool( $command_info[0] ) && $command_info[0];
		$messages = array_filter( $command_info, function( $v ) { return is_string( $v ); } );

		$code = WP_CLI::launch( $command, $exit_on_error );

		if ( empty( $messages ) )
			return;

		$success = array_shift( $messages );
		$fail = ! empty( $messages ) ? array_shift( $messages ) : $success;

		if ( $code ) {
			WP_CLI::warning( $fail );
		} else {
			WP_CLI::success( $success );
		}
	}

	private function _push_uploads( $settings ) {

		$uploads_dir = wp_upload_dir();

		self::_rsync_files( $uploads_dir['basedir'], $settings );
		WP_CLI::success( "Synced the '{$uploads_dir['basedir']} to server." );
	}

	private function _rsync_files( $source_path, $settings ) {

		extract( $settings );
		$remote_path = $ssh_path . '/';
		/** TODO Manage by flag. */
		$exclude = array(
			'.git',
			'cache',
		);

		WP_CLI::line( sprintf(
			'Running rsync from %s to %s:%s\n',
			$source_path,
			$ssh_host,
			$remote_path
		) );

		/** Exclude files from rsync. */
		$exclude = '--exclude '
			. implode(
				' --exclude ',
				array_map( 'escapeshellarg', $exclude )
			);
		$command = sprintf(
			'rsync -avz -e ssh %s %s@%s:%s %s',
			$source_path,
			$ssh_user,
			$ssh_host,
			$remote_path,
			$exclude
		);

		WP_CLI::line( $command );
		WP_CLI::launch( $command );
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

	private static function _get_sanitized_args( $args ) {

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

		return $out;
	}

	protected static function _validate_config() {

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

	protected static function _get_constants() {

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

	protected static function _prefix_constant( $name ) {

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
