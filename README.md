# wp-deploy-flow

This is a modified version of [wp-deploy-flow](https://github.com/demental/wp-deploy-flow)
A wp-cli command to deploy your wordpress instance.

## Dependencies

* Wordpress
* git (currently) on all your servers
* rsync

## Install - Usage

http://demental.info/blog/2013/04/09/a-decent-wordpress-deploy-workflow/

Currently, this fork only supports one subcommand: `wp push <environment>
--what<what>`.

* <environment>:

	The name of the environment. This is the prefix of the constants defined in
wp-config.

* `--what`=<what>:

	What needs to be pushed. Suports multiple comma sepparated values. This
determines the order of execution for deployments. Valid options are: 'db'
(deploys the databse with the url and paths replaced) and 'uploads' (deploys
the uploads folder).

__EXAMPLES__

	# Deploy database and uploads folder
	wp deploy staging --what=db,uploads

## List of wp-config.php constant

%ENV% must is the environment that needs to be specified in the deploy
subcommands

* `%ENV%_URL`: Required. The URL of the staging server. This will be replaced in the
  deployed db.
* `%ENV%_SSH_HOST`: Required. The SSH host for the server where the uploads will be
  deployed.
* `%ENV%_SSH_USER`: Required. The SSH user for the server where the uploads will be
  deployed.
* `%ENV%_SSH_PATH`: Required. The SSH path on the server where the uploads will be
  deployed.
* `%ENV%_SSH_DB_HOST`: This will be used for the db deployment. If missing,
`%ENV%_SSH_HOST` will be used.
* `%ENV%_SSH_DB_USER`: This will be used for the db deployment. If missing,
`%ENV%_SSH_USER` will be used.
* `%ENV%_SSH_DB_HOST`: This will be used for the db deployment. If missing,
`%ENV%_SSH_HOST` will be used.
* `%ENV%_DB_HOST`: Required. The db host for the server where db will be deployed.
* `%ENV%_DB_USER`: Required. The mysql username for the server where the db will be
  deployed.
* `%ENV%_DB_PORT`: The db port.
* `%ENV%_DB_NAME`: Required. The db name on the server where the db will be
  deployed.
* `%ENV%_DB_PASSWORD`: Required. The db password on the server where the deb
  will be deployed.
* `%ENV%_LOCKED`: If this is set to true, no push can be made on that server.

## Notes

* The search replace operation on the db is ran using the `--network` flag,
  which means all tables will have their url and paths updated.

See the issue tracker for more info about what's planned.
