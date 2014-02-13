<?php
require 'helpers.php';
require 'runner.php';
use \WP_Deploy_Command\Helpers as Util;
use \WP_Deploy_Command\Command_Runner as Runner;

class WP_Deploy_Command extends WP_CLI_Command {

	/** The config holder. */
	private static $c;

	private static $fs;

	private static $config_dependencies;

	private static $env;

	public function __construct() {
		ini_set( 'display_errors', 'STDERR' );

		/** Define the constants dependencies. */
		self::$config_dependencies = array(
			'global' => array(
			),
			'commands' => array(
				'push' => array(
					'url',
					'wp_path'
				),
				'pull' => array(
				),
				'dump' => array(
					'wp_path',
					'url'
				),
			)
		);

		/**
		 * Depending paths need to be under the
		 * paths they depend on.
		 */
		self::$fs = array(
			'wd' => '%%abspath%%/%%env%%_%%hash%%',
			'tmp' => '%%wd%%/tmp/%%rand%%',
			'bk' => '%%wd%%/%%hostname%%_%%pretty_date%%',
		);
	}


/* define( 'DEV_URL', 'themes.portfolio.w3-edge.com' ); */
/* define( 'DEV_PATH', '/media/AuctolloProd/auctollo_production/portfolio/dev/wordpress' ); */
/* define( 'DEV_SSH_HOST', 'w3-edge.com' ); */
/* define( 'DEV_SSH_USER', 'w3edge' ); */
/* define( 'DEV_SSH_PATH', '/home/w3edge/wp_deploy' ); */
/* define( 'DEV_UPLOADS_PATH', '/media/AuctolloProd/auctollo_production/portfolio/dev/shared/uploads' ); */
/* define( 'DEV_DB_HOST', 'shared-001.cjwmyavkhhjf.us-east-1.rds.amazonaws.com' ); */
/* define( 'DEV_DB_NAME', 'w3_themes_portfolio' ); */
/* define( 'DEV_DB_USER', 'w3_auctollo_prod' ); */
/* define( 'DEV_DB_PASSWORD', 'a79uJdnSBcj4U3mQ' ); */

	private static function sanitize_args( $command, $args, $assoc_args = null ) {

		self::$env = $args[0];

		/** See if all required constants are defined. */
		self::validate_config( $command, self::$env );

		/** Expand the paths placeholders. */
		self::expand_paths( self::$fs );
	}

	/** Replace the placeholders in the paths with actual data. */
	private static function expand_paths( &$paths ) {

		$fs_data = array(
			'env' => self::$env,
			'hash' => Util::get_hash(),
			'abspath' => untrailingslashit( ABSPATH ),
			'pretty_date' => date( 'Y_m_d-H_i' ),
			'rand' => substr( sha1( time() ), 0, 8 ),
			'hostname' => Runner::get_result( "hostname" ),
		);

		foreach ( $paths as &$item ) {
			$item = Util::unplaceholdit( $item, $fs_data + array(
				/** This ensures that we can have dependencies. */
				'wd' => $paths['wd']
			) );
		}

	}

	/**
	 * Verifies that all required constants are defined.
	 * Constants must be of the form: "%ENV%_%NAME%"
	 */
	private static function validate_config( $command, $env ) {

		$required_constants = array_unique( array_merge(
			self::$config_dependencies['commands'][$command],
			self::$config_dependencies['global']
		) );

		$errors = array();
		$config = array();
		foreach ( $required_constants as $const ) {
			/** The constants template */
			$required_constant = strtoupper( $env . '_' . $const );
			var_dump( $required_constant );
			if ( ! defined( $required_constant ) ) {
				$errors[] = "Required constant $required_constant is not defined.";
			} else {
				$config[$required_constant] = constant( $required_constant );
			}
		}

		if ( count( $errors ) ) {
			foreach ( $errors as $error ) {
				WP_Cli::line( "$error" );
			}
			WP_Cli::error( 'The missing contants are required in order to run this subcommand.' );
			return false;
		}

		/** Save the config. */
		self::$c = json_decode( json_encode( $config ), false );

		return true;
	}

