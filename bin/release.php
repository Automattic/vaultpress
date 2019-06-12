<?php
/**
 * Plugin release management.
 *
 * Example usage: `php bin/release.php`
 *
 * @package jetpack
 */

/**
 * Functions!
 */

function build_or_update_production_release_branch( $version ) {
	$tmp = '/tmp/build-release';
	execute_command( sprintf( 'rm -rf %s', escapeshellarg( $tmp ) ), 'Could not clean.' );

	$release_branch = "release/$version";
	$release_branch_prod = "release/$version-prod";

	execute_command( sprintf( 'git checkout %s && git pull', escapeshellarg( $release_branch ) ), 'Could not check out to release branch.' );

	needs_action( "Building production branch...\n" );
	execute_command( 'yarn build-production', 'Something went wrong. See output above for error.' );
	success( "Release built! Now purging dev files for built branch...\n" );
	purge_dev_files();

	// Create a new local branch if none exists, else checkout to it
	$branch_exists = execute_command( sprintf( 'git ls-remote --exit-code --heads origin %s', escapeshellarg( $release_branch_prod ) ), '', true );
	if ( empty( $branch_exists ) ) {
		execute_command( sprintf( "git checkout -b %s", escapeshellarg( $release_branch_prod ) ), "Could not create local release branch: $release_branch_prod" );
	} else {
		$remote_url = execute_command( 'git remote get-url --all origin', 'Error', true );
		execute_command( sprintf( 'git clone --depth 1 -b %1s --single-branch %2s %3s', escapeshellarg( trim( $release_branch_prod ) ), escapeshellarg( $remote_url ), escapeshellarg( $tmp ) ), "Could not do it!" );
		execute_command( sprintf( 'rsync -r --delete --exclude="*.git*" . %s', $tmp ), "Could not rsync." );
		chdir( $tmp );
	}

	// Commit and push!
	execute_command( 'git commit -am "New Build"', 'Could not commit.' );
	execute_command( sprintf( "git push -u origin %s", escapeshellarg( $release_branch_prod ) ), "Could not push $release_branch_prod to remote." );
}

/**
 * Resets everything.
 */
function reset_and_clean() {
	execute_command( 'git fetch origin && git checkout master && git reset --hard origin/master' );
	execute_command( 'rm -rf /tmp/build-release' );
}

