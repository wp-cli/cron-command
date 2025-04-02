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

  @less-than-wp-4.9.0
  Scenario: Unschedule cron event for WP < 4.9.0, wp_unschedule_hook was not included
    When I try `wp cron event unschedule wp_cli_test_event_1`
    Then STDERR should be:
      """
      Error: Unscheduling events is only supported from WordPress 4.9.0 onwards.
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
