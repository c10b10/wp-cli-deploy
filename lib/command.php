<?php
WP_CLI::add_command( 'deploy', 'WP_Deploy_Flow_Command' );

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
  * @synopsis <environment>
  */
  public function push( $args = array() ) {

    extract(self::_prepareAndExtract($args, false));
    if($locked === true) {
      WP_CLI::error("$env environment is locked, you cannot push to it");
      return;
    }

    $commands = array(
      "wp migrate to $path $url dump.sql",
      'sed -i "" -e "1s/^/SET NAMES UTF8;/" dump.sql',
      "scp dump.sql $ssh_db_user@$ssh_db_host:$ssh_db_path",
      "ssh $ssh_db_user@$ssh_db_host \"cd $ssh_db_path;cat dump.sql | mysql --host=$db_host --user=$db_user --password=$db_password $db_name; rm dump.sql\"",
      "rm dump.sql",
      "git push $env"
    );

    foreach($commands as $command) {
      WP_CLI::line($command);
      WP_CLI::launch($command);
    }

    if($remove_admin === true) {
      $com = "ssh $ssh_user@$ssh_host \"cd $path;rm -Rf wp-login.php\"";
      WP_CLI::line($com);
      WP_CLI::launch($com);
    }

    self::push_uploads($args);
  }
  public function push_uploads( $args = array() )
  {
    extract(self::_prepareAndExtract($args, false));
    if($locked === true) {
      WP_CLI::error("$env environment is locked, you cannot push to it");
      return;
    }
    if($ssh_host) {
      $dir = wp_upload_dir();
      $remote_path = $path.'/wp-content';
      $local_path = $dir['basedir'];

      WP_CLI::line( sprintf( 'Running rsync from %s to %s:%s', $local_path, $ssh_host, $remote_path ) );
      $command = sprintf( "rsync -avz -e ssh %s %s@%s:%s  --exclude 'cache' --exclude '_wpremote_backups'", $local_path, $ssh_user, $ssh_host, $remote_path );
      WP_CLI::line($command);
      WP_CLI::launch($command);
    }
  }
  public function pull( $args = array() ) {
    extract(self::_prepareAndExtract($args, false));

    $const = strtoupper(ENVIRONMENT).'_LOCKED';
    if(constant($const) === true) {
      return WP_CLI::error(ENVIRONMENT.' env is locked, you can not pull to your local copy');
    }
    $host = $db_host.':'.$db_port;

    $wpdb = new Wpdb($db_user, $db_password, $db_name, $host);
    $path = ABSPATH;
    $url = get_bloginfo( 'url' );
    $dist_path  = constant(self::config_constant('path'));
    $command = "ssh $ssh_user@$ssh_host  \"cd $dist_path;wp migrate to $path $url dump.sql\" && scp $ssh_user@$ssh_host:$dist_path/dump.sql .";
    WP_CLI::launch($command);
    WP_CLI::launch("wp db import dump.sql");

  }
  public function pull_uploads($args = array())
  {
    extract(self::_prepareAndExtract($args, false));

    $const = strtoupper(ENVIRONMENT).'_LOCKED';
    if(constant($const) === true) {
      return WP_CLI::error(ENVIRONMENT.' env is locked, you can not pull to your local copy');
    }
    $host = $db_host.':'.$db_port;

    if($ssh_host) {
      $dir = wp_upload_dir();
      $dist_path  = constant(self::config_constant('path'));
      $remote_path = $dist_path.'/wp-content/uploads/';
      $local_path = $dir['basedir'];

      WP_CLI::line( sprintf( 'Running rsync from %s:%s to %s', $ssh_host, $remote_path, $local_path ) );
      $com = sprintf( "rsync -avz -e ssh  %s@%s:%s %s  --delete --exclude 'cache' --exclude '_wpremote_backups'", $ssh_user, $ssh_host, $remote_path, $local_path );
      WP_CLI::line( $com );
      WP_CLI::launch( $com );
    }

  }
  public static function _prepareAndExtract($args, $tunnel = true)
  {
    $out = array();
    self::$_env = $args[0];
    $errors = self::_validateConfig();
    if($errors !== true) {
      foreach($errors as $error) {
        WP_Cli::error($error);
      }
      return false;
    }
    $out = self::config_constants_to_array();
    $out['env'] = self::$_env;
    $out['db_user'] = escapeshellarg($out['db_user']);
    $out['db_host'] = escapeshellarg($out['db_host']);
    $out['db_password'] = escapeshellarg($out['db_password']);

    if ( $out['ssh_db_host'] && $tunnel) {
      $com = sprintf( "ssh -f -L 3310:127.0.01:%s %s@%s sleep 600 >> logfile", ($out['db_port'] ? $out['db_port'] : 3306), $out['ssh_db_user'], escapeshellarg($out['ssh_db_host']) );
      shell_exec( $com );
      $out['db_host'] = '127.0.0.1';
      $out['db_port'] = '3310';
    }
    return $out;
  }
  protected static function _validateConfig() {
    $errors = array();
    foreach(array('path', 'url', 'db_host', 'db_user', 'db_name', 'db_password') as $postfix) {
      $required_constant = self::config_constant($postfix);
      if(!defined($required_constant)) {
        $errors []= "$required_constant is not defined";
      }
    }
    if(count($errors) == 0) return true;
    return $errors;
  }
  protected static function config_constant($postfix) {
    return strtoupper(self::$_env.'_'.$postfix);
  }

  protected static function config_constants_to_array() {
    $out = array();
    foreach(array('locked', 'path', 'ssh_db_path', 'url', 'db_host', 'db_user', 'db_port', 'db_name', 'db_password', 'ssh_db_host', 'ssh_db_user', 'ssh_db_path',  'ssh_host', 'ssh_user', 'remove_admin') as $postfix) {
      $out[$postfix] = defined(self::config_constant($postfix)) ? constant(self::config_constant($postfix)) : null;
    }
    return $out;
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