function purge_dev_files() {
	$ignored = file( dirname( dirname( __FILE__ ) ) . '/.svnignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

	foreach ( $ignored as $file_pattern ) {
		execute_command( sprintf( 'rm -rf %s', $file_pattern ) );
	}
}

/**
 * Prompt for user-input strings
 *
 * @param string $question
 * @param array $options
 * @return bool|string
 */
function prompt( $question = '', $options = array(), $show_options = true ) {
	if ( empty( $question ) ) {
		usage();
	};

	needs_action( $question );
	if ( ! empty( $options ) ) {
		$options_string = "Your options are: " . implode( ' or ', $options ) . "\n";
		if ( $show_options ) {
			echo $options_string;
		}
	}

	$handle = fopen( 'php://stdin','r' );
	$line = trim( fgets( $handle ) );

	if ( ! empty( $options ) && ! in_array( $line, $options ) ) {
		fail( "Sorry, that is not a valid input. Try again?" );
		echo $options_string;
		prompt( $question, $options, false );
	}

	echo "\n";
	return preg_replace( '/[^A-Za-z0-9\-\.]/', '', $line );
}

/**
 * Get a yes/no confirmation
 *
 * @param string $question
 */
function confirm( $question = '' ) {
	$question = ! empty( $question ) ? $question : "Are you sure you want to do this?  Type 'yes' to continue: ";
	needs_action( $question );

	$handle = fopen( 'php://stdin','r' );
	$line = fgets( $handle );
	if ( trim( $line ) != 'yes' ) {
		exit;
	}
	echo "\n";
}


// Bold
function needs_action( $string ) {
	echo sprintf( '%c[%sm%s%c[0m', 27, 1, (string) $string, 27 );
}

// Green
function success( $string ) {
	printf( "\e[32m%s\e[0m\n", $string );
}

// Red
function fail( $string ) {
	printf( "\e[31m%s\e[0m\n", $string );
}

/**
 * Execute a command.
 * On failure, throw an exception with the specified message (if specified).
 *
 * @param string $command           Command to execute.
 * @param string $error             Error message to be thrown if command fails.
 * @param bool   $return            Whether to return the output
 * @param bool   $cleanup_repo      Whether to cleaup repo on error.
 * @param bool   $cleanup_remotes   Whether to cleanup remotes on error.
 *
 * @return string|null
 */
function execute_command( $command, $error = '', $return = false, $cleanup_repo = false, $cleanup_remotes = false ) {
	if ( $return ) {
		return trim( shell_exec( $command ) );
	}

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru
	passthru( $command, $status );
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru

	if ( $error && 0 !== $status ) {
		cleanup( $cleanup_repo, $cleanup_remotes );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo( 'Error: ' . $error . PHP_EOL );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	return '';
}

/**
 * Cleanup repository and remotes.
 * Should be called at any error that changes the repo, or at success at the end.
 *
 * @param bool $cleanup_repo    Whether to cleaup repo on error.
 * @param bool $cleanup_remotes Whether to cleanup remotes on error.
 */
function cleanup( $cleanup_repo = false, $cleanup_remotes = false ) {
	if ( $cleanup_repo ) {
		// Reset the main repository to the original state.
		execute_command( 'git reset --hard refs/original/refs/heads/master', 'Could not reset the repository to its original state.' );

		// Pull the latest master from the main repository.
		execute_command( 'git pull', 'Could not pull the latest master from the repository.' );
	}

	if ( $cleanup_remotes ) {
		// Remove the temporary repository package remote.
		execute_command( 'git remote rm package', 'Could not clean up the package repository remote.' );
	}
}

/**
 * How does it work?
 *
 * @param int $exit_value
 * @param string $message
 */
function usage( $exit_value = 1, $message = '' ) {
	$handle = $exit_value ? STDERR : STDOUT;

	if ( $message ) {
		fwrite( $handle, "$message\n\n" );
	}

	$usage = <<<"USAGE"
php {$GLOBALS['argv'][0]} --new=[major|point] --update=RELEASE_NUM

  Plugin release management scripts.

  Can do things like:
  - Create a new release branch in GitHub
  - Update an existing release branch in GitHub
  - Publish a release branch as a tag/release on GitHub
  - Publish a GitHub tag/release to the wp.org svn

    --list
        List all release branches and tags

    --new RELEASE_NUM
        New release?

    --update RELEASE_NUM
        Update an existing release in GitHub.

    --publish RELEASE_NUM [github|svn]
        Create a new release in GitHub or wp.org SVN


USAGE;

	fwrite( $handle, $usage );
	exit( (int) $exit_value );
}



/**
 * Begin script!
 */

// Should never be uncommitted changes
$changes = execute_command( "git status -s --porcelain", 'Uncommitted changes found.', true );
if ( ! empty( $changes ) ) {
	fail( 'Uncommitted changes found, please clean them up.' );
	exit;
}

$opts = array(
	'list',
	'new::',
	'update::',
//	'publish::', @todo
);
$args = getopt( '', $opts );

// Gotta tell us something
if ( empty( $args ) ) {
	usage();
}
else if ( isset( $args['list'] ) ) {
	execute_command( "git branch -l origin release/*", "No release branches found." );
}
else if ( isset( $args['new'] ) ) {
	$version = $args['new'];
	if ( empty( $version ) ) {
		$version = prompt( "What version are you releasing?\n" );
	}

	$release_branch = "release/$version";
	$release_branch_prod = "release/$version-prod";

	// Checkout to current origin/master in detached state
	execute_command( "git fetch origin master", "Could not fetch origin master" );
	execute_command( "git checkout origin/master", "Could not check out to origin/master" );

	// Create a new local branch
	execute_command( sprintf( "git checkout -b %s", escapeshellarg( $release_branch ) ), "Could not create local release branch: $release_branch" );

	// Push it to remote
	execute_command( sprintf( "git push -u origin %s", escapeshellarg( $release_branch ) ), "Could not push $release_branch to remote." );
	success( "New dev release branch pushed!\n" );

	build_or_update_production_release_branch( $version );

	success( "Success! New release branches were created and pushed to the repo. \n- dev: $release_branch\n- production: $release_branch_prod\n" );
}
else if ( isset( $args['update'] ) ) {
	$version = $args['update'];
	if ( empty( $update ) ) {
		$version = prompt( "What version are you updating?\n" );
	}

	build_or_update_production_release_branch( $version );
	success( "Updated the $version production release branch!" );
}

reset_and_clean();
