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
	 * Push local to remote
	 *
	 * @synopsis <environment> --mode=<mode>
	 */
	public function push( $args, $assoc_args ) {

		$settings = self::_prepare_and_extract( $args );

		if ( $settings['locked'] === true ) {
			WP_CLI::error( "$env environment is locked, you cannot push to it." );
			return;
		}

		/**
		 * Mode accepts comma separated modes
		 */
		$modes = explode( ',', $assoc_args['mode'] );
		foreach ( $modes as $mode ) {
			if ( method_exists( __CLASS__, "_push_$mode" ) )
				call_user_func( "self::_push_$mode", $settings );
			else
				WP_CLI::line( "No such mode: $mode" );
		}

		/* if ( $remove_admin === true ) { */
		/* 	$com = "ssh $ssh_user@$ssh_host \"cd $path;rm -Rf wp-login.php\""; */
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

		/** TODO: Add command description here.. */
		$commands = array(
			array( "wp db export $backup_name.sql", true ),
			/* array( "wp search-replace $siteurl $url", true ), */
			/* array( 'wp search-replace ' . untrailingslashit( ABSPATH ) . ' ' . untrailingslashit( $path ), true ), */
			/* array( "wp db dump $dump_name.sql", true ), */

			array( "wp db import $backup_name.sql", true ),
			array( "rm $backup_name.sql", true ),

			/* array( "scp $dump_name.sql $ssh_db_user@$ssh_db_host:$ssh_db_path", true ), */
			array( "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql\"" ),
			array( "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $dump_name.sql; rm $dump_name.sql\"", true ),
			array( "rm $dump_name.sql", true ),
		);

		foreach ( $commands as $command_info ) {
			list( $command, $exit_on_error ) = $command_info;
			WP_CLI::line( $command );
			WP_CLI::launch( $command, $exit_on_error );
		}
	}

	private function _push_uploads( $settings ) {

		$uploads_dir = wp_upload_dir();

		self::_rsync_files( $uploads_dir['basedir'], $settings );
		WP_CLI::success( "Synced the '{$uploads_dir['basedir']} to server." );
	}

	private function _rsync_files( $source_path, $settings ) {

		extract( $settings );
		$remote_path = $path . '/';
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

	public function pull( $args = array() ) {
		extract( self::_prepare_and_extract( $args ) );

		$const = strtoupper( ENVIRONMENT ) . '_LOCKED';
		if ( constant( $const ) === true ) {
			return WP_CLI::error( ENVIRONMENT . ' env is locked, you can not pull to your local copy' );
		}
		$host = $db_host . ':' . $db_port;

		$wpdb = new wpdb( $db_user, $db_password, $db_name, $host );
		$path = ABSPATH;
		$url = get_bloginfo( 'url' );
		$dist_path  = constant( self::config_constant( 'path' ) ) . '/';
		$command = "ssh $ssh_user@$ssh_host  \"cd $dist_path;wp migrate to $path $url dump.sql\" && scp $ssh_user@$ssh_host:$dist_path/dump.sql .";
		WP_CLI::launch( $command );
		WP_CLI::launch( 'wp db import dump.sql' );
		self::pull_files( $args );
	}

	public function pull_files( $args = array() ) {

		WP_CLI::line( 'pulling files' );
		extract( self::_prepare_and_extract( $args ) );

		$const = strtoupper( ENVIRONMENT ) . '_LOCKED';
		if ( constant( $const ) === true ) {
			return WP_CLI::error( ENVIRONMENT . ' env is locked, you can not pull to your local copy' );
		}
		$host = $db_host.':'.$db_port;

		if ( $ssh_host ) {
			$dir = wp_upload_dir();
			$dist_path  = constant( self::config_constant( 'path' ) ) . '/';
			$remote_path = $dist_path;
			$local_path = ABSPATH;

			WP_CLI::line( sprintf( 'Running rsync from %s:%s to %s', $ssh_host, $remote_path, $local_path ) );
			$com = sprintf( "rsync -avz -e ssh  %s@%s:%s %s  --delete --exclude '.git' --exclude 'wp-content/cache' --exclude 'wp-content/_wpremote_backups' --exclude 'wp-config.php'", $ssh_user, $ssh_host, $remote_path, $local_path );
			WP_CLI::line( $com );
			WP_CLI::launch( $com );
		}

	}

	public static function _prepare_and_extract( $args ) {
		$out = array();
		self::$_env = $args[0];
		$errors = self::_validate_config();
		if ( $errors !== true ) {
			foreach ( $errors as $error ) {
				WP_Cli::error( $error );
			}
			return false;
		}
		$out = self::config_constants_to_array();
		$out['env'] = self::$_env;
		$out['db_user'] = escapeshellarg( $out['db_user'] );
		$out['db_host'] = escapeshellarg( $out['db_host'] );
		$out['db_password'] = escapeshellarg( $out['db_password'] );

		return $out;
	}

	protected static function _validate_config() {
		$errors = array();
		foreach ( array( 'path', 'url', 'db_host', 'db_user', 'db_name', 'db_password' ) as $postfix ) {
			$required_constant = self::config_constant( $postfix );
			if ( ! defined( $required_constant ) ) {
				$errors[] = "$required_constant is not defined";
			}
		}
		if ( count( $errors ) == 0 ) return true;
		return $errors;
	}

	protected static function config_constant( $postfix ) {

		return strtoupper( self::$_env.'_'.$postfix );
	}

	protected static function config_constants_to_array() {
		$out = array();
		foreach ( array( 'locked', 'path', 'ssh_db_path', 'url', 'db_host', 'db_user', 'db_port', 'db_name', 'db_password', 'ssh_db_host', 'ssh_db_user', 'ssh_db_path', 'ssh_host', 'ssh_user', 'remove_admin' ) as $postfix ) {
			$out[$postfix] = defined( self::config_constant( $postfix ) ) ? constant( self::config_constant( $postfix ) ) : null;
		}
		return $out;
	}

	private static function _trim_url( $url ) {

		/** In case scheme relative URI is passed, e.g., //www.google.com/ */
		$url = trim( $url, '/' );

		/** If scheme not included, prepend it */
		if ( ! preg_match( '#^http(s)?://#', $url ) ) {
			$url = 'http://' . $url;
		}

		$url_parts = parse_url( $url );

		/** Remove www. */
		$domain = preg_replace( '/^www\./', '', $url_parts['host'] );

		return $domain;
	}

	/**
	 * Help function for this command
	 */
	public static function help() {
		WP_CLI::line( <<<EOB

EOB
  );
  }
}

WP_CLI::add_command( 'deploy', 'WP_Deploy_Flow_Command' );