	/**
	 * Pushes the local database and / or uploads from local to remote.
	 *
	 * ## OPTIONS
	 *
	 * <environment>
	 * : The name of the environment. This is the prefix of the constants defined in
	 * wp-config.
	 *
	 * `--what`=<what>
	 * : What needs to be pushed. Suports multiple comma sepparated values. Valid
	 * options are: 'db' (pushes the database to the remote server) and
	 * 'uploads' (pushes the uploads to the remote server).
	 *
	 * ## EXAMPLE
	 *
	 *    # Push the database and the uploads for to "staging" environment.
	 *    # You must have STAGING_* constants defined for this to work.
	 *
	 *    wp deploy push staging --what=db,uploads
	 *
	 * @synopsis <environment> --what=<what> [--safe]
	 */
	public function push( $args, $assoc_args ) {

		$args = self::sanitize_args( __FUNCTION__, $args, $assoc_args );

		var_dump( self::$fs );
		die;

		if ( ! $args )
			return false;

		self::dump_db();
	}

	private function push_db() {

		extract( self::$_settings );
		$env = self::$_env;
		$backup_name = time() . "_$env";
		$server_file = "{$env}_push_" . self::_get_unique_env_id() . '.sql';

		WP_CLI::line( "Pushing your databse to {self::$env}." );

		$dump_file = self::dump_db();

		$runner = new Runner();

		$runner->add(
			Util::get_rsync(
				$dump_file,
				"$ssh_db_user@$ssh_db_host:$ssh_db_path/$server_file"
			),
			$message
		);

		$runner->add(
			"ssh $ssh_db_user@$ssh_db_host 'cd $ssh_db_path; mysql --user=$db_user --password=$db_password --host=$db_host $db_name < $server_file'",
			'Deploying the db on server.',
			'Failed deploying the db to server.'
		);

		$runner->add(
			"rm $dump_file",
			'Removing the local dump.'
		);

		$runner->run();
	}

	private static function dump_db() {
		$backup_file = 'x';//self::_get_temp_bk( '.sql' );
		$abspath = untrailingslashit( ABSPATH );
		$siteurl = untrailingslashit( Util::trim_url( get_option( 'siteurl' ) ) );
		$path = self::$c->wp_path;
		$url = self::$c->url;

		$runner = new Runner();

		$runner->add(
			( $abspath != $path ) || ( $url != $siteurl ),
			"wp db export $backup_file",
			'Exported local backup.'
		);

		$runner->add(
			( $url != $siteurl ),
			"wp search-replace --network $siteurl $url",
			'Exported local backup.'
		);

		$runner->add(
			( $siteurl != $url ),
			"wp search-replace --network $siteurl $url",
			"Replaced $siteurl with $url on local db."
		);

		$runner->add(
			( $abspath != $path ),
			"wp search-replace --network $abspath $path",
			"Replaced $abspath with with $path on local db."
		);

		$runnder->add(
			"wp db export $dump_file",
			'Dumped the db which will be deployed.'
		);

		$runner->add(
			( $abspath != $path ) || ( $url != $siteurl ),
			"wp db import $backup_file",
			'Imported local backup.'
		);

		var_dump( $runner->commands );

		/* $runner->run(); */

		return 'dumpfile';
	}

	private function push_uploads( $args ) {
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
	}

	/**
	 * Dumps the local database and / or uploads from local to remote. The
	 * database will be prepared for upload to the specified environment.
	 *
	 * ## OPTIONS
	 *
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
	 *
	 * ## EXAMPLE
	 *
	 *    # Dumps database for to "staging" environment. You must have STAGING_*
	 *    # constants defined for this to work
	 *    wp deploy dump staging --what=db
	 *
	 * @synopsis <environment> --what=<what> [--file=<file>]
	 */
	public function dump( $args, $assoc_args ) {
	}

	private static function _get_bk( $suffix = '' ) {
		$id = self::_launch( "hostname;" );
		$date = date( 'Y_m_d-H_i' );

		$suffix = empty( $suffix ) ? $suffix : "_$suffix";

		$fname = "{$id}_{$date}{$suffix}";

		return $fname;
	}

	private static function _get_local_bk( $suffix = '' ) {
		return trailingslashit( self::_get_wd() )
			. self::_get_bk( $suffix );
	}

	private static function _get_temp_bk( $suffix = '' ) {
		return trailingslashit( self::_get_wd() )
			. '/tmp/'
			. self::_get_bk( $suffix );
	}
}
WP_CLI::add_command( 'deploy', 'WP_Deploy_Command' );
