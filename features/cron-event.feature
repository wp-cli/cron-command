Feature: Manage WP Cron events

  Background:
    Given a WP install

  # Fails on WordPress 4.9 because `wp cron event run --due-now`
  # executes the "wp_privacy_delete_old_export_files" event there.
  @require-wp-5.0
  Scenario: --due-now with supplied events should only run those
    # WP throws a notice here for older versions of core.
    When I try `wp cron event run --all`
    Then STDOUT should contain:
      """
      Success: Executed a total of
      """

    When I run `wp cron event run --due-now`
    Then STDOUT should contain:
      """
      Executed a total of 0 cron events
      """

    When I run `wp cron event schedule wp_cli_test_event_1 now hourly`
    Then STDOUT should contain:
      """
      Success: Scheduled event with hook 'wp_cli_test_event_1'
      """

    When I run `wp cron event schedule wp_cli_test_event_2 now hourly`
    Then STDOUT should contain:
      """
      Success: Scheduled event with hook 'wp_cli_test_event_2'
      """

    When I run `wp cron event run wp_cli_test_event_1 --due-now`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_cli_test_event_1'
      """
    And STDOUT should contain:
      """
      Executed a total of 1 cron event
      """

    When I run `wp cron event run --due-now --exclude=wp_cli_test_event_2`
    Then STDOUT should contain:
      """
      Executed a total of 0 cron events
      """

    When I run `wp cron event run wp_cli_test_event_2 --due-now`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_cli_test_event_2'
      """
    And STDOUT should contain:
      """
      Executed a total of 1 cron event
      """

  @require-wp-4.9.0
  Scenario: Unschedule cron event
    When I run `wp cron event schedule wp_cli_test_event_1 now hourly`
    And I try `wp cron event unschedule wp_cli_test_event_1`
    Then STDOUT should contain:
      """
      Success: Unscheduled 1 event for hook 'wp_cli_test_event_1'.
      """

    When I run `wp cron event schedule wp_cli_test_event_2 now hourly`
    And I run `wp cron event schedule wp_cli_test_event_2 '+1 hour' hourly`
    And I try `wp cron event unschedule wp_cli_test_event_2`
    Then STDOUT should contain:
      """
      Success: Unscheduled 2 events for hook 'wp_cli_test_event_2'.
      """

    When I try `wp cron event unschedule wp_cli_test_event`
    Then STDERR should be:
      """
      Error: No events found for hook 'wp_cli_test_event'.
      """

  Scenario: Run cron event with a registered shutdown function
    Given a wp-content/mu-plugins/setup_shutdown_function.php file:
      """
      add_action('mycron', function() {
        breakthings();
      });

      register_shutdown_function(function() {
        $error = error_get_last();
        if ($error['type'] === E_ERROR) {
          WP_CLI::line('MY SHUTDOWN FUNCTION');
        }
        });
      """

    When I run `wp cron event schedule mycron now`
    And I try `wp cron event run --due-now`
    Then STDOUT should contain:
      """
      MY SHUTDOWN FUNCTION
      """

  Scenario: Run cron event with a registered shutdown function that logs to a file
    Given a wp-content/mu-plugins/setup_shutdown_function_log.php file:
      """
      <?php
      add_action('mycronlog', function() {
        breakthings();
      });

      register_shutdown_function(function() {
        error_log('LOG A SHUTDOWN FROM ERROR');
      });
      """

    And I run `wp config set WP_DEBUG true --raw`
    And I run `wp config set WP_DEBUG_LOG '{RUN_DIR}/server.log'`

    When I try `wp cron event schedule mycronlog now`
    And I try `wp cron event run --due-now`
    Then STDERR should contain:
      """
      Call to undefined function breakthings()
      """
    And the {RUN_DIR}/server.log file should exist
    And the {RUN_DIR}/server.log file should contain:
      """
      LOG A SHUTDOWN FROM ERROR
      """

  Scenario: Run cron event with arguments and debug output
    When I run `wp cron event schedule wp_cli_test_event_with_args now --0=123 --1=test-value`
    Then STDOUT should contain:
      """
      Success: Scheduled event with hook 'wp_cli_test_event_with_args'
      """

    When I try `wp cron event run wp_cli_test_event_with_args --due-now --debug=cron`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_cli_test_event_with_args'
      """
    And STDERR should contain:
      """
      Debug: Arguments: ["123","test-value"]
      """

    When I run `wp cron event schedule wp_cli_test_event_no_args now`
    And I try `wp cron event run wp_cli_test_event_no_args --due-now --debug=cron`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_cli_test_event_no_args'
      """
    And STDERR should not contain:
      """
      Debug: Arguments:
      """

  Scenario: Run cron events with --network flag on non-multisite
    When I try `wp cron event run --due-now --network`
    Then STDERR should be:
      """
      Error: This is not a multisite installation.
      """
    And the return code should be 1

  Scenario: Run cron events with --network flag on multisite
    Given a WP multisite subdirectory install
    And I run `wp site create --slug=site2`
    And I run `wp site create --slug=site3`

    When I run `wp cron event schedule wp_cli_network_test now`
    Then STDOUT should contain:
      """
      Success: Scheduled event with hook 'wp_cli_network_test'
      """

    When I run `wp --url=example.com/site2 cron event schedule wp_cli_network_test_site2 now`
    Then STDOUT should contain:
      """
      Success: Scheduled event with hook 'wp_cli_network_test_site2'
      """

    When I run `wp cron event run --due-now --network --exclude=wp_privacy_delete_old_export_files,wp_version_check,wp_update_plugins,wp_update_themes,wp_site_health_scheduled_check,wp_update_user_counts,wp_scheduled_delete,wp_scheduled_auto_draft_delete`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_cli_network_test'
      """
    And STDOUT should contain:
      """
      Executed the cron event 'wp_cli_network_test_site2'
      """
    And STDOUT should contain:
      """
      Success: Executed a total of 2 cron events across 3 sites.
      """

  Scenario: Confirm that cron event run in debug mode shows the start of events
    When I try `wp cron event run wp_version_check --debug=cron`
    Then STDOUT should contain:
      """
      Executed the cron event 'wp_version_check'
      """
    And STDOUT should contain:
      """
      Executed a total of 1 cron event
      """
    And STDERR should contain:
      """
      Debug: Beginning execution of cron event 'wp_version_check'
      """